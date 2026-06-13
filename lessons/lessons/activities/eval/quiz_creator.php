<?php
/**
 * quiz_creator.php
 * Create a quiz from scratch — accessible from hub or admin_eval.
 * Saves to eval_exams + eval_questions + eval_answers (same tables as admin_eval).
 * URL: quiz_creator.php?unit=48   (from hub)
 *      quiz_creator.php           (standalone from admin)
 */
session_start();

$isAdmin   = !empty($_SESSION['admin_logged']);
$isTeacher = !empty($_SESSION['academic_logged']);

if (!$isAdmin && !$isTeacher) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

require_once __DIR__ . '/../../../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) die('DB unavailable.');

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$unitId  = (int) ($_GET['unit'] ?? $_POST['unit_id'] ?? 0);
$examId  = (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$msg     = '';

$skillLabels = [
    'grammar'    => 'Grammar',
    'vocabulary' => 'Vocabulary',
    'listening'  => 'Listening',
    'reading'    => 'Reading',
    'writing'    => 'Writing',
    'speaking'   => 'Speaking',
];

// ── Auto-create eval tables if needed ────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS eval_exams (
        id SERIAL PRIMARY KEY, title TEXT NOT NULL, cefr_level TEXT,
        unit_id INTEGER, time_limit_min INTEGER DEFAULT 50,
        max_attempts INTEGER DEFAULT 1, status TEXT DEFAULT 'draft',
        modalities JSONB DEFAULT '[\"online\",\"printed\"]',
        instructions TEXT, created_by TEXT, created_at TIMESTAMPTZ DEFAULT NOW()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS eval_questions (
        id SERIAL PRIMARY KEY, exam_id INTEGER NOT NULL REFERENCES eval_exams(id) ON DELETE CASCADE,
        type TEXT NOT NULL, skill TEXT DEFAULT 'grammar',
        question_text TEXT, audio_url TEXT, image_url TEXT,
        points NUMERIC DEFAULT 1, position INTEGER DEFAULT 0,
        data JSONB DEFAULT '{}'
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS eval_answers (
        id SERIAL PRIMARY KEY, question_id INTEGER NOT NULL REFERENCES eval_questions(id) ON DELETE CASCADE,
        answer_text TEXT, is_correct BOOLEAN DEFAULT FALSE, order_index INTEGER DEFAULT 0
    )");
} catch (Throwable $e) {}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Save exam config
    if ($action === 'save_exam') {
        $title     = trim($_POST['title'] ?? '');
        $cefr      = trim($_POST['cefr_level'] ?? '');
        $uid       = (int) ($_POST['unit_id'] ?? 0) ?: null;
        $time      = (int) ($_POST['time_limit_min'] ?? 50);
        $attempts  = (int) ($_POST['max_attempts'] ?? 1);
        $status    = trim($_POST['status'] ?? 'draft');
        $mods      = $_POST['modalities'] ?? ['online', 'printed'];

        if ($title === '') { $msg = 'El nombre del examen es requerido.'; }
        else {
            $modsJson = json_encode(array_values($mods));
            if ($examId > 0) {
                $pdo->prepare(
                    "UPDATE eval_exams SET title=?,cefr_level=?,unit_id=?,time_limit_min=?,
                     max_attempts=?,status=?,modalities=? WHERE id=?"
                )->execute([$title,$cefr,$uid,$time,$attempts,$status,$modsJson,$examId]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO eval_exams(title,cefr_level,unit_id,time_limit_min,
                     max_attempts,status,modalities,created_by)
                     VALUES(?,?,?,?,?,?,?,?) RETURNING id"
                );
                $stmt->execute([$title,$cefr,$uid,$time,$attempts,$status,$modsJson,
                    $_SESSION['admin_username'] ?? $_SESSION['teacher_username'] ?? 'admin']);
                $examId = (int) $stmt->fetchColumn();
            }
            header('Location: quiz_creator.php?unit='.$unitId.'&exam_id='.$examId.'&msg=saved');
            exit;
        }
    }

    // Save question
    if ($action === 'save_question') {
        $examId  = (int) ($_POST['exam_id'] ?? 0);
        $qId     = (int) ($_POST['question_id'] ?? 0);
        $type    = trim($_POST['type'] ?? 'multiple_choice');
        $skill   = trim($_POST['skill'] ?? 'grammar');
        $text    = trim($_POST['question_text'] ?? '');
        $audio   = trim($_POST['audio_url'] ?? '');
        $image   = trim($_POST['image_url'] ?? '');
        $points  = (float) ($_POST['points'] ?? 1);

        if ($qId > 0) {
            $pdo->prepare(
                "UPDATE eval_questions SET type=?,skill=?,question_text=?,
                 audio_url=?,image_url=?,points=? WHERE id=? AND exam_id=?"
            )->execute([$type,$skill,$text,$audio?:null,$image?:null,$points,$qId,$examId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO eval_questions(exam_id,type,skill,question_text,audio_url,image_url,points)
                 VALUES(?,?,?,?,?,?,?) RETURNING id"
            );
            $stmt->execute([$examId,$type,$skill,$text,$audio?:null,$image?:null,$points]);
            $qId = (int) $stmt->fetchColumn();
        }

        $pdo->prepare("DELETE FROM eval_answers WHERE question_id=?")->execute([$qId]);
        $answerTexts   = (array) ($_POST['answer_text'] ?? []);
        $answerCorrect = (array) ($_POST['answer_correct'] ?? []);
        $aStmt = $pdo->prepare(
            "INSERT INTO eval_answers(question_id,answer_text,is_correct,order_index) VALUES(?,?,?,?)"
        );
        foreach ($answerTexts as $idx => $aText) {
            $aText = trim((string) $aText);
            if ($aText === '') continue;
            $isCorrect = isset($answerCorrect[$idx]) && in_array($answerCorrect[$idx], ['1','true',1,true], true);
            $aStmt->execute([$qId, $aText, $isCorrect ? 'true' : 'false', $idx]);
        }

        header('Location: quiz_creator.php?unit='.$unitId.'&exam_id='.$examId.'&msg=question_saved');
        exit;
    }

    // Delete question
    if ($action === 'delete_question') {
        $qId    = (int) ($_POST['question_id'] ?? 0);
        $examId = (int) ($_POST['exam_id'] ?? 0);
        $pdo->prepare("DELETE FROM eval_questions WHERE id=? AND exam_id=?")->execute([$qId,$examId]);
        header('Location: quiz_creator.php?unit='.$unitId.'&exam_id='.$examId.'&msg=deleted');
        exit;
    }
}

// Flash msg from redirect
if (($_GET['msg'] ?? '') === 'saved')          $msg = '✅ Examen guardado. Ahora agrega las preguntas.';
if (($_GET['msg'] ?? '') === 'question_saved') $msg = '✅ Pregunta guardada.';
if (($_GET['msg'] ?? '') === 'deleted')        $msg = 'Pregunta eliminada.';

// ── Load exam ─────────────────────────────────────────────────────────────────
$exam = null;
if ($examId > 0) {
    $s = $pdo->prepare("SELECT e.*, u.name AS unit_name FROM eval_exams e
                         LEFT JOIN units u ON u.id=e.unit_id WHERE e.id=? LIMIT 1");
    $s->execute([$examId]);
    $exam = $s->fetch(PDO::FETCH_ASSOC);
    if ($exam && !$unitId && $exam['unit_id']) $unitId = (int)$exam['unit_id'];
}

// ── Load unit name ────────────────────────────────────────────────────────────
$unitName = '';
if ($unitId > 0) {
    $s = $pdo->prepare("SELECT name FROM units WHERE id=? LIMIT 1");
    $s->execute([$unitId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $unitName = $row['name'] ?? '';
}

// ── Load questions ────────────────────────────────────────────────────────────
$examQuestions = [];
if ($examId > 0) {
    $s = $pdo->prepare(
        "SELECT eq.*,
                array_agg(ea.answer_text  ORDER BY ea.order_index) FILTER (WHERE ea.answer_text IS NOT NULL) AS answer_texts,
                array_agg(ea.is_correct   ORDER BY ea.order_index) FILTER (WHERE ea.answer_text IS NOT NULL) AS answer_corrects
         FROM eval_questions eq
         LEFT JOIN eval_answers ea ON ea.question_id=eq.id
         WHERE eq.exam_id=?
         GROUP BY eq.id ORDER BY eq.position,eq.id"
    );
    $s->execute([$examId]);
    $examQuestions = $s->fetchAll(PDO::FETCH_ASSOC);
}

$mods = json_decode($exam['modalities'] ?? '["online","printed"]', true) ?: ['online','printed'];
$totalPts = array_sum(array_column($examQuestions, 'points'));

// Back URL
$backUrl = $unitId
    ? '/lessons/lessons/activities/hub/index.php?unit='.$unitId
    : '/lessons/lessons/activities/eval/admin_eval.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $exam ? h($exam['title']) : 'Crear quiz' ?> — ONES</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--ora:#F97316;--pur:#7F77DD;--pur-l:#EEEDFE;--pur-d:#534AB7;--line:#EDE9FA;--bg:#F0EFF8;--ink:#1a1a2e;--muted:#9B8FCC;--r:14px}
body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}

/* Topbar */
.topbar{background:#fff;border-bottom:1.5px solid var(--line);height:58px;display:flex;align-items:center;padding:0 24px;gap:14px;position:sticky;top:0;z-index:50}
.tb-logo{font-family:'Fredoka One',sans-serif;font-size:20px;color:var(--ora)}
.tb-sep{width:1.5px;height:24px;background:var(--line)}
.tb-title{font-size:14px;font-weight:700;color:var(--ink)}
.tb-unit{font-size:12px;font-weight:600;color:var(--muted);background:var(--pur-l);padding:3px 10px;border-radius:20px}
.tb-right{margin-left:auto;display:flex;gap:8px}

/* Page */
.page{max-width:820px;margin:0 auto;padding:24px 20px 60px;display:flex;flex-direction:column;gap:18px}

/* Card */
.card{background:#fff;border:1.5px solid var(--line);border-radius:var(--r);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1.5px solid var(--line)}
.card-title{font-family:'Fredoka One',sans-serif;font-size:17px;color:var(--ink)}
.card-sub{font-size:11px;font-weight:600;color:var(--muted);margin-top:2px}
.card-body{padding:19px}

/* Form */
.fg{margin-bottom:14px}
.fg:last-child{margin-bottom:0}
.fg label{display:block;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px}
.fg input,.fg select,.fg textarea{width:100%;padding:8px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:'Nunito',sans-serif;font-size:13px;color:var(--ink);background:#fff;outline:none;transition:border .15s}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--muted)}
.fg textarea{min-height:72px;resize:vertical}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:13px}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:#374151;text-decoration:none;white-space:nowrap;transition:all .15s}
.btn i{font-size:14px}
.btn:hover{background:var(--pur-l);border-color:#C4B9E8;color:var(--pur-d)}
.btn-ora{background:var(--ora);border-color:var(--ora);color:#fff}
.btn-ora:hover{background:#E8650A;border-color:#E8650A;color:#fff}
.btn-pur{background:var(--pur);border-color:var(--pur);color:#fff}
.btn-pur:hover{background:#6B63CC;color:#fff}
.btn-grn{background:#16A34A;border-color:#16A34A;color:#fff}
.btn-grn:hover{background:#15803D;color:#fff}
.btn-red{background:#fff;border-color:#FCA5A5;color:#DC2626}
.btn-red:hover{background:#FFF5F5}
.btn-sm{padding:5px 10px;font-size:11.5px;border-radius:7px}
.btn-ghost{background:transparent;border-color:transparent;color:var(--muted)}
.btn-ghost:hover{background:var(--pur-l);color:var(--pur-d);border-color:var(--line)}

/* Msg */
.msg{padding:11px 16px;border-radius:9px;font-size:13px;font-weight:700;background:#D1FAE5;color:#065F46;border:1.5px solid #A7F3D0}
.msg.err{background:#FEE2E2;color:#DC2626;border-color:#FCA5A5}

/* Type picker */
.type-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:18px}
.qtype-card{border:2px solid var(--line);border-radius:10px;padding:12px 8px;cursor:pointer;text-align:center;transition:all .15s;background:#fff;user-select:none}
.qtype-card:hover{border-color:#C4B9E8;background:var(--pur-l)}
.qtype-card i{font-size:22px;color:var(--muted);display:block;margin-bottom:6px;transition:color .15s}
.qtype-card .ql{font-size:10px;font-weight:800;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;line-height:1.3;transition:color .15s}

/* MC options */
.mc-row{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid var(--line);border-radius:9px;background:#FAFAFE;margin-bottom:6px;transition:all .15s}
.mc-row.correct-row{border-color:#10B981;background:#F0FDF4}
.mc-letter{width:26px;height:26px;border-radius:7px;background:var(--pur-l);color:var(--pur-d);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s}
.mc-row.correct-row .mc-letter{background:#10B981;color:#fff}
.mc-text{flex:1;border:none;background:transparent;font-family:'Nunito',sans-serif;font-size:13px;color:var(--ink);outline:none}
.mc-mark{padding:3px 9px;border-radius:6px;border:1.5px solid var(--line);background:#fff;font-size:10px;font-weight:800;cursor:pointer;color:#6B7280;white-space:nowrap;transition:all .15s}
.mc-row.correct-row .mc-mark{background:#10B981;border-color:#10B981;color:#fff}
.mc-del{width:24px;height:24px;border-radius:6px;border:none;background:#FEE2E2;color:#DC2626;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;line-height:1}

/* Fill preview */
.fill-prev{background:var(--pur-l);border:1.5px solid var(--line);border-radius:9px;padding:11px 13px;font-size:14px;line-height:2.2;color:var(--ink);min-height:44px}
.fill-blank{display:inline-block;min-width:70px;border-bottom:2px solid var(--ora);margin:0 4px;vertical-align:baseline;height:1.2em}
.fill-chip{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;background:#FFF0E6;border:1.5px solid var(--ora);color:var(--ora);font-size:11px;font-weight:700;margin:4px 4px 0 0}

/* RC */
.rc-q-box{background:var(--pur-l);border-radius:9px;padding:11px 13px;margin-bottom:8px}
.rc-opt-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}
.rc-opt{flex:1;min-width:140px;display:flex;align-items:center;gap:5px;padding:6px 9px;border:1.5px solid var(--line);border-radius:7px;background:#fff;cursor:pointer;transition:all .15s}
.rc-opt.rc-correct{border-color:#10B981;background:#F0FDF4}
.rc-opt.rc-correct .rc-ltr{background:#10B981;color:#fff}
.rc-ltr{width:20px;height:20px;border-radius:5px;background:var(--pur-l);color:var(--pur-d);font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s}
.rc-inp{border:none;background:transparent;font-family:'Nunito',sans-serif;font-size:12px;flex:1;outline:none;color:var(--ink)}

/* Writing lines */
.w-lines{display:flex;flex-direction:column;gap:18px;padding:6px 4px}
.w-line{border-bottom:1.5px solid #C4B9E8;height:26px}
.model-box{background:var(--pur-l);border-left:3px solid var(--pur);border-radius:0 8px 8px 0;padding:9px 12px;font-size:12px;color:var(--pur-d);font-weight:600;margin-bottom:8px}

/* Dict item */
.dict-row{display:flex;align-items:center;gap:8px;padding:9px 11px;border:1.5px solid var(--line);border-radius:9px;background:#FAFAFE;margin-bottom:7px}
.dict-inp{flex:1;border:none;background:transparent;font-family:'Nunito',sans-serif;font-size:13px;color:var(--ink);outline:none}
.dict-audio{width:180px;padding:5px 9px;border:1.5px solid var(--line);border-radius:7px;font-size:11px;font-family:'Nunito',sans-serif}

/* Question table */
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.09em;padding:9px 18px;text-align:left;border-bottom:1.5px solid var(--line);background:#FAFAFE}
td{padding:11px 18px;border-bottom:1.5px solid var(--line);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#FAFAFE}
.q-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}

/* Bottom actions */
.sticky-bottom{position:sticky;bottom:0;background:#fff;border-top:1.5px solid var(--line);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;z-index:40}
.skill-pills{display:flex;gap:5px;flex-wrap:wrap}
.sk-pill{padding:4px 11px;border-radius:20px;border:1.5px solid var(--line);font-size:11px;font-weight:700;color:#6B7280;cursor:pointer;transition:all .15s;background:#fff}
.sk-pill.sk-active{background:var(--pur);border-color:var(--pur);color:#fff}

/* Modalities checkboxes */
.mod-row{display:flex;gap:20px;flex-wrap:wrap}
.mod-label{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:#374151;cursor:pointer}
.mod-label input[type=checkbox]{width:16px;height:16px;accent-color:var(--pur);cursor:pointer}

/* Qtype fields */
.qtype-fields{display:none}
.qtype-fields.active{display:block}

/* Done banner */
.done-banner{background:linear-gradient(135deg,#EEEDFE,#FFF0E6);border:1.5px solid var(--line);border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:14px}
.done-icon{font-size:32px;color:var(--ora)}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <div class="tb-logo">ONES</div>
  <div class="tb-sep"></div>
  <div class="tb-title"><?= $exam ? 'Editando quiz' : 'Crear nuevo quiz' ?></div>
  <?php if ($unitName): ?>
  <div class="tb-unit"><i class="ti ti-book-2" style="font-size:12px;vertical-align:-1px" aria-hidden="true"></i> <?= h($unitName) ?></div>
  <?php endif; ?>
  <div class="tb-right">
    <?php if ($examId > 0): ?>
    <a class="btn btn-sm btn-pur" href="eval_viewer.php?t=PREVIEW&exam_id=<?= $examId ?>" target="_blank"><i class="ti ti-player-play" aria-hidden="true"></i>Ver online</a>
    <a class="btn btn-sm" href="quiz_print.php?exam_id=<?= $examId ?>&mode=student" target="_blank"><i class="ti ti-printer" aria-hidden="true"></i>Imprimir</a>
    <?php endif; ?>
    <a class="btn btn-sm" href="<?= h($backUrl) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i>Volver</a>
  </div>
</div>

<div class="page">

  <?php if ($msg): ?>
  <div class="msg <?= str_starts_with($msg,'✅') ? '' : (str_contains($msg,'Error') ? 'err' : '') ?>"><?= h($msg) ?></div>
  <?php endif; ?>

  <!-- ── Paso 1: Configuración ── -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= $exam ? 'Configuración del examen' : 'Paso 1 — Configurar el quiz' ?></div>
        <div class="card-sub">Nombre, nivel, unidad y modalidades</div>
      </div>
      <?php if ($examId > 0): ?>
      <span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:800;padding:3px 10px;border-radius:20px;">
        <i class="ti ti-check" aria-hidden="true"></i> Guardado
      </span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="save_exam">
        <input type="hidden" name="exam_id" value="<?= $examId ?>">
        <input type="hidden" name="unit_id" value="<?= $unitId ?>">
        <div class="row2">
          <div class="fg">
            <label>Nombre del quiz *</label>
            <input type="text" name="title" required value="<?= h($exam['title'] ?? '') ?>"
                   placeholder="Ej: Advance 3 Unit 6 — Final Quiz">
          </div>
          <div class="fg">
            <label>Nivel MCER</label>
            <select name="cefr_level">
              <option value="">Sin nivel</option>
              <?php foreach (['A1','A2','B1','B2','C1','C2'] as $lvl): ?>
              <option value="<?= $lvl ?>" <?= ($exam['cefr_level'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php if (!$unitId): ?>
        <div class="fg">
          <label>Unidad asociada (opcional)</label>
          <select name="unit_id">
            <option value="">— Sin unidad —</option>
            <?php
            // Load units grouped
            try {
                $uRows = $pdo->query(
                    "SELECT u.id, u.name AS unit_name,
                            COALESCE(l.name,'English') AS level_name,
                            COALESCE(p.name,'Sin fase') AS phase_name
                     FROM units u
                     LEFT JOIN english_phases p ON p.id=u.phase_id
                     LEFT JOIN english_levels l ON l.id=p.level_id
                     ORDER BY l.id,p.id,u.position NULLS LAST,u.name"
                )->fetchAll(PDO::FETCH_ASSOC);
                $uTree = [];
                foreach ($uRows as $ur) {
                    $g = $ur['level_name'].' · '.$ur['phase_name'];
                    $uTree[$g][] = $ur;
                }
                foreach ($uTree as $grp => $units): ?>
                <optgroup label="<?= h($grp) ?>">
                  <?php foreach ($units as $u): ?>
                  <option value="<?= h($u['id']) ?>" <?= ((string)($exam['unit_id']??'')) === (string)$u['id'] ? 'selected' : '' ?>><?= h($u['unit_name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
            <?php endforeach;
            } catch (Throwable $e) {} ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="row3">
          <div class="fg">
            <label>Tiempo (minutos)</label>
            <input type="number" name="time_limit_min" value="<?= (int)($exam['time_limit_min']??50) ?>" min="1">
          </div>
          <div class="fg">
            <label>Intentos máximos</label>
            <input type="number" name="max_attempts" value="<?= (int)($exam['max_attempts']??1) ?>" min="1">
          </div>
          <div class="fg">
            <label>Status</label>
            <select name="status">
              <?php foreach (['draft'=>'Draft','active'=>'Activo','closed'=>'Cerrado'] as $sv=>$sl): ?>
              <option value="<?= $sv ?>" <?= ($exam['status']??'draft')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="fg">
          <label>Modalidades</label>
          <div class="mod-row">
            <?php foreach (['online'=>'Online (sin usuario)','printed'=>'Impreso','registered'=>'Con usuario registrado'] as $mv=>$ml): ?>
            <label class="mod-label">
              <input type="checkbox" name="modalities[]" value="<?= $mv ?>" <?= in_array($mv,$mods,true)?'checked':'' ?>>
              <?= $ml ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div style="margin-top:6px">
          <button type="submit" class="btn btn-grn"><i class="ti ti-device-floppy" aria-hidden="true"></i>
            <?= $examId ? 'Actualizar configuración' : 'Guardar y continuar' ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Paso 2: Preguntas ── -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Paso 2 — Preguntas del quiz</div>
        <div class="card-sub"><?= count($examQuestions) ?> pregunta<?= count($examQuestions)!==1?'s':'' ?> · <?= number_format($totalPts,1) ?> pts total</div>
      </div>
      <?php if ($examId > 0): ?>
      <button class="btn btn-ora" onclick="openQModal(0)"><i class="ti ti-plus" aria-hidden="true"></i>Agregar pregunta</button>
      <?php else: ?>
      <span style="font-size:12px;color:var(--muted);font-weight:600;">Guarda la configuración primero</span>
      <?php endif; ?>
    </div>

    <?php if ($examId > 0): ?>
    <!-- Question editor modal trigger area -->
    <div id="q-inline-editor" style="display:none;border-bottom:1.5px solid var(--line);">
      <!-- populated by JS -->
    </div>
    <?php endif; ?>

    <table>
      <thead>
        <tr><th>#</th><th>Tipo</th><th>Skill</th><th>Pregunta</th><th>Pts</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($examQuestions)): ?>
        <tr><td colspan="6" style="text-align:center;padding:28px 20px;color:var(--muted);font-weight:600;font-size:13px;">
          <?= $examId > 0 ? 'Sin preguntas todavía — haz clic en "+ Agregar pregunta"' : 'Guarda la configuración del examen para comenzar a agregar preguntas' ?>
        </td></tr>
        <?php else: ?>
        <?php $qtBadges=['multiple_choice'=>['MC','#1D4ED8','#EFF6FF'],'fill_blank'=>['Fill','#C2410C','#FFF0E6'],'reading_comprehension'=>['RC','#065F46','#ECFDF5'],'writing_practice'=>['Writing','#534AB7','#F5F3FF'],'dictation'=>['Dictation','#854D0E','#FEFCE8']]; ?>
        <?php foreach ($examQuestions as $i => $q): ?>
        <?php $qb = $qtBadges[$q['type']] ?? [h($q['type']),'#534AB7','#EEEDFE']; ?>
        <tr>
          <td style="font-weight:700;color:var(--muted)"><?= $i+1 ?></td>
          <td><span class="q-badge" style="background:<?= $qb[2] ?>;color:<?= $qb[1] ?>"><?= $qb[0] ?></span></td>
          <td style="color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase"><?= h($q['skill']) ?></td>
          <td style="font-weight:600;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h(mb_substr($q['question_text']??'',0,80,'UTF-8')) ?><?= mb_strlen($q['question_text']??'','UTF-8')>80?'…':'' ?></td>
          <td style="font-weight:700;color:var(--ora)"><?= number_format((float)$q['points'],1) ?></td>
          <td>
            <div style="display:flex;gap:5px">
              <button class="btn btn-sm" onclick='openQModal(<?= $q["id"] ?>,<?= htmlspecialchars(json_encode($q),ENT_QUOTES) ?>)'>
                <i class="ti ti-edit" aria-hidden="true"></i>Editar
              </button>
              <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta pregunta?')">
                <input type="hidden" name="action" value="delete_question">
                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                <button type="submit" class="btn btn-sm btn-red"><i class="ti ti-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ($examId > 0 && count($examQuestions) > 0): ?>
    <!-- Done banner -->
    <div style="padding:19px">
      <div class="done-banner">
        <i class="ti ti-circle-check" style="font-size:32px;color:#10B981" aria-hidden="true"></i>
        <div style="flex:1">
          <div style="font-family:'Fredoka One',sans-serif;font-size:16px;color:var(--ink);margin-bottom:4px">Quiz listo para enviar</div>
          <div style="font-size:12px;color:var(--muted);font-weight:600"><?= count($examQuestions) ?> preguntas · <?= number_format($totalPts,1) ?> pts · Ahora puedes compartirlo con tus estudiantes</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn btn-pur" href="admin_eval.php?tab=links&exam_id=<?= $examId ?>"><i class="ti ti-send" aria-hidden="true"></i>Generar links</a>
          <a class="btn" href="quiz_print.php?exam_id=<?= $examId ?>&mode=student" target="_blank"><i class="ti ti-printer" aria-hidden="true"></i>Imprimir</a>
          <a class="btn" href="admin_eval.php?tab=list"><i class="ti ti-clipboard-list" aria-hidden="true"></i>Todos los exámenes</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /page -->

<?php if ($examId > 0): ?>
<!-- ── Question modal (full-screen overlay) ── -->
<div id="q-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;overflow-y:auto;padding:20px;">
  <div style="background:#fff;border-radius:16px;max-width:660px;margin:auto;padding:26px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <span style="font-family:'Fredoka One',sans-serif;font-size:18px;color:var(--ink)" id="q-modal-title">Agregar pregunta</span>
      <button onclick="closeQModal()" style="background:none;border:none;font-size:24px;color:var(--muted);cursor:pointer;line-height:1">×</button>
    </div>
    <form method="POST" id="q-form">
      <input type="hidden" name="action" value="save_question">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <input type="hidden" name="unit_id" value="<?= $unitId ?>">
      <input type="hidden" name="question_id" id="q-id" value="0">
      <input type="hidden" name="type" id="q-type-h" value="multiple_choice">

      <!-- Type picker -->
      <div class="type-grid" style="margin-bottom:16px">
        <?php $qt=['multiple_choice'=>['ti-list-check','MC','#1D4ED8','#EFF6FF'],'fill_blank'=>['ti-text-size','Fill','#C2410C','#FFF0E6'],'reading_comprehension'=>['ti-book-2','RC','#065F46','#ECFDF5'],'writing_practice'=>['ti-writing','Writing','#534AB7','#F5F3FF'],'dictation'=>['ti-microphone','Dictation','#854D0E','#FEFCE8']];
        foreach ($qt as $qtV=>[$qtI,$qtL,$qtC,$qtB]): ?>
        <div class="qtype-card" data-type="<?= $qtV ?>" data-color="<?= $qtC ?>" data-bg="<?= $qtB ?>" onclick="selType('<?= $qtV ?>')">
          <i class="ti <?= $qtI ?>" aria-hidden="true"></i>
          <div class="ql"><?= $qtL ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Skill + Points -->
      <div class="row2" style="margin-bottom:14px">
        <div class="fg" style="margin-bottom:0">
          <label>Skill</label>
          <select name="skill" id="q-skill">
            <?php foreach ($skillLabels as $sv=>$sl): ?>
            <option value="<?= $sv ?>"><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg" style="margin-bottom:0">
          <label>Puntos</label>
          <input type="number" name="points" id="q-pts" value="1" step="0.5" min="0.5">
        </div>
      </div>

      <!-- ── Multiple Choice ── -->
      <div id="qf-multiple_choice" class="qtype-fields active">
        <div class="fg">
          <label>Tipo de pregunta</label>
          <select name="question_type"><option value="text">Texto</option><option value="listen">Audio (Listen)</option></select>
        </div>
        <div class="fg"><label>Pregunta</label><textarea name="question_text" id="qt-mc" required placeholder="¿Cuál es la respuesta correcta?"></textarea></div>
        <div class="row2">
          <div class="fg"><label>URL audio</label><input type="url" name="audio_url" id="qa-mc" placeholder="https://...mp3"></div>
          <div class="fg"><label>URL imagen</label><input type="url" name="image_url" id="qi-mc" placeholder="https://...jpg"></div>
        </div>
        <div class="fg">
          <label>Opciones — marca ✓ la correcta</label>
          <div id="mc-container"></div>
          <button type="button" onclick="addMC()" class="btn btn-ghost btn-sm" style="margin-top:6px"><i class="ti ti-plus" aria-hidden="true"></i>Opción</button>
        </div>
      </div>

      <!-- ── Fill in Blank ── -->
      <div id="qf-fill_blank" class="qtype-fields">
        <div class="fg"><label>Oración (usa ___ para cada espacio)</label><textarea name="question_text" id="qt-fill" oninput="prevFill()" placeholder="Ej: You need a ___ to board the plane."></textarea></div>
        <div class="fg"><label>Vista previa</label><div id="fill-prev" class="fill-prev"><span style="color:#C4B9E8;font-style:italic">Escribe arriba para ver la vista previa…</span></div></div>
        <div class="fg">
          <label>Respuestas correctas (separadas por |)</label>
          <input type="text" name="answer_text[]" id="fill-ans" oninput="prevFill()" placeholder="boarding pass | runway">
          <input type="hidden" name="answer_correct[]" value="1">
        </div>
        <div class="fg"><label>URL audio</label><input type="url" name="audio_url" placeholder="https://...mp3"></div>
      </div>

      <!-- ── Reading Comprehension ── -->
      <div id="qf-reading_comprehension" class="qtype-fields">
        <div class="fg"><label>Texto de lectura</label><textarea name="question_text" id="qt-rc" style="min-height:90px" placeholder="Pega el texto de lectura aquí..."></textarea></div>
        <div class="fg"><label>Preguntas de comprensión</label><div id="rc-container"></div>
          <button type="button" onclick="addRC()" class="btn btn-ghost btn-sm" style="margin-top:6px"><i class="ti ti-plus" aria-hidden="true"></i>Pregunta</button>
        </div>
      </div>

      <!-- ── Writing Practice ── -->
      <div id="qf-writing_practice" class="qtype-fields">
        <div class="fg"><label>Instrucción</label><textarea name="question_text" id="qt-wr" placeholder="Ej: Describe a time you used public transportation."></textarea></div>
        <div class="fg">
          <label>Respuesta modelo (para Answer Key)</label>
          <div class="model-box">Esta respuesta aparecerá solo en la Clave de Respuestas</div>
          <input type="text" name="answer_text[]" id="wr-model" placeholder="Ej: I usually take the bus...">
          <input type="hidden" name="answer_correct[]" value="1">
        </div>
        <div class="row2">
          <div class="fg"><label>Número de líneas</label><input type="number" name="data[lines]" value="3" min="1" max="10"></div>
          <div class="fg"><label>Imagen (opcional)</label><input type="url" name="image_url" placeholder="https://...jpg"></div>
        </div>
      </div>

      <!-- ── Dictation ── -->
      <div id="qf-dictation" class="qtype-fields">
        <div class="fg"><label>Frases de dictado</label><div id="dict-container"></div>
          <button type="button" onclick="addDict()" class="btn btn-ghost btn-sm" style="margin-top:6px"><i class="ti ti-plus" aria-hidden="true"></i>Frase</button>
        </div>
        <div class="fg"><label>Instrucción general</label>
          <textarea name="question_text" id="qt-dict" style="min-height:44px">Listen and write what you hear.</textarea>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1.5px solid var(--line)">
        <button type="submit" class="btn btn-grn"><i class="ti ti-device-floppy" aria-hidden="true"></i>Guardar pregunta</button>
        <button type="button" onclick="closeQModal()" class="btn">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
var LETTERS=['A','B','C','D','E','F'];
var _rcN=0,_dictN=0;

/* ── Type selector ── */
function selType(type){
  document.getElementById('q-type-h').value=type;
  document.querySelectorAll('.qtype-card').forEach(function(c){
    var active=c.dataset.type===type;
    c.style.borderColor=active?c.dataset.color:'#EDE9FA';
    c.style.background=active?c.dataset.bg:'#fff';
    c.querySelector('i').style.color=active?c.dataset.color:'#9B8FCC';
    c.querySelector('.ql').style.color=active?c.dataset.color:'#6B7280';
  });
  document.querySelectorAll('.qtype-fields').forEach(function(f){f.style.display='none';f.classList.remove('active');});
  var p=document.getElementById('qf-'+type);
  if(p){p.style.display='block';p.classList.add('active');}
}

/* ── Modal open/close ── */
function openQModal(id,data){
  document.getElementById('q-id').value=id||0;
  document.getElementById('q-modal-title').textContent=id?'Editar pregunta':'Agregar pregunta';
  document.getElementById('q-pts').value=data?(data.points||1):1;
  // Skill
  if(data&&data.skill){var sk=document.getElementById('q-skill');for(var i=0;i<sk.options.length;i++)if(sk.options[i].value===data.skill){sk.selectedIndex=i;break;}}
  var type=(data&&data.type)?data.type:'multiple_choice';
  if(type==='fill'||type==='fill_in_blank')type='fill_blank';
  if(type==='writing')type='writing_practice';
  selType(type);
  // populate
  var texts=(data&&data.answer_texts)?([].concat(data.answer_texts)):[];
  var corrs=(data&&data.answer_corrects)?([].concat(data.answer_corrects)):[];
  if(type==='multiple_choice'){
    var t=document.getElementById('qt-mc'),au=document.getElementById('qa-mc'),im=document.getElementById('qi-mc');
    if(t)t.value=data?(data.question_text||''):'';
    if(au)au.value=data?(data.audio_url||''):'';
    if(im)im.value=data?(data.image_url||''):'';
    document.getElementById('mc-container').innerHTML='';
    if(texts.length){texts.forEach(function(t,i){addMC(t,corrs[i]==='t'||corrs[i]===true||corrs[i]===1);});}
    else{addMC();addMC();addMC();addMC();}
  } else if(type==='fill_blank'){
    var ft=document.getElementById('qt-fill'),fa=document.getElementById('fill-ans');
    if(ft)ft.value=data?(data.question_text||''):'';
    if(fa)fa.value=texts.length?texts.join(' | '):'';
    prevFill();
  } else if(type==='reading_comprehension'){
    var rt=document.getElementById('qt-rc');
    if(rt)rt.value=data?(data.question_text||''):'';
    document.getElementById('rc-container').innerHTML=''; _rcN=0;
    if(texts.length){texts.forEach(function(t,i){addRC(t,corrs[i]);});}else{addRC();}
  } else if(type==='writing_practice'){
    var wt=document.getElementById('qt-wr'),wm=document.getElementById('wr-model');
    if(wt)wt.value=data?(data.question_text||''):'';
    if(wm)wm.value=texts.length?texts[0]:'';
  } else if(type==='dictation'){
    var dt=document.getElementById('qt-dict');
    if(dt)dt.value=data?(data.question_text||'Listen and write what you hear.'):'Listen and write what you hear.';
    document.getElementById('dict-container').innerHTML=''; _dictN=0;
    if(texts.length){texts.forEach(function(t){addDict(t);});}else{addDict();addDict();}
  }
  document.getElementById('q-modal-bg').style.display='block';
  document.body.style.overflow='hidden';
}
function closeQModal(){
  document.getElementById('q-modal-bg').style.display='none';
  document.body.style.overflow='';
}
document.getElementById('q-modal-bg')&&document.getElementById('q-modal-bg').addEventListener('click',function(e){if(e.target===this)closeQModal();});

/* ── Multiple Choice ── */
function addMC(text,correct){
  var c=document.getElementById('mc-container');
  var idx=c.children.length;
  var letter=LETTERS[idx]||String.fromCharCode(65+idx);
  var d=document.createElement('div');
  d.className='mc-row'+(correct?' correct-row':'');
  d.innerHTML='<div class="mc-letter">'+letter+'</div>'+
    '<input class="mc-text" type="text" name="answer_text[]" value="'+(text?esc(text):'')+'" placeholder="Opción '+letter+'">'+
    '<input type="hidden" name="answer_correct[]" value="'+(correct?'1':'0')+'" class="mc-flag">'+
    '<button type="button" class="mc-mark" onclick="toggleMC(this)">'+(correct?'✓ Correcta':'Marcar')+'</button>'+
    '<button type="button" class="mc-del" onclick="this.parentElement.remove();reindexMC()">×</button>';
  c.appendChild(d);
}
function toggleMC(btn){
  var row=btn.parentElement;
  var flag=row.querySelector('.mc-flag');
  var nowCorrect=flag.value!=='1';
  document.querySelectorAll('#mc-container .mc-row').forEach(function(r){
    r.classList.remove('correct-row');
    r.querySelector('.mc-flag').value='0';
    r.querySelector('.mc-mark').textContent='Marcar';
    r.querySelector('.mc-mark').style.cssText='';
    r.querySelector('.mc-letter').style.cssText='';
  });
  if(nowCorrect){
    row.classList.add('correct-row');
    flag.value='1';
    btn.textContent='✓ Correcta';
    btn.style.cssText='background:#10B981;border-color:#10B981;color:#fff';
    row.querySelector('.mc-letter').style.cssText='background:#10B981;color:#fff';
  }
}
function reindexMC(){
  document.querySelectorAll('#mc-container .mc-row').forEach(function(r,i){
    r.querySelector('.mc-letter').textContent=LETTERS[i]||String.fromCharCode(65+i);
  });
}

/* ── Fill Preview ── */
function prevFill(){
  var text=(document.getElementById('qt-fill')||{value:''}).value;
  var prev=document.getElementById('fill-prev');
  if(!prev)return;
  if(!text){prev.innerHTML='<span style="color:#C4B9E8;font-style:italic">Escribe arriba para ver la vista previa…</span>';return;}
  prev.innerHTML=text.replace(/___/g,'<span class="fill-blank"></span>');
}

/* ── Reading Comprehension ── */
function addRC(text,correct){
  _rcN++;var c=document.getElementById('rc-container');var n=_rcN;
  var d=document.createElement('div');
  d.className='rc-q-box';
  d.innerHTML='<div style="display:flex;align-items:center;gap:6px;margin-bottom:7px">'+
    '<span style="font-size:11px;font-weight:800;color:var(--pur-d)">Pregunta '+n+'</span>'+
    '<button type="button" onclick="this.closest(\'.rc-q-box\').remove()" style="margin-left:auto;background:none;border:none;color:#DC2626;cursor:pointer;font-size:16px;line-height:1">×</button></div>'+
    '<input type="text" name="rc_question_text[]" value="'+(text?esc(text):'')+'" placeholder="Escribe la pregunta…" style="width:100%;margin-bottom:8px;padding:7px 10px;border:1.5px solid var(--line);border-radius:8px;font-family:Nunito,sans-serif;font-size:13px;outline:none">'+
    '<div class="rc-opt-row">'+
    ['A','B','C','D'].map(function(l,i){
      return '<div class="rc-opt" onclick="toggleRC(this)">'+
        '<div class="rc-ltr">'+l+'</div>'+
        '<input type="text" class="rc-inp" name="rc_opt_'+n+'[]" placeholder="Opción '+l+'">'+
        '<input type="hidden" name="rc_cor_'+n+'[]" value="0" class="rc-flag">'+
        '</div>';
    }).join('')+'</div>';
  c.appendChild(d);
}
function toggleRC(div){
  var opts=div.parentElement.querySelectorAll('.rc-opt');
  opts.forEach(function(o){o.classList.remove('rc-correct');o.querySelector('.rc-flag').value='0';o.querySelector('.rc-ltr').style.cssText='';});
  div.classList.add('rc-correct');
  div.querySelector('.rc-flag').value='1';
  div.querySelector('.rc-ltr').style.cssText='background:#10B981;color:#fff';
}

/* ── Dictation ── */
function addDict(text){
  _dictN++;var c=document.getElementById('dict-container');
  var d=document.createElement('div');
  d.className='dict-row';
  d.innerHTML='<input class="dict-inp" type="text" name="answer_text[]" value="'+(text?esc(text):'')+'" placeholder="Frase de dictado…">'+
    '<input type="hidden" name="answer_correct[]" value="1">'+
    '<input class="dict-audio" type="url" name="dict_audio[]" placeholder="URL audio">'+
    '<button type="button" onclick="this.parentElement.remove()" class="mc-del">×</button>';
  c.appendChild(d);
}

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// Init with MC type
selType('multiple_choice');
</script>
</body>
</html>

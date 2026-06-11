<?php
/**
 * eval_viewer.php — Vista del estudiante para presentar examen.
 * Acceso por token SIN usuario ni contraseña.
 * URL: eval_viewer.php?t={token}
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
require_once __DIR__ . '/exam_question_selector.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$token     = trim($_GET['t'] ?? '');
$step      = $_GET['step'] ?? 'welcome';   // welcome | exam | result
$resultId  = (int) ($_GET['rid'] ?? 0);

// ─── Validar token ────────────────────────────────────────────────────────────
$link = null;
if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT l.*, e.title AS exam_title, e.time_limit_min, e.max_attempts,
                e.instructions, e.cefr_level AS exam_cefr, e.status AS exam_status,
                e.modalities
         FROM eval_links l
         JOIN eval_exams e ON e.id = l.exam_id
         WHERE l.token = ?
           AND (l.expires_at IS NULL OR l.expires_at > NOW())
           AND l.uses_count < l.max_uses
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$link) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Link inválido</title>
    <style>body{font-family:Arial,sans-serif;text-align:center;padding:60px;background:#fef3cd;color:#664d03;}
    h1{font-size:28px;}p{font-size:16px;}</style></head><body>
    <h1>⚠️ Link inválido o expirado</h1>
    <p>Este link de evaluación no es válido, ya expiró o alcanzó el límite de usos.</p>
    <p>Contacta a tu institución para obtener un nuevo link.</p>
    </body></html><?php
    exit;
}

$examId     = (int) $link['exam_id'];
$isIndividual = ($link['link_type'] === 'individual');

// ─── POST: Iniciar examen ─────────────────────────────────────────────────────
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_exam'])) {
    $sName  = trim($_POST['student_name'] ?? $link['student_name'] ?? '');
    $sDoc   = trim($_POST['student_doc']  ?? $link['student_doc']  ?? '');
    $sPhone = trim($_POST['student_phone'] ?? $link['student_phone'] ?? '');
    $sEmail = trim($_POST['student_email'] ?? $link['student_email'] ?? '');

    if ($sName === '') {
        $errorMsg = 'Por favor ingresa tu nombre.';
    } else {
        // Contar intentos previos de este estudiante
        $histStmt = $pdo->prepare(
            "SELECT skill_scores, answers_json FROM eval_results
             WHERE exam_id=? AND student_doc=? AND status='submitted' ORDER BY submitted_at DESC"
        );
        $histStmt->execute([$examId, $sDoc]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        // Verificar límite de intentos
        $maxAttempts = (int) ($link['max_attempts'] ?? 1);
        if (count($history) >= $maxAttempts) {
            $errorMsg = 'Ya alcanzaste el número máximo de intentos para este examen.';
        } else {
            $attempt     = count($history) + 1;
            $examConfig  = [
                'exam_id'         => $examId,
                'total_questions' => 20,
                'quotas'          => DEFAULT_QUOTAS,
                'skills'          => array_keys(DEFAULT_QUOTAS),
            ];

            $questions   = select_exam_questions($pdo, $examConfig, $sDoc ?: $sName, $attempt, $history);
            $selJson     = serialize_exam_selection($questions);

            $insStmt = $pdo->prepare(
                "INSERT INTO eval_results
                    (exam_id, link_id, student_name, student_doc, student_phone, student_email,
                     modality, selection_json, status, started_at)
                 VALUES (?,?,?,?,?,?,'online',?,'started',CURRENT_TIMESTAMP) RETURNING id"
            );
            $insStmt->execute([
                $examId, $link['id'], $sName, $sDoc, $sPhone ?: null, $sEmail ?: null, $selJson,
            ]);
            $row = $insStmt->fetch(PDO::FETCH_ASSOC);
            $newResultId = (int) $row['id'];

            // Incrementar uses_count
            $pdo->prepare("UPDATE eval_links SET uses_count=uses_count+1 WHERE id=?")
                ->execute([$link['id']]);

            // Guardar preguntas en sesión temporal (via URL)
            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=exam&rid=' . $newResultId);
            exit;
        }
    }
}

// ─── POST: Enviar respuestas ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam']) && $resultId > 0) {
    $resStmt = $pdo->prepare("SELECT * FROM eval_results WHERE id=? AND status='started' LIMIT 1");
    $resStmt->execute([$resultId]);
    $result = $resStmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['exam_id'] == $examId) {
        $questions = load_exam_questions_from_selection($pdo, $result['selection_json'] ?? '');
        $answers   = (array) ($_POST['answers'] ?? []);

        $totalScore  = 0.0;
        $maxScore    = 0.0;
        $skillScores = [];
        $answersLog  = [];

        foreach ($questions as $i => $q) {
            $given   = trim((string) ($answers[$i] ?? ''));
            $correct = trim((string) ($q['correct'] ?? ''));
            $pts     = (float) ($q['points'] ?? 1);
            $skill   = $q['skill'] ?? 'grammar';

            $isCorrect = ($given !== '' && $correct !== '' &&
                mb_strtolower($given) === mb_strtolower($correct));

            $earned = $isCorrect ? $pts : 0.0;

            $skillScores[$skill] = $skillScores[$skill] ?? ['score' => 0, 'total' => 0];
            $skillScores[$skill]['score'] += $earned;
            $skillScores[$skill]['total'] += $pts;

            $totalScore += $earned;
            $maxScore   += $pts;

            $answersLog[] = [
                'q' => $i, 'type' => $q['type'], 'skill' => $skill,
                'given' => $given, 'correct' => $correct,
                'is_correct' => $isCorrect, 'pts_earned' => $earned, 'pts_max' => $pts,
            ];
        }

        $pct = $maxScore > 0 ? round($totalScore / $maxScore * 100, 2) : 0;

        // MCER sugerido
        $cefrStmt = $pdo->prepare(
            "SELECT cefr_level FROM eval_cefr_ranges
             WHERE exam_id=? AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1"
        );
        $cefrStmt->execute([$examId, $pct]);
        $cefrRow = $cefrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cefrRow) {
            $cefrStmt2 = $pdo->prepare(
                "SELECT cefr_level FROM eval_cefr_ranges
                 WHERE is_global=TRUE AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1"
            );
            $cefrStmt2->execute([$pct]);
            $cefrRow = $cefrStmt2->fetch(PDO::FETCH_ASSOC);
        }
        $cefr = $cefrRow ? $cefrRow['cefr_level'] : 'A1';

        $pdo->prepare(
            "UPDATE eval_results SET score=?, max_score=?, pct=?, cefr_suggested=?,
             answers_json=?, skill_scores=?, status='submitted', submitted_at=CURRENT_TIMESTAMP
             WHERE id=?"
        )->execute([
            $totalScore, $maxScore, $pct, $cefr,
            json_encode($answersLog), json_encode($skillScores), $resultId,
        ]);

        header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=result&rid=' . $resultId);
        exit;
    }
}

// ─── Cargar datos para exam / result ─────────────────────────────────────────
$questions  = [];
$result     = null;
$skillScores = [];

if ($step === 'exam' && $resultId > 0) {
    $resStmt = $pdo->prepare("SELECT * FROM eval_results WHERE id=? LIMIT 1");
    $resStmt->execute([$resultId]);
    $result = $resStmt->fetch(PDO::FETCH_ASSOC);
    if ($result && $result['exam_id'] == $examId) {
        $questions = load_exam_questions_from_selection($pdo, $result['selection_json'] ?? '');
    }
}

if ($step === 'result' && $resultId > 0) {
    $resStmt = $pdo->prepare("SELECT * FROM eval_results WHERE id=? LIMIT 1");
    $resStmt->execute([$resultId]);
    $result = $resStmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $skillScores = is_string($result['skill_scores'])
            ? json_decode($result['skill_scores'], true) : ($result['skill_scores'] ?? []);
    }
}

$cefrColors = [
    'A1' => '#6c757d', 'A2' => '#17a2b8', 'B1' => '#28a745',
    'B2' => '#007bff', 'C1' => '#6f42c1', 'C2' => '#dc3545',
];
$cefrLabels = [
    'A1' => 'Principiante', 'A2' => 'Básico', 'B1' => 'Intermedio',
    'B2' => 'Intermedio Alto', 'C1' => 'Avanzado', 'C2' => 'Maestría',
];
$skillLabels = [
    'grammar' => 'Grammar', 'vocabulary' => 'Vocabulary',
    'listening' => 'Listening', 'reading' => 'Reading',
    'writing' => 'Writing', 'speaking' => 'Speaking',
];
$timeLimitMin = (int) ($link['time_limit_min'] ?? 50);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($link['exam_title']) ?> — ONES Evaluación</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&display=swap');
:root{
  --orange:#F97316;--purple:#7F77DD;--green:#2f9e44;
  --bg:#f8f7ff;--card:#fff;--line:#e2e0f0;--text:#2d2b55;--muted:#7c7aa0;
  --radius:20px;--shadow:0 8px 32px rgba(127,119,221,.13);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Nunito',Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* Header */
.header{background:linear-gradient(135deg,var(--purple),#5a52c5);color:#fff;
  padding:18px 24px;display:flex;align-items:center;gap:16px;
  box-shadow:0 4px 20px rgba(127,119,221,.3);}
.header-logo{width:44px;height:44px;border-radius:12px;background:#fff;
  display:flex;align-items:center;justify-content:center;font-size:22px;}
.header-title{flex:1;}
.header-title h1{font-family:'Fredoka One',Arial,sans-serif;font-size:22px;margin:0;}
.header-title p{font-size:13px;opacity:.85;margin:2px 0 0;}
.header-badge{background:rgba(255,255,255,.2);border-radius:12px;padding:6px 14px;
  font-size:12px;font-weight:700;}

.page{max-width:780px;margin:0 auto;padding:24px 16px 40px;}

/* Cards */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow);padding:28px;margin-bottom:20px;}
.card-title{font-family:'Fredoka One',Arial,sans-serif;font-size:26px;color:var(--purple);margin-bottom:8px;}
.card-sub{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.5;}

/* Formulario bienvenida */
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:13px;font-weight:700;color:var(--muted);margin-bottom:6px;}
.form-group input{width:100%;padding:11px 14px;border:2px solid var(--line);border-radius:12px;
  font-size:15px;font-family:'Nunito',Arial,sans-serif;color:var(--text);transition:border .2s;}
.form-group input:focus{outline:none;border-color:var(--purple);}

/* Botones */
.btn{display:inline-block;font-family:'Nunito',Arial,sans-serif;font-size:15px;font-weight:800;
  padding:12px 28px;border-radius:14px;border:none;cursor:pointer;text-decoration:none;
  transition:filter .2s,transform .15s;}
.btn:hover{filter:brightness(1.06);transform:translateY(-2px);}
.btn-primary{background:linear-gradient(135deg,var(--orange),#e05f00);color:#fff;
  box-shadow:0 4px 16px rgba(249,115,22,.3);}
.btn-purple{background:linear-gradient(135deg,var(--purple),#5a52c5);color:#fff;
  box-shadow:0 4px 16px rgba(127,119,221,.3);}
.btn-green{background:linear-gradient(135deg,#41b95a,#2f9e44);color:#fff;}
.btn-block{display:block;width:100%;text-align:center;}

/* Progress bar */
.progress-wrap{background:#e2e0f0;border-radius:999px;height:10px;margin-bottom:20px;overflow:hidden;}
.progress-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--orange),var(--purple));
  transition:width .4s ease;}
.progress-label{display:flex;justify-content:space-between;font-size:13px;color:var(--muted);
  margin-bottom:6px;font-weight:700;}

/* Timer */
.timer{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--purple),#5a52c5);
  color:#fff;padding:8px 18px;border-radius:14px;font-size:16px;font-weight:800;
  box-shadow:0 4px 12px rgba(127,119,221,.3);}
.timer.warning{background:linear-gradient(135deg,#e55353,#c82333);}

/* Pregunta */
.q-number{font-size:12px;font-weight:800;color:var(--orange);text-transform:uppercase;
  letter-spacing:.08em;margin-bottom:8px;}
.q-text{font-size:18px;font-weight:700;color:var(--text);margin-bottom:18px;line-height:1.5;}
.q-audio{margin-bottom:14px;}

/* Opciones multiple choice */
.options-list{display:flex;flex-direction:column;gap:10px;}
.option-label{display:flex;align-items:center;gap:12px;padding:12px 16px;
  border:2px solid var(--line);border-radius:14px;cursor:pointer;
  transition:all .2s;font-size:15px;font-weight:600;}
.option-label:hover{border-color:var(--purple);background:#f3f2ff;}
.option-label input[type=radio]{display:none;}
.option-label.selected{border-color:var(--purple);background:#f3f2ff;}
.option-label .opt-letter{width:32px;height:32px;border-radius:10px;background:var(--line);
  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;
  flex-shrink:0;transition:all .2s;}
.option-label.selected .opt-letter{background:var(--purple);color:#fff;}

/* Fill in blank */
.fill-input{width:100%;padding:12px 16px;border:2px solid var(--line);border-radius:14px;
  font-size:16px;font-family:'Nunito',Arial,sans-serif;transition:border .2s;}
.fill-input:focus{outline:none;border-color:var(--purple);}

/* Chips (unscramble/build_sentence) */
.chips-pool{display:flex;flex-wrap:wrap;gap:8px;min-height:44px;border:2px dashed var(--line);
  border-radius:14px;padding:10px;margin-bottom:10px;}
.chips-answer{display:flex;flex-wrap:wrap;gap:8px;min-height:44px;border:2px solid var(--purple);
  border-radius:14px;padding:10px;background:#f3f2ff;}
.chip{background:var(--purple);color:#fff;padding:8px 16px;border-radius:999px;
  font-size:14px;font-weight:700;cursor:grab;user-select:none;transition:opacity .2s;}
.chip:active{cursor:grabbing;}

/* Match selects */
.match-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;font-size:15px;}
.match-row .left{font-weight:700;flex:1;}
.match-row select{flex:1;padding:9px 12px;border:2px solid var(--line);border-radius:12px;
  font-size:14px;font-family:'Nunito',Arial,sans-serif;}

/* Flip card */
.flip-card{width:100%;height:160px;perspective:1000px;cursor:pointer;margin-bottom:14px;}
.flip-inner{position:relative;width:100%;height:100%;transition:transform .6s;transform-style:preserve-3d;}
.flip-card.flipped .flip-inner{transform:rotateY(180deg);}
.flip-front,.flip-back{position:absolute;width:100%;height:100%;backface-visibility:hidden;
  border-radius:16px;display:flex;align-items:center;justify-content:center;
  font-size:20px;font-weight:800;padding:20px;text-align:center;}
.flip-front{background:linear-gradient(135deg,var(--purple),#5a52c5);color:#fff;}
.flip-back{background:linear-gradient(135deg,var(--orange),#e05f00);color:#fff;
  transform:rotateY(180deg);}

/* Resultado */
.result-hero{text-align:center;padding:32px 20px;}
.result-score{font-family:'Fredoka One',Arial,sans-serif;font-size:72px;color:var(--purple);line-height:1;}
.result-pct{font-size:18px;color:var(--muted);margin:4px 0 16px;}
.cefr-chip{display:inline-block;padding:10px 28px;border-radius:999px;
  font-family:'Fredoka One',Arial,sans-serif;font-size:28px;color:#fff;margin-bottom:20px;}
.skill-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:16px;}
.skill-box{background:#f7f6ff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;}
.skill-name{font-size:12px;font-weight:800;color:var(--muted);text-transform:uppercase;
  letter-spacing:.06em;margin-bottom:6px;}
.skill-bar-wrap{background:#e2e0f0;border-radius:999px;height:8px;overflow:hidden;}
.skill-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--purple),var(--orange));}
.skill-pct{font-size:18px;font-weight:800;color:var(--purple);margin-top:4px;}

.error-msg{background:#fff3f3;border:1px solid #f5c6cb;color:#842029;border-radius:12px;
  padding:12px 16px;margin-bottom:14px;font-size:14px;font-weight:700;}

.nav-btns{display:flex;gap:12px;justify-content:space-between;margin-top:20px;}

@media(max-width:600px){
  .skill-grid{grid-template-columns:1fr;}
  .result-score{font-size:56px;}
  .cefr-chip{font-size:22px;}
  .q-text{font-size:16px;}
}
</style>
</head>
<body>

<header class="header">
  <div class="header-logo">📝</div>
  <div class="header-title">
    <h1><?= h($link['exam_title']) ?></h1>
    <p>Evaluación de inglés<?= $link['exam_cefr'] ? ' — Nivel ' . h($link['exam_cefr']) : '' ?></p>
  </div>
  <?php if ($step === 'exam' && $timeLimitMin > 0): ?>
  <div class="timer" id="timer">⏱ <?= $timeLimitMin ?>:00</div>
  <?php endif; ?>
</header>

<div class="page">

<?php // ─── Paso 1: Bienvenida / Datos del estudiante ──────────────────────────
if ($step === 'welcome'): ?>
  <div class="card">
    <div class="card-title">👋 ¡Bienvenido!</div>
    <p class="card-sub">
      <?= $link['instructions'] ? nl2br(h($link['instructions'])) : 'Completa tus datos para iniciar la evaluación.' ?>
    </p>
    <?php if ($errorMsg): ?><div class="error-msg"><?= h($errorMsg) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="start_exam" value="1">
      <?php if ($isIndividual && $link['student_name']): ?>
        <div class="form-group">
          <label>Nombre</label>
          <input type="text" name="student_name" value="<?= h($link['student_name']) ?>" readonly>
        </div>
        <div class="form-group">
          <label>Documento</label>
          <input type="text" name="student_doc" value="<?= h($link['student_doc'] ?? '') ?>" readonly>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label>Nombre completo *</label>
          <input type="text" name="student_name" required placeholder="Ej: Ana García">
        </div>
        <div class="form-group">
          <label>Documento de identidad</label>
          <input type="text" name="student_doc" placeholder="Cédula o ID">
        </div>
        <div class="form-group">
          <label>WhatsApp (opcional)</label>
          <input type="text" name="student_phone" placeholder="+57 300 000 0000">
        </div>
        <div class="form-group">
          <label>Correo electrónico (opcional)</label>
          <input type="email" name="student_email" placeholder="correo@ejemplo.com">
        </div>
      <?php endif; ?>
      <br>
      <div style="background:#f7f6ff;border-radius:14px;padding:16px 18px;margin-bottom:20px;font-size:14px;">
        <strong>📋 Detalles del examen:</strong><br>
        ⏱ Tiempo: <strong><?= $timeLimitMin ?> minutos</strong><br>
        📝 Preguntas: <strong>20 preguntas</strong>
        <?php if ((int)($link['max_attempts'] ?? 1) > 1): ?>
        <br>🔄 Intentos permitidos: <strong><?= (int)$link['max_attempts'] ?></strong>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-primary btn-block">🚀 Iniciar evaluación</button>
    </form>
  </div>

<?php // ─── Paso 2: Examen ───────────────────────────────────────────────────
elseif ($step === 'exam' && $result && !empty($questions)): ?>
  <form method="POST" id="exam-form">
    <input type="hidden" name="submit_exam" value="1">

    <?php foreach ($questions as $i => $q):
      $qNum     = $i + 1;
      $total    = count($questions);
      $pctDone  = round($qNum / $total * 100);
      $qType    = $q['type'] ?? 'multiple_choice';
      $letters  = ['A','B','C','D','E'];
    ?>
    <div class="card q-card" id="q-card-<?= $i ?>" style="<?= $i > 0 ? 'display:none' : '' ?>">
      <!-- Progress -->
      <div class="progress-label">
        <span>Pregunta <?= $qNum ?> de <?= $total ?></span>
        <span><?= h(ucfirst($q['skill'] ?? 'grammar')) ?></span>
      </div>
      <div class="progress-wrap"><div class="progress-bar" style="width:<?= $pctDone ?>%"></div></div>

      <div class="q-number">Pregunta <?= $qNum ?> · <?= h(strtoupper($q['type'] ?? '')) ?></div>
      <div class="q-text"><?= nl2br(h($q['text'] ?? '')) ?></div>

      <?php if ($q['audio']): ?>
      <div class="q-audio">
        <audio controls src="<?= h($q['audio']) ?>">Tu navegador no soporta audio.</audio>
      </div>
      <?php endif; ?>

      <?php if ($q['image']): ?>
      <div style="margin-bottom:14px;text-align:center;">
        <img src="<?= h($q['image']) ?>" alt="Imagen" style="max-width:100%;border-radius:14px;max-height:240px;">
      </div>
      <?php endif; ?>

      <?php
      // ─── Render según tipo ───────────────────────────────────────────
      if (in_array($qType, ['multiple_choice'], true) && !empty($q['options'])):
      ?>
      <div class="options-list" id="opts-<?= $i ?>">
        <?php foreach ($q['options'] as $oi => $opt): ?>
        <label class="option-label" onclick="selectOption(this, <?= $i ?>, <?= json_encode((string)$opt) ?>)">
          <input type="radio" name="answers[<?= $i ?>]" value="<?= h($opt) ?>">
          <span class="opt-letter"><?= $letters[$oi] ?? $oi + 1 ?></span>
          <span><?= h($opt) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <?php elseif (in_array($qType, ['fill_in_blank','dictation','question_answer'], true)): ?>
      <input type="text" name="answers[<?= $i ?>]" class="fill-input"
        placeholder="Escribe tu respuesta aquí..." autocomplete="off">

      <?php elseif (in_array($qType, ['unscramble','build_sentence','order_sentences'], true)): ?>
      <?php
        $words = [];
        if (!empty($q['data']['words'])) $words = $q['data']['words'];
        elseif (!empty($q['correct'])) $words = explode(' ', $q['correct']);
        shuffle($words);
      ?>
      <p style="font-size:13px;color:var(--muted);margin-bottom:8px;">Arrastra las palabras en el orden correcto:</p>
      <div class="chips-answer" id="answer-area-<?= $i ?>" ondrop="dropChip(event,<?= $i ?>)" ondragover="event.preventDefault()"></div>
      <div class="chips-pool" id="pool-<?= $i ?>">
        <?php foreach ($words as $wi => $wd): ?>
        <div class="chip" draggable="true" id="chip-<?= $i ?>-<?= $wi ?>"
          ondragstart="dragChip(event,'chip-<?= $i ?>-<?= $wi ?>')">
          <?= h($wd) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="answers[<?= $i ?>]" id="answer-input-<?= $i ?>">

      <?php elseif (in_array($qType, ['match','matching_lines'], true) && !empty($q['options'])): ?>
      <?php
        $leftItems   = [$q['text'] ?? ''];
        $rightItems  = $q['options'];
        shuffle($rightItems);
      ?>
      <div>
        <?php foreach ($leftItems as $li => $left): ?>
        <div class="match-row">
          <span class="left"><?= h($left) ?></span>
          <select name="answers[<?= $i ?>]">
            <option value="">Seleccionar...</option>
            <?php foreach ($rightItems as $right): ?>
            <option value="<?= h($right) ?>"><?= h($right) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>

      <?php elseif (in_array($qType, ['flashcards','memory_cards'], true)): ?>
      <div class="flip-card" id="flip-<?= $i ?>" onclick="this.classList.toggle('flipped')">
        <div class="flip-inner">
          <div class="flip-front"><?= h($q['text'] ?? '') ?></div>
          <div class="flip-back"><?= h($q['correct'] ?? '') ?></div>
        </div>
      </div>
      <p style="font-size:13px;color:var(--muted);text-align:center;margin-bottom:10px;">Haz clic en la tarjeta para ver la respuesta</p>
      <div class="options-list" id="opts-fc-<?= $i ?>">
        <label class="option-label" onclick="selectFlipAnswer(<?= $i ?>, 'si')">
          <input type="radio" name="answers[<?= $i ?>]" value="<?= h($q['correct'] ?? '') ?>">
          <span class="opt-letter" style="background:#28a745;color:#fff;">✓</span>
          <span>La sabía</span>
        </label>
        <label class="option-label" onclick="selectFlipAnswer(<?= $i ?>, 'no')">
          <input type="radio" name="answers[<?= $i ?>]" value="_skip_">
          <span class="opt-letter" style="background:#dc3545;color:#fff;">✗</span>
          <span>No la sabía</span>
        </label>
      </div>

      <?php else: // Tipo genérico (writing, speaking, etc.) ?>
      <textarea name="answers[<?= $i ?>]" style="width:100%;min-height:100px;padding:12px 16px;
        border:2px solid var(--line);border-radius:14px;font-size:15px;font-family:'Nunito',Arial,sans-serif;
        resize:vertical;" placeholder="Escribe tu respuesta..."></textarea>
      <?php endif; ?>

      <!-- Navegación -->
      <div class="nav-btns">
        <?php if ($i > 0): ?>
        <button type="button" class="btn btn-purple" onclick="goTo(<?= $i - 1 ?>)">← Anterior</button>
        <?php else: ?><span></span><?php endif; ?>

        <?php if ($i < $total - 1): ?>
        <button type="button" class="btn btn-primary" onclick="goTo(<?= $i + 1 ?>)">Siguiente →</button>
        <?php else: ?>
        <button type="button" class="btn btn-green" onclick="confirmSubmit()">✅ Finalizar examen</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </form>

<?php // ─── Paso 3: Resultado ────────────────────────────────────────────────
elseif ($step === 'result' && $result): ?>
  <?php
    $pct   = (float) ($result['pct'] ?? 0);
    $cefr  = $result['cefr_suggested'] ?? 'A1';
    $cc    = $cefrColors[$cefr] ?? '#6c757d';
    $clbl  = $cefrLabels[$cefr] ?? $cefr;
  ?>
  <div class="card">
    <div class="result-hero">
      <div class="result-score"><?= number_format($pct, 0) ?>%</div>
      <div class="result-pct"><?= number_format((float)$result['score'],1) ?> / <?= number_format((float)$result['max_score'],1) ?> puntos</div>
      <div class="cefr-chip" style="background:<?= $cc ?>"><?= h($cefr) ?> — <?= h($clbl) ?></div>
      <p style="font-size:15px;color:var(--muted);">
        <?= h($result['student_name'] ?? '') ?> · <?= h(date('d/m/Y', strtotime($result['submitted_at'] ?? 'now'))) ?>
      </p>
    </div>

    <?php if (!empty($skillScores)): ?>
    <h3 style="font-family:'Fredoka One',Arial,sans-serif;color:var(--purple);margin-bottom:14px;font-size:20px;">
      Resultados por habilidad
    </h3>
    <div class="skill-grid">
      <?php foreach ($skillScores as $sk => $ss):
        $sPct = ($ss['total'] ?? 0) > 0 ? round($ss['score'] / $ss['total'] * 100) : 0;
      ?>
      <div class="skill-box">
        <div class="skill-name"><?= h($skillLabels[$sk] ?? ucfirst($sk)) ?></div>
        <div class="skill-bar-wrap">
          <div class="skill-bar" style="width:<?= $sPct ?>%"></div>
        </div>
        <div class="skill-pct"><?= $sPct ?>%</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap;">
      <button class="btn btn-purple" onclick="window.print()">📄 Descargar PDF</button>
      <?php
        $waMsg = 'Mi resultado en ' . $link['exam_title'] . ': ' . number_format($pct,0) . '% — Nivel ' . $cefr;
        $waUrl = 'https://wa.me/?text=' . urlencode($waMsg);
      ?>
      <a href="<?= h($waUrl) ?>" class="btn btn-green" target="_blank">💬 Compartir WA</a>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-title">⚠️ Algo salió mal</div>
    <p class="card-sub">No se pudo cargar la evaluación. Vuelve al link original e intenta de nuevo.</p>
    <a href="eval_viewer.php?t=<?= h($token) ?>" class="btn btn-primary">← Volver</a>
  </div>
<?php endif; ?>

</div><!-- .page -->

<script>
// Navegación entre preguntas
let currentQ = 0;
const totalQ = <?= count($questions) ?>;

function goTo(n) {
  if (n < 0 || n >= totalQ) return;
  document.querySelectorAll('.q-card').forEach((c, i) => {
    c.style.display = i === n ? '' : 'none';
  });
  currentQ = n;
  window.scrollTo({top:0,behavior:'smooth'});
}

function selectOption(label, qIdx, val) {
  document.querySelectorAll('#opts-' + qIdx + ' .option-label').forEach(l => l.classList.remove('selected'));
  label.classList.add('selected');
  label.querySelector('input[type=radio]').checked = true;
}

function selectFlipAnswer(qIdx, answer) {
  document.querySelectorAll('#opts-fc-' + qIdx + ' .option-label').forEach(l => l.classList.remove('selected'));
}

function confirmSubmit() {
  if (confirm('¿Seguro que deseas enviar el examen? No podrás cambiar tus respuestas.')) {
    document.getElementById('exam-form').submit();
  }
}

// Drag & drop chips
let draggingId = null;
function dragChip(e, chipId) { draggingId = chipId; }
function dropChip(e, qIdx) {
  e.preventDefault();
  if (!draggingId) return;
  const chip = document.getElementById(draggingId);
  if (!chip) return;
  const area = document.getElementById('answer-area-' + qIdx);
  area.appendChild(chip);
  draggingId = null;
  updateChipAnswer(qIdx);
}
function updateChipAnswer(qIdx) {
  const area = document.getElementById('answer-area-' + qIdx);
  const chips = Array.from(area.querySelectorAll('.chip'));
  const val = chips.map(c => c.textContent.trim()).join(' ');
  const inp = document.getElementById('answer-input-' + qIdx);
  if (inp) inp.value = val;
}
// Allow dropping back to pool
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.chips-pool').forEach(pool => {
    pool.addEventListener('dragover', e => e.preventDefault());
    pool.addEventListener('drop', e => {
      e.preventDefault();
      if (!draggingId) return;
      const chip = document.getElementById(draggingId);
      if (chip) pool.appendChild(chip);
      draggingId = null;
      // Find the q index from pool id
      const qIdx = pool.id.replace('pool-','');
      updateChipAnswer(qIdx);
    });
  });
});

<?php if ($step === 'exam' && $timeLimitMin > 0): ?>
// Timer
let timeLeft = <?= $timeLimitMin * 60 ?>;
const timerEl = document.getElementById('timer');
const timerInterval = setInterval(() => {
  timeLeft--;
  if (timeLeft <= 0) {
    clearInterval(timerInterval);
    document.getElementById('exam-form').submit();
    return;
  }
  const m = Math.floor(timeLeft / 60);
  const s = timeLeft % 60;
  timerEl.textContent = '⏱ ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
  if (timeLeft <= 120) timerEl.classList.add('warning');
}, 1000);
<?php endif; ?>
</script>
</body>
</html>

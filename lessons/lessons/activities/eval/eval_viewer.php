<?php
/**
 * eval_viewer.php — Vista del estudiante para presentar examen.
 * Acceso por token SIN usuario ni contraseña.
 * URL: eval_viewer.php?t={token}
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
require_once __DIR__ . '/exam_question_selector.php';
require_once __DIR__ . '/../quiz/_quiz_lib.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$token     = trim($_GET['t'] ?? '');
$step      = $_GET['step'] ?? 'welcome';
$resultId  = (int) ($_GET['rid'] ?? 0);

// ─── Preview mode (admin/teacher only, no token needed) ──────────────────────
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1';
if ($isPreview) {
    session_start();
    $isAdmin   = !empty($_SESSION['admin_logged']);
    $isTeacher = !empty($_SESSION['academic_logged']);
    if (!$isAdmin && !$isTeacher) {
        http_response_code(403);
        die('Acceso denegado. Solo administradores pueden previsualizar.');
    }
    $previewExamId = (int) ($_GET['exam_id'] ?? 0);
    if ($previewExamId <= 0) die('exam_id requerido para preview.');
    $stmt = $pdo->prepare(
        "SELECT e.id AS exam_id, e.title AS exam_title, e.time_limit_min,
                1 AS max_attempts, '' AS instructions, e.cefr_level AS exam_cefr,
                e.status AS exam_status, e.modalities, e.unit_id AS exam_unit_id,
                'group' AS link_type, '' AS student_name, '' AS student_doc,
                '' AS student_phone, '' AS student_email,
                9999 AS max_uses, 0 AS uses_count, NULL AS expires_at
         FROM eval_exams e WHERE e.id=? LIMIT 1"
    );
    $stmt->execute([$previewExamId]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) die('Examen no encontrado.');
    $link['id'] = null; // preview: no real eval_links row
    $token = 'PREVIEW_' . $previewExamId;
}

// ─── Validar token ────────────────────────────────────────────────────────────
$link = $link ?? null;
if (!$isPreview && $token !== '') {
    $stmt = $pdo->prepare(
        "SELECT l.*, e.title AS exam_title, e.time_limit_min, e.max_attempts,
                e.instructions, e.cefr_level AS exam_cefr, e.status AS exam_status,
                e.modalities, e.unit_id AS exam_unit_id
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

if (!$link && !$isPreview) {
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
        $histStmt = $pdo->prepare(
            "SELECT skill_scores, answers_json FROM eval_results
             WHERE exam_id=? AND student_doc=? AND status='submitted' ORDER BY submitted_at DESC"
        );
        $histStmt->execute([$examId, $sDoc]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        $maxAttempts = (int) ($link['max_attempts'] ?? 1);
        if (count($history) >= $maxAttempts) {
            $errorMsg = 'Ya alcanzaste el número máximo de intentos para este examen.';
        } else {
            $attempt = count($history) + 1;
            $examUnitIds = [];
            if (!empty($link['exam_unit_id'])) {
                $examUnitIds = [(string) $link['exam_unit_id']];
            }
            $examConfig  = [
                'exam_id'         => $examId,
                'unit_ids'        => $examUnitIds,
                'assignment_id'   => (string) ($link['id'] ?? $examId),
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
                $examId,
                $isPreview ? null : (int)$link['id'],
                $sName,
                $sDoc,
                $sPhone ?: null,
                $sEmail ?: null,
                $selJson,
            ]);
            $row = $insStmt->fetch(PDO::FETCH_ASSOC);
            $newResultId = (int) $row['id'];

            if (!$isPreview && !empty($link['id'])) {
                $pdo->prepare("UPDATE eval_links SET uses_count=uses_count+1 WHERE id=?")
                    ->execute([$link['id']]);
            }

            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=quiz&rid=' . $newResultId . '&q=0' . ($isPreview ? '&preview=1&exam_id=' . $examId : ''));
            exit;
        }
    }
}

// ─── POST: Enviar respuestas ──────────────────────────────────────────────────
// ─── POST: Navigate question by question (quiz/viewer style) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eval_answer']) && $resultId > 0) {
    $qIndex   = (int) ($_POST['q_index'] ?? 0);
    $total    = (int) ($_POST['q_total'] ?? 0);
    $sessKey  = 'eval_answers_' . $resultId;
    if (!isset($_SESSION[$sessKey])) $_SESSION[$sessKey] = [];

    $resStmt = $pdo->prepare("SELECT * FROM eval_results WHERE id=? AND status='started' LIMIT 1");
    $resStmt->execute([$resultId]);
    $qResult = $resStmt->fetch(PDO::FETCH_ASSOC);

    if ($qResult) {
        $questions = load_exam_questions_from_selection($pdo, $qResult['selection_json'] ?? '');
        $q         = $questions[$qIndex] ?? null;

        if ($q) {
            $qType = $q['type'] ?? 'multiple_choice';
            if (in_array($qType, ['match', 'drag_drop', 'drag_drop_kids', 'unscramble'], true)) {
                $rawAns = isset($_POST['answer']) && is_array($_POST['answer'])
                    ? $_POST['answer']
                    : (isset($_POST['answer']) ? $_POST['answer'] : null);
            } else {
                $rawAns = trim((string) ($_POST['answer'] ?? ''));
            }

            if (isset($_POST['skip'])) {
                $rawAns = null;
            }

            $_SESSION[$sessKey][$qIndex] = $rawAns;
        }

        $next = $qIndex + 1;
        if ($next >= $total) {
            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=submit&rid=' . $resultId . ($isPreview ? '&preview=1&exam_id=' . $examId : ''));
        } else {
            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=quiz&rid=' . $resultId . '&q=' . $next . ($isPreview ? '&preview=1&exam_id=' . $examId : ''));
        }
        exit;
    }
}

// ─── GET: Auto-submit when all questions answered ─────────────────────────────
if ($step === 'submit' && $resultId > 0) {
    $sessKey   = 'eval_answers_' . $resultId;
    $sessAns   = $_SESSION[$sessKey] ?? [];
    $resStmt   = $pdo->prepare("SELECT * FROM eval_results WHERE id=? AND status='started' LIMIT 1");
    $resStmt->execute([$resultId]);
    $subResult = $resStmt->fetch(PDO::FETCH_ASSOC);

    if ($subResult) {
        $_POST['submit_exam'] = '1';
        $_POST['answers']     = $sessAns;
        unset($_SESSION[$sessKey]);
    }
}

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
            $qType   = $q['type'] ?? 'multiple_choice';
            $pts     = (float) ($q['points'] ?? 1);
            $skill   = $q['skill'] ?? 'grammar';
            $rawAns  = $answers[$i] ?? null;

            if (in_array($qType, ['match', 'drag_drop', 'drag_drop_kids'], true)) {
                $given = is_array($rawAns) ? $rawAns : (is_string($rawAns) ? json_decode($rawAns, true) ?? $rawAns : null);
            } else {
                $given = is_string($rawAns) ? trim($rawAns) : (string)($rawAns ?? '');
            }

            $scoreResult = qz_answer_score($q, $given);
            $earned      = min($pts, ($scoreResult['earned'] / max(1, $scoreResult['possible'])) * $pts);
            $isCorrect   = $scoreResult['correct'];
            $givenStr    = is_array($given) ? json_encode($given) : (string)($given ?? '');
            $correctStr  = is_array($q['correct'] ?? null) ? json_encode($q['correct']) : (string)($q['correct'] ?? '');

            $skillScores[$skill] = $skillScores[$skill] ?? ['score' => 0, 'total' => 0];
            $skillScores[$skill]['score'] += $earned;
            $skillScores[$skill]['total'] += $pts;

            $totalScore += $earned;
            $maxScore   += $pts;

            $answersLog[] = ['q'=>$i,'type'=>$qType,'skill'=>$skill,'given'=>$givenStr,'correct'=>$correctStr,'is_correct'=>$isCorrect,'pts_earned'=>$earned,'pts_max'=>$pts];
        }

        $pct = $maxScore > 0 ? round($totalScore / $maxScore * 100, 2) : 0;
        $cefrStmt = $pdo->prepare("SELECT cefr_level FROM eval_cefr_ranges WHERE exam_id=? AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1");
        $cefrStmt->execute([$examId, $pct]);
        $cefrRow = $cefrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cefrRow) {
            $cefrStmt2 = $pdo->prepare("SELECT cefr_level FROM eval_cefr_ranges WHERE is_global=TRUE AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1");
            $cefrStmt2->execute([$pct]);
            $cefrRow = $cefrStmt2->fetch(PDO::FETCH_ASSOC);
        }
        $cefr = $cefrRow ? $cefrRow['cefr_level'] : 'A1';

        $pdo->prepare("UPDATE eval_results SET score=?, max_score=?, pct=?, cefr_suggested=?, answers_json=?, skill_scores=?, status='submitted', submitted_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$totalScore, $maxScore, $pct, $cefr, json_encode($answersLog), json_encode($skillScores), $resultId]);

        header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=result&rid=' . $resultId . ($isPreview ? '&preview=1&exam_id=' . $examId : ''));
        exit;
    }
}

// ─── Cargar datos para exam / result ─────────────────────────────────────────
$questions  = [];
$result     = null;
$skillScores = [];

if (in_array($step, ['exam', 'quiz'], true) && $resultId > 0) {
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
        $skillScores = is_string($result['skill_scores']) ? json_decode($result['skill_scores'], true) : ($result['skill_scores'] ?? []);
    }
}

$cefrColors = ['A1'=>'#6c757d','A2'=>'#17a2b8','B1'=>'#28a745','B2'=>'#007bff','C1'=>'#6f42c1','C2'=>'#dc3545'];
$cefrLabels = ['A1'=>'Principiante','A2'=>'Básico','B1'=>'Intermedio','B2'=>'Intermedio Alto','C1'=>'Avanzado','C2'=>'Maestría'];
$skillLabels = ['grammar'=>'Grammar','vocabulary'=>'Vocabulary','listening'=>'Listening','reading'=>'Reading','writing'=>'Writing','speaking'=>'Speaking'];
$timeLimitMin = (int) ($link['time_limit_min'] ?? 50);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($link['exam_title']) ?> — ONES Evaluación</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&display=swap');
:root{--orange:#F97316;--purple:#7F77DD;--green:#2f9e44;--bg:#f8f7ff;--card:#fff;--line:#e2e0f0;--text:#2d2b55;--muted:#7c7aa0;--radius:20px;--shadow:0 8px 32px rgba(127,119,221,.13);}*{box-sizing:border-box;margin:0;padding:0;}body{font-family:'Nunito',Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}.header{background:linear-gradient(135deg,var(--purple),#5a52c5);color:#fff;padding:18px 24px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 20px rgba(127,119,221,.3);}.header-logo{width:44px;height:44px;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;}.header-title{flex:1;}.header-title h1{font-family:'Fredoka One',Arial,sans-serif;font-size:22px;margin:0;}.header-title p{font-size:13px;opacity:.85;margin:2px 0 0;}.header-badge{background:rgba(255,255,255,.2);border-radius:12px;padding:6px 14px;font-size:12px;font-weight:700;}.page{max-width:780px;margin:0 auto;padding:24px 16px 40px;}.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:28px;margin-bottom:20px;}.card-title{font-family:'Fredoka One',Arial,sans-serif;font-size:26px;color:var(--purple);margin-bottom:8px;}.card-sub{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.5;}.form-group{margin-bottom:16px;}.form-group label{display:block;font-size:13px;font-weight:700;color:var(--muted);margin-bottom:6px;}.form-group input{width:100%;padding:11px 14px;border:2px solid var(--line);border-radius:12px;font-size:15px;font-family:'Nunito',Arial,sans-serif;color:var(--text);transition:border .2s;}.form-group input:focus{outline:none;border-color:var(--purple);}.btn{display:inline-block;font-family:'Nunito',Arial,sans-serif;font-size:15px;font-weight:800;padding:12px 28px;border-radius:14px;border:none;cursor:pointer;text-decoration:none;transition:filter .2s,transform .15s;}.btn:hover{filter:brightness(1.06);transform:translateY(-2px);}.btn-primary{background:linear-gradient(135deg,var(--orange),#e05f00);color:#fff;box-shadow:0 4px 16px rgba(249,115,22,.3);}.btn-purple{background:linear-gradient(135deg,var(--purple),#5a52c5);color:#fff;box-shadow:0 4px 16px rgba(127,119,221,.3);}.btn-green{background:linear-gradient(135deg,#41b95a,#2f9e44);color:#fff;}.btn-block{display:block;width:100%;text-align:center;}.progress-wrap{background:#e2e0f0;border-radius:999px;height:10px;margin-bottom:20px;overflow:hidden;}.progress-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--orange),var(--purple));transition:width .4s ease;}.progress-label{display:flex;justify-content:space-between;font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:700;}.timer{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--purple),#5a52c5);color:#fff;padding:8px 18px;border-radius:14px;font-size:16px;font-weight:800;box-shadow:0 4px 12px rgba(127,119,221,.3);}.q-number{font-size:12px;font-weight:800;color:var(--orange);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;}.q-text{font-size:18px;font-weight:700;color:var(--text);margin-bottom:18px;line-height:1.5;}.options-list{display:flex;flex-direction:column;gap:10px;}.option-label{display:flex;align-items:center;gap:12px;padding:12px 16px;border:2px solid var(--line);border-radius:14px;cursor:pointer;transition:all .2s;font-size:15px;font-weight:600;}.option-label:hover{border-color:var(--purple);background:#f3f2ff;}.option-label input[type=radio]{display:none;}.option-label.selected{border-color:var(--purple);background:#f3f2ff;}.option-label .opt-letter{width:32px;height:32px;border-radius:10px;background:var(--line);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;transition:all .2s;}.option-label.selected .opt-letter{background:var(--purple);color:#fff;}.fill-input{width:100%;padding:12px 16px;border:2px solid var(--line);border-radius:14px;font-size:16px;font-family:'Nunito',Arial,sans-serif;transition:border .2s;}.match-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;font-size:15px;}.match-row .left{font-weight:700;flex:1;}.match-row select{flex:1;padding:10px 12px;border:2px solid var(--line);border-radius:12px;font-size:15px;}.result-score{font-size:56px;font-family:'Fredoka One',Arial,sans-serif;color:var(--orange);text-align:center;margin:18px 0;}.skill-row{display:flex;align-items:center;gap:12px;margin-bottom:12px;}.skill-name{width:120px;font-weight:700;font-size:14px;}.skill-bar-wrap{flex:1;background:#e2e0f0;border-radius:999px;height:14px;overflow:hidden;}.skill-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--orange),var(--purple));}.skill-pct{width:50px;text-align:right;font-weight:800;color:var(--purple);} .text-answer{width:100%;min-height:90px;border:2px solid var(--line);border-radius:14px;padding:12px;font-family:'Nunito',Arial,sans-serif;font-size:15px;}
</style>
</head>
<body>
<header class="header"><div class="header-logo">🟠</div><div class="header-title"><h1><?= h($link['exam_title']) ?></h1><p>ONES Online English Solution</p></div><div class="header-badge"><i class="ti ti-clock"></i> <?= $timeLimitMin ?> min</div></header>
<div class="page">
<?php if ($step === 'welcome'): ?>
  <div class="card"><h2 class="card-title">Bienvenido</h2><p class="card-sub">Completa tus datos para iniciar la evaluación.</p><?php if ($errorMsg): ?><p style="color:#dc3545;font-weight:800;margin-bottom:12px;"><?= h($errorMsg) ?></p><?php endif; ?><form method="POST"><input type="hidden" name="start_exam" value="1"><div class="form-group"><label>Nombre completo *</label><input type="text" name="student_name" required value="<?= h($link['student_name'] ?? '') ?>"></div><div class="form-group"><label>Documento / ID</label><input type="text" name="student_doc" value="<?= h($link['student_doc'] ?? '') ?>"></div><div class="form-group"><label>Teléfono</label><input type="text" name="student_phone" value="<?= h($link['student_phone'] ?? '') ?>"></div><div class="form-group"><label>Email</label><input type="email" name="student_email" value="<?= h($link['student_email'] ?? '') ?>"></div><button type="submit" class="btn btn-primary btn-block">Iniciar examen</button></form></div>
<?php elseif ($step === 'quiz'): $qIndex=(int)($_GET['q'] ?? 0); $total=count($questions); $q=$questions[$qIndex] ?? null; ?>
  <?php if (!$q): ?><div class="card"><h2 class="card-title">No hay preguntas</h2><p>No se encontraron preguntas para este examen. Regresa al editor y revisa las actividades.</p></div><?php else: ?>
  <div class="progress-label"><span>Pregunta <?= $qIndex+1 ?> de <?= $total ?></span><span><?= round((($qIndex+1)/max(1,$total))*100) ?>%</span></div><div class="progress-wrap"><div class="progress-bar" style="width:<?= (($qIndex+1)/max(1,$total))*100 ?>%"></div></div>
  <div class="card"><div class="q-number"><?= h($q['type'] ?? 'question') ?> · <?= h($q['skill'] ?? '') ?></div><div class="q-text"><?= h($q['question'] ?? $q['text'] ?? '') ?></div><?php if (!empty($q['audio'])): ?><audio controls src="<?= h($q['audio']) ?>" style="width:100%;margin-bottom:12px"></audio><?php endif; ?><?php if (!empty($q['image'])): ?><img src="<?= h($q['image']) ?>" style="max-width:100%;border-radius:14px;margin-bottom:12px"><?php endif; ?><form method="POST"><input type="hidden" name="eval_answer" value="1"><input type="hidden" name="q_index" value="<?= $qIndex ?>"><input type="hidden" name="q_total" value="<?= $total ?>"><?php $type=$q['type'] ?? 'multiple_choice'; if (!empty($q['options'])): ?><div class="options-list"><?php foreach ($q['options'] as $i=>$opt): ?><label class="option-label"><input type="radio" name="answer" value="<?= h((string)$opt) ?>"><span class="opt-letter"><?= chr(65+$i) ?></span><span><?= h((string)$opt) ?></span></label><?php endforeach; ?></div><?php else: ?><textarea class="text-answer" name="answer" placeholder="Escribe tu respuesta"></textarea><?php endif; ?><div style="display:flex;gap:10px;margin-top:22px;"><button class="btn btn-purple" type="submit">Continuar</button><button class="btn" type="submit" name="skip" value="1">Saltar</button></div></form></div>
  <?php endif; ?>
<?php elseif ($step === 'result'): ?>
  <div class="card"><h2 class="card-title" style="text-align:center;">Resultado</h2><div class="result-score"><?= h((string)round((float)($result['pct'] ?? 0))) ?>%</div><p style="text-align:center;font-weight:800;color:var(--purple);">Nivel sugerido: <?= h($result['cefr_suggested'] ?? 'A1') ?></p></div>
<?php endif; ?>
</div>
<script>document.querySelectorAll('.option-label').forEach(l=>l.addEventListener('click',()=>{document.querySelectorAll('.option-label').forEach(x=>x.classList.remove('selected'));l.classList.add('selected');}));</script>
</body></html>

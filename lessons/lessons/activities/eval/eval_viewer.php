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

if (session_status() === PHP_SESSION_NONE) session_start();

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
           AND (
             l.uses_count < l.max_uses
             OR EXISTS (
               SELECT 1 FROM eval_results r
               WHERE r.link_id = l.id AND r.id = ? AND r.status IN ('started', 'submitted')
             )
           )
         LIMIT 1"
    );
    $stmt->execute([$token, $resultId]);
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
<?php
$evalHref = static function (string $nextStep, ?int $nextQuestion = null) use ($token, $resultId, $isPreview, $examId): string {
    $params = ['t' => $token, 'step' => $nextStep];
    if ($resultId > 0) $params['rid'] = $resultId;
    if ($nextQuestion !== null) $params['q'] = $nextQuestion;
    if ($isPreview) {
        $params['preview'] = '1';
        $params['exam_id'] = $examId;
    }
    return 'eval_viewer.php?' . http_build_query($params);
};
$resultAnswers = [];
if ($result && !empty($result['answers_json'])) {
    $resultAnswers = is_string($result['answers_json'])
        ? (json_decode($result['answers_json'], true) ?: [])
        : (array) $result['answers_json'];
}
$resultPct = (float) ($result['pct'] ?? 0);
$resultCorrect = count(array_filter($resultAnswers, static fn ($answer) => !empty($answer['is_correct'])));
$resultTotal = count($resultAnswers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($link['exam_title']) ?> — ONES Evaluation</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="../../core/activity_zoom.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap');
:root{--pu:#8070dd;--or:#ff7315;--ink:#14113a;--mut:#8f86c5;--line:#e9e3fb;--bg:#f8f7ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Nunito,sans-serif;color:var(--ink)}.page{max-width:1020px;margin:auto;padding:34px 24px 56px}.top,.card{background:#fff;border:1px solid var(--line);border-radius:22px}.top{padding:18px 28px;display:flex;justify-content:space-between;align-items:center;max-width:820px;margin:0 auto 26px}.brand{font-weight:900;color:var(--pu);font-size:20px}.sub{font-size:15px;color:var(--mut)}.btn-back,.tab,.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-family:Nunito,sans-serif;font-weight:900;cursor:pointer;transition:.15s}.btn-back{gap:6px;padding:9px 16px;border-radius:10px;background:#f0ecff;color:var(--pu);font-size:13px;border:1px solid var(--line)}.btn-back:hover,.tab:hover{transform:translateY(-1px)}.tabs{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:20px}.tab{border:1px solid var(--line);background:#fff;color:var(--pu);border-radius:12px;padding:11px 22px;font-size:13px;box-shadow:0 7px 18px rgba(127,112,221,.13);min-width:112px}.tab.on{background:var(--pu);color:#fff;border-color:var(--pu)}.screen-title{text-align:center;color:#c0b8e8;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:14px}.card{max-width:720px;margin:auto;padding:36px;box-shadow:0 8px 24px rgba(127,119,221,.09)}.kicker,.tag{display:inline-flex;align-items:center;gap:8px;background:#fff0e6;color:var(--or);border:1px solid #fcd7b9;border-radius:999px;padding:7px 16px;font-weight:900;font-size:13px;text-transform:uppercase}.title{font:700 40px Fredoka,sans-serif;color:var(--or);margin:14px 0 6px}.lead{color:var(--pu);font-size:18px;line-height:1.45}.chips{display:flex;gap:10px;flex-wrap:wrap;margin:22px 0}.chip{border:1px solid var(--line);border-radius:999px;color:var(--pu);padding:9px 14px;font-size:14px;font-weight:900;background:#fbfaff}.hr{height:1px;background:var(--line);margin:24px 0}.included{color:#a99ee0;font-size:13px;font-weight:900}.form-group{margin-bottom:16px}.form-group label{display:block;color:var(--mut);font-size:13px;font-weight:800;margin-bottom:6px}.form-group input,.text-answer{width:100%;border:1px solid var(--line);border-radius:13px;padding:14px;font:600 15px Nunito;color:var(--ink)}.form-group input:focus,.text-answer:focus{outline:3px solid rgba(127,119,221,.12);border-color:var(--pu)}.btn{border:0;border-radius:13px;padding:14px 20px;gap:8px;font-size:15px}.btn-primary,.btn-purple{background:var(--pu);color:#fff}.btn-primary:hover,.btn-purple:hover{background:#6559cc}.btn-light{background:#fff;color:var(--pu);border:1px solid var(--line)}.w100{width:100%}.progress-head{display:flex;justify-content:space-between;color:var(--pu);font-size:14px;font-weight:900}.track{height:9px;background:#eeeafa;border-radius:999px;overflow:hidden;margin:10px 0 24px}.bar{height:100%;background:linear-gradient(90deg,var(--or),var(--pu))}.tag{background:#f0ecff;color:var(--pu);padding:8px 13px;margin-bottom:16px}.question{font-weight:900;line-height:1.4;margin-bottom:22px;font-size:24px}.audio-player{width:100%;margin:0 0 16px;border-radius:12px}.question-image{display:block;width:100%;max-height:280px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff;margin:0 0 18px}.options-list{display:flex;flex-direction:column;gap:12px}.option{border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;gap:14px;align-items:center;font-weight:800;font-size:17px;cursor:pointer}.option input{display:none}.option:hover,.option:has(input:checked){border-color:var(--pu);background:#f8f6ff}.letter{background:#eeeafa;color:var(--pu);border-radius:999px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}.option:has(input:checked) .letter{background:var(--pu);color:#fff}.actions{display:flex;gap:12px;margin-top:18px}.result-hero{background:linear-gradient(135deg,#eee9ff,#fff0e6);border-radius:18px;text-align:center;padding:36px 22px;margin-bottom:22px}.pct{font:700 58px Fredoka,sans-serif;color:var(--or);margin:18px 0 4px}.result-chip{display:inline-flex;border-radius:999px;padding:7px 12px;font-size:14px;font-weight:900;margin:5px;background:#fff;border:1px solid var(--line);color:var(--pu)}.skill-row{display:flex;align-items:center;gap:12px;margin:12px 0}.skill-name{width:120px;font-weight:800;font-size:14px}.skill-bar-wrap{flex:1;background:#eeeafa;border-radius:999px;height:12px;overflow:hidden}.skill-bar{height:100%;background:linear-gradient(90deg,var(--or),var(--pu))}.skill-pct{width:50px;text-align:right;font-weight:900;color:var(--pu)}.error{background:#fff1f1;color:#991b1b;border:1px solid #fecaca;border-radius:12px;padding:10px 14px;font-weight:800;margin-bottom:16px}
@media(max-width:760px){.page{padding:22px 14px 42px}.top{max-width:100%;padding:14px 18px}.card{padding:24px}.title{font-size:34px}.question{font-size:20px}.actions{flex-direction:column}.actions .btn{width:100%}.tab{min-width:0;flex:1;padding:10px 12px}.skill-name{width:90px}}
</style>
</head>
<body>
<div class="page" data-az-zoom>
  <div class="top">
    <div style="display:flex;align-items:center;gap:10px">
      <svg width="42" height="42" viewBox="0 0 36 36" fill="none" aria-hidden="true"><rect width="36" height="36" rx="9" fill="#FFF0E6"/><circle cx="17" cy="15" r="8.5" fill="#F97316"/><polygon points="12,22 7,30 21,26" fill="#F97316"/><circle cx="17" cy="15" r="4.5" fill="#FFF0E6"/><circle cx="24" cy="9" r="3.5" fill="#7B6EE6"/><circle cx="24" cy="9" r="1.75" fill="#fff"/></svg>
      <div><div class="brand">ONES</div><div class="sub">ONLINE ENGLISH SOLUTION · EVALUATION</div></div>
    </div>
    <a class="btn-back" href="<?= h($evalHref('welcome')) ?>">← Back</a>
  </div>
  <nav class="tabs" aria-label="Evaluation navigation">
    <a class="tab <?= $step === 'welcome' ? 'on' : '' ?>" href="<?= h($evalHref('welcome')) ?>">Intro</a>
    <a class="tab <?= $step === 'quiz' ? 'on' : '' ?>" href="<?= h($evalHref('quiz', (int)($_GET['q'] ?? 0))) ?>">Quiz</a>
    <a class="tab <?= $step === 'result' ? 'on' : '' ?>" href="<?= h($evalHref('result')) ?>">Result</a>
  </nav>
<?php if ($step === 'welcome'): ?>
  <div class="screen-title">Evaluation introduction</div>
  <div class="card">
    <span class="kicker">EVALUATION</span>
    <div class="title"><?= h($link['exam_title']) ?></div>
    <div class="lead">Complete your details to begin the evaluation.</div>
    <div class="chips"><span class="chip"><i class="ti ti-clock"></i> <?= $timeLimitMin ?> minutes</span><span class="chip">Secure submission</span><span class="chip">Results at the end</span></div>
    <div class="hr"></div>
    <div class="included">STUDENT DETAILS</div>
    <?php if ($errorMsg): ?><div class="error" role="alert"><?= h($errorMsg) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="start_exam" value="1">
      <div class="form-group"><label for="student-name">Full name *</label><input id="student-name" type="text" name="student_name" required value="<?= h($link['student_name'] ?? '') ?>"></div>
      <div class="form-group"><label for="student-doc">Document / ID</label><input id="student-doc" type="text" name="student_doc" value="<?= h($link['student_doc'] ?? '') ?>"></div>
      <div class="form-group"><label for="student-phone">Phone</label><input id="student-phone" type="text" name="student_phone" value="<?= h($link['student_phone'] ?? '') ?>"></div>
      <div class="form-group"><label for="student-email">Email</label><input id="student-email" type="email" name="student_email" value="<?= h($link['student_email'] ?? '') ?>"></div>
      <button type="submit" class="btn btn-purple w100">Start evaluation</button>
    </form>
  </div>
<?php elseif ($step === 'quiz'): $qIndex=(int)($_GET['q'] ?? 0); $total=count($questions); $q=$questions[$qIndex] ?? null; ?>
  <?php if (!$q): ?><div class="card"><span class="kicker">EMPTY STATE</span><div class="title">No questions found</div><div class="lead">No questions were found for this evaluation. Please contact your institution.</div></div><?php else: ?>
  <div class="screen-title">Question <?= $qIndex + 1 ?> of <?= $total ?></div>
  <div class="card">
    <div class="progress-head"><span>PROGRESS</span><span><?= $qIndex + 1 ?> / <?= $total ?></span></div>
    <div class="track" aria-label="Evaluation progress"><div class="bar" style="width:<?= round((($qIndex + 1) / max(1, $total)) * 100) ?>%"></div></div>
    <div class="tag"><i class="ti ti-<?= $q['type'] === 'multiple_choice' ? 'checks' : 'question-mark' ?>"></i><?= h($q['type'] ?? 'Question') ?> · <?= h($q['skill'] ?? '') ?></div>
    <div class="question"><?= h($q['question'] ?? $q['text'] ?? '') ?></div>
    <?php if (!empty($q['audio'])): ?><audio class="audio-player" controls preload="metadata" src="<?= h($q['audio']) ?>" aria-label="Question audio"></audio><?php endif; ?>
    <?php if (!empty($q['image'])): ?><img class="question-image" src="<?= h($q['image']) ?>" alt="Question illustration" loading="lazy"><?php endif; ?>
    <form method="POST" id="eval-question-form">
      <input type="hidden" name="eval_answer" value="1"><input type="hidden" name="q_index" value="<?= $qIndex ?>"><input type="hidden" name="q_total" value="<?= $total ?>">
      <?php if (!empty($q['options'])): ?><div class="options-list"><?php foreach ($q['options'] as $i => $opt): $optionValue = $q['type'] === 'multiple_choice' ? (string)$i : (string)$opt; ?><label class="option"><input type="radio" name="answer" value="<?= h($optionValue) ?>" onchange="this.form.submit()"><span class="letter"><?= chr(65 + $i) ?></span><span><?= h((string)$opt) ?></span></label><?php endforeach; ?></div><?php else: ?><textarea class="text-answer" name="answer" rows="4" placeholder="Type your answer…" aria-label="Your answer"></textarea><?php endif; ?>
      <div class="actions"><?php if (empty($q['options'])): ?><button class="btn btn-purple" type="submit">Next</button><?php endif; ?><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div>
    </form>
  </div>
  <?php endif; ?>
<?php elseif ($step === 'result'): ?>
  <div class="screen-title">Evaluation complete</div>
  <div class="result-hero">
    <span class="kicker">EVALUATION RESULT</span>
    <div class="title"><?= h($link['exam_title']) ?></div>
    <div class="pct"><?= h((string)round($resultPct)) ?>%</div>
    <div class="result-chip">✓ <?= $resultCorrect ?> / <?= $resultTotal ?> correct</div><div class="result-chip">CEFR <?= h($result['cefr_suggested'] ?? 'A1') ?></div>
  </div>
  <div class="card">
    <div class="tag"><i class="ti ti-chart-bar"></i> Skill breakdown</div>
    <?php foreach ($skillLabels as $skillKey => $skillLabel): $skill = $skillScores[$skillKey] ?? null; $skillPct = $skill && (float)($skill['total'] ?? 0) > 0 ? round((float)$skill['score'] / (float)$skill['total'] * 100) : null; if ($skillPct === null) continue; ?>
      <div class="skill-row"><span class="skill-name"><?= h($skillLabel) ?></span><span class="skill-bar-wrap"><span class="skill-bar" style="display:block;width:<?= $skillPct ?>%"></span></span><span class="skill-pct"><?= $skillPct ?>%</span></div>
    <?php endforeach; ?>
  </div>
  <div class="card actions"><a class="btn btn-light" href="<?= h($evalHref('result')) ?>">View result</a><a class="btn btn-purple" href="<?= h($evalHref('welcome')) ?>">Finish</a></div>
<?php endif; ?>
</div>
<script>document.querySelectorAll('.option').forEach(function(option){option.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();var input=option.querySelector('input');if(input){input.checked=true;input.form.submit();}}});});</script>
<script src="../../core/activity_zoom.js"></script>
</body>
</html>

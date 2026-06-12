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
$step      = $_GET['step'] ?? 'welcome';   // welcome | quiz | result
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
            // Add unit_ids so selector can pull activity questions
            // Add assignment_id so qz_build() uses same seed as quiz/viewer
            $examUnitIds = [];
            if (!empty($exam['unit_id'])) {
                $examUnitIds = [(int) $exam['unit_id']];
            }
            $examConfig  = [
                'exam_id'         => $examId,
                'unit_ids'        => $examUnitIds,
                'assignment_id'   => (string) $link['id'],
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
            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=quiz&rid=' . $newResultId . '&q=0');
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
            // All answered — submit
            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=submit&rid=' . $resultId);
        } else {
            header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=quiz&rid=' . $resultId . '&q=' . $next);
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
        // Fake a POST submit_exam with session answers
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

            // Parse answer based on type (match/drag_drop come as arrays)
            if (in_array($qType, ['match', 'drag_drop', 'drag_drop_kids'], true)) {
                $given = is_array($rawAns) ? $rawAns : (is_string($rawAns) ? json_decode($rawAns, true) ?? $rawAns : null);
            } else {
                $given = is_string($rawAns) ? trim($rawAns) : (string)($rawAns ?? '');
            }

            // Use qz_answer_score — same scoring engine as quiz/viewer.php
            // Handles multi-blank |, word-by-word, pronunciation, match pairs, drag_drop arrays
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

            $answersLog[] = [
                'q'          => $i,
                'type'       => $qType,
                'skill'      => $skill,
                'given'      => $givenStr,
                'correct'    => $correctStr,
                'is_correct' => $isCorrect,
                'pts_earned' => $earned,
                'pts_max'    => $pts,
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

        // ── Sync to student_quiz_state + student_unit_results ─────────────
        // So the result appears in teacher_course.php dashboard and student_quiz.php
        // exactly like a regular unit quiz result.
        $unitIdForSync = (int) ($exam['unit_id'] ?? 0);
        if ($unitIdForSync > 0) {
            // Resolve student_id — use doc as surrogate when no session
            $syncStudentId = trim((string) ($_SESSION['student_id'] ?? ''));
            if ($syncStudentId === '') {
                // For external (tokenised) access: use doc or name as stable ID
                $syncStudentId = trim((string) ($result['student_doc']  ?? ''));
                if ($syncStudentId === '') {
                    $syncStudentId = trim((string) ($result['student_name'] ?? ''));
                }
            }

            // Resolve assignment_id — use link token as surrogate
            $syncAssignment = trim((string) ($link['id'] ?? '0'));

            if ($syncStudentId !== '') {
                // Ensure table exists
                qz_ensure_quiz_state_table($pdo);

                // Build minimal answers array for qz_save_db_state
                $qzAnswers = [];
                foreach ($questions as $qi => $q) {
                    $log = $answersLog[$qi] ?? [];
                    $qzAnswers[$qi] = [
                        'answer'   => $log['given']      ?? null,
                        'correct'  => $log['is_correct'] ?? false,
                        'earned'   => $log['pts_earned'] ?? 0.0,
                        'possible' => $log['pts_max']    ?? 1.0,
                        'skipped'  => ($log['given'] ?? '') === '',
                    ];
                }

                $qzTotal   = (int) round($maxScore);
                $qzCorrect = (int) round($totalScore);
                $qzWrong   = max(0, $qzTotal - $qzCorrect);
                $qzSkip    = 0;
                $qzPct     = (int) round($pct);

                // Save attempt — attempt 1 (external exams don't track multi-attempt)
                qz_save_db_state(
                    $pdo,
                    $syncStudentId,
                    $unitIdForSync,
                    $syncAssignment,
                    1,            // attempt
                    $questions,
                    $qzAnswers,
                    true,         // completed
                    $qzPct,
                    $qzCorrect,
                    $qzWrong,
                    $qzSkip,
                    $qzTotal
                );

                // Save quiz_score_percent to student_unit_results
                // (teacher dashboard reads: Activities 60% + Quiz 40%)
                qz_save_quiz_unit_score(
                    $pdo,
                    $syncStudentId,
                    $unitIdForSync,
                    $syncAssignment,
                    $qzPct
                );
            }
        }
        // ─────────────────────────────────────────────────────────────────

        header('Location: eval_viewer.php?t=' . urlencode($token) . '&step=result&rid=' . $resultId);
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
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

/* ── Quiz/viewer question UI ── */
.tag{display:inline-flex;gap:8px;align-items:center;background:#f0ecff;color:var(--purple);border-radius:999px;padding:8px 13px;font-size:13px;font-weight:900;text-transform:uppercase;margin-bottom:16px;}
.progress-head{display:flex;justify-content:space-between;color:var(--purple);font-size:14px;font-weight:900;}
.track{height:9px;background:#eeeafa;border-radius:999px;overflow:hidden;margin:10px 0 24px;}
.bar{height:100%;background:linear-gradient(90deg,var(--orange),var(--purple));}
.question{font-weight:900;line-height:1.4;margin-bottom:22px;font-size:22px;}
.option{border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:12px;display:flex;gap:14px;font-weight:800;font-size:17px;cursor:pointer;}
.option input{display:none;}
.letter{background:#eeeafa;color:var(--purple);border-radius:999px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;}
.option:hover,.option:has(input:checked){border-color:var(--purple);background:#f8f6ff;}
.input,.select{width:100%;border:1px solid var(--line);border-radius:13px;padding:16px;font:800 17px Nunito;margin-bottom:12px;}
.match-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.match-left{padding:15px;border:1px solid var(--purple);border-radius:12px;text-align:center;font-weight:900;background:#fbfaff;font-size:16px;}
.btn{border:0;border-radius:13px;padding:16px 20px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:16px;}
.btn-primary{background:var(--orange);color:white;}
.btn-purple{background:var(--purple);color:white;}
.btn-light{background:white;color:var(--purple);border:1px solid var(--line);}
.btn-green{background:#16a34a;color:white;}
.w100{width:100%;}
.actions{display:flex;gap:12px;margin-top:18px;}
.pron-card{min-height:400px;border:1px solid #EDE9FA;border-radius:30px;background:#fff;padding:18px;text-align:center;}
.pron-listen-cue{display:inline-flex;margin-bottom:12px;padding:6px 13px;border-radius:999px;background:#EEEDFE;color:#534AB7;font-size:12px;font-weight:900;}
.pron-image{width:100%;height:280px;margin-bottom:14px;border-radius:24px;background:#fff;border:1px solid #EDE9FA;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:10px;}
.pron-image img{width:100%;height:100%;object-fit:contain;border-radius:18px;}
.pron-word{font-size:28px;font-weight:900;color:#534AB7;}
.pron-box{width:100%;margin-top:8px;border-radius:12px;padding:9px 12px;font-size:13px;font-weight:800;text-align:center;}
.pron-captured{border:1px solid #EDE9FA;background:#fff;color:#534AB7;}
.pron-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid #F0EEF8;}
.pron-btn{border:0;border-radius:10px;min-width:130px;padding:13px 20px;color:#fff;font-size:13px;font-weight:900;cursor:pointer;}
.pron-purple{background:#7F77DD;}
.pron-orange{background:#F97316;}
.qz-dd-instruction{font-size:17px;font-weight:900;line-height:2.2;margin-bottom:14px;padding:12px;border:1px solid var(--line);border-radius:14px;background:#fbfaff;}
.qz-dd-slot{display:inline-flex;align-items:center;justify-content:center;min-width:90px;height:38px;border:2px dashed var(--purple);border-radius:10px;margin:0 6px;padding:0 10px;background:#f8f6ff;vertical-align:middle;}
.qz-dd-placeholder{color:#c4b9e8;font-size:13px;font-style:italic;}
.qz-dd-filled{border-style:solid;background:#eeedfe;}
.qz-drag-over{background:#e8e5ff!important;border-color:var(--orange)!important;}
.qz-word-bank{display:flex;flex-wrap:wrap;gap:10px;min-height:60px;border:1px solid var(--line);border-radius:18px;padding:14px;background:#fbfaff;margin-bottom:12px;}
.qz-chip{padding:12px 16px;border-radius:16px;background:white;border:1px solid #EDE9FA;box-shadow:0 4px 14px rgba(127,119,221,.13);font-weight:900;color:#534AB7;cursor:grab;user-select:none;}
.qz-built-chip{background:#eeedfe;border-color:#7F77DD;}
.qz-fill-inline{font-size:20px;font-weight:900;line-height:2.5;margin-bottom:16px;}
.qz-fill-blank{border:none;border-bottom:3px solid var(--purple);width:120px;font-size:20px;font-weight:900;color:var(--purple);background:transparent;text-align:center;outline:none;padding:0 4px;margin:0 4px;}
.qz-fill-input-lite{width:100%;border:1px solid var(--line);border-radius:13px;padding:16px;font:800 17px Nunito;margin-bottom:12px;}
.us-chip{padding:12px 16px;border-radius:16px;background:white;border:1px solid #EDE9FA;box-shadow:0 4px 14px rgba(127,119,221,.13);font-weight:900;color:#534AB7;cursor:grab;user-select:none;}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;background:#f0ecff;color:var(--purple);font-size:13px;font-weight:900;text-decoration:none;border:1px solid var(--line);}
/* Also load tabler icons for tag */


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
  <?php if (in_array($step, ['exam','quiz'], true) && $timeLimitMin > 0): ?>
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

<?php // ─── Paso 2: Quiz — pregunta por pregunta (idéntico a quiz/viewer.php) ───
elseif ($step === 'quiz' && $result && !empty($questions)):
    $qIndex  = max(0, min((int)($_GET['q'] ?? 0), count($questions) - 1));
    $q       = $questions[$qIndex];
    $total   = count($questions);
    $qNum    = $qIndex + 1;
    $pctDone = round($qNum / $total * 100);
    $qType   = $q['type'] ?? 'multiple_choice';
    $bt      = $q['block_type'] ?? $qType;
    $labels  = ['dictation'=>['Dictation','Listen and type what you hear','ti-keyboard'],
                'pronunciation'=>['Pronunciation','Say the phrase','ti-microphone'],
                'multiple_choice'=>['Multiple choice','Pick the correct answer','ti-checks'],
                'fill'=>['Fill in the blank','Complete the sentence','ti-pencil'],
                'writing_practice'=>['Writing practice','Write your answer','ti-writing'],
                'match'=>['Match','Connect each word to its pair','ti-arrows-shuffle'],
                'drag_drop'=>['Drag and drop','Arrange or match items','ti-hand-move'],
                'unscramble'=>['Unscramble','Put the words in order','ti-sort-ascending']];
    $inf = $labels[$bt] ?? [$bt, '', 'ti-circle'];
    // Seed-based shuffle for drag_drop/unscramble (use result id as seed)
    $qzShuffleSeed = (int)$result['id'] + $qIndex + 991;
?>
<div class="card" style="max-width:720px;margin:auto;padding:36px;">
  <div class="progress-head"><span>PROGRESS</span><span><?= $qNum ?> / <?= $total ?></span></div>
  <div class="track"><div class="bar" style="width:<?= $pctDone ?>%"></div></div>
  <div class="tag"><i class="ti <?= h($inf[2]) ?>"></i><?= h($inf[0]) ?></div>

  <?php if ($qType === 'pronunciation'): ?>
  <div class="pron-card">
    <div class="pron-listen-cue">Listen first</div>
    <div class="pron-image">
      <?php if (!empty($q['image'])): ?>
        <img src="<?= h($q['image']) ?>" alt="<?= h($q['correct'] ?? '') ?>">
      <?php else: ?>
        <div class="pron-word"><?= h($q['correct'] ?? '') ?></div>
      <?php endif; ?>
    </div>
    <div class="pron-box pron-captured" id="pron-captured"></div>
  </div>
  <form method="post">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <input type="hidden" name="answer" id="pron-answer" value="">
    <div class="pron-actions">
      <button type="button" class="pron-btn pron-purple" id="pron-listen">Listen</button>
      <button type="button" class="pron-btn pron-purple" id="pron-speak">Speak</button>
      <button type="submit" class="pron-btn pron-purple" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>
  <script>(function(){
    var expected=<?= json_encode((string)($q['correct'] ?? '')) ?>;
    var audio=<?= json_encode((string)($q['audio'] ?? '')) ?>;
    var cap=document.getElementById('pron-captured'),answer=document.getElementById('pron-answer'),submitted=false;
    var form=answer.closest('form');
    function norm(t){return String(t||'').toLowerCase().trim().replace(/[.,!?;:'"\-]/g,'').replace(/\s+/g,' ');}
    function overlap(a,b){var wa=a.split(' ').filter(Boolean),wb=b.split(' ').filter(Boolean);if(!wa.length||!wb.length)return 0;return wa.filter(function(w){return wb.indexOf(w)!==-1;}).length/Math.max(wa.length,wb.length);}
    function isMatch(a,b){return a===b||overlap(a,b)>=.8;}
    function submit(ok){if(submitted)return;submitted=true;answer.value=ok?'1':'';setTimeout(function(){form.submit();},250);}
    function listen(){if(audio){var a=new Audio(audio);a.play().catch(function(){});return;}if(!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(expected);u.lang='en-US';u.rate=.82;window.speechSynthesis.speak(u);}
    function speak(){var C=window.SpeechRecognition||window.webkitSpeechRecognition;if(!C){submit(false);return;}var r=new C();r.lang='en-US';r.interimResults=false;r.maxAlternatives=1;var heard=false;r.onresult=function(e){heard=true;var said=String(e.results&&e.results[0]&&e.results[0][0]?e.results[0][0].transcript:'');submit(isMatch(norm(said),norm(expected)));};r.onerror=function(){heard=true;submit(false);};r.onend=function(){if(!heard)submit(false);};r.start();}
    document.getElementById('pron-listen').onclick=listen;
    document.getElementById('pron-speak').onclick=speak;
  })();</script>

  <?php elseif ($qType === 'dictation'): ?>
  <div class="pron-card">
    <div class="pron-listen-cue">Listen and type what you hear</div>
    <div class="pron-image" style="min-height:140px;">
      <?php if (!empty($q['image'])): ?>
        <img src="<?= h($q['image']) ?>" alt="">
      <?php else: ?><span style="font-size:64px;">🎧</span><?php endif; ?>
    </div>
  </div>
  <form method="post" id="dict-form">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <input type="hidden" name="answer" id="dict-answer" value="">
    <input class="input" id="dict-input" autocomplete="off" placeholder="Type what you heard…">
    <div class="pron-actions">
      <button type="button" class="pron-btn pron-purple" id="dict-listen">Listen</button>
      <button type="button" class="pron-btn pron-orange" id="dict-next">Next</button>
      <button type="submit" class="pron-btn pron-purple" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>
  <script>(function(){
    var audio=<?= json_encode((string)($q['audio'] ?? '')) ?>;
    var expected=<?= json_encode((string)($q['correct'] ?? '')) ?>;
    var input=document.getElementById('dict-input'),answer=document.getElementById('dict-answer'),submitted=false;
    var form=document.getElementById('dict-form');
    function listen(){if(audio){var a=new Audio(audio);a.play().catch(function(){});return;}if(!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(expected);u.lang='en-US';u.rate=.82;window.speechSynthesis.speak(u);}
    document.getElementById('dict-listen').onclick=listen;
    document.getElementById('dict-next').onclick=function(){if(submitted)return;submitted=true;answer.value=input.value.trim();form.submit();};
    input.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();if(!submitted){submitted=true;answer.value=input.value.trim();form.submit();}}});
    setTimeout(listen,150);
  })();</script>

  <?php elseif ($qType === 'multiple_choice'): ?>
  <?php $qzMcListen = ($q['question_type'] ?? 'text') === 'listen'; $qzMcImg = ($q['option_type'] ?? 'text') === 'image'; ?>
  <form method="post" id="mc-form">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <?php if ($qzMcListen): ?>
      <div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="qz-mc-listen">🔊 Listen</button></div>
    <?php else: ?>
      <div class="question"><?= h($q['question'] ?? $q['text'] ?? '') ?></div>
    <?php endif; ?>
    <?php if (!empty($q['image'])): ?>
      <div style="margin-bottom:14px;"><img src="<?= h($q['image']) ?>" alt="" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff;"></div>
    <?php endif; ?>
    <?php foreach ($q['options'] as $oi => $op): ?>
    <label class="option"><input type="radio" name="answer" value="<?= $oi ?>"
      onchange="setTimeout(function(){document.getElementById('mc-form').submit();},180)">
      <span class="letter"><?= chr(65 + $oi) ?></span>
      <?php if ($qzMcImg): ?>
        <img src="<?= h($op) ?>" alt="" style="max-width:100%;max-height:120px;object-fit:contain;border-radius:10px;">
      <?php else: ?><?= h($op) ?><?php endif; ?>
    </label>
    <?php endforeach; ?>
    <div class="actions"><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div>
  </form>
  <?php if ($qzMcListen): ?><script>(function(){
    var b=document.getElementById('qz-mc-listen');if(!b)return;
    var text=<?= json_encode((string)($q['question'] ?? $q['text'] ?? '')) ?>;
    var audioUrl=<?= json_encode((string)($q['audio'] ?? '')) ?>;
    function speak(){if(audioUrl){var a=new Audio(audioUrl);a.play().catch(function(){});return;}if(!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);}
    b.addEventListener('click',speak);setTimeout(speak,150);
  })();</script><?php endif; ?>

  <?php elseif ($qType === 'drag_drop'): ?>
  <?php
    $qzDdWords = $q['options'] ?? [];
    $qzDdShuffled = $qzDdWords;
    qz_shuffle($qzDdShuffled, $qzShuffleSeed + 577);
    $qzDdParts = preg_split('/(___)/u', $q['instruction'] ?? '', -1, PREG_SPLIT_DELIM_CAPTURE);
    $qzDdSlotCount = count($q['correct_words'] ?? []);
  ?>
  <form method="post" id="qz-dd-form">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <?php if (!empty($q['listen_enabled'])): ?>
      <div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="qz-dd-listen">🔊 Listen</button></div>
    <?php endif; ?>
    <?php if (!empty($q['image'])): ?>
      <div style="margin-bottom:14px;"><img src="<?= h($q['image']) ?>" alt="" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);"></div>
    <?php endif; ?>
    <div class="question"><?= h($q['question'] ?? 'Drag the words into the correct blanks.') ?></div>
    <div class="qz-dd-instruction"><?php
      $rSlots = 0;
      foreach ($qzDdParts as $p) {
        if ($p === '___' && $rSlots < $qzDdSlotCount) {
          echo '<div class="qz-dd-slot" data-slot-index="' . $rSlots . '"><span class="qz-dd-placeholder">Drop here</span></div>';
          $rSlots++;
        } elseif ($p !== '') {
          echo '<span class="qz-dd-text">' . h($p) . '</span>';
        }
      }
    ?></div>
    <div id="qz-dd-bank" class="qz-word-bank"><?php foreach ($qzDdShuffled as $w): ?>
      <span class="qz-chip qz-bank-chip" draggable="true" data-word="<?= h($w) ?>"><?= h($w) ?></span>
    <?php endforeach; ?></div>
    <div id="qz-dd-inputs"></div>
    <div class="actions">
      <button type="button" class="btn btn-purple" id="qz-dd-next">Next</button>
      <button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>
  <script>(function(){
    var form=document.getElementById('qz-dd-form'),bank=document.getElementById('qz-dd-bank'),inputs=document.getElementById('qz-dd-inputs');
    var slots=Array.from(document.querySelectorAll('.qz-dd-slot')),nextBtn=document.getElementById('qz-dd-next'),dragged=null,submitted=false;
    var listenText=<?= json_encode((string)($q['listen_text'] ?? $q['correct'] ?? '')) ?>,listenAudio=<?= json_encode((string)($q['audio'] ?? '')) ?>;
    function clearSlot(slot){var chip=slot.querySelector('.qz-built-chip');if(chip){bank.appendChild(createBankChip(chip.dataset.word||chip.textContent.trim()));chip.remove();}slot.classList.remove('qz-dd-filled');slot.innerHTML='<span class="qz-dd-placeholder">Drop here</span>';}
    function fillSlot(slot,word){clearSlot(slot);var chip=createBuiltChip(word);slot.innerHTML='';slot.appendChild(chip);slot.classList.add('qz-dd-filled');}
    function createBankChip(word){var c=document.createElement('span');c.className='qz-chip qz-bank-chip';c.draggable=true;c.dataset.word=word;c.textContent=word;c.addEventListener('dragstart',function(e){dragged=c;c.dataset.src='bank';});c.addEventListener('click',function(){var empty=slots.find(function(s){return !s.classList.contains('qz-dd-filled');});if(!empty)return;fillSlot(empty,word);c.remove();syncInputs();});return c;}
    function createBuiltChip(word){var c=document.createElement('span');c.className='qz-chip qz-built-chip';c.draggable=true;c.dataset.word=word;c.textContent=word;c.addEventListener('dragstart',function(e){dragged=c;c.dataset.src='slot';c.dataset.from=c.parentElement&&c.parentElement.dataset.slotIndex?c.parentElement.dataset.slotIndex:'';});c.addEventListener('click',function(){var slot=c.parentElement;if(slot)clearSlot(slot);syncInputs();});return c;}
    function syncInputs(){inputs.innerHTML='';slots.forEach(function(slot,index){var chip=slot.querySelector('.qz-built-chip');if(!chip)return;var inp=document.createElement('input');inp.type='hidden';inp.name='answer['+index+']';inp.value=chip.dataset.word||chip.textContent.trim();inputs.appendChild(inp);});}
    slots.forEach(function(slot){slot.addEventListener('dragover',function(e){e.preventDefault();slot.classList.add('qz-drag-over');});slot.addEventListener('dragleave',function(){slot.classList.remove('qz-drag-over');});slot.addEventListener('drop',function(e){e.preventDefault();slot.classList.remove('qz-drag-over');if(!dragged)return;var word=dragged.dataset.word||dragged.textContent.trim();if(dragged.dataset.src==='slot'&&dragged.parentElement===slot){dragged=null;return;}if(dragged.dataset.src==='slot'&&dragged.parentElement)clearSlot(dragged.parentElement);fillSlot(slot,word);if(dragged.dataset.src==='bank')dragged.remove();dragged=null;syncInputs();});slot.addEventListener('click',function(){if(!slot.classList.contains('qz-dd-filled'))return;clearSlot(slot);syncInputs();});});
    bank.addEventListener('dragover',function(e){e.preventDefault();});bank.addEventListener('drop',function(e){e.preventDefault();if(!dragged||dragged.dataset.src!=='slot')return;if(dragged.parentElement)clearSlot(dragged.parentElement);dragged=null;syncInputs();});
    Array.from(bank.querySelectorAll('.qz-bank-chip')).forEach(function(c){c.addEventListener('dragstart',function(e){dragged=c;c.dataset.src='bank';});c.addEventListener('click',function(){var empty=slots.find(function(s){return !s.classList.contains('qz-dd-filled');});if(!empty)return;fillSlot(empty,c.dataset.word||c.textContent.trim());c.remove();syncInputs();});});
    var listenBtn=document.getElementById('qz-dd-listen');
    if(listenBtn){listenBtn.addEventListener('click',function(){if(listenAudio){var a=new Audio(listenAudio);a.play().catch(function(){});return;}if(!listenText||!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(listenText);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);});setTimeout(function(){listenBtn.click();},150);}
    nextBtn.addEventListener('click',function(){if(submitted)return;submitted=true;syncInputs();form.submit();});
  })();</script>

  <?php elseif ($qType === 'unscramble'): ?>
  <?php
    $qzTokens = $q['options'] ?? [];
    $qzShuf   = $qzTokens;
    qz_shuffle($qzShuf, $qzShuffleSeed + 991);
  ?>
  <form method="post" id="us-form">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <?php if (!empty($q['listen_enabled'])): ?>
      <div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="us-listen">🔊 Listen</button></div>
    <?php endif; ?>
    <div class="question">Drag the words to build the sentence:</div>
    <div class="us-list" id="us-build" style="min-height:60px;border:2px dashed var(--line);border-radius:16px;padding:12px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;"></div>
    <div class="us-list" id="us-bank" style="min-height:60px;"><?php foreach ($qzShuf as $w): ?>
      <span class="us-chip" draggable="true" data-word="<?= h($w) ?>"><?= h($w) ?></span>
    <?php endforeach; ?></div>
    <input type="hidden" name="answer" id="us-answer">
    <div class="actions">
      <button type="button" class="btn btn-purple" id="us-next">Next</button>
      <button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>
  <script>(function(){
    var build=document.getElementById('us-build'),bank=document.getElementById('us-bank'),answer=document.getElementById('us-answer'),form=document.getElementById('us-form'),submitted=false,dragged=null;
    var listenText=<?= json_encode((string)($q['correct'] ?? '')) ?>,listenAudio=<?= json_encode((string)($q['audio'] ?? '')) ?>;
    function makeChip(word,src){var c=document.createElement('span');c.className='us-chip';c.draggable=true;c.dataset.word=word;c.textContent=word;c.addEventListener('dragstart',function(e){dragged={c:c,src:src};});c.addEventListener('click',function(){if(src==='bank'){build.appendChild(makeChip(word,'build'));c.remove();}else{bank.appendChild(makeChip(word,'bank'));c.remove();}});return c;}
    Array.from(bank.querySelectorAll('.us-chip')).forEach(function(c){c.addEventListener('dragstart',function(){dragged={c:c,src:'bank'};});c.addEventListener('click',function(){build.appendChild(makeChip(c.dataset.word,'build'));c.remove();});});
    [build,bank].forEach(function(zone){zone.addEventListener('dragover',function(e){e.preventDefault();});zone.addEventListener('drop',function(e){e.preventDefault();if(!dragged)return;var word=dragged.c.dataset.word;if(zone===build){build.appendChild(makeChip(word,'build'));dragged.c.remove();}else{bank.appendChild(makeChip(word,'bank'));dragged.c.remove();}dragged=null;});});
    var listenBtn=document.getElementById('us-listen');
    if(listenBtn){listenBtn.addEventListener('click',function(){if(listenAudio){var a=new Audio(listenAudio);a.play().catch(function(){});return;}if(!listenText||!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(listenText);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);});setTimeout(function(){listenBtn.click();},150);}
    document.getElementById('us-next').addEventListener('click',function(){if(submitted)return;submitted=true;answer.value=Array.from(build.querySelectorAll('.us-chip')).map(function(c){return c.dataset.word;}).join(' ');form.submit();});
  })();</script>

  <?php elseif ($qType === 'match' && !empty($q['pairs'])): ?>
  <?php $rights = array_column($q['pairs'], 'right'); shuffle($rights); ?>
  <form method="post">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <div class="question"><?= h($q['question'] ?? 'Match each item with the correct option.') ?></div>
    <div class="match-grid"><?php foreach ($q['pairs'] as $pi => $p): ?>
      <div class="match-left"><?= h($p['left'] ?? '') ?></div>
      <select class="select" name="answer[<?= $pi ?>]" required>
        <option value="">Choose</option>
        <?php foreach ($rights as $r): ?><option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?>
      </select>
    <?php endforeach; ?></div>
    <div class="actions">
      <button class="btn btn-purple" type="submit">Next</button>
      <button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>

  <?php elseif (in_array($qType, ['fill', 'writing_practice'], true)): ?>
  <?php
    $qzIsFill = $qType === 'fill';
    $qzQText  = (string)($q['question'] ?? $q['text'] ?? '');
    $qzHasBlanks = $qzIsFill && preg_match('/_{3,}/', $qzQText);
    $qzExpected  = array_values(array_filter(array_map('trim', preg_split('/\s*[|,]\s*/', (string)($q['correct'] ?? ''))), fn($v) => $v !== ''));
  ?>
  <form method="post" id="fill-form">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <?php if (!empty($q['audio'])): ?>
      <div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="fill-listen">🔊 Listen</button></div>
    <?php endif; ?>
    <?php if (!empty($q['image'])): ?>
      <div style="margin-bottom:14px;"><img src="<?= h($q['image']) ?>" alt="" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);"></div>
    <?php endif; ?>
    <?php if ($qzHasBlanks): ?>
      <div class="question qz-fill-inline">
        <?php $parts = preg_split('/_{3,}/', $qzQText); foreach ($parts as $pi => $part): ?>
          <span><?= h($part) ?></span>
          <?php if ($pi < count($parts) - 1): ?>
            <input class="qz-fill-blank" data-blank="<?= $pi ?>" type="text" autocomplete="off" placeholder="...">
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="answer" id="fill-combined">
    <?php else: ?>
      <div class="question"><?= h($qzQText) ?></div>
      <?php if ($qzIsFill): ?>
        <input class="qz-fill-input-lite" name="answer" autocomplete="off" placeholder="Type your answer">
      <?php else: ?>
        <textarea class="input" name="answer" required autocomplete="off" placeholder="Write your answer"></textarea>
      <?php endif; ?>
    <?php endif; ?>
    <div class="actions">
      <?php if (!$qzIsFill): ?>
        <button class="btn btn-purple" type="submit">Next</button>
      <?php endif; ?>
      <button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>
  <?php if (!empty($q['audio'])): ?><script>(function(){
    var b=document.getElementById('fill-listen');if(!b)return;
    var au=<?= json_encode((string)($q['audio'] ?? '')) ?>;
    b.addEventListener('click',function(){if(au){var a=new Audio(au);a.play().catch(function(){});return;}if(!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(<?= json_encode($qzQText) ?>);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);});
  })();</script><?php endif; ?>
  <?php if ($qzIsFill): ?><script>(function(){
    var form=document.getElementById('fill-form'),expected=<?= json_encode($qzExpected) ?>,combined=document.getElementById('fill-combined');
    var blanks=Array.from(form.querySelectorAll('.qz-fill-blank')),single=form.querySelector('input[name="answer"]'),submitted=false;
    function norm(v){return String(v||'').toLowerCase().trim().replace(/\s+/g,' ');}
    function values(){if(blanks.length)return blanks.map(function(i){return String(i.value||'').trim();});return[single?String(single.value||'').trim():''];}
    function sync(){if(combined)combined.value=values().join(' | ');}
    function isCorrect(){var cur=values();if(!cur.length||!expected.length)return false;for(var i=0;i<Math.max(cur.length,expected.length);i++)if(norm(cur[i]||'')!==norm(expected[i]||''))return false;return true;}
    function submitNow(){if(submitted)return;submitted=true;sync();form.submit();}
    if(blanks.length){blanks.forEach(function(inp){inp.addEventListener('input',function(){sync();if(isCorrect())submitNow();});inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();submitNow();}});});setTimeout(function(){try{blanks[0].focus();}catch(e){}},80);}else if(single){single.addEventListener('input',function(){sync();if(isCorrect())submitNow();});single.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();submitNow();}});setTimeout(function(){try{single.focus();single.select();}catch(e){}},80);}
    form.addEventListener('submit',sync);
  })();</script><?php endif; ?>

  <?php else: // Generic fallback ?>
  <form method="post">
    <input type="hidden" name="eval_answer" value="1">
    <input type="hidden" name="q_index" value="<?= $qIndex ?>">
    <input type="hidden" name="q_total" value="<?= $total ?>">
    <div class="question"><?= h($q['question'] ?? $q['text'] ?? '') ?></div>
    <input class="input" name="answer" required autocomplete="off" placeholder="Type your answer">
    <div class="actions">
      <button class="btn btn-purple" type="submit">Next</button>
      <button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button>
    </div>
  </form>
  <?php endif; ?>
</div><!-- /card -->

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

<?php if (in_array($step, ['exam','quiz'], true) && $timeLimitMin > 0): ?>
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


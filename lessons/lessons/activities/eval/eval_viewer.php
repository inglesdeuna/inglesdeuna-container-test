<?php
/**
 * eval_viewer.php — Vista del estudiante para presentar examen.
 * Acceso por token SIN usuario ni contraseña.
 * URL: eval_viewer.php?t={token}
 */

date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
require_once __DIR__ . '/exam_question_selector.php';
require_once __DIR__ . '/../quiz/_quiz_lib.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$token     = trim($_GET['t'] ?? '');
$step      = $_GET['step'] ?? 'welcome';   // welcome | quiz | result
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
    // Build a fake $link array from the exam
    $stmt = $pdo->prepare(
        "SELECT e.id AS exam_id, e.title AS exam_title, e.time_limit_min,
                1 AS max_attempts, '' AS instructions, e.cefr_level AS exam_cefr,
                e.status AS exam_status, e.modalities, e.unit_id,
                'group' AS link_type, '' AS student_name, '' AS student_doc,
                '' AS student_phone, '' AS student_email,
                9999 AS max_uses, 0 AS uses_count, NULL AS expires_at
         FROM eval_exams e WHERE e.id=? LIMIT 1"
    );
    $stmt->execute([$previewExamId]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) die('Examen no encontrado.');
    $link['id'] = 0; // no real link_id
    $token = 'PREVIEW_' . $previewExamId;
}

// ─── Validar token ────────────────────────────────────────────────────────────
$link = $link ?? null;
$linkErrorReason = '';
if (!$isPreview && $token !== '') {
    // Important: do not validate expires_at with DB NOW(). Render/Postgres may run
    // in UTC while admin dates are selected in Colombia time. Fetch by token first,
    // then validate using America/Bogota so links remain valid through the selected day.
    $stmt = $pdo->prepare(
        "SELECT l.*, e.title AS exam_title, e.time_limit_min, e.max_attempts,
                e.instructions, e.cefr_level AS exam_cefr, e.status AS exam_status,
                e.modalities, e.unit_id
         FROM eval_links l
         JOIN eval_exams e ON e.id = l.exam_id
         WHERE l.token = ?
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($link) {
        $expiresAt = trim((string)($link['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            $expiresTs = strtotime($expiresAt);
            if ($expiresTs !== false && $expiresTs < time()) {
                $linkErrorReason = 'expired';
                $link = null;
            }
        }
        if ($link && (int)($link['uses_count'] ?? 0) >= (int)($link['max_uses'] ?? 1)) {
            $linkErrorReason = 'uses';
            $link = null;
        }
    } else {
        $linkErrorReason = 'not_found';
    }
}

if (!$link && !$isPreview) {
    http_response_code(404);
    $detail = 'Este link de evaluación no es válido, ya expiró o alcanzó el límite de usos.';
    if ($linkErrorReason === 'expired') $detail = 'Este link de evaluación ya expiró.';
    if ($linkErrorReason === 'uses') $detail = 'Este link ya alcanzó el límite de usos permitidos.';
    if ($linkErrorReason === 'not_found') $detail = 'Este link de evaluación no existe o fue eliminado.';
    ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Link inválido</title>
    <style>body{font-family:Arial,sans-serif;text-align:center;padding:60px;background:#fef3cd;color:#664d03;}
    h1{font-size:28px;}p{font-size:16px;}</style></head><body>
    <h1>⚠️ Link inválido o expirado</h1>
    <p><?= h($detail) ?></p>
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
            // Add unit_ids so selector can pull activity questions.
            // Add assignment_id so qz_build() uses same seed as quiz/viewer.
            $examUnitIds = [];
            if (!empty($link['unit_id'])) {
                $examUnitIds = [(int) $link['unit_id']];
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

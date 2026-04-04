<?php
/**
 * wp_save_response.php
 * POST endpoint — saves open-writing responses from students for teacher review.
 *
 * POST body (multipart/form-data or application/x-www-form-urlencoded):
 *   activity_id   — activity UUID
 *   unit_id       — unit UUID
 *   assignment_id — assignment UUID
 *   responses     — JSON array of { question_id, question_text, response_text, max_points }
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

/* ── Auth ──────────────────────────────────────────────── */
$studentId = trim((string) ($_SESSION['student_id'] ?? ''));
if ($studentId === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';

/* ── Input ─────────────────────────────────────────────── */
$activityId   = trim((string) ($_POST['activity_id']   ?? ''));
$unitId       = trim((string) ($_POST['unit_id']       ?? ''));
$assignmentId = trim((string) ($_POST['assignment_id'] ?? ''));
$responsesRaw = trim((string) ($_POST['responses']     ?? '[]'));

if ($activityId === '') {
    echo json_encode(['success' => false, 'error' => 'Missing activity_id']);
    exit;
}

$responses = json_decode($responsesRaw, true);
if (!is_array($responses) || count($responses) === 0) {
    echo json_encode(['success' => false, 'error' => 'No responses provided']);
    exit;
}

/* ── Ensure table exists ───────────────────────────────── */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS writing_practice_responses (
            id            TEXT        NOT NULL,
            activity_id   TEXT        NOT NULL,
            student_id    TEXT        NOT NULL,
            assignment_id TEXT        NOT NULL DEFAULT '',
            unit_id       TEXT        NOT NULL DEFAULT '',
            question_id   TEXT        NOT NULL,
            question_text TEXT        NOT NULL DEFAULT '',
            response_text TEXT        NOT NULL DEFAULT '',
            score         INTEGER     DEFAULT NULL,
            max_points    INTEGER     NOT NULL DEFAULT 10,
            graded_by     TEXT        DEFAULT NULL,
            graded_at     TIMESTAMPTZ DEFAULT NULL,
            created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            PRIMARY KEY (activity_id, student_id, assignment_id, question_id)
        )
    ");
} catch (Throwable $e) {
    // Table may already exist with different constraints – continue
}

/* ── Upsert each response ──────────────────────────────── */
$pdo->beginTransaction();
try {
    foreach ($responses as $r) {
        if (!is_array($r)) { continue; }
        $qId       = trim((string) ($r['question_id']   ?? ''));
        $qText     = trim((string) ($r['question_text'] ?? ''));
        $rText     = trim((string) ($r['response_text'] ?? ''));
        $maxPts    = max(1, (int) ($r['max_points']    ?? 10));

        if ($qId === '' || $rText === '') { continue; }

        $rowId = substr(md5($activityId . $studentId . $assignmentId . $qId), 0, 20);

        $stmt = $pdo->prepare("
            INSERT INTO writing_practice_responses
                (id, activity_id, student_id, assignment_id, unit_id, question_id, question_text, response_text, max_points, updated_at)
            VALUES
                (:id, :activity_id, :student_id, :assignment_id, :unit_id, :question_id, :question_text, :response_text, :max_points, NOW())
            ON CONFLICT (activity_id, student_id, assignment_id, question_id)
            DO UPDATE SET
                response_text = EXCLUDED.response_text,
                question_text = EXCLUDED.question_text,
                max_points    = EXCLUDED.max_points,
                score         = NULL,
                graded_by     = NULL,
                graded_at     = NULL,
                updated_at    = NOW()
        ");
        $stmt->execute([
            'id'            => $rowId,
            'activity_id'   => $activityId,
            'student_id'    => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id'       => $unitId,
            'question_id'   => $qId,
            'question_text' => $qText,
            'response_text' => $rText,
            'max_points'    => $maxPts,
        ]);
    }

    /* Mark the activity as "pending grading" (completion_percent = 0 until teacher grades) */
    if ($unitId !== '' && $assignmentId !== '') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS student_activity_results (
              student_id         TEXT NOT NULL,
              assignment_id      TEXT NOT NULL,
              unit_id            TEXT NOT NULL,
              activity_id        TEXT NOT NULL,
              activity_type      TEXT NOT NULL DEFAULT '',
              completion_percent INTEGER NOT NULL DEFAULT 0,
              errors_count       INTEGER NOT NULL DEFAULT 0,
              total_count        INTEGER NOT NULL DEFAULT 0,
              attempts_count     INTEGER NOT NULL DEFAULT 1,
              updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              PRIMARY KEY (student_id, assignment_id, unit_id, activity_id)
            )
        ");
        $stmtSar = $pdo->prepare("
            INSERT INTO student_activity_results
                (student_id, assignment_id, unit_id, activity_id, activity_type, completion_percent,
                 errors_count, total_count, attempts_count, updated_at)
            VALUES
                (:student_id, :assignment_id, :unit_id, :activity_id, 'writing_practice', 0,
                 0, :total, 1, NOW())
            ON CONFLICT (student_id, assignment_id, unit_id, activity_id)
            DO UPDATE SET
                attempts_count     = student_activity_results.attempts_count + 1,
                updated_at         = NOW()
        ");
        $stmtSar->execute([
            'student_id'    => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id'       => $unitId,
            'activity_id'   => $activityId,
            'total'         => count($responses),
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error']);
}

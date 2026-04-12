<?php
session_start();

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password'])) {
    header('Location: change_password_student.php');
    exit;
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$studentId    = trim((string) ($_SESSION['student_id'] ?? ''));
// Release session lock early so parallel fetch requests from activity iframes
// are not blocked waiting for the session file lock.
session_write_close();

if ($assignmentId === '') {
    die('Assignment not specified.');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_upper(string $value): string
{
    $normalized = strtr($value, [
        'á' => 'Á',
        'é' => 'É',
        'í' => 'Í',
        'ó' => 'Ó',
        'ú' => 'Ú',
        'ü' => 'Ü',
        'ñ' => 'Ñ',
    ]);

    return function_exists('mb_strtoupper') ? mb_strtoupper($normalized, 'UTF-8') : strtoupper($normalized);
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    static $cachedPdo = null;
    static $loaded = false;

    if ($loaded) {
        return $cachedPdo;
    }

    $loaded = true;

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        if (isset($pdo) && $pdo instanceof PDO) {
            $cachedPdo = $pdo;
        }
    } catch (Throwable $e) {
        return null;
    }

    return $cachedPdo;
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_student_performance_tables(PDO $pdo): void
{
    try {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_unit_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              quiz_errors INTEGER NOT NULL DEFAULT 0,\n              quiz_total INTEGER NOT NULL DEFAULT 0,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id)\n            )\n        ");
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_activity_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              activity_id TEXT NOT NULL,\n              activity_type TEXT NOT NULL DEFAULT '',\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              errors_count INTEGER NOT NULL DEFAULT 0,\n              total_count INTEGER NOT NULL DEFAULT 0,\n              attempts_count INTEGER NOT NULL DEFAULT 1,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id, activity_id)\n            )\n        ");
        $pdo->exec("ALTER TABLE student_activity_results ADD COLUMN IF NOT EXISTS attempts_count INTEGER NOT NULL DEFAULT 1");
    } catch (Throwable $e) {
    }
}

function ensure_teacher_quiz_unlocks_table(PDO $pdo): void
{
    try {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS teacher_quiz_unlocks (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              enabled_by_teacher_id TEXT NOT NULL,\n              enabled_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id)\n            )\n        ");
    } catch (Throwable $e) {
    }
}

function is_quiz_enabled_by_teacher(PDO $pdo, string $studentId, string $assignmentId, string $unitId): bool
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM teacher_quiz_unlocks\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n              AND unit_id = :unit_id\n            LIMIT 1\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function save_student_activity_performance(PDO $pdo, string $studentId, string $assignmentId, string $unitId, string $activityId, string $activityType, int $completionPercent, int $errorsCount, int $totalCount): void
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '' || $activityId === '') {
        return;
    }

    try {
        $hasAttemptsColumn = table_has_column($pdo, 'student_activity_results', 'attempts_count');
        $cleanType = strtolower(trim($activityType));
        $isQuizType = ($cleanType === 'quiz');

        // After a quiz attempt starts, learners can still study but score-adding
        // activities are locked to avoid changing the graded baseline.
        if (!$isQuizType) {
            try {
                $quizLockStmt = $pdo->prepare("\n                    SELECT 1\n                    FROM student_activity_results\n                    WHERE student_id = :student_id\n                      AND assignment_id = :assignment_id\n                      AND unit_id = :unit_id\n                      AND LOWER(activity_type) = 'quiz'\n                    LIMIT 1\n                ");
                $quizLockStmt->execute([
                    'student_id' => $studentId,
                    'assignment_id' => $assignmentId,
                    'unit_id' => $unitId,
                ]);
                if ((bool) $quizLockStmt->fetchColumn()) {
                    return;
                }
            } catch (Throwable $e) {
            }
        }

        $cleanErrors = max(0, $errorsCount);
        $cleanTotal = max(0, $totalCount);
        if ($cleanTotal > 0 && $cleanErrors > $cleanTotal) {
            $cleanErrors = $cleanTotal;
        }
        $cleanPercent = max(0, min(100, $completionPercent));
        if ($cleanTotal > 0) {
            $cleanPercent = max(0, min(100, (int) round((($cleanTotal - $cleanErrors) / $cleanTotal) * 100)));
        }

        $existingSql = $hasAttemptsColumn
            ? "\n            SELECT completion_percent, errors_count, total_count, attempts_count, updated_at\n            FROM student_activity_results\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n              AND unit_id = :unit_id\n              AND activity_id = :activity_id\n            LIMIT 1\n        "
            : "\n            SELECT completion_percent, errors_count, total_count, updated_at\n            FROM student_activity_results\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n              AND unit_id = :unit_id\n              AND activity_id = :activity_id\n            LIMIT 1\n        ";

        $existingStmt = $pdo->prepare($existingSql);
        $existingStmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
            'activity_id' => $activityId,
        ]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($existing)) {
            $insertSql = $hasAttemptsColumn
                ? "\n                INSERT INTO student_activity_results (student_id, assignment_id, unit_id, activity_id, activity_type, completion_percent, errors_count, total_count, attempts_count, updated_at)\n                VALUES (:student_id, :assignment_id, :unit_id, :activity_id, :activity_type, :completion_percent, :errors_count, :total_count, 1, NOW())\n            "
                : "\n                INSERT INTO student_activity_results (student_id, assignment_id, unit_id, activity_id, activity_type, completion_percent, errors_count, total_count, updated_at)\n                VALUES (:student_id, :assignment_id, :unit_id, :activity_id, :activity_type, :completion_percent, :errors_count, :total_count, NOW())\n            ";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                'student_id' => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id' => $unitId,
                'activity_id' => $activityId,
                'activity_type' => $cleanType,
                'completion_percent' => $cleanPercent,
                'errors_count' => $cleanErrors,
                'total_count' => $cleanTotal,
            ]);
            return;
        }

        $existingErrors = max(0, (int) ($existing['errors_count'] ?? 0));
        $existingTotal = max(0, (int) ($existing['total_count'] ?? 0));
        $existingAttempts = 1;
        if ($hasAttemptsColumn) {
            $existingAttempts = max(1, (int) ($existing['attempts_count'] ?? 1));
        } elseif ($cleanTotal > 0) {
            // Fallback inference when attempts_count column is unavailable.
            $existingAttempts = max(1, (int) floor($existingTotal / $cleanTotal));
        }

        $maxAttempts = $isQuizType ? 3 : 2;

        // Quiz policy: one attempt per day and max 3 attempts in total.
        if ($isQuizType) {
            $updatedAtRaw = trim((string) ($existing['updated_at'] ?? ''));
            if ($updatedAtRaw !== '') {
                try {
                    $existingDate = (new DateTimeImmutable($updatedAtRaw))->format('Y-m-d');
                    $todayDate = (new DateTimeImmutable('now'))->format('Y-m-d');
                    if ($existingAttempts >= 1 && $existingDate === $todayDate) {
                        return;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        if ($existingAttempts >= $maxAttempts) {
            return;
        }

        $newErrors = $existingErrors + $cleanErrors;
        $newTotal = $existingTotal + $cleanTotal;
        if ($newTotal > 0 && $newErrors > $newTotal) {
            $newErrors = $newTotal;
        }
        $newPercent = $newTotal > 0
            ? max(0, min(100, (int) round((($newTotal - $newErrors) / $newTotal) * 100)))
            : 0;

        if ($hasAttemptsColumn) {
            $updateStmt = $pdo->prepare("\n                UPDATE student_activity_results\n                SET activity_type = :activity_type,\n                    completion_percent = :completion_percent,\n                    errors_count = :errors_count,\n                    total_count = :total_count,\n                    attempts_count = :attempts_count,\n                    updated_at = NOW()\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n            ");
            $updateStmt->execute([
                'activity_type' => $cleanType,
                'completion_percent' => $newPercent,
                'errors_count' => $newErrors,
                'total_count' => $newTotal,
                'attempts_count' => min($maxAttempts, $existingAttempts + 1),
                'student_id' => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id' => $unitId,
                'activity_id' => $activityId,
            ]);
        } else {
            $updateStmt = $pdo->prepare("\n                UPDATE student_activity_results\n                SET activity_type = :activity_type,\n                    completion_percent = :completion_percent,\n                    errors_count = :errors_count,\n                    total_count = :total_count,\n                    updated_at = NOW()\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n            ");
            $updateStmt->execute([
                'activity_type' => $cleanType,
                'completion_percent' => $newPercent,
                'errors_count' => $newErrors,
                'total_count' => $newTotal,
                'student_id' => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id' => $unitId,
                'activity_id' => $activityId,
            ]);
        }
    } catch (Throwable $e) {
    }
}

function delete_student_activity_performance(PDO $pdo, string $studentId, string $assignmentId, string $unitId, string $activityId, string $activityType = ''): void
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '' || $activityId === '') {
        return;
    }

    try {
        if ($activityType !== '') {
            $stmt = $pdo->prepare("\n                DELETE FROM student_activity_results\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n                  AND activity_type = :activity_type\n            ");

            $stmt->execute([
                'student_id' => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id' => $unitId,
                'activity_id' => $activityId,
                'activity_type' => trim($activityType),
            ]);

            return;
        }

        $stmt = $pdo->prepare("\n            DELETE FROM student_activity_results\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n              AND unit_id = :unit_id\n              AND activity_id = :activity_id\n        ");

        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
            'activity_id' => $activityId,
        ]);
    } catch (Throwable $e) {
    }
}

function aggregate_student_activity_performance(PDO $pdo, string $studentId, string $assignmentId, string $unitId): ?array
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT SUM(errors_count) AS errors_sum, SUM(total_count) AS total_sum\n            FROM student_activity_results\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n              AND unit_id = :unit_id\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $total = (int) ($row['total_sum'] ?? 0);
        $errors = (int) ($row['errors_sum'] ?? 0);
        if ($total < 0) {
            $total = 0;
        }
        if ($errors < 0) {
            $errors = 0;
        }
        if ($total > 0 && $errors > $total) {
            $errors = $total;
        }

        $percent = $total > 0
            ? max(0, min(100, (int) round((($total - $errors) / $total) * 100)))
            : 0;

        return [
            'completion_percent' => $percent,
            'quiz_errors' => $errors,
            'quiz_total' => $total,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function save_student_unit_performance(PDO $pdo, string $studentId, string $assignmentId, string $unitId, int $completionPercent, int $quizErrors, int $quizTotal): void
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO student_unit_results (student_id, assignment_id, unit_id, completion_percent, quiz_errors, quiz_total, updated_at)\n            VALUES (:student_id, :assignment_id, :unit_id, :completion_percent, :quiz_errors, :quiz_total, NOW())\n            ON CONFLICT (student_id, assignment_id, unit_id)\n            DO UPDATE SET\n              completion_percent = EXCLUDED.completion_percent,\n              quiz_errors = EXCLUDED.quiz_errors,\n              quiz_total = EXCLUDED.quiz_total,\n              updated_at = NOW()\n        ");

        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
            'completion_percent' => max(0, min(100, $completionPercent)),
            'quiz_errors' => max(0, $quizErrors),
            'quiz_total' => max(0, $quizTotal),
        ]);
    } catch (Throwable $e) {
    }
}

function load_student_unit_results(PDO $pdo, string $studentId, string $assignmentId): array
{
    if ($studentId === '' || $assignmentId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT unit_id, completion_percent, quiz_errors, quiz_total\n            FROM student_unit_results\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mapped = [];
        foreach ($rows as $row) {
            $unitId = (string) ($row['unit_id'] ?? '');
            if ($unitId === '') {
                continue;
            }
            $mapped[$unitId] = [
                'completion_percent' => (int) ($row['completion_percent'] ?? 0),
                'quiz_errors' => (int) ($row['quiz_errors'] ?? 0),
                'quiz_total' => (int) ($row['quiz_total'] ?? 0),
            ];
        }

        return $mapped;
    } catch (Throwable $e) {
        return [];
    }
}

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    try {
        $stmt = $pdo->prepare("\n            SELECT sa.id, sa.student_id, sa.teacher_id, sa.course_id, sa.period, sa.program, sa.unit_id, sa.level_id,\n                   t.name AS teacher_name,\n                   c.name AS course_name\n            FROM student_assignments sa\n            LEFT JOIN teachers t ON t.id = sa.teacher_id\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            WHERE sa.id = :id\n            LIMIT 1\n        ");
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_units_for_assignment(PDO $pdo, array $assignment): array
{
    $courseId = trim((string) ($assignment['course_id'] ?? ''));
    if ($courseId === '') {
        return [];
    }

    $program = trim((string) ($assignment['program'] ?? ''));

    try {
        $orderBy = table_has_column($pdo, 'units', 'position') ? 'ORDER BY position ASC, id ASC' : 'ORDER BY id ASC';

        if ($program === 'english' && table_has_column($pdo, 'units', 'phase_id')) {
            $stmt = $pdo->prepare("SELECT id, name FROM units WHERE phase_id::text = :course_id {$orderBy}");
            $stmt->execute(['course_id' => $courseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($rows)) {
                return $rows;
            }
        }

        $stmt = $pdo->prepare("SELECT id, name FROM units WHERE course_id::text = :course_id {$orderBy}");
        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_activities_for_unit(PDO $pdo, string $unitId): array
{
    if ($unitId === '') {
        return [];
    }

    try {
        $orderBy = table_has_column($pdo, 'activities', 'position')
            ? 'ORDER BY COALESCE(position, 0) ASC, id ASC'
            : 'ORDER BY id ASC';

        $stmt = $pdo->prepare("SELECT id, type, data FROM activities WHERE unit_id::text = :unit_id {$orderBy}");
        $stmt->execute(['unit_id' => $unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_activity_base_path(string $type): ?string
{
    if (!preg_match('/^[a-z0-9_]+$/i', $type)) {
        return null;
    }

    $absolute = __DIR__ . '/../activities/' . $type;
    if (!is_dir($absolute)) {
        return null;
    }

    return '../activities/' . rawurlencode($type);
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

ensure_student_performance_tables($pdo);
ensure_teacher_quiz_unlocks_table($pdo);

$assignment = load_assignment($pdo, $assignmentId);
if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
    die('You do not have access to this course.');
}

$selectedUnitId = trim((string) ($_GET['unit'] ?? (string) ($assignment['unit_id'] ?? '')));
$rawTotal = isset($_GET['activity_total'])
    ? (int) $_GET['activity_total']
    : (isset($_GET['quiz_total']) ? (int) $_GET['quiz_total'] : -1);
$rawErrors = isset($_GET['activity_errors'])
    ? (int) $_GET['activity_errors']
    : (isset($_GET['quiz_errors']) ? (int) $_GET['quiz_errors'] : -1);
$rawPercent = isset($_GET['activity_percent'])
    ? (int) $_GET['activity_percent']
    : (isset($_GET['quiz_percent']) ? (int) $_GET['quiz_percent'] : -1);
$activityResultId = trim((string) ($_GET['activity_id'] ?? ($_GET['quiz_activity_id'] ?? '')));
$activityType = trim((string) ($_GET['activity_type'] ?? 'quiz'));
$resetActivityId = trim((string) ($_GET['reset_activity_id'] ?? ''));
$resetActivityType = trim((string) ($_GET['reset_activity_type'] ?? ''));
$shouldResetActivity = isset($_GET['reset_activity']) && $_GET['reset_activity'] === '1';

if ($selectedUnitId !== '' && $shouldResetActivity && $resetActivityId !== '') {
    delete_student_activity_performance(
        $pdo,
        $studentId,
        $assignmentId,
        $selectedUnitId,
        $resetActivityId,
        $resetActivityType
    );

    $aggregated = aggregate_student_activity_performance($pdo, $studentId, $assignmentId, $selectedUnitId);
    if (is_array($aggregated)) {
        save_student_unit_performance(
            $pdo,
            $studentId,
            $assignmentId,
            $selectedUnitId,
            (int) ($aggregated['completion_percent'] ?? 0),
            (int) ($aggregated['quiz_errors'] ?? 0),
            (int) ($aggregated['quiz_total'] ?? 0)
        );
    }

    $redirectQuery = [
        'assignment' => $assignmentId,
        'unit' => $selectedUnitId,
        'step' => (string) max(0, (int) ($_GET['step'] ?? 0)),
    ];
    header('Location: student_course.php?' . http_build_query($redirectQuery));
    exit;
}

if ($selectedUnitId !== '' && $rawTotal >= 0) {
    $activityTotal = max(0, $rawTotal);
    $activityErrors = max(0, $rawErrors);
    if ($activityTotal > 0 && $activityErrors > $activityTotal) {
        $activityErrors = $activityTotal;
    }

    $activityPercent = $activityTotal > 0
        ? max(0, min(100, (int) round((($activityTotal - $activityErrors) / $activityTotal) * 100)))
        : max(0, min(100, $rawPercent));

    if ($activityResultId !== '') {
        save_student_activity_performance($pdo, $studentId, $assignmentId, $selectedUnitId, $activityResultId, $activityType, $activityPercent, $activityErrors, $activityTotal);
        $aggregated = aggregate_student_activity_performance($pdo, $studentId, $assignmentId, $selectedUnitId);
        if (is_array($aggregated)) {
            save_student_unit_performance(
                $pdo,
                $studentId,
                $assignmentId,
                $selectedUnitId,
                (int) ($aggregated['completion_percent'] ?? 0),
                (int) ($aggregated['quiz_errors'] ?? 0),
                (int) ($aggregated['quiz_total'] ?? 0)
            );
        } else {
            save_student_unit_performance($pdo, $studentId, $assignmentId, $selectedUnitId, $activityPercent, $activityErrors, $activityTotal);
        }
    } else {
        save_student_unit_performance($pdo, $studentId, $assignmentId, $selectedUnitId, $activityPercent, $activityErrors, $activityTotal);
    }
}

$allUnits = load_units_for_assignment($pdo, $assignment);
$unitResults = load_student_unit_results($pdo, $studentId, $assignmentId);
$courseName = trim((string) ($assignment['course_name'] ?? 'Course'));
if ($courseName === '') {
    $courseName = 'Course';
}
$courseName = app_upper($courseName);
$teacherName = trim((string) ($assignment['teacher_name'] ?? 'Teacher'));
$teacherName = app_upper($teacherName);

/* ---- Determine active unit ---- */
if ($selectedUnitId === '' && !empty($allUnits)) {
    $selectedUnitId = (string) ($allUnits[0]['id'] ?? '');
}

$selectedUnitName = 'Unit';
foreach ($allUnits as $_u) {
    if ((string) ($_u['id'] ?? '') === $selectedUnitId) {
        $selectedUnitName = (string) ($_u['name'] ?? 'Unit');
        break;
    }
}
$selectedUnitName = app_upper($selectedUnitName);

/* ---- Activities for selected unit ---- */
$step = max(0, (int) ($_GET['step'] ?? 0));
$activities = $selectedUnitId !== '' ? load_activities_for_unit($pdo, $selectedUnitId) : [];

// --- Separate worksheet (flipbook) activities from the sequential flow ---
$worksheets = [];
$activities = array_values(array_filter($activities, function ($act) use (&$worksheets) {
    if (strtolower(trim((string) ($act['type'] ?? ''))) === 'flipbooks') {
        $actData = json_decode((string) ($act['data'] ?? ''), true);
        $pdfUrl  = isset($actData['pdf_url']) ? trim((string) $actData['pdf_url']) : '';
        if ($pdfUrl !== '') {
            $worksheets[] = [
                'id'        => (string) ($act['id'] ?? ''),
                'title'     => trim((string) ($actData['title'] ?? '')) ?: 'Worksheet',
                'serve_url' => '/lessons/lessons/activities/flipbooks/serve_pdf.php?id=' . rawurlencode((string) ($act['id'] ?? '')),
            ];
        }
        return false;
    }
    return true;
}));
// -------------------------------------------------------------------------

$topWorksheetDownloadUrl = !empty($worksheets) ? (string) ($worksheets[0]['serve_url'] ?? '') : '';

$total = count($activities);

$quizStepIndex = null;
foreach ($activities as $activityIndex => $activityItem) {
    $activityType = strtolower(trim((string) ($activityItem['type'] ?? '')));
    if ($activityType === 'quiz') {
        $quizStepIndex = $activityIndex;
        break;
    }
}

$quizHref = $quizStepIndex !== null
    ? 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '&step=' . urlencode((string) $quizStepIndex)
    : '';

if ($quizHref === '' && $selectedUnitId !== '') {
    $quizReturnTo = '../../academic/student_course.php?' . http_build_query([
        'assignment' => $assignmentId,
        'unit' => $selectedUnitId,
        'step' => (string) max(9999, $total),
    ]);
    $quizHref = '../activities/quiz/viewer.php?' . http_build_query([
        'unit' => $selectedUnitId,
        'assignment' => $assignmentId,
        'return_to' => $quizReturnTo,
    ]);
}

$isCompleted = $total > 0 && $step >= $total;
$current = (!$isCompleted && $total > 0) ? $activities[$step] : null;
$prevStep = max(0, $step - 1);
$nextStep = $step + 1;
$hasPrev = $step > 0;
$hasNext = $nextStep < $total;
$isLastActivity = !$isCompleted && $total > 0 && $step === ($total - 1);

$activityTypeLabels = [
    'flashcards' => 'Flashcards', 'memory_cards' => 'Memory Cards', 'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice', 'video_comprehension' => 'Video Comprehension',
    'flipbooks' => 'Video Lesson', 'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation', 'listen_order' => 'Listen & Order',
    'order_sentences' => 'Order the Sentences',
    'drag_drop' => 'Drag & Drop', 'match' => 'Match',
    'external' => 'External', 'powerpoint' => 'PowerPoint',
    'crossword' => 'Crossword Puzzle',
];

$viewerHref = null;
$currentTypeLabel = 'Activity';
if ($current) {
    $type = (string) ($current['type'] ?? '');
    $activityPath = get_activity_base_path($type);
    if ($activityPath) {
        $returnUrl = '../../academic/student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId);
        $query = http_build_query([
            'id'         => (string) ($current['id'] ?? ''),
            'unit'       => $selectedUnitId,
            'embedded'   => '1',
            'from'       => 'student_course',
            'assignment' => $assignmentId,
            'return_to'  => $returnUrl,
        ]);
        $viewerHref = $activityPath . '/viewer.php?' . $query;
    }
    $currentType = strtolower(trim($type));
    $currentTypeLabel = $activityTypeLabels[$currentType] ?? ucwords(str_replace('_', ' ', $type));
}

$unitResult = $unitResults[$selectedUnitId] ?? ['completion_percent' => 0, 'quiz_errors' => 0, 'quiz_total' => 0];
$completionPercent = (int) ($unitResult['completion_percent'] ?? 0);
$quizErrors = (int) ($unitResult['quiz_errors'] ?? 0);
$quizTotal = (int) ($unitResult['quiz_total'] ?? 0);
$hasUnitResult = $quizTotal > 0;
$passThreshold = 60;
$isPassingScore = $hasUnitResult && $completionPercent >= $passThreshold;
$quizEnabledByTeacher = is_quiz_enabled_by_teacher($pdo, $studentId, $assignmentId, $selectedUnitId);
$canAccessQuiz = $isPassingScore || $quizEnabledByTeacher;
$scoreToneClass = $isPassingScore ? 'score-pass' : 'score-fail';
$resultStatusLabel = $isPassingScore ? 'PASS' : 'FAIL';
$resultStatusClass = $isPassingScore ? 'result-badge-pass' : 'result-badge-fail';

$backHref = 'student_dashboard.php';
$completedStep = max(9999, $total);
$completedHref = 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '&step=' . urlencode((string) $completedStep);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($currentTypeLabel . ' — ' . $courseName); ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');
:root{
    --bg:#fff8e6;
    --card:#ffffff;
    --line:#dcc4f0;
    --title:#a855c8;
    --text:#f14902;
    --muted:#b8551f;
    --salmon:#f14902;
    --salmon-dark:#d33d00;
    --salmon-soft:#eddeff;
    --shadow:0 10px 24px rgba(120,40,160,.12);
    --shadow-sm:0 2px 8px rgba(0,0,0,.06);
    --radius:18px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{margin:0;font-family:'Nunito','Segoe UI',sans-serif;background:linear-gradient(145deg,#fff8e6 0%,#fdeaff 55%,#f0e0ff 100%);color:var(--text)}

.topbar{
    background:linear-gradient(180deg,#f14902,#d33d00);
    color:#fff;
    padding:12px 24px;
}
.topbar-inner{
    max-width:1280px;
    margin:0 auto;
    display:grid;
    grid-template-columns:180px 1fr 320px;
    align-items:center;
    gap:12px;
}
.top-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    min-width:0;
}
.top-btn{
    display:inline-flex;align-items:center;justify-content:center;
    gap:6px;
    padding:13px 22px;border-radius:12px;text-decoration:none;
    font-size:15px;font-weight:700;color:#fff;white-space:nowrap;
    background:linear-gradient(180deg,#a855f7,#7c3aed);
    box-shadow:var(--shadow-sm);
    transition:filter .15s,transform .15s;
}
.top-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.topbar-title{font-size:28px;font-weight:700;text-align:center;font-family:'Fredoka','Trebuchet MS',sans-serif;letter-spacing:.01em}

.page{max-width:1280px;margin:0 auto;padding:12px 16px 20px}
.content{display:flex;flex-direction:column;gap:14px;min-width:0}

.viewer-shell{
    background:var(--card);border:1px solid var(--line);
    border-radius:22px;box-shadow:var(--shadow);padding:18px;
    display:flex;
    flex-direction:column;
    min-height:0;
}
.viewer-top{
    display:flex;align-items:center;justify-content:space-between;
    gap:14px;margin-bottom:14px;flex-wrap:wrap;
}
.section-title{
    display:flex;align-items:center;gap:12px;
    font-size:22px;font-weight:800;color:var(--muted);
}
.section-title::after{
    content:"";flex:1;height:2px;min-width:60px;
    background:linear-gradient(90deg,var(--line) 0%,transparent 100%);
}
.act-badge{
    display:inline-flex;align-items:center;padding:7px 12px;
    border-radius:999px;background:var(--salmon-soft);color:var(--salmon);
    font-size:12px;font-weight:800;text-transform:uppercase;
}
.frame-wrap{
    border-radius:14px;overflow:hidden;background:#fff;
    border:1px solid var(--line);box-shadow:var(--shadow-sm);
    min-height:300px;
    flex:1 1 auto;
}
.frame-wrap iframe{display:block;width:100%;height:clamp(260px, calc(100vh - 390px), 640px);min-height:260px;border:0;background:#fff}

.controls{
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;padding-top:12px;padding-bottom:4px;
    position:sticky;
    bottom:0;
    background:linear-gradient(180deg, rgba(255,255,255,0) 0%, var(--card) 14%, var(--card) 100%);
    z-index:3;
}
.step-counter{font-size:13px;font-weight:700;color:var(--muted);text-align:center}
.step-counter strong{color:var(--salmon)}

.ctrl-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    min-width:130px;padding:12px 18px;border-radius:12px;text-decoration:none;
    color:#fff;font-size:14px;font-weight:700;
    background:linear-gradient(180deg,#f14902,#d33d00);
    box-shadow:var(--shadow-sm);transition:filter .15s,transform .15s;
}
.ctrl-btn.blue{background:linear-gradient(180deg,#c97de8,#8b1a9a)}
.ctrl-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.ctrl-btn.disabled{opacity:.38;pointer-events:none}

/* units sidebar strip */
.units-strip{
    display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;
}
.unit-chip{
    display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;
    font-size:13px;font-weight:700;text-decoration:none;
    background:var(--card);border:2px solid var(--line);color:var(--muted);
    transition:background .15s,border-color .15s;
}
.unit-chip:hover{border-color:var(--salmon);color:var(--salmon)}
.unit-chip.active{background:var(--salmon);border-color:var(--salmon);color:#fff}

/* empty / completed */
.empty-shell{
    background:var(--card);border:1px solid var(--line);border-radius:22px;
    box-shadow:var(--shadow);padding:48px 24px;text-align:center;
}
.empty-state{display:flex;flex-direction:column;align-items:center;gap:14px}
.empty-icon{font-size:46px}
.empty-title{font-size:24px;font-weight:800;color:var(--muted)}
.empty-text{max-width:480px;font-size:15px;line-height:1.6;color:var(--muted)}
.unit-result-card{
    width:min(520px, 100%);
    border:1px solid var(--line);
    background:linear-gradient(180deg,#fff9ef 0%,#ffffff 100%);
    border-radius:20px;
    padding:18px 16px;
    box-shadow:var(--shadow-sm);
}
.result-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:112px;
    padding:9px 18px;
    border-radius:999px;
    font-size:13px;
    font-weight:900;
    letter-spacing:.16em;
    margin-bottom:14px;
    border:1px solid transparent;
}
.result-badge-pass{
    background:#f3e8ff;
    color:#6d28d9;
    border-color:#d8b4fe;
}
.result-badge-fail{
    background:#fee2e2;
    color:#b91c1c;
    border-color:#fca5a5;
}
.unit-percent{
    font-size:clamp(58px, 9vw, 96px);
    line-height:1;
    font-weight:900;
    letter-spacing:-.02em;
    margin-bottom:8px;
}
.unit-percent.score-fail{color:#dc2626}
.unit-percent.score-pass{color:#7c3aed}
.unit-errors{
    font-size:18px;
    font-weight:800;
    color:var(--muted);
}
.unit-rule{
    margin-top:10px;
    font-size:14px;
    font-weight:700;
}
.unit-rule.fail{color:#b91c1c}
.unit-rule.pass{color:#6d28d9}

/* worksheet panel */
.ws-panel{
    border:1px solid #d1fae5;
    background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);
    border-radius:22px;
    box-shadow:var(--shadow-sm);
    overflow:hidden;
}
.ws-panel-header{
    display:flex;
    align-items:center;
    gap:10px;
    padding:14px 18px;
    cursor:pointer;
    user-select:none;
}
.ws-panel-header:hover{background:rgba(16,185,129,.06)}
.ws-panel-icon{font-size:22px;line-height:1}
.ws-panel-label{
    flex:1;
    font-size:15px;
    font-weight:800;
    color:#065f46;
}
.ws-panel-count{
    background:#d1fae5;
    color:#065f46;
    font-size:12px;
    font-weight:800;
    padding:3px 9px;
    border-radius:999px;
}
.ws-panel-arrow{
    font-size:13px;
    color:#10b981;
    transition:transform .2s;
}
.ws-panel[open] .ws-panel-arrow{transform:rotate(180deg)}
.ws-items{
    padding:0 18px 14px;
    display:flex;
    flex-direction:column;
    gap:8px;
}
.ws-item{
    background:#fff;
    border:1px solid #a7f3d0;
    border-radius:14px;
    padding:12px 14px;
    display:flex;
    align-items:center;
    gap:12px;
}
.ws-item-icon{font-size:20px;flex-shrink:0}
.ws-item-title{
    flex:1;
    font-size:14px;
    font-weight:700;
    color:#047857;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.ws-item-actions{display:flex;gap:6px;flex-shrink:0}
.ws-btn{
    display:inline-flex;align-items:center;justify-content:center;
    padding:7px 12px;
    border-radius:10px;
    text-decoration:none;
    font-size:12px;
    font-weight:800;
    transition:filter .15s,transform .15s;
    white-space:nowrap;
}
.ws-btn:hover{filter:brightness(.92);transform:translateY(-1px)}
.ws-btn-view{background:linear-gradient(180deg,#34d399,#10b981);color:#fff}
.ws-btn-dl{background:linear-gradient(180deg,#a3e635,#65a30d);color:#fff}
@media(max-width:560px){.ws-item{flex-wrap:wrap}.ws-item-actions{width:100%;justify-content:flex-end}}
.result-actions{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    justify-content:center;
}
.empty-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    padding:13px 22px;border-radius:12px;text-decoration:none;color:#fff;
    font-size:15px;font-weight:700;background:linear-gradient(180deg,#f14902,#d33d00);
    box-shadow:var(--shadow-sm);margin-top:4px;
}
.empty-btn.blue{background:linear-gradient(180deg,#c97de8,#8b1a9a)}
.empty-btn.disabled{opacity:.45;pointer-events:none;cursor:not-allowed}

@media(max-width:768px){
    .topbar-inner{grid-template-columns:1fr;text-align:center}
    .top-actions{justify-content:center;flex-wrap:wrap}
    .page{padding:8px}
    .frame-wrap iframe{height:calc(100vh - 320px);min-height:260px}
    .controls{flex-wrap:wrap}
    .ctrl-btn,.empty-btn{flex:1 1 100%;min-width:0}
    .step-counter{width:100%;order:-1}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a class="top-btn" href="<?php echo h($backHref); ?>">← Back</a>
        <h1 class="topbar-title"><?php echo h(($selectedUnitName !== '' && $selectedUnitName !== 'UNIT') ? $selectedUnitName : $courseName); ?></h1>
        <div class="top-actions">
            <?php if ($topWorksheetDownloadUrl !== ''): ?>
            <a class="top-btn"
               style="background:linear-gradient(180deg,#84cc16,#65a30d);"
               href="<?php echo h($topWorksheetDownloadUrl); ?>"
               download="worksheet.pdf">⬇ Download</a>
            <?php endif; ?>
            <?php if ($selectedUnitId !== ''): ?>
            <a class="top-btn"
               style="background:linear-gradient(180deg,#0ea5e9,#0284c7);"
               href="unit_pdf.php?unit=<?php echo urlencode($selectedUnitId); ?>&assignment=<?php echo urlencode($assignmentId); ?>"
               target="_blank"
               rel="noopener noreferrer">📄 PDF</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="page">
<main class="content">

    <!-- Unit selector strip (only if multiple units) -->
    <?php if (count($allUnits) > 1): ?>
    <div class="units-strip">
        <?php foreach ($allUnits as $_unit):
            $_uid = (string) ($_unit['id'] ?? '');
            $_uname = (string) ($_unit['name'] ?? 'Unit');
            $_href = 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($_uid);
        ?>
            <a class="unit-chip <?php echo $_uid === $selectedUnitId ? 'active' : ''; ?>"
               href="<?php echo h($_href); ?>">
                <?php echo h($_uname); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($worksheets)): ?>
    <!-- Worksheet (Downloadable PDF) panel -->
    <details class="ws-panel" open>
        <summary class="ws-panel-header">
            <span class="ws-panel-icon">📄</span>
            <span class="ws-panel-label">Worksheets</span>
            <span class="ws-panel-count"><?php echo count($worksheets); ?></span>
            <span class="ws-panel-arrow">▼</span>
        </summary>
        <div class="ws-items">
            <?php foreach ($worksheets as $_ws): ?>
            <div class="ws-item">
                <span class="ws-item-icon">📄</span>
                <span class="ws-item-title" title="<?php echo h($_ws['title']); ?>"><?php echo h($_ws['title']); ?></span>
                <div class="ws-item-actions">
                    <a class="ws-btn ws-btn-view"
                       href="<?php echo h($_ws['serve_url']); ?>"
                       target="_blank"
                       rel="noopener noreferrer">View</a>
                    <a class="ws-btn ws-btn-dl"
                       href="<?php echo h($_ws['serve_url']); ?>"
                       download="worksheet.pdf">Download</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($isCompleted): ?>
    <!-- COMPLETED -->
    <section class="empty-shell">
        <div class="empty-state">
            <div class="empty-icon">🏁</div>
            <div class="empty-title">Unit completed!</div>
            <div class="empty-text">You completed all activities in this unit.</div>

            <?php if ($hasUnitResult): ?>
            <div class="unit-result-card">
                <div class="result-badge <?php echo h($resultStatusClass); ?>"><?php echo h($resultStatusLabel); ?></div>
                <div class="unit-percent <?php echo h($scoreToneClass); ?>"><?php echo $completionPercent; ?>%</div>
                <div class="unit-errors">Errors: <?php echo $quizErrors; ?> / <?php echo $quizTotal; ?></div>
                <?php if ($isPassingScore): ?>
                    <div class="unit-rule pass">Passed: quiz unlocked.</div>
                <?php elseif ($quizEnabledByTeacher): ?>
                    <div class="unit-rule pass">Quiz enabled by your teacher.</div>
                <?php else: ?>
                    <div class="unit-rule fail">Below 60%: you must repeat this unit to unlock the quiz.</div>
                <?php endif; ?>
            </div>

            <div class="result-actions">
                <a class="empty-btn blue" href="<?php echo h($backHref); ?>">← My courses</a>

                <?php if ($canAccessQuiz): ?>
                    <?php if ($quizHref !== ''): ?>
                        <a class="empty-btn" href="<?php echo h($quizHref); ?>">Start quiz</a>
                    <?php else: ?>
                        <a class="empty-btn disabled" href="#" aria-disabled="true">Quiz not available</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="empty-btn"
                       href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=0">
                       Repeat unit
                    </a>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="empty-text">Complete the graded activities to calculate your score and unlock the quiz.</div>
            <div class="result-actions">
                <a class="empty-btn blue" href="<?php echo h($backHref); ?>">← My courses</a>
                <a class="empty-btn"
                   href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=0">
                   Repeat unit
                </a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php elseif (!$current || !$viewerHref): ?>
    <!-- NO ACTIVITIES -->
    <section class="empty-shell">
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <div class="empty-title">No activities available</div>
            <div class="empty-text">This unit has no activities yet, or this activity type does not have a configured viewer.</div>
            <a class="empty-btn blue" href="<?php echo h($backHref); ?>">← My courses</a>
        </div>
    </section>

    <?php else: ?>
    <!-- ACTIVITY VIEWER -->
    <section class="viewer-shell">
        <div class="viewer-top">
            <h2 class="section-title">Activity presentation</h2>
            <span class="act-badge">Activity <?php echo ($step + 1); ?> / <?php echo $total; ?></span>
        </div>

        <div class="frame-wrap">
            <iframe
                id="activityViewer"
                src="<?php echo h($viewerHref); ?>"
                title="Activity viewer"
            ></iframe>
        </div>

        <div class="controls">
            <a class="ctrl-btn blue <?php echo $hasPrev ? '' : 'disabled'; ?>"
               href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">
                &larr; Previous
            </a>
            <div class="step-counter">
                <strong><?php echo ($step + 1); ?></strong> / <?php echo $total; ?>
            </div>
            <a class="ctrl-btn <?php echo ($hasNext || $isLastActivity) ? '' : 'disabled'; ?>"
               href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=<?php echo $isLastActivity ? $completedStep : ($hasNext ? $nextStep : $step); ?>">
                <?php echo $isLastActivity ? 'Finish unit' : 'Next &rarr;'; ?>
            </a>
        </div>
    </section>
    <?php endif; ?>

</main>
</div>

<script>
(function () {
    const iframe = document.getElementById('activityViewer');
    if (!iframe) return;

    function hideEmbeddedBackButton() {
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return;

            const selectors = [
                '.back','.btn-volver','.back-button','.btn.back','.back-btn',
                '[class*="back"]','[id*="back"]',
                'a[href*="dashboard"]','a[href*="unit_view"]',
                'a[href*="student_course"]','a[href*="course.php"]'
            ];

            selectors.forEach((selector) => {
                doc.querySelectorAll(selector).forEach((el) => {
                    const text = (el.textContent || '').toLowerCase();
                    const href = (el.getAttribute('href') || '').toLowerCase();
                    if (
                        text.includes('volver') || text.includes('back') ||
                        text.includes('regresar') || text.includes('mis cursos') ||
                        href.includes('dashboard') || href.includes('unit_view') ||
                        href.includes('student_course') || href.includes('course.php')
                    ) {
                        el.style.display = 'none';
                    }
                });
            });

            const style = doc.createElement('style');
            style.innerHTML = 'body{ margin-top:0 !important; padding-top:0 !important; }';
            doc.head.appendChild(style);
        } catch (e) {
            // cross-origin — ignore
        }
    }

    iframe.addEventListener('load', hideEmbeddedBackButton);
})();
</script>
</body>
</html>

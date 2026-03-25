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
$studentId = trim((string) ($_SESSION['student_id'] ?? ''));

if ($assignmentId === '') {
    die('Asignación no especificada.');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
    } catch (Throwable $e) {
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
        $stmt = $pdo->prepare("\n            SELECT sa.id, sa.student_id, sa.teacher_id, sa.course_id, sa.period, sa.program, sa.unit_id, sa.level_id,\n                   t.name AS teacher_name,\n                   c.name AS course_name\n            FROM student_assignments sa\n            LEFT JOIN teachers t ON t.id = sa.teacher_id\n            LEFT JOIN courses c ON c.id = sa.course_id\n            WHERE sa.id = :id\n            LIMIT 1\n        ");
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
            $stmt = $pdo->prepare("SELECT id, name FROM units WHERE phase_id = :course_id {$orderBy}");
            $stmt->execute(['course_id' => $courseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($rows)) {
                return $rows;
            }
        }

        $stmt = $pdo->prepare("SELECT id, name FROM units WHERE course_id = :course_id {$orderBy}");
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

        $stmt = $pdo->prepare("SELECT id, type FROM activities WHERE unit_id = :unit_id {$orderBy}");
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
    die('Base de datos no disponible.');
}

ensure_student_performance_tables($pdo);

$assignment = load_assignment($pdo, $assignmentId);
if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
    die('No tienes acceso a este curso.');
}

$selectedUnitId = trim((string) ($_GET['unit'] ?? (string) ($assignment['unit_id'] ?? '')));
$quizTotalRaw = isset($_GET['quiz_total']) ? (int) $_GET['quiz_total'] : -1;
$quizErrorsRaw = isset($_GET['quiz_errors']) ? (int) $_GET['quiz_errors'] : -1;
$quizPercentRaw = isset($_GET['quiz_percent']) ? (int) $_GET['quiz_percent'] : -1;

if ($selectedUnitId !== '' && $quizTotalRaw >= 0) {
    $quizTotal = max(0, $quizTotalRaw);
    $quizErrors = max(0, $quizErrorsRaw);
    if ($quizTotal > 0 && $quizErrors > $quizTotal) {
        $quizErrors = $quizTotal;
    }

    $quizPercent = $quizTotal > 0
        ? max(0, min(100, (int) round((($quizTotal - $quizErrors) / $quizTotal) * 100)))
        : max(0, min(100, $quizPercentRaw));

    save_student_unit_performance($pdo, $studentId, $assignmentId, $selectedUnitId, $quizPercent, $quizErrors, $quizTotal);
}

$units = load_units_for_assignment($pdo, $assignment);
$unitResults = load_student_unit_results($pdo, $studentId, $assignmentId);
$courseName = trim((string) ($assignment['course_name'] ?? 'Curso'));
if ($courseName === '') {
    $courseName = 'Curso';
}
$teacherName = trim((string) ($assignment['teacher_name'] ?? 'Docente'));
$programLabel = ((string) ($assignment['program'] ?? '') === 'english') ? 'Inglés' : 'Técnico';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($courseName); ?></title>
<style>
:root{
    --bg:#fff8f5;
    --card:#ffffff;
    --line:#ffd9d2;
    --title:#b04632;
    --text:#5e352e;
    --muted:#8a625a;
    --salmon:#fa8072;
    --salmon-dark:#e8654e;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    font-family:Arial,sans-serif;
    padding:24px;
    color:var(--text);
}
.page{max-width:1100px;margin:0 auto}
.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.top h1{margin:0;color:var(--title);font-size:30px}
.back{color:var(--salmon-dark);text-decoration:none;font-weight:700}
.meta{margin:0 0 24px;color:var(--muted);font-size:16px}
.section-title{margin:0 0 14px;color:var(--title);font-size:24px;font-weight:700}
.unit{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-top:12px}
.unit h3{margin:0 0 10px;color:var(--text);font-size:20px}
.badges{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#fff1ed;color:#b04632;border:1px solid #ffcfc4}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-block;margin-top:8px;padding:9px 14px;background:var(--salmon);color:#fff;text-decoration:none;border-radius:8px;font-weight:700;transition:background .2s ease}
.btn:hover{background:var(--salmon-dark)}
.btn.secondary{background:#9f8882}
.btn.secondary:hover{background:#896f68}
.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;color:var(--muted)}
@media (max-width: 768px){body{padding:18px}.top h1{font-size:24px}}
</style>
</head>
<body>
<div class="page">
    <div class="top">
        <h1><?php echo h($courseName); ?></h1>
        <a class="back" href="student_dashboard.php">← Volver</a>
    </div>

    <p class="meta">
        Programa: <strong><?php echo h($programLabel); ?></strong> ·
        Docente: <strong><?php echo h($teacherName); ?></strong> ·
        Periodo: <strong><?php echo h((string) ($assignment['period'] ?? '')); ?></strong>
    </p>

    <h2 class="section-title">Unidades y puntaje</h2>

    <?php if (empty($units)) { ?>
        <div class="empty">No hay unidades disponibles.</div>
    <?php } else { ?>
        <?php foreach ($units as $unit) { ?>
            <?php
            $unitId = (string) ($unit['id'] ?? '');
            $unitName = (string) ($unit['name'] ?? 'Unidad');
            $result = $unitResults[$unitId] ?? [
                'completion_percent' => 0,
                'quiz_errors' => 0,
                'quiz_total' => 0,
            ];
            $quizReturn = 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unitId);
            $quizViewerHref = '../activities/quiz/viewer.php?unit=' . urlencode($unitId) . '&assignment=' . urlencode($assignmentId) . '&return_to=' . urlencode($quizReturn);
            $activities = load_activities_for_unit($pdo, $unitId);
            ?>
            <div class="unit">
                <h3><?php echo h($unitName); ?></h3>
                <div class="badges">
                    <span class="badge">Puntaje: <?php echo (int) ($result['completion_percent'] ?? 0); ?>%</span>
                    <?php if ((int) ($result['quiz_total'] ?? 0) > 0) { ?>
                        <span class="badge">Errores quiz: <?php echo (int) ($result['quiz_errors'] ?? 0); ?>/<?php echo (int) ($result['quiz_total'] ?? 0); ?></span>
                    <?php } ?>
                </div>
                <div class="actions">
                    <?php foreach ($activities as $activity) { ?>
                        <?php
                        $activityId = (string) ($activity['id'] ?? '');
                        $activityType = strtolower(trim((string) ($activity['type'] ?? '')));
                        $basePath = get_activity_base_path($activityType);
                        if ($activityId === '' || $basePath === null) {
                            continue;
                        }

                        $viewerHref = $basePath . '/viewer.php?id=' . rawurlencode($activityId) . '&unit=' . rawurlencode($unitId) . '&assignment=' . rawurlencode($assignmentId) . '&return_to=' . rawurlencode($quizReturn);
                        ?>
                        <a class="btn secondary" href="<?php echo h($viewerHref); ?>" target="_blank">Ver <?php echo h($activityType); ?></a>
                    <?php } ?>

                    <a class="btn" href="<?php echo h($quizViewerHref); ?>" target="_blank">Abrir quiz (viewer)</a>
                </div>
            </div>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>

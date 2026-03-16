<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$unitId = trim((string) ($_GET['unit'] ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? 'view'));
$mode = $mode === 'edit' ? 'edit' : 'view';

if ($unitId === '') {
    die('Unidad no especificada.');
}

if ($assignmentId === '') {
    die('Asignación no especificada.');
}

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');

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

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute(['table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    if ($assignmentId === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                teacher_id,
                teacher_name,
                program_type,
                course_id,
                course_name,
                unit_id,
                unit_name,
                updated_at
            FROM teacher_assignments
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_teacher_permission_from_accounts(PDO $pdo, string $teacherId): string
}

if (!$allowed) {
    die('No tienes permiso para esta unidad.');
}

if ($mode === 'edit' && !$allowEdit) {
    $mode = 'view';
}

try {
    $stmtActivities = $pdo->prepare("
        SELECT id, type, title, name, position
        FROM activities
        WHERE unit_id = :unit_id
        ORDER BY position ASC, id ASC
    ");
    $stmtActivities->execute(['unit_id' => $unitId]);
    $activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($activities)) {
        $stmtAltUnits = $pdo->prepare("
            SELECT id
            FROM units
            WHERE name = :name
              AND COALESCE(course_id, '') = COALESCE(:course_id, '')
              AND COALESCE(phase_id, '') = COALESCE(:phase_id, '')
              AND id <> :unit_id
        ");
        $stmtAltUnits->execute([
            'name' => (string) ($unit['name'] ?? ''),
            'course_id' => (string) ($unit['course_id'] ?? ''),
            'phase_id' => (string) ($unit['phase_id'] ?? ''),
            'unit_id' => $unitId,
        ]);
        $altUnitIds = $stmtAltUnits->fetchAll(PDO::FETCH_COLUMN) ?: [];
try {
    $stmtUnit = $pdo->prepare("
        SELECT id, name, course_id, phase_id
        FROM units
        WHERE id = :id
        LIMIT 1
    ");
    $stmtUnit->execute(['id' => $unitId]);
    $unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $unit = false;
}

if (!$unit) {
    die('Unidad no encontrada.');
}

$programType = (string) ($assignment['program_type'] ?? 'technical');
$assignmentCourseId = (string) ($assignment['course_id'] ?? '');
$assignmentUnitId = (string) ($assignment['unit_id'] ?? '');
$permission = load_teacher_permission_from_accounts($pdo, $teacherId);

$allowed = false;

if ($programType === 'english') {
    $allowed = $assignmentCourseId !== '' && $assignmentCourseId === (string) ($unit['phase_id'] ?? '');
} else {
    if ($assignmentUnitId !== '') {
        $allowed = $assignmentUnitId === (string) ($unit['id'] ?? '');
    } else {
        $allowed = $assignmentCourseId !== '' && $assignmentCourseId === (string) ($unit['course_id'] ?? '');
    }
}

if (!$allowed) {
    die('No tienes permiso para esta unidad.');
}

$allowEdit = $permission === 'editor';

if ($mode === 'edit' && !$allowEdit) {
    $mode = 'view';
}

try {
    $stmtActivities = $pdo->prepare("
        SELECT id, type, title, name, position
        FROM activities
        WHERE unit_id = :unit_id
        ORDER BY COALESCE(position, 0) ASC, id ASC
    ");
    $stmtActivities->execute(['unit_id' => $unitId]);
    $activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $activities = [];
}

$activityLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Quiz',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Video Lesson',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'build_sentence' => 'Build the Sentence',
];

$courseName = (string) ($assignment['course_name'] ?? 'Curso');
$assignmentUnitName = (string) ($assignment['unit_name'] ?? '');
$programLabel = $programType === 'english' ? 'English' : 'Técnico';

$backHref = 'teacher_course.php?assignment=' . urlencode($assignmentId);
if ($mode === 'edit') {
    $backHref .= '&mode=edit';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></title>
<style>
:root{
    --bg:#eef3ff;
    --card:#ffffff;
    --line:#d8e2f2;
    --title:#1f4d8f;
    --text:#1d355d;
    --muted:#5d6f8f;
    --blue:#2563eb;
    --blue-hover:#1d4ed8;
    --green:#15803d;
    --green-hover:#166534;
    --badge-bg:#eef2ff;
    --badge-text:#1f4ec9;
    --shadow:0 8px 24px rgba(0,0,0,.08);
}
*{
    box-sizing:border-box;
}
body{
    margin:0;
    background:var(--bg);
    font-family:Arial, "Segoe UI", sans-serif;
    padding:24px;
    color:var(--text);
}
.page{
    max-width:1100px;
    margin:0 auto;
}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
}
.top h1{
    margin:0;
    color:var(--title);
    font-size:30px;
}
.back{
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
}
.meta{
    margin:0 0 24px;
    color:var(--muted);
    font-size:16px;
    line-height:1.6;
}
.badges{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin:0 0 18px;
}
.badge{
    display:inline-block;
    padding:5px 10px;
    border-radius:999px;
    background:var(--badge-bg);
    color:var(--badge-text);
    font-size:12px;
    font-weight:700;
}
.section-title{
    margin:0 0 14px;
    color:var(--title);
    font-size:24px;
    font-weight:700;
}
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:12px;
    padding:16px;
    margin-top:12px;
    box-shadow:var(--shadow);
}
.card h3{
    margin:0 0 10px;
    color:var(--text);
    font-size:20px;
}
.card p{
    margin:0 0 10px;
    color:var(--muted);
}
.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:10px;
}
.btn{
    display:inline-block;
    padding:9px 14px;
    background:var(--blue);
    color:#fff;
    text-decoration:none;
    border-radius:8px;
    font-weight:700;
}
.btn:hover{
    background:var(--blue-hover);
}
.btn.edit{
    background:var(--green);
}
.btn.edit:hover{
    background:var(--green-hover);
}
.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:12px;
    padding:16px;
    color:var(--muted);
    box-shadow:var(--shadow);
}
@media (max-width: 768px){
    body{
        padding:18px;
    }
    .top h1{
        font-size:24px;
    }
}
</style>
</head>
<body>
<div class="page">
    <div class="top">
        <h1><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></h1>
        <a class="back" href="<?php echo h($backHref); ?>">← Volver</a>
    </div>

    <div class="badges">
        <span class="badge"><?php echo h($programLabel); ?></span>
        <span class="badge"><?php echo h($courseName); ?></span>
        <?php if ($assignmentUnitName !== '') { ?>
            <span class="badge"><?php echo h($assignmentUnitName); ?></span>
        <?php } ?>
        <span class="badge"><?php echo h($mode === 'edit' ? 'Modo edición' : 'Modo visualización'); ?></span>
    </div>

    <p class="meta">
        Unidad vinculada a la asignación del docente.
    </p>

    <h2 class="section-title">Actividades de la unidad</h2>

    <?php if (empty($activities)) { ?>
        <div class="empty">No hay actividades registradas en esta unidad.</div>
    <?php } else { ?>
        <?php foreach ($activities as $activity) { ?>
            <?php
            $activityId = (string) ($activity['id'] ?? '');
            $type = strtolower((string) ($activity['type'] ?? 'activity'));
            $typeLabel = $activityLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
            $title = (string) ($activity['title'] ?? $activity['name'] ?? $typeLabel);
            $activityBase = '../activities/' . rawurlencode($type);
            $viewerFile = __DIR__ . '/../activities/' . $type . '/viewer.php';
            ?>
            <div class="card">
                <h3><?php echo h($title); ?></h3>
                <p>Tipo: <strong><?php echo h($typeLabel); ?></strong></p>

                <div class="actions">
                    <?php if (file_exists($viewerFile)) { ?>
                        <a
                            class="btn"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h($activityBase . '/viewer.php?id=' . urlencode($activityId) . '&unit=' . urlencode($unitId) . '&assignment=' . urlencode($assignmentId)); ?>"
                        >
                            Ver actividad
                        </a>
                    <?php } ?>

                    <?php if ($allowEdit) { ?>
                        <a
                            class="btn edit"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h('teacher_activity_edit.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unitId) . '&activity=' . urlencode($activityId)); ?>"
                        >
                            Editar actividad
                        </a>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>

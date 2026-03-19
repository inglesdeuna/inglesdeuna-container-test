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

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));

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

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    if ($assignmentId === '' || !table_exists($pdo, 'teacher_assignments')) {
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
                unit_name
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

function load_teacher_permission(PDO $pdo, string $teacherId): string
{
    if ($teacherId === '' || !table_exists($pdo, 'teacher_accounts')) {
        return 'viewer';
    }

    try {
        $stmt = $pdo->prepare("
            SELECT permission
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $permission = trim((string) $stmt->fetchColumn());
        return $permission === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        return 'viewer';
    }
}

function load_unit(PDO $pdo, string $unitId): ?array
{
    if ($unitId === '' || !table_exists($pdo, 'units')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, course_id, phase_id
            FROM units
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_units_for_assignment(PDO $pdo, array $assignment): array
{
    if (!table_exists($pdo, 'units')) {
        return [];
    }

    $programType = trim((string) ($assignment['program_type'] ?? ''));
    $courseId = trim((string) ($assignment['course_id'] ?? ''));

    if ($courseId === '') {
        return [];
    }

    try {
        if ($programType === 'english' && table_has_column($pdo, 'units', 'phase_id')) {
            $stmt = $pdo->prepare("
                SELECT id, name, course_id, phase_id
                FROM units
                WHERE phase_id = :course_id
                ORDER BY id ASC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT id, name, course_id, phase_id
                FROM units
                WHERE course_id = :course_id
                ORDER BY id ASC
            ");
        }

        $stmt->execute(['course_id' => $courseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function teacher_can_access_unit(array $assignment, array $unit): bool
{
    $programType = (string) ($assignment['program_type'] ?? 'technical');
    $assignmentCourseId = (string) ($assignment['course_id'] ?? '');
    $assignmentUnitId = (string) ($assignment['unit_id'] ?? '');

    if ($programType === 'english') {
        return $assignmentCourseId !== '' && $assignmentCourseId === (string) ($unit['phase_id'] ?? '');
    }

    if ($assignmentUnitId !== '') {
        return $assignmentUnitId === (string) ($unit['id'] ?? '');
    }

    return $assignmentCourseId !== '' && $assignmentCourseId === (string) ($unit['course_id'] ?? '');
}

function load_activities_for_unit(PDO $pdo, string $unitId): array
{
    if ($unitId === '' || !table_exists($pdo, 'activities')) {
        return [];
    }

    $hasTitle = table_has_column($pdo, 'activities', 'title');
    $hasName = table_has_column($pdo, 'activities', 'name');
    $hasPosition = table_has_column($pdo, 'activities', 'position');

    $selectFields = ['id', 'type'];

    if ($hasTitle) {
        $selectFields[] = 'title';
    }
    if ($hasName) {
        $selectFields[] = 'name';
    }
    if ($hasPosition) {
        $selectFields[] = 'position';
    }

    $orderBy = $hasPosition ? 'COALESCE(position, 0) ASC, id ASC' : 'id ASC';

    try {
        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $selectFields) . "
            FROM activities
            WHERE unit_id = :unit_id
            ORDER BY {$orderBy}
        ");
        $stmt->execute(['unit_id' => $unitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('No fue posible conectar con la base de datos.');
}

$assignment = load_assignment($pdo, $assignmentId);
if (!$assignment) {
    die('Asignación no encontrada.');
}

if ((string) ($assignment['teacher_id'] ?? '') !== '' && (string) ($assignment['teacher_id'] ?? '') !== $teacherId) {
    die('No tienes permiso para esta asignación.');
}

$unitsForAssignment = load_units_for_assignment($pdo, $assignment);
$selectedUnit = null;

foreach ($unitsForAssignment as $candidate) {
    if ((string) ($candidate['id'] ?? '') === $unitId) {
        $selectedUnit = $candidate;
        break;
    }
}

if (!$selectedUnit) {
    $selectedUnit = load_unit($pdo, $unitId);
}

if (!$selectedUnit) {
    die('Unidad no encontrada.');
}

if (!teacher_can_access_unit($assignment, $selectedUnit)) {
    die('No tienes permiso para esta unidad.');
}

$permission = load_teacher_permission($pdo, $teacherId);
$allowEdit = $permission === 'editor';

if ($mode === 'edit' && !$allowEdit) {
    $mode = 'view';
}

$activities = load_activities_for_unit($pdo, (string) ($selectedUnit['id'] ?? ''));

$activityLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Flipbook',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'build_sentence' => 'Unscramble',
];

$programType = (string) ($assignment['program_type'] ?? 'technical');
$programLabel = $programType === 'english' ? 'English' : 'Técnico';
$courseName = (string) ($assignment['course_name'] ?? 'Curso');
$backHref = 'dashboard.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode((string) ($selectedUnit['id'] ?? '')) . '#unidades-curso';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h((string) ($selectedUnit['name'] ?? 'Unidad')); ?></title>
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
*{ box-sizing:border-box; }
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
.meta{
    margin:0 0 18px;
    color:var(--muted);
    font-size:16px;
}
.unit-switcher{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin:0 0 20px;
}
.unit-link{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    background:#fff;
    color:var(--blue);
    border:1px solid var(--line);
}
.unit-link.active{
    background:var(--blue);
    color:#fff;
    border-color:var(--blue);
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
</style>
</head>
<body>
<div class="page">
    <div class="top">
        <h1><?php echo h((string) ($selectedUnit['name'] ?? 'Unidad')); ?></h1>
        <a class="back" href="<?php echo h($backHref); ?>">← Volver</a>
    </div>

    <div class="badges">
        <span class="badge"><?php echo h($programLabel); ?></span>
        <span class="badge"><?php echo h($courseName); ?></span>
        <span class="badge"><?php echo h($mode === 'edit' ? 'Modo edición' : 'Modo visualización'); ?></span>
    </div>

    <p class="meta">Unidad vinculada a la asignación del docente.</p>

    <?php if (!empty($unitsForAssignment)) { ?>
        <div class="unit-switcher">
            <?php foreach ($unitsForAssignment as $navUnit) { ?>
                <?php
                $navUnitId = (string) ($navUnit['id'] ?? '');
                $isActive = $navUnitId === (string) ($selectedUnit['id'] ?? '');
                ?>
                <a
                    class="unit-link<?php echo $isActive ? ' active' : ''; ?>"
                    href="teacher_unit.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($navUnitId); ?>&mode=<?php echo urlencode($mode === 'edit' ? 'edit' : 'view'); ?>"
                >
                    <?php echo h((string) ($navUnit['name'] ?? 'Unidad')); ?>
                </a>
            <?php } ?>
        </div>
    <?php } ?>

    <h2 class="section-title">Actividades de la unidad</h2>

    <?php if (empty($activities)) { ?>
        <div class="empty">No hay actividades registradas en esta unidad.</div>
    <?php } else { ?>
        <?php foreach ($activities as $activity) { ?>
            <?php
            $activityId = (string) ($activity['id'] ?? '');
            $type = strtolower((string) ($activity['type'] ?? 'activity'));
            $typeLabel = $activityLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
            $title = trim((string) ($activity['title'] ?? $activity['name'] ?? $typeLabel));
            $viewerFile = __DIR__ . '/../activities/' . $type . '/viewer.php';
            ?>
            <div class="card">
                <h3><?php echo h($title !== '' ? $title : $typeLabel); ?></h3>
                <p>Tipo: <strong><?php echo h($typeLabel); ?></strong></p>

                <div class="actions">
                    <?php if (file_exists($viewerFile)) { ?>
                        <a
                            class="btn"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h('../activities/' . rawurlencode($type) . '/viewer.php?id=' . urlencode($activityId) . '&unit=' . urlencode((string) ($selectedUnit['id'] ?? '')) . '&assignment=' . urlencode($assignmentId)); ?>"
                        >
                            Ver actividad
                        </a>
                    <?php } ?>

                    <?php if ($allowEdit) { ?>
                        <a
                            class="btn edit"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h('teacher_activity_edit.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode((string) ($selectedUnit['id'] ?? '')) . '&activity=' . urlencode($activityId)); ?>"
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

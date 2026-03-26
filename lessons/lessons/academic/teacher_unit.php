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

function teacher_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'DC';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) === 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'DC';
}

function ensure_data_directory(): string
{
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    return $dataDir;
}

function teacher_photos_store_file(): string
{
    return ensure_data_directory() . '/teacher_photos.json';
}

function load_teacher_photos_store(): array
{
    $storeFile = teacher_photos_store_file();
    if (!file_exists($storeFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($storeFile), true);
    return is_array($decoded) ? $decoded : [];
}

function load_teacher_photo_from_database(PDO $pdo, string $teacherId): string
{
    if ($teacherId === '' || !table_exists($pdo, 'teacher_accounts') || !table_has_column($pdo, 'teacher_accounts', 'teacher_photo')) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT teacher_photo\n            FROM teacher_accounts\n            WHERE teacher_id = :teacher_id\n            ORDER BY updated_at DESC NULLS LAST\n            LIMIT 1\n        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        return trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function load_teacher_photo(PDO $pdo, string $teacherId): string
{
    if ($teacherId === '') {
        return '';
    }

    $store = load_teacher_photos_store();
    $fromStore = trim((string) ($store[$teacherId] ?? ''));
    if ($fromStore !== '') {
        return $fromStore;
    }

    return load_teacher_photo_from_database($pdo, $teacherId);
}

function resolve_teacher_photo_src(string $teacherPhoto): string
{
    $teacherPhoto = trim($teacherPhoto);
    if ($teacherPhoto === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $teacherPhoto)) {
        return $teacherPhoto;
    }

    $fullPath = __DIR__ . '/' . ltrim($teacherPhoto, '/');
    if (is_file($fullPath)) {
        return ltrim($teacherPhoto, '/');
    }

    return '';
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

function update_activities_order(PDO $pdo, string $unitId, array $orderedActivityIds): bool
{
    if ($unitId === '' || empty($orderedActivityIds)) {
        return false;
    }

    $orderedActivityIds = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $orderedActivityIds
    ), static fn (string $value): bool => $value !== '')));

    if (empty($orderedActivityIds)) {
        return false;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($orderedActivityIds), '?'));
        $sql = "
            SELECT id
            FROM activities
            WHERE unit_id = ?
              AND id IN ({$placeholders})
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$unitId], $orderedActivityIds));
        $validIds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (count($validIds) !== count($orderedActivityIds)) {
            return false;
        }

        $pdo->beginTransaction();
        $updateStmt = $pdo->prepare("\n            UPDATE activities\n            SET position = :position\n            WHERE id = :id\n              AND unit_id = :unit_id\n        ");

        foreach ($orderedActivityIds as $index => $activityId) {
            $updateStmt->execute([
                'position' => $index + 1,
                'id' => $activityId,
                'unit_id' => $unitId,
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
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
$hasActivityPosition = table_has_column($pdo, 'activities', 'position');

if ($mode === 'edit' && !$allowEdit) {
    $mode = 'view';
}

$allowReorder = $allowEdit && $mode === 'edit' && $hasActivityPosition;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'reorder_activities') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!$allowReorder) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No autorizado para reordenar actividades.']);
        exit;
    }

    $orderedIds = isset($_POST['order']) && is_array($_POST['order']) 
        ? array_filter(array_map('strval', $_POST['order']), fn($id) => trim($id) !== '')
        : [];
    
    if (empty($orderedIds)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'No se recibió un orden válido.']);
        exit;
    }

    $updated = update_activities_order($pdo, (string) ($selectedUnit['id'] ?? ''), array_values($orderedIds));

    if (!$updated) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'No fue posible guardar el nuevo orden.']);
        exit;
    }

    echo json_encode(['status' => 'success']);
    exit;
}

$activities = load_activities_for_unit($pdo, (string) ($selectedUnit['id'] ?? ''));

$activityLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice',
    'video_comprehension' => 'Video Comprehension',
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
$teacherName = trim((string) ($_SESSION['teacher_name'] ?? $assignment['teacher_name'] ?? 'Docente'));
$teacherPhoto = trim((string) ($_SESSION['teacher_photo'] ?? ''));
if ($teacherPhoto === '') {
    $teacherPhoto = load_teacher_photo($pdo, $teacherId);
    if ($teacherPhoto !== '') {
        $_SESSION['teacher_photo'] = $teacherPhoto;
    }
}
$teacherPhotoSrc = resolve_teacher_photo_src($teacherPhoto);
$teacherInitials = teacher_initials($teacherName);
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
    --text:#1b3050;
    --muted:#5d6f8f;
    --blue:#2563eb;
    --blue-dark:#1d4ed8;
    --blue-soft:#e9f1ff;
    --green:#15803d;
    --green-hover:#166534;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}
*{ box-sizing:border-box; }
body{
    margin:0;
    background:var(--bg);
    font-family:Arial, sans-serif;
    color:var(--text);
}

.topbar{
    background:linear-gradient(180deg, var(--blue), var(--blue-dark));
    color:#fff;
    padding:16px 24px;
}

.topbar-inner{
    max-width:1280px;
    margin:0 auto;
    display:grid;
    grid-template-columns:180px 1fr;
    align-items:center;
    gap:12px;
}

.topbar-title{
    margin:0;
    text-align:center;
    font-size:28px;
    font-weight:800;
}

.top-btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:10px;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    color:#fff;
    box-shadow:var(--shadow);
    background:rgba(255,255,255,.2);
}

.top-btn.back{ justify-self:start; }

.page{
    max-width:1280px;
    margin:0 auto;
    padding:18px 20px 24px;
}

.layout{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:18px;
    align-items:start;
}

.sidebar{
    background:#e3ecff;
    border-radius:20px;
    padding:18px 14px;
    box-shadow:var(--shadow);
    min-height:calc(100vh - 150px);
}

.logo-wrap{
    text-align:center;
    margin-bottom:16px;
}

.avatar{
    width:90px;
    height:90px;
    margin:0 auto;
    border-radius:50%;
    overflow:hidden;
    background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border:3px solid #f0f4ff;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 8px 20px rgba(59, 130, 246, 0.15);
}

.avatar-image{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.avatar-fallback{
    display:none;
    width:100%;
    height:100%;
    align-items:center;
    justify-content:center;
    color:var(--blue-dark);
    font-size:24px;
    font-weight:800;
    letter-spacing:.08em;
}

.side-btn{
    display:block;
    width:100%;
    text-align:center;
    text-decoration:none;
    color:#fff;
    font-weight:700;
    font-size:14px;
    padding:12px 10px;
    border-radius:12px;
    margin-bottom:12px;
    box-shadow:var(--shadow);
}

.side-btn.blue{ background:linear-gradient(180deg,#3d73ee,#2563eb); }
.side-btn.gray{ background:linear-gradient(180deg,#7b8b9e,#66758b); }
.side-btn.red{ background:linear-gradient(180deg,#ef4444,#dc2626); }

.content{ padding:0; }

.info-card,
.activities-shell{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:22px;
    box-shadow:var(--shadow);
}

.info-card{
    padding:20px 22px;
    margin-bottom:18px;
}

.info-card h2{
    margin:0 0 12px;
    color:var(--blue-dark);
    font-size:28px;
}

.badges{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin:0 0 12px;
}
.badge{
    display:inline-block;
    padding:7px 12px;
    border-radius:999px;
    background:var(--blue-soft);
    color:var(--blue-dark);
    font-size:12px;
    font-weight:800;
}
.meta{
    margin:8px 0 0;
    color:var(--muted);
    font-size:15px;
}

.unit-switcher{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin:14px 0 0;
}
.unit-link{
    display:inline-block;
    padding:16px 18px;
    border-radius:14px;
    text-decoration:none;
    font-size:15px;
    font-weight:700;
    background:#fff;
    color:var(--blue);
    border:1px solid #cddfff;
    box-shadow:0 8px 18px rgba(37, 99, 235, 0.18);
}
.unit-link.active{
    background:#fff;
    color:var(--blue-dark);
    border-color:var(--blue);
    box-shadow:0 10px 22px rgba(37, 99, 235, 0.26);
}
.unit-link:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 22px rgba(37, 99, 235, 0.26);
}

.activities-shell{ padding:18px; }

.section-title{
    margin:0 0 10px;
    color:var(--blue-dark);
    font-size:22px;
    font-weight:800;
}

.helper{
    margin:0 0 16px;
    color:var(--muted);
    font-size:14px;
}

.activity-list{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.card.draggable{
    cursor:grab;
}

.card.draggable:active{
    cursor:grabbing;
}

.card.dragging{
    opacity:.65;
}

.helper-status{
    margin:0 0 14px;
    font-size:13px;
    font-weight:700;
    color:var(--blue-dark);
}

.helper-status.error{
    color:#b91c1c;
}

.card{
    background:linear-gradient(180deg,#3d73ee,#2557d1);
    border-radius:16px;
    padding:18px;
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    box-shadow:var(--shadow);
}

.activity-main{ min-width:0; }

.card h3{
    margin:0 0 6px;
    font-size:20px;
    font-weight:800;
    line-height:1.2;
}
.card p{
    margin:0 0 4px;
    font-size:14px;
    opacity:.95;
}
.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.btn{
    display:inline-block;
    padding:10px 16px;
    background:#0f2f72;
    color:#fff;
    text-decoration:none;
    border-radius:10px;
    font-weight:700;
    font-size:14px;
    box-shadow:var(--shadow);
}
.btn:hover{
    background:#153d94;
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
    border-radius:16px;
    padding:18px;
    color:var(--muted);
    box-shadow:var(--shadow);
}

@media (max-width: 980px){
    .topbar-inner{
        grid-template-columns:1fr;
        text-align:center;
    }

    .top-btn.back{
        justify-self:center;
    }

    .layout{ grid-template-columns:1fr; }

    .sidebar{ min-height:auto; }
}

@media (max-width: 768px){
    .page{ padding:12px; }

    .topbar{ padding:14px; }

    .topbar-title{ font-size:24px; }

    .card{
        flex-direction:column;
        align-items:flex-start;
    }

    .actions{
        width:100%;
        justify-content:stretch;
    }

    .actions .btn{
        flex:1 1 auto;
        text-align:center;
    }
}
</style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="top-btn back" href="<?php echo h($backHref); ?>">← Volver</a>
        <h1 class="topbar-title">Gestión de Unidad</h1>
    </div>
</header>

<div class="page">
    <div class="layout">
        <aside class="sidebar">
            <div class="logo-wrap">
                <div class="avatar">
                    <?php if ($teacherPhotoSrc !== '') { ?>
                        <img
                            class="avatar-image"
                            src="<?php echo h($teacherPhotoSrc); ?>"
                            alt="Foto de <?php echo h($teacherName); ?>"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                        >
                    <?php } ?>
                    <span class="avatar-fallback" aria-hidden="true" style="<?php echo $teacherPhotoSrc === '' ? 'display:flex;' : ''; ?>"><?php echo h($teacherInitials); ?></span>
                </div>
            </div>

            <a class="side-btn blue" href="<?php echo h($backHref); ?>">📚 Volver a unidades</a>
            <a class="side-btn gray" href="teacher_assignments.php">🧾 Mis asignaciones</a>
            <a class="side-btn red" href="/lessons/lessons/academic/logout.php">🚪 Cerrar sesión</a>
        </aside>

        <main class="content">
            <section class="info-card">
                <h2><?php echo h((string) ($selectedUnit['name'] ?? 'Unidad')); ?></h2>

                <div class="badges">
                    <span class="badge"><?php echo h($programLabel); ?></span>
                    <span class="badge"><?php echo h($courseName); ?></span>
                    <span class="badge"><?php echo h($mode === 'edit' ? 'Modo edición' : 'Modo visualización'); ?></span>
                    <span class="badge">Unit ID: <?php echo h((string) ($selectedUnit['id'] ?? '')); ?></span>
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
            </section>

            <section class="activities-shell">
                <h2 class="section-title">Actividades de la unidad</h2>
                <p class="helper">Consulta y administra las actividades disponibles para esta unidad<?php echo $allowReorder ? '. Arrastra las tarjetas para cambiar el orden.' : '.'; ?></p>
                <?php if ($allowReorder) { ?>
                    <p class="helper-status" id="orderStatus" aria-live="polite"></p>
                <?php } ?>

                <?php if (empty($activities)) { ?>
                    <div class="empty">No hay actividades registradas en esta unidad.</div>
                <?php } else { ?>
                    <div class="activity-list" id="activityContainer">
                    <?php foreach ($activities as $activity) { ?>
                        <?php
                        $activityId = (string) ($activity['id'] ?? '');
                        $type = strtolower((string) ($activity['type'] ?? 'activity'));
                        $typeLabel = $activityLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
                        $title = trim((string) ($activity['title'] ?? $activity['name'] ?? $typeLabel));
                        $viewerFile = __DIR__ . '/../activities/' . $type . '/viewer.php';
                        ?>
                        <div class="card<?php echo $allowReorder ? ' draggable' : ''; ?>"<?php echo $allowReorder ? ' draggable="true" data-id="' . h($activityId) . '"' : ''; ?>>
                            <div class="activity-main">
                                <h3><?php echo h($title !== '' ? $title : $typeLabel); ?></h3>
                                <p>Tipo: <strong><?php echo h($typeLabel); ?></strong></p>
                            </div>

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
                                        href="<?php echo h('./teacher_activity_edit.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode((string) ($selectedUnit['id'] ?? '')) . '&activity=' . urlencode($activityId)); ?>"
                                    >
                                        Editar actividad
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                    </div>
                <?php } ?>
            </section>
        </main>
    </div>
</div>
<?php if ($allowReorder) { ?>
<script>
(function () {
    const container = document.getElementById('activityContainer');
    const status = document.getElementById('orderStatus');
    if (!container || !status) {
        return;
    }

    let draggedItem = null;
    let isSaving = false;
    let lastOrder = [];

    function getCurrentOrder() {
        return Array.from(container.querySelectorAll('.draggable')).map(item => item.dataset.id || '');
    }

    // Initialize lastOrder after DOM is ready
    lastOrder = getCurrentOrder();

    function setStatus(message, isError) {
        status.textContent = message;
        status.classList.toggle('error', Boolean(isError));
    }

    function getDragAfterElement(parent, y) {
        const draggableElements = [...parent.querySelectorAll('.draggable:not(.dragging)')];

        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;

            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            }

            return closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    async function saveOrder() {
        const order = getCurrentOrder().filter(Boolean);
        if (order.length === 0 || order.join('|') === lastOrder.join('|') || isSaving) {
            return;
        }

        isSaving = true;
        setStatus('Guardando orden...', false);

        const payload = new URLSearchParams();
        payload.append('action', 'reorder_activities');
        order.forEach(id => payload.append('order[]', id));

        try {
            const response = await fetch('teacher_unit.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode((string) ($selectedUnit['id'] ?? '')); ?>&mode=edit', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: payload.toString()
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || data.status !== 'success') {
                const errorMsg = data.message || 'Error al guardar el orden.';
                console.error('Reorder error response:', { status: response.status, data });
                throw new Error(errorMsg);
            }

            lastOrder = order;
            setStatus('Orden guardado correctamente.', false);
            
            // Reload page after 1 second to show updated order
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } catch (error) {
            const errMsg = (error && error.message) ? error.message : 'No fue posible guardar el orden.';
            setStatus(errMsg, true);
            console.error('Reorder failed:', error);
        } finally {
            isSaving = false;
        }
    }

    container.addEventListener('dragstart', function (event) {
        const target = event.target.closest('.draggable');
        if (!target) {
            return;
        }

        draggedItem = target;
        target.classList.add('dragging');
    });

    container.addEventListener('dragend', function (event) {
        const target = event.target.closest('.draggable');
        if (!target) {
            return;
        }

        target.classList.remove('dragging');
        draggedItem = null;
        saveOrder();
    });

    container.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (!draggedItem) {
            return;
        }

        const afterElement = getDragAfterElement(container, event.clientY);

        if (!afterElement) {
            container.appendChild(draggedItem);
        } else if (afterElement !== draggedItem) {
            container.insertBefore(draggedItem, afterElement);
        }
    });
})();
</script>
<?php } ?>
</body>
</html>

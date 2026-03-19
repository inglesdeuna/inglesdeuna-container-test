<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
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

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

function load_teacher_permission_from_accounts(PDO $pdo, string $teacherId): string
{
    if ($teacherId === '') {
        return 'viewer';
    }

    try {
        if (!table_exists($pdo, 'teacher_accounts')) {
            return 'viewer';
        }

        $stmt = $pdo->prepare("
            SELECT permission
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $permission = (string) $stmt->fetchColumn();

        return $permission === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        return 'viewer';
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

function load_english_units(PDO $pdo, string $phaseId): array
{
    if ($phaseId === '') {
        return [];
    }

    if (!table_exists($pdo, 'units') || !column_exists($pdo, 'units', 'phase_id')) {
        return [];
    }

    try {
        $orderBy = column_exists($pdo, 'units', 'position')
            ? 'ORDER BY position ASC, id ASC'
            : 'ORDER BY id ASC';

        $stmt = $pdo->prepare("
            SELECT id, name
            FROM units
            WHERE phase_id = :phase_id
            {$orderBy}
        ");
        $stmt->execute(['phase_id' => $phaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_technical_units(PDO $pdo, string $courseId, ?string $preferredUnitId = null, ?string $preferredUnitName = null): array
{
    $preferredUnitId = trim((string) $preferredUnitId);
    $preferredUnitName = trim((string) $preferredUnitName);

    if ($preferredUnitId !== '') {
        return [[
            'id' => $preferredUnitId,
            'name' => $preferredUnitName !== '' ? $preferredUnitName : 'Unidad',
        ]];
    }

    if ($courseId === '') {
        return [];
    }

    $candidates = [
        ['table' => 'course_units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'technical_units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'technical_units', 'course_column' => 'semester_id', 'name_column' => 'name'],
        ['table' => 'units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'units', 'course_column' => 'semester_id', 'name_column' => 'name'],
    ];

    foreach ($candidates as $candidate) {
        $table = $candidate['table'];
        $courseColumn = $candidate['course_column'];
        $nameColumn = $candidate['name_column'];

        if (
            !table_exists($pdo, $table) ||
            !column_exists($pdo, $table, 'id') ||
            !column_exists($pdo, $table, $courseColumn) ||
            !column_exists($pdo, $table, $nameColumn)
        ) {
            continue;
        }

        try {
            $orderBy = column_exists($pdo, $table, 'position')
                ? 'ORDER BY position ASC, id ASC'
                : 'ORDER BY id ASC';

            $stmt = $pdo->prepare("
                SELECT id, {$nameColumn} AS name
                FROM {$table}
                WHERE {$courseColumn} = :course_id
                {$orderBy}
            ");
            $stmt->execute(['course_id' => $courseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}

function load_activities_for_units(PDO $pdo, array $unitIds): array
{
    $unitIds = array_values(array_filter(array_map('strval', $unitIds), static fn ($v): bool => $v !== ''));
    if (empty($unitIds)) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));

        $orderBy = 'unit_id ASC, id ASC';
        if (column_exists($pdo, 'activities', 'position')) {
            $orderBy = 'unit_id ASC, COALESCE(position, 0) ASC, id ASC';
        }

        $sql = "
            SELECT id, type, unit_id
            FROM activities
            WHERE unit_id IN ($placeholders)
            ORDER BY {$orderBy}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($unitIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$selectedUnitId = trim((string) ($_GET['unit'] ?? ''));
$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$mode = (string) ($_GET['mode'] ?? 'view');
$mode = $mode === 'edit' ? 'edit' : 'view';
$step = max(0, (int) ($_GET['step'] ?? 0));

if ($assignmentId === '') {
    die('Asignación docente no especificada.');
}

$assignment = load_assignment($pdo, $assignmentId);

if (!$assignment || (string) ($assignment['teacher_id'] ?? '') !== $teacherId) {
    die('No tienes permiso para este curso.');
}

$permission = load_teacher_permission_from_accounts($pdo, $teacherId);
if ($mode === 'edit' && $permission !== 'editor') {
    $mode = 'view';
}

$programType = (string) ($assignment['program_type'] ?? 'technical');
$courseId = (string) ($assignment['course_id'] ?? '');
$courseName = (string) ($assignment['course_name'] ?? 'Curso');
$assignmentUnitId = (string) ($assignment['unit_id'] ?? '');
$assignmentUnitName = (string) ($assignment['unit_name'] ?? '');

if ($programType === 'english') {
    $allUnits = load_english_units($pdo, $courseId);
} else {
    $allUnits = load_technical_units($pdo, $courseId, $assignmentUnitId, $assignmentUnitName);
}

$units = [];
if ($selectedUnitId !== '') {
    foreach ($allUnits as $unit) {
        if ((string) ($unit['id'] ?? '') === $selectedUnitId) {
            $units[] = $unit;
            break;
        }
    }

    if (empty($units) && $selectedUnitId !== '') {
        $units[] = [
            'id' => $selectedUnitId,
            'name' => $assignmentUnitName !== '' ? $assignmentUnitName : 'Unidad',
        ];
    }
} else {
    $units = $allUnits;
    if (!empty($units)) {
        $selectedUnitId = (string) ($units[0]['id'] ?? '');
    }
}

$unitIds = array_values(array_filter(array_map(
    static fn ($unit) => (string) ($unit['id'] ?? ''),
    $units
)));

$unitMap = [];
foreach ($units as $unit) {
    $unitMap[(string) ($unit['id'] ?? '')] = (string) ($unit['name'] ?? 'Unidad');
}

$activities = load_activities_for_units($pdo, $unitIds);

$total = count($activities);
if ($step > max(0, $total - 1)) {
    $step = max(0, $total - 1);
}

$current = $total > 0 ? $activities[$step] : null;
$prevStep = max(0, $step - 1);
$nextStep = $step + 1;
$hasPrev = $step > 0;
$hasNext = $nextStep < $total;

$activityTypeLabels = [
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

$viewerHref = null;
$editorHref = null;
$currentTypeLabel = 'Actividad';
$currentUnitName = $assignmentUnitName !== '' ? $assignmentUnitName : 'Unidad';
$currentType = '';
$isMatchActivity = false;

if ($current) {
    $type = (string) ($current['type'] ?? '');
    $currentType = strtolower($type);
    $isMatchActivity = $currentType === 'match';

    $activityPath = get_activity_base_path($type);

    if ($activityPath) {
        $query = http_build_query([
            'id' => (string) ($current['id'] ?? ''),
            'unit' => (string) ($current['unit_id'] ?? ''),
            'embedded' => '1',
            'from' => 'teacher_course',
            'assignment' => $assignmentId,
        ]);

        $viewerHref = $activityPath . '/viewer.php?' . $query;

        if ($mode === 'edit' && $permission === 'editor') {
            $editorAbsolute = __DIR__ . '/../activities/' . $type . '/editor.php';
            if (file_exists($editorAbsolute)) {
                $editorHref = $activityPath . '/editor.php?' . $query;
            }
        }
    }

    $currentTypeLabel = $activityTypeLabels[$currentType] ?? ucwords(str_replace('_', ' ', $type));
    $currentUnitName = $unitMap[(string) ($current['unit_id'] ?? '')] ?? $currentUnitName;
}

$logoPath = '../hangman/assets/LETS%20NUEVO%20-%20copia.jpeg';
$backDashboard = 'dashboard.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '#unidades-curso';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Actividad</title>
<style>
:root{
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --danger:#dc2626;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --topbar:#3d69cf;
    --topbar-dark:#2f59b8;
}

*{
    box-sizing:border-box;
}

html, body{
    height:100%;
}

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
}

.topbar{
    background:linear-gradient(180deg, var(--topbar), var(--topbar-dark));
    padding:14px 24px;
    color:#fff;
}

.topbar-inner{
    max-width:1380px;
    margin:0 auto;
    display:grid;
    grid-template-columns:140px 1fr 140px;
    align-items:center;
    gap:12px;
}

.topbar-title{
    text-align:center;
    font-size:24px;
    font-weight:700;
    margin:0;
}

.top-btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:10px;
    color:#fff;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    box-shadow:var(--shadow);
}

.top-btn.back{
    background:#3359b8;
    justify-self:start;
}

.top-btn.logout{
    background:#d95b5b;
    justify-self:end;
}

.page{
    max-width:1380px;
    margin:0 auto;
    padding:16px 20px 20px;
}

.layout{
    display:grid;
    grid-template-columns:165px 1fr;
    gap:18px;
    align-items:start;
}

.sidebar{
    background:#eaf0fb;
    min-height:calc(100vh - 110px);
    padding:20px 12px;
}

.logo-wrap{
    text-align:center;
    margin-bottom:18px;
}

.logo-wrap img{
    width:110px;
    max-width:100%;
    border-radius:10px;
    display:block;
    margin:0 auto;
}

.side-btn{
    display:block;
    width:100%;
    padding:12px 10px;
    margin-bottom:12px;
    border-radius:10px;
    text-decoration:none;
    color:#fff;
    font-size:13px;
    font-weight:700;
    text-align:center;
    box-shadow:var(--shadow);
}

.side-btn.blue{
    background:linear-gradient(180deg,#4f77df,#355fc9);
}

.side-btn.orange{
    background:linear-gradient(180deg,#f2b33e,#e39a12);
}

.side-btn.green{
    background:linear-gradient(180deg,#62c56c,#40a853);
}

.content{
    padding:4px 0;
}

.viewer-shell{
    background:#f2f5fb;
    border-radius:22px;
    padding:18px;
    border:1px solid var(--line);
    box-shadow:var(--shadow);
}

.viewer-card{
    background:var(--card);
    border-radius:20px;
    border:1px solid var(--line);
    box-shadow:var(--shadow);
    padding:18px 18px 16px;
}

.viewer-header{
    margin-bottom:10px;
}

.viewer-title{
    margin:0 0 6px;
    font-size:18px;
    font-weight:700;
    color:#3c63c7;
}

.viewer-subtitle{
    margin:0;
    font-size:13px;
    color:var(--muted);
}

.viewer-frame-wrap{
    background:#fff;
    border:1px solid #e6ebf4;
    border-radius:16px;
    padding:6px;
    height:calc(100vh - 240px);
    min-height:620px;
    overflow:hidden;
}

.viewer-frame{
    width:100%;
    height:100%;
    border:0;
    border-radius:12px;
    background:#fff;
    display:block;
}

.controls{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    margin-top:14px;
}

.nav-btn{
    display:inline-block;
    min-width:130px;
    text-align:center;
    padding:12px 18px;
    border-radius:10px;
    text-decoration:none;
    color:#fff;
    font-size:14px;
    font-weight:700;
    box-shadow:var(--shadow);
}

.nav-btn.prev,
.nav-btn.next{
    background:linear-gradient(180deg,#4f77df,#355fc9);
}

.nav-btn.disabled{
    opacity:.45;
    pointer-events:none;
}

.editor-link{
    display:inline-block;
    margin-top:12px;
    padding:10px 14px;
    border-radius:8px;
    background:var(--blue);
    color:#fff;
    text-decoration:none;
    font-size:14px;
    font-weight:700;
}

.empty-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    padding:24px;
    box-shadow:var(--shadow);
}

.empty-card h2{
    margin-top:0;
    font-size:22px;
    color:#1f3c75;
}

.empty-card p{
    font-size:15px;
    color:var(--muted);
}

.empty-card a{
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
}

/* SOLO PARA MATCH */
.match-activity-page .layout{
    display:block;
}

.match-activity-page .sidebar{
    display:none;
}

.match-activity-page .content{
    padding:0;
    width:100%;
}

.match-activity-page .viewer-shell{
    background:transparent;
    border-radius:0;
    padding:0;
    border:none;
    box-shadow:none;
}

.match-activity-page .viewer-card{
    width:100%;
    max-width:1280px;
    margin:0 auto;
    padding:14px 14px 12px;
}

.match-activity-page .viewer-frame-wrap{
    background:#fff;
    border:1px solid #e6ebf4;
    border-radius:16px;
    padding:0;
    height:auto;
    min-height:0;
    overflow:visible;
}

.match-activity-page .viewer-frame{
    height:900px;
    border-radius:16px;
}

@media (max-width: 980px){
    .topbar-inner{
        grid-template-columns:1fr;
        text-align:center;
    }

    .top-btn.back,
    .top-btn.logout{
        justify-self:center;
    }

    .layout{
        grid-template-columns:1fr;
    }

    .sidebar{
        min-height:auto;
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:12px;
        align-items:start;
    }

    .logo-wrap{
        grid-column:1 / -1;
    }

    .viewer-frame-wrap{
        height:calc(100vh - 300px);
        min-height:520px;
    }

    .match-activity-page .sidebar{
        display:none;
    }

    .match-activity-page .viewer-card{
        padding:12px 12px 10px;
    }

    .match-activity-page .viewer-frame-wrap{
        height:auto;
        min-height:0;
    }

    .match-activity-page .viewer-frame{
        height:820px;
    }
}

@media (max-width: 768px){
    .page{
        padding:12px;
    }

    .topbar{
        padding:14px;
    }

    .viewer-shell{
        padding:12px;
    }

    .viewer-card{
        padding:14px 12px 12px;
    }

    .topbar-title{
        font-size:22px;
    }

    .viewer-title{
        font-size:17px;
    }

    .viewer-frame-wrap{
        height:calc(100vh - 260px);
        min-height:460px;
    }

    .controls{
        flex-direction:column;
    }

    .nav-btn{
        width:100%;
    }

    .match-activity-page .viewer-shell{
        padding:0;
    }

    .match-activity-page .viewer-card{
        padding:10px;
    }

    .match-activity-page .viewer-frame-wrap{
        height:auto;
        min-height:0;
    }

    .match-activity-page .viewer-frame{
        height:720px;
    }
}
</style>
</head>
<body class="<?php echo $isMatchActivity ? 'match-activity-page' : ''; ?>">
<header class="topbar">
    <div class="topbar-inner">
        <a class="top-btn back" href="<?php echo h($backDashboard); ?>">← Volver</a>
        <h1 class="topbar-title">Actividad</h1>
        <a class="top-btn logout" href="logout.php">Logout</a>
    </div>
</header>

<div class="page">
    <?php if (!$current || !$viewerHref) { ?>
        <div class="content" style="padding-top:20px;">
            <div class="empty-card">
                <h2>No hay actividades disponibles.</h2>
                <p>Esta unidad aún no tiene actividades para presentar o el tipo de actividad no tiene viewer configurado.</p>
                <a href="<?php echo h($backDashboard); ?>">← Volver al panel docente</a>
            </div>
        </div>
    <?php } else { ?>
        <div class="layout">
            <aside class="sidebar">
                <div class="logo-wrap">
                    <img src="<?php echo h($logoPath); ?>" alt="Let's aprende inglés">
                </div>

                <a class="side-btn blue" href="<?php echo h($backDashboard); ?>">📘 Mis Cursos</a>

                <?php if ($permission === 'editor') { ?>
                    <a class="side-btn orange" href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=edit&step=<?php echo $step; ?>">🧑‍🏫 Editar Cursos</a>
                <?php } ?>

                <a class="side-btn green" href="teacher_groups.php">👥 Ver Estudiantes</a>
            </aside>

            <main class="content">
                <div class="viewer-shell">
                    <section class="viewer-card">
                        <div class="viewer-header">
                            <h2 class="viewer-title"><?php echo h($currentTypeLabel); ?></h2>
                            <p class="viewer-subtitle">Presentación de la actividad del curso para el docente.</p>
                        </div>

                        <div class="viewer-frame-wrap">
                            <iframe
                                id="activityViewer"
                                class="viewer-frame"
                                src="<?php echo h($viewerHref); ?>"
                                title="Viewer actividad"
                            ></iframe>
                        </div>

                        <div class="controls">
                            <a class="nav-btn prev <?php echo $hasPrev ? '' : 'disabled'; ?>"
                               href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">
                                ← Previous
                            </a>

                            <a class="nav-btn next <?php echo $hasNext ? '' : 'disabled'; ?>"
                               href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasNext ? $nextStep : $step; ?>">
                                Next →
                            </a>
                        </div>

                        <?php if ($editorHref !== null) { ?>
                            <a class="editor-link" target="_blank" rel="noopener noreferrer" href="<?php echo h($editorHref); ?>">
                                Editar actividad actual
                            </a>
                        <?php } ?>
                    </section>
                </div>
            </main>
        </div>
    <?php } ?>
</div>

<script>
(function () {
    const iframe = document.getElementById('activityViewer');
    const isMatchActivity = <?php echo $isMatchActivity ? 'true' : 'false'; ?>;

    if (!iframe) {
        return;
    }

    function resizeIframe() {
        if (!isMatchActivity) {
            return;
        }

        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) {
                return;
            }

            const body = doc.body;
            const html = doc.documentElement;

            const height = Math.max(
                body ? body.scrollHeight : 0,
                body ? body.offsetHeight : 0,
                html ? html.scrollHeight : 0,
                html ? html.offsetHeight : 0,
                720
            );

            iframe.style.height = height + 'px';
        } catch (e) {
            // Ignorar
        }
    }

    function hideEmbeddedBackButton() {
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) {
                return;
            }

            const selectors = [
                '.back',
                '.btn-volver',
                '.back-button',
                '.btn.back',
                '.back-btn',
                'a[href*="dashboard"]',
                'a[href*="unit_view"]',
                'a[href*="technical_units"]',
                'a[href*="english_structure_units"]'
            ];

            selectors.forEach((selector) => {
                doc.querySelectorAll(selector).forEach((el) => {
                    const text = (el.textContent || '').toLowerCase();
                    const href = (el.getAttribute('href') || '').toLowerCase();

                    if (
                        text.includes('volver') ||
                        text.includes('back') ||
                        href.includes('dashboard') ||
                        href.includes('unit_view') ||
                        href.includes('technical_units') ||
                        href.includes('english_structure_units')
                    ) {
                        el.style.display = 'none';
                    }
                });
            });

            doc.querySelectorAll('a, button').forEach((el) => {
                const text = (el.textContent || '').trim().toLowerCase();
                if (text === 'back' || text === 'volver' || text === '← volver' || text === '↩ back') {
                    el.style.display = 'none';
                }
            });

            if (isMatchActivity) {
                const style = doc.createElement('style');
                style.innerHTML = `
                    html, body{
                        margin:0 !important;
                        padding:0 !important;
                        overflow:visible !important;
                    }
                `;
                doc.head.appendChild(style);

                resizeIframe();
                setTimeout(resizeIframe, 200);
                setTimeout(resizeIframe, 700);
            } else {
                const style = doc.createElement('style');
                style.innerHTML = `
                    body{
                        margin-top:0 !important;
                        padding-top:0 !important;
                    }
                `;
                doc.head.appendChild(style);
            }
        } catch (e) {
            // Ignorar si algún viewer no permite manipulación.
        }
    }

    iframe.addEventListener('load', hideEmbeddedBackButton);
    window.addEventListener('resize', resizeIframe);
})();
</script>
</body>
</html>

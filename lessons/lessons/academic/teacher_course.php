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

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    } catch (Throwable $e) {
        return null;
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

$accountId = trim((string) ($_GET['account'] ?? ''));
$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$mode = (string) ($_GET['mode'] ?? 'view');
$mode = $mode === 'edit' ? 'edit' : 'view';
$step = max(0, (int) ($_GET['step'] ?? 0));

if ($accountId === '') {
    die('Cuenta docente no especificada.');
}

try {
    $stmtAccount = $pdo->prepare("
        SELECT id, teacher_id, target_name, target_id, scope, permission
        FROM teacher_accounts
        WHERE id = :id
        LIMIT 1
    ");
    $stmtAccount->execute(['id' => $accountId]);
    $account = $stmtAccount->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $account = false;
}

if (!$account || (string) ($account['teacher_id'] ?? '') !== $teacherId) {
    die('No tienes permiso para este curso.');
}

$scope = (string) ($account['scope'] ?? 'technical');
$targetId = (string) ($account['target_id'] ?? '');
$permission = (string) ($account['permission'] ?? 'viewer');

if ($mode === 'edit' && $permission !== 'editor') {
    $mode = 'view';
}

try {
    if ($scope === 'english') {
        $stmtUnits = $pdo->prepare("
            SELECT id, name, position
            FROM units
            WHERE phase_id = :target
            ORDER BY position ASC, id ASC
        ");
    } else {
        $stmtUnits = $pdo->prepare("
            SELECT id, name, position
            FROM units
            WHERE course_id = :target
            ORDER BY position ASC, id ASC
        ");
    }

    $stmtUnits->execute(['target' => $targetId]);
    $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $units = [];
}

$unitIds = array_values(array_filter(array_map(
    fn($unit) => (string) ($unit['id'] ?? ''),
    $units
)));

$unitMap = [];
foreach ($units as $unit) {
    $unitMap[(string) ($unit['id'] ?? '')] = (string) ($unit['name'] ?? 'Unidad');
}

$activities = [];
if (!empty($unitIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $sql = "
            SELECT id, type, unit_id, COALESCE(position, 0) AS pos
            FROM activities
            WHERE unit_id IN ($placeholders)
            ORDER BY unit_id ASC, pos ASC, id ASC
        ";
        $stmtActivities = $pdo->prepare($sql);
        $stmtActivities->execute($unitIds);
        $activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $activities = [];
    }
}

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

$sidebarItems = [];
foreach ($activities as $index => $activity) {
    $rawType = (string) ($activity['type'] ?? 'activity');
    $normalizedType = strtolower($rawType);

    if (isset($sidebarItems[$normalizedType])) {
        continue;
    }

    $sidebarItems[$normalizedType] = [
        'label' => $activityTypeLabels[$normalizedType] ?? ucwords(str_replace('_', ' ', $normalizedType)),
        'step' => $index,
    ];
}

$viewerHref = null;
$editorHref = null;
$currentTypeLabel = 'Actividad';
$currentUnitName = 'Unidad';

if ($current) {
    $type = (string) ($current['type'] ?? '');
    $activityPath = get_activity_base_path($type);

    if ($activityPath) {
        $query = http_build_query([
            'id' => (string) ($current['id'] ?? ''),
            'unit' => (string) ($current['unit_id'] ?? ''),
            'embedded' => '1',
            'from' => 'teacher_course',
        ]);

        $viewerHref = $activityPath . '/viewer.php?' . $query;

        if ($mode === 'edit' && $permission === 'editor') {
            $editorAbsolute = __DIR__ . '/../activities/' . $type . '/editor.php';
            if (file_exists($editorAbsolute)) {
                $editorHref = $activityPath . '/editor.php?' . $query;
            }
        }
    }

    $currentTypeLabel = $activityTypeLabels[strtolower($type)] ?? ucwords(str_replace('_', ' ', $type));
    $currentUnitName = $unitMap[(string) ($current['unit_id'] ?? '')] ?? 'Unidad';
}

$logoPath = '../hangman/assets/LETS%20NUEVO%20-%20copia.jpeg';
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
    --title:#1f3c75;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --badge-bg:#eef2ff;
    --badge-text:#1f4ec9;
    --danger:#dc2626;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --topbar:#3d69cf;
    --topbar-dark:#2f59b8;
    --green:#43b05c;
    --orange:#f0a629;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
}

.topbar{
    background:linear-gradient(180deg, var(--topbar), var(--topbar-dark));
    padding:22px 30px;
    color:#fff;
}

.topbar-inner{
    max-width:1280px;
    margin:0 auto;
    display:grid;
    grid-template-columns:160px 1fr 160px;
    align-items:center;
    gap:16px;
}

.topbar-title{
    text-align:center;
    font-size:28px;
    font-weight:700;
    margin:0;
}

.top-btn{
    display:inline-block;
    padding:12px 18px;
    border-radius:10px;
    color:#fff;
    text-decoration:none;
    font-size:14px;
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
    max-width:1280px;
    margin:0 auto;
    padding:0 30px 30px;
}

.layout{
    display:grid;
    grid-template-columns:180px 1fr;
    gap:22px;
    align-items:start;
}

.sidebar{
    background:#eaf0fb;
    min-height:calc(100vh - 140px);
    padding:26px 14px;
}

.logo-wrap{
    background:transparent;
    text-align:center;
    margin-bottom:28px;
}

.logo-wrap img{
    width:120px;
    max-width:100%;
    border-radius:10px;
    display:block;
    margin:0 auto;
}

.side-btn{
    display:block;
    width:100%;
    padding:13px 12px;
    margin-bottom:14px;
    border-radius:10px;
    text-decoration:none;
    color:#fff;
    font-size:14px;
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
    padding:26px 0;
}

.viewer-shell{
    background:#f2f5fb;
    border-radius:22px;
    padding:36px;
    border:1px solid var(--line);
    box-shadow:var(--shadow);
}

.viewer-card{
    background:var(--card);
    border-radius:20px;
    border:1px solid var(--line);
    box-shadow:var(--shadow);
    padding:34px 36px 28px;
}

.viewer-header{
    margin-bottom:18px;
}

.viewer-title{
    margin:0 0 12px;
    font-size:22px;
    font-weight:700;
    color:#3c63c7;
}

.viewer-subtitle{
    margin:0;
    font-size:15px;
    color:var(--muted);
}

.viewer-frame-wrap{
    background:#fff;
    border:1px solid #e6ebf4;
    border-radius:16px;
    padding:8px;
    min-height:420px;
}

.viewer-frame{
    width:100%;
    min-height:430px;
    border:0;
    border-radius:12px;
    background:#fff;
}

.controls{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    margin-top:26px;
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

.nav-btn.prev{
    background:linear-gradient(180deg,#4f77df,#355fc9);
}

.nav-btn.next{
    background:linear-gradient(180deg,#4f77df,#355fc9);
}

.nav-btn.disabled{
    opacity:.45;
    pointer-events:none;
}

.meta-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.badges{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:var(--badge-bg);
    color:var(--badge-text);
    font-size:12px;
    font-weight:700;
}

.editor-link{
    display:inline-block;
    margin-top:16px;
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
    color:var(--title);
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
}

@media (max-width: 768px){
    .page{
        padding:0 20px 20px;
    }

    .topbar{
        padding:20px;
    }

    .viewer-shell{
        padding:18px;
    }

    .viewer-card{
        padding:22px 18px;
    }

    .topbar-title{
        font-size:24px;
    }

    .viewer-title{
        font-size:20px;
    }
}
</style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="top-btn back" href="dashboard.php">← Volver</a>
        <h1 class="topbar-title">Actividad</h1>
        <a class="top-btn logout" href="logout.php">Logout</a>
    </div>
</header>

<div class="page">
    <?php if (!$current || !$viewerHref) { ?>
        <div class="content" style="padding-top:30px;">
            <div class="empty-card">
                <h2>No hay actividades disponibles.</h2>
                <p>Este curso aún no tiene actividades para presentar o el tipo de actividad no tiene viewer configurado.</p>
                <a href="dashboard.php">← Volver al panel docente</a>
            </div>
        </div>
    <?php } else { ?>
        <div class="layout">
            <aside class="sidebar">
                <div class="logo-wrap">
                    <img src="<?php echo h($logoPath); ?>" alt="Let's aprende inglés">
                </div>

                <a class="side-btn blue" href="dashboard.php">📘 Mis Cursos</a>

                <?php if ($permission === 'editor') { ?>
                    <a class="side-btn orange" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=edit&step=<?php echo $step; ?>">🧑‍🏫 Editar Cursos</a>
                <?php } ?>

                <a class="side-btn green" href="teacher_groups.php">👥 Ver Estudiantes</a>
            </aside>

            <main class="content">
                <div class="viewer-shell">
                    <div class="meta-row">
                        <div class="badges">
                            <span class="badge"><?php echo h((string) ($account['target_name'] ?? 'Curso')); ?></span>
                            <span class="badge"><?php echo h($currentUnitName); ?></span>
                            <span class="badge"><?php echo h($currentTypeLabel); ?></span>
                        </div>

                        <div class="badges">
                            <span class="badge">Actividad <?php echo $step + 1; ?> de <?php echo $total; ?></span>
                            <span class="badge"><?php echo h($mode === 'edit' ? 'Modo edición' : 'Modo presentación'); ?></span>
                        </div>
                    </div>

                    <section class="viewer-card">
                        <div class="viewer-header">
                            <h2 class="viewer-title">🎯 <?php echo h($currentTypeLabel); ?></h2>
                            <p class="viewer-subtitle">
                                Presentación de la actividad del curso para el docente.
                            </p>
                        </div>

                        <div class="viewer-frame-wrap">
                            <iframe class="viewer-frame" src="<?php echo h($viewerHref); ?>" title="Viewer actividad"></iframe>
                        </div>

                        <div class="controls">
                            <a class="nav-btn prev <?php echo $hasPrev ? '' : 'disabled'; ?>" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">← Previous</a>

                            <a class="nav-btn next <?php echo $hasNext ? '' : 'disabled'; ?>" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasNext ? $nextStep : $step; ?>">Next →</a>
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
</body>
</html>

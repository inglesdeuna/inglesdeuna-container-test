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
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Presentación docente</title>
<style>
:root{
    --bg:#e9edf7;
    --panel:#f3f6fc;
    --card:#ffffff;
    --line:#d8e0f0;
    --blue:#3568c9;
    --green:#47ad66;
    --orange:#efab3b;
    --muted:#6f7f9a;
    --text:#22304f;
    --danger:#d9534f;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    font-family:Arial,sans-serif;
    color:var(--text);
}
.page{
    max-width:1280px;
    margin:0 auto;
    padding:22px;
}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    background:#dce4f3;
    border-radius:16px;
    padding:16px 20px;
    margin-bottom:16px;
}
.logo{
    font-size:28px;
    font-weight:700;
    color:#1f4d95;
}
.logout{
    background:var(--danger);
    color:#fff;
    text-decoration:none;
    padding:11px 22px;
    border-radius:12px;
    font-weight:700;
}
.breadcrumb{
    background:#dfe4ef;
    border-radius:999px;
    padding:12px 20px;
    color:#66758f;
    margin-bottom:18px;
    font-size:18px;
}
.layout{
    display:grid;
    grid-template-columns:320px 1fr;
    gap:18px;
}
.sidebar{
    background:var(--panel);
    border-radius:12px;
    padding:16px;
}
.nav-btn{
    display:block;
    width:100%;
    text-decoration:none;
    color:#fff;
    border-radius:10px;
    padding:12px 16px;
    margin-bottom:12px;
    font-size:22px;
    font-weight:700;
    background:#8195c2;
}
.nav-btn.quiz{background:var(--green)}
.nav-btn.video{background:var(--orange)}
.nav-btn.active{outline:3px solid rgba(31,102,204,.35)}
.content{text-align:center}
h1{
    margin:6px 0 10px;
    color:#2f61c5;
    font-size:54px;
}
.listen{
    display:inline-block;
    background:var(--blue);
    padding:10px 22px;
    border-radius:14px;
    color:#fff;
    text-decoration:none;
    font-weight:700;
    margin-bottom:14px;
}
.viewer-card{
    background:var(--card);
    border-radius:24px;
    min-height:620px;
    max-width:760px;
    margin:0 auto;
    padding:24px;
    border:1px solid var(--line);
    box-shadow:0 14px 36px rgba(28,51,95,.12);
    display:flex;
    flex-direction:column;
}
.meta{
    font-size:18px;
    color:var(--muted);
    margin-bottom:14px;
}
.viewer-frame{
    flex:1;
    width:100%;
    border:0;
    border-radius:14px;
    background:#fafbfd;
    min-height:440px;
}
.controls{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin-top:18px;
}
.btn{
    display:inline-block;
    color:#fff;
    text-decoration:none;
    padding:12px 24px;
    border-radius:14px;
    font-size:34px;
    font-weight:700;
}
.btn.prev{background:var(--blue)}
.btn.next{background:var(--green)}
.btn.disabled{
    pointer-events:none;
    opacity:.5;
}
.mode{
    margin-top:12px;
    font-size:16px;
    color:var(--muted);
}
.mode a{
    color:#1f66cc;
    text-decoration:none;
    font-weight:700;
}
.editor-link{
    display:inline-block;
    margin-top:10px;
    background:#15803d;
    color:#fff;
    text-decoration:none;
    padding:10px 16px;
    border-radius:10px;
    font-weight:700;
}
.empty-card{
    background:#fff;
    border-radius:20px;
    border:1px solid var(--line);
    padding:24px;
    text-align:left;
}
@media (max-width:1100px){
    .layout{grid-template-columns:1fr}
    .nav-btn{font-size:20px}
    .btn{font-size:24px}
    h1{font-size:36px}
}
</style>
</head>
<body>
<div class="page">
    <header class="top">
        <div class="logo">Let's aprende inglés</div>
        <a class="logout" href="logout.php">Logout</a>
    </header>

    <div class="breadcrumb">
        Home &nbsp;›&nbsp; <?php echo h((string) ($account['target_name'] ?? 'Curso')); ?> &nbsp;›&nbsp; <?php echo h($currentUnitName); ?> &nbsp;›&nbsp; <strong><?php echo h($currentTypeLabel); ?></strong>
    </div>

    <?php if (!$current || !$viewerHref) { ?>
        <div class="empty-card">
            <h2 style="margin-top:0">No hay actividades disponibles.</h2>
            <p>Este curso aún no tiene actividades para presentar o el tipo de actividad no tiene viewer configurado.</p>
            <a href="dashboard.php">← Volver al panel docente</a>
        </div>
    <?php } else { ?>
        <div class="layout">
            <aside class="sidebar">
                <a class="nav-btn" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">← Prev</a>

                <?php foreach ($sidebarItems as $type => $item) { ?>
                    <?php
                    $typeClass = '';
                    if ($type === 'quiz' || $type === 'multiple_choice') {
                        $typeClass .= ' quiz';
                    } elseif ($type === 'video_lesson' || $type === 'flipbooks') {
                        $typeClass .= ' video';
                    }

                    if ((int) $item['step'] === $step) {
                        $typeClass .= ' active';
                    }
                    ?>
                    <a class="nav-btn<?php echo h($typeClass); ?>" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo (int) $item['step']; ?>">
                        <?php echo h((string) $item['label']); ?>
                    </a>
                <?php } ?>
            </aside>

            <main class="content">
                <h1><?php echo h($currentTypeLabel); ?></h1>
                <a class="listen" href="<?php echo h($viewerHref); ?>" target="_blank" rel="noopener noreferrer">🔊 Listen</a>

                <section class="viewer-card">
                    <div class="meta">
                        Actividad <?php echo $step + 1; ?> de <?php echo $total; ?> ·
                        Modo <?php echo h($mode === 'edit' ? 'edición' : 'presentación'); ?>
                    </div>

                    <iframe class="viewer-frame" src="<?php echo h($viewerHref); ?>" title="Viewer actividad"></iframe>

                    <div class="controls">
                        <a class="btn prev <?php echo $hasPrev ? '' : 'disabled'; ?>" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">← Prev</a>
                        <a class="btn next <?php echo $hasNext ? '' : 'disabled'; ?>" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasNext ? $nextStep : $step; ?>">Next →</a>
                    </div>
                </section>

                <?php if ($editorHref !== null) { ?>
                    <a class="editor-link" target="_blank" rel="noopener noreferrer" href="<?php echo h($editorHref); ?>">Editar actividad actual</a>
                <?php } ?>

                <div class="mode">
                    <a href="dashboard.php">← Volver al perfil docente</a>
                </div>
            </main>
        </div>
    <?php } ?>
</div>
</body>
</html>

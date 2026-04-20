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

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));
$accountId = trim((string) ($_GET['account'] ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$requestedUnitId = trim((string) ($_GET['unit'] ?? ''));
$mode = (string) ($_GET['mode'] ?? 'view');
$mode = $mode === 'edit' ? 'edit' : 'view';
$step = max(0, (int) ($_GET['step'] ?? 0));

if ($teacherId === '') {
    die('Sesión inválida.');
}

if ($accountId === '' && $assignmentId === '') {
    die('Cuenta o asignación docente no especificada.');
}

$scope = 'technical';
$targetId = '';
$permission = 'viewer';
$titleFromSource = 'Curso';

if ($assignmentId !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, teacher_id, program_type, course_id, course_name
            FROM teacher_assignments
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $assignment = false;
    }

    if (!$assignment || (string) ($assignment['teacher_id'] ?? '') !== $teacherId) {
        die('No tienes permiso para este curso.');
    }

    $scope = ((string) ($assignment['program_type'] ?? '') === 'english') ? 'english' : 'technical';
    $targetId = trim((string) ($assignment['course_id'] ?? ''));
    $titleFromSource = trim((string) ($assignment['course_name'] ?? 'Curso'));

    try {
        $stmtPerm = $pdo->prepare("
            SELECT permission
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
              AND scope = :scope
              AND target_id = :target_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmtPerm->execute([
            'teacher_id' => $teacherId,
            'scope' => $scope,
            'target_id' => $targetId,
        ]);
        $permissionDb = (string) $stmtPerm->fetchColumn();
        $permission = $permissionDb === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        $permission = 'viewer';
    }
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT id, teacher_id, target_name, target_id, scope, permission
            FROM teacher_accounts
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $account = false;
    }

    if (!$account || (string) ($account['teacher_id'] ?? '') !== $teacherId) {
        die('No tienes permiso para este curso.');
    }

    $scope = (string) ($account['scope'] ?? 'technical');
    $targetId = trim((string) ($account['target_id'] ?? ''));
    $permission = ((string) ($account['permission'] ?? 'viewer') === 'editor') ? 'editor' : 'viewer';
    $titleFromSource = trim((string) ($account['target_name'] ?? 'Curso'));
}

if ($targetId === '') {
    die('No se encontró el curso asignado.');
}

if ($mode === 'edit' && $permission !== 'editor') {
    $mode = 'view';
}

try {
    if ($scope === 'english') {
        $stmtUnits = $pdo->prepare("
            SELECT id, name, COALESCE(position, 0) AS position
            FROM units
            WHERE phase_id = :target
            ORDER BY position ASC, id ASC
        ");
    } else {
        $stmtUnits = $pdo->prepare("
            SELECT id, name, COALESCE(position, 0) AS position
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
    static fn ($unit) => (string) ($unit['id'] ?? ''),
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

if ($requestedUnitId !== '' && !empty($activities)) {
    foreach ($activities as $index => $activityRow) {
        if ((string) ($activityRow['unit_id'] ?? '') === $requestedUnitId) {
            $step = $index;
            break;
        }
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
    'memory_cards' => 'Memory Cards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Quiz',
    'video_comprehension' => 'Video Comprehension',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Video Lesson',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'order_sentences' => 'Order the Sentences',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'dot_to_dot' => 'Dot to Dot',
    'external' => 'External',
    'powerpoint' => 'PowerPoint',
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
$currentUnitName = $requestedUnitId !== '' ? ($unitMap[$requestedUnitId] ?? 'Unidad') : 'Unidad';

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
    --green:#16a34a;
    --green-hover:#15803d;
    --orange:#f59e0b;
    --orange-hover:#d97706;
    --danger:#dc2626;
    --shadow:0 8px 24px rgba(0,0,0,.08);
}

*{box-sizing:border-box;}

body{
    margin:0;
    font-family:Arial, "Segoe UI", sans-serif;
    background:var(--bg);
    color:var(--text);
}

.page{
    max-width:1400px;
    margin:0 auto;
    padding:24px;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    margin-bottom:18px;
}

.brand{
    display:flex;
    align-items:center;
    gap:10px;
}

.brand img{
    width:44px;
    height:44px;
    border-radius:10px;
    object-fit:cover;
    border:1px solid #d9e2f0;
    background:#fff;
}

.brand h1{
    margin:0;
    font-size:24px;
    color:var(--title);
}

.header-right{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:#eef2ff;
    color:#1f4ec9;
}

.link-btn{
    display:inline-block;
    text-decoration:none;
    padding:10px 14px;
    border-radius:10px;
    font-size:13px;
    font-weight:700;
    color:#fff;
}

.link-back{ background:linear-gradient(180deg,#4b8ce0,#2f6ec4); }
.link-logout{ background:linear-gradient(180deg,#ef4444,#b91c1c); }

.layout{
    display:grid;
    grid-template-columns:280px minmax(0, 1fr);
    gap:18px;
}

.panel,
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:14px;
    box-shadow:var(--shadow);
}

.panel{ padding:14px; }

.panel-title{
    margin:0 0 10px;
    font-size:14px;
    font-weight:700;
    color:var(--title);
}

.activity-list{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.activity-item{
    display:block;
    text-decoration:none;
    border:1px solid var(--line);
    border-radius:10px;
    padding:10px;
    color:#1f3559;
    font-size:13px;
    font-weight:700;
    background:#fff;
}

.activity-item.active{
    border-color:#60a5fa;
    background:#eff6ff;
}

.main{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.course-title{
    margin:0;
    color:var(--title);
    font-size:22px;
}

.meta{
    margin:4px 0 0;
    color:var(--muted);
    font-size:13px;
}

.card{ padding:16px; }

.viewer-wrap{
    width:100%;
    min-height:520px;
    border:1px solid var(--line);
    border-radius:12px;
    overflow:hidden;
    background:#fff;
}

.viewer-wrap iframe{
    width:100%;
    min-height:520px;
    border:0;
    display:block;
}

.empty{
    border:1px dashed #b7c6dc;
    border-radius:12px;
    padding:18px;
    color:#4a5a73;
    font-size:14px;
    background:#f8fbff;
}

.controls{
    display:flex;
    flex-wrap:wrap;
    justify-content:space-between;
    gap:10px;
    align-items:center;
}

.step{
    font-size:13px;
    color:#334155;
    font-weight:700;
}

.buttons{ display:flex; flex-wrap:wrap; gap:8px; }

.btn{
    display:inline-block;
    text-decoration:none;
    border-radius:10px;
    padding:10px 14px;
    font-size:13px;
    font-weight:700;
    color:#fff;
}

.btn-prev{ background:linear-gradient(180deg,#94a3b8,#64748b); }
.btn-next{ background:linear-gradient(180deg,#3b82f6,#1d4ed8); }
.btn-view{ background:linear-gradient(180deg,#10b981,#047857); }
.btn-edit{ background:linear-gradient(180deg,#f59e0b,#d97706); }
.btn-disabled{ background:#cbd5e1; pointer-events:none; }

@media (max-width: 1024px){
    .layout{ grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="brand">
            <img src="<?php echo h($logoPath); ?>" alt="Logo">
            <div>
                <h1>Curso del Docente</h1>
                <p class="meta"><?php echo h($titleFromSource); ?></p>
            </div>
        </div>

        <div class="header-right">
            <span class="badge"><?php echo h($scope === 'english' ? 'English' : 'Técnico'); ?></span>
            <span class="badge"><?php echo h($permission === 'editor' ? 'Puede editar' : 'Solo ver'); ?></span>
            <a class="link-btn link-back" href="dashboard.php?assignment=<?php echo urlencode($assignmentId); ?>#unidades-curso">Volver</a>
            <a class="link-btn link-logout" href="logout.php">Cerrar sesión</a>
        </div>
    </div>

    <div class="layout">
        <aside class="panel">
            <h2 class="panel-title">Tipos de actividad</h2>
            <div class="activity-list">
                <?php if (empty($sidebarItems)) { ?>
                    <div class="empty">No hay actividades en este curso.</div>
                <?php } else { ?>
                    <?php foreach ($sidebarItems as $item) { ?>
                        <?php $itemStep = (int) ($item['step'] ?? 0); ?>
                        <a class="activity-item <?php echo $itemStep === $step ? 'active' : ''; ?>" href="teacher_course.php?<?php
                            echo h(http_build_query([
                                'assignment' => $assignmentId,
                                'account' => $accountId,
                                'unit' => $requestedUnitId,
                                'mode' => $mode,
                                'step' => $itemStep,
                            ]));
                        ?>">
                            <?php echo h((string) ($item['label'] ?? 'Actividad')); ?>
                        </a>
                    <?php } ?>
                <?php } ?>
            </div>
        </aside>

        <main class="main">
            <div class="card">
                <h2 class="course-title"><?php echo h($currentTypeLabel); ?></h2>
                <p class="meta">Unidad: <?php echo h($currentUnitName); ?></p>
            </div>

            <div class="card">
                <?php if ($viewerHref) { ?>
                    <div class="viewer-wrap">
                        <iframe src="<?php echo h($viewerHref); ?>" title="Actividad"></iframe>
                    </div>
                <?php } else { ?>
                    <div class="empty">No hay actividad disponible para mostrar.</div>
                <?php } ?>
            </div>

            <div class="card controls">
                <div class="step">Paso <?php echo h((string) ($step + 1)); ?> de <?php echo h((string) max(1, $total)); ?></div>

                <div class="buttons">
                    <?php
                    $baseParams = [
                        'assignment' => $assignmentId,
                        'account' => $accountId,
                        'unit' => $requestedUnitId,
                        'mode' => $mode,
                    ];
                    ?>
                    <a class="btn btn-prev <?php echo !$hasPrev ? 'btn-disabled' : ''; ?>" href="teacher_course.php?<?php echo h(http_build_query($baseParams + ['step' => $prevStep])); ?>">Anterior</a>
                    <a class="btn btn-next <?php echo !$hasNext ? 'btn-disabled' : ''; ?>" href="teacher_course.php?<?php echo h(http_build_query($baseParams + ['step' => $nextStep])); ?>">Siguiente</a>

                    <?php if ($viewerHref) { ?>
                        <a class="btn btn-view" href="<?php echo h($viewerHref); ?>" target="_blank" rel="noopener">Abrir viewer</a>
                    <?php } ?>

                    <?php if ($editorHref) { ?>
                        <a class="btn btn-edit" href="<?php echo h($editorHref); ?>" target="_blank" rel="noopener">Abrir editor</a>
                    <?php } ?>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>

<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$unitId = trim((string) ($_GET['unit'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? 'view'));
$mode = $mode === 'edit' ? 'edit' : 'view';

if ($unitId === '') {
    die('Unidad no especificada.');
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

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

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

try {
    $stmtAccounts = $pdo->prepare("
        SELECT teacher_id, scope, target_id, permission
        FROM teacher_accounts
        WHERE teacher_id = :teacher_id
    ");
    $stmtAccounts->execute(['teacher_id' => $teacherId]);
    $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $accounts = [];
}

$allowed = false;
$allowEdit = false;

foreach ($accounts as $account) {
    if ((string) ($account['teacher_id'] ?? '') !== $teacherId) {
        continue;
    }

    $scope = (string) ($account['scope'] ?? 'technical');
    $targetId = (string) ($account['target_id'] ?? '');
    $permission = (string) ($account['permission'] ?? 'viewer');

    if (
        $scope === 'english' &&
        $targetId !== '' &&
        $targetId === (string) ($unit['phase_id'] ?? '')
    ) {
        $allowed = true;
        if ($permission === 'editor') {
            $allowEdit = true;
        }
    }

    if (
        $scope === 'technical' &&
        $targetId !== '' &&
        $targetId === (string) ($unit['course_id'] ?? '')
    ) {
        $allowed = true;
        if ($permission === 'editor') {
            $allowEdit = true;
        }
    }
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
];
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
}
*{
    box-sizing:border-box;
}
body{
    margin:0;
    background:var(--bg);
    font-family:Arial,sans-serif;
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
.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:12px;
    padding:16px;
    color:var(--muted);
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
        <a class="back" href="dashboard.php">← Volver</a>
    </div>

    <p class="meta">
        Modo: <strong><?php echo h($mode === 'edit' ? 'Edición' : 'Visualización'); ?></strong>
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
            ?>
            <div class="card">
                <h3><?php echo h($title); ?></h3>
                <p>Tipo: <strong><?php echo h($typeLabel); ?></strong></p>

                <div class="actions">
                    <a
                        class="btn"
                        target="_blank"
                        rel="noopener noreferrer"
                        href="<?php echo h($activityBase . '/viewer.php?id=' . urlencode($activityId) . '&unit=' . urlencode($unitId)); ?>"
                    >
                        Ver actividad
                    </a>

                    <?php if ($allowEdit) { ?>
                        <a
                            class="btn edit"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h($activityBase . '/editor.php?id=' . urlencode($activityId) . '&unit=' . urlencode($unitId)); ?>"
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

<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

if (!getenv('DATABASE_URL')) {
    die('DATABASE_URL no está configurada.');
}

require __DIR__ . '/../config/db.php';

$accountId = trim((string) ($_GET['account'] ?? ''));
$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$mode = (string) ($_GET['mode'] ?? 'view');
$mode = $mode === 'edit' ? 'edit' : 'view';
$step = max(0, (int) ($_GET['step'] ?? 0));

if ($accountId === '') {
    die('Cuenta docente no especificada.');
}

$stmtAccount = $pdo->prepare('SELECT id, teacher_id, target_name, target_id, scope, permission FROM teacher_accounts WHERE id = :id LIMIT 1');
$stmtAccount->execute(['id' => $accountId]);
$account = $stmtAccount->fetch(PDO::FETCH_ASSOC);

if (!$account || (string) ($account['teacher_id'] ?? '') !== $teacherId) {
    die('No tienes permiso para este curso.');
}

$scope = (string) ($account['scope'] ?? 'technical');
$targetId = (string) ($account['target_id'] ?? '');
$permission = (string) ($account['permission'] ?? 'viewer');
if ($mode === 'edit' && $permission !== 'editor') {
    $mode = 'view';
}

if ($scope === 'english') {
    $stmtUnits = $pdo->prepare('SELECT id, name, position FROM units WHERE phase_id = :target ORDER BY position ASC, id ASC');
} else {
    $stmtUnits = $pdo->prepare('SELECT id, name, position FROM units WHERE course_id = :target ORDER BY position ASC, id ASC');
}
$stmtUnits->execute(['target' => $targetId]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC) ?: [];

$unitIds = array_values(array_filter(array_map(fn($u) => (string) ($u['id'] ?? ''), $units)));
$unitMap = [];
foreach ($units as $unit) {
    $unitMap[(string) ($unit['id'] ?? '')] = (string) ($unit['name'] ?? 'Unidad');
}

$activities = [];
if (!empty($unitIds)) {
    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $sql = "SELECT id, type, unit_id, COALESCE(position, 0) AS pos FROM activities WHERE unit_id IN ($placeholders) ORDER BY unit_id ASC, pos ASC, id ASC";
    $stmtActivities = $pdo->prepare($sql);
    $stmtActivities->execute($unitIds);
    $activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$total = count($activities);
if ($step > max(0, $total - 1)) {
    $step = max(0, $total - 1);
}
$current = $total > 0 ? $activities[$step] : null;
$nextStep = $step + 1;
$hasNext = $nextStep < $total;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Curso Docente</title>
<style>
body{margin:0;background:#edf2fb;font-family:Arial,sans-serif;color:#1d355d;padding:20px}
.wrap{max-width:1100px;margin:0 auto}
.card{background:#fff;border:1px solid #d8e2f1;border-radius:12px;padding:16px;margin-bottom:14px}
.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
a{color:#1f66cc;text-decoration:none;font-weight:700}
.btn{display:inline-block;padding:10px 14px;border-radius:8px;background:#1f66cc;color:#fff;text-decoration:none;font-weight:700}
.btn-green{background:#188748}.btn-gray{background:#6b7280}
.info{color:#607090}
</style>
</head>
<body>
<div class="wrap">
    <div class="card top">
        <div>
            <h1 style="margin:0"><?php echo h((string) ($account['target_name'] ?? 'Curso')); ?></h1>
            <div class="info">Modo <?php echo h($mode === 'edit' ? 'edición' : 'presentación'); ?> · Actividad <?php echo $total > 0 ? ($step + 1) . ' de ' . $total : '0'; ?></div>
        </div>
        <div>
            <a href="dashboard.php">← Volver al panel</a>
        </div>
    </div>

    <?php if (!$current) { ?>
        <div class="card">No hay actividades registradas en este curso.</div>
    <?php } else { ?>
        <div class="card">
            <h2 style="margin-top:0"><?php echo h((string) ($current['type'] ?? 'Actividad')); ?></h2>
            <p class="info">Unidad: <?php echo h($unitMap[(string) ($current['unit_id'] ?? '')] ?? 'Unidad'); ?></p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <a class="btn" target="_blank" href="../activities/<?php echo urlencode((string) ($current['type'] ?? '')); ?>/viewer.php?id=<?php echo urlencode((string) ($current['id'] ?? '')); ?>&unit=<?php echo urlencode((string) ($current['unit_id'] ?? '')); ?>">Abrir actividad</a>
                <?php if ($mode === 'edit' && $permission === 'editor') { ?>
                    <a class="btn btn-green" target="_blank" href="../activities/<?php echo urlencode((string) ($current['type'] ?? '')); ?>/editor.php?id=<?php echo urlencode((string) ($current['id'] ?? '')); ?>&unit=<?php echo urlencode((string) ($current['unit_id'] ?? '')); ?>">Editar</a>
                <?php } ?>
                <?php if ($hasNext) { ?>
                    <a class="btn btn-gray" href="teacher_course.php?account=<?php echo urlencode($accountId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $nextStep; ?>">Next</a>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>
</body>
</html>

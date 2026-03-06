<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$unitId = trim((string) ($_GET['unit'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? 'view'));
if ($mode !== 'edit') {
    $mode = 'view';
}

if ($unitId === '') {
    die('Unidad no especificada');
}

$dataDir = __DIR__ . '/data';
$accountsFile = $dataDir . '/teacher_accounts.json';
$accounts = file_exists($accountsFile) ? json_decode((string) file_get_contents($accountsFile), true) : [];
$accounts = is_array($accounts) ? $accounts : [];

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');

$allowed = false;
$allowEdit = false;

if (getenv('DATABASE_URL')) {
    try {
        require __DIR__ . '/../config/db.php';

        $stmtUnit = $pdo->prepare('SELECT id, name, course_id, phase_id FROM units WHERE id = :id LIMIT 1');
        $stmtUnit->execute(['id' => $unitId]);
        $unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            die('Unidad no encontrada');
        }

        foreach ($accounts as $account) {
            if ((string) ($account['teacher_id'] ?? '') !== $teacherId) {
                continue;
            }

            $scope = (string) ($account['scope'] ?? 'technical');
            $target = (string) ($account['target_id'] ?? '');
            $permission = (string) ($account['permission'] ?? 'viewer');

            if ($scope === 'english' && $target !== '' && $target === (string) ($unit['phase_id'] ?? '')) {
                $allowed = true;
                $allowEdit = $allowEdit || $permission === 'editor';
            }

            if ($scope === 'technical' && $target !== '' && $target === (string) ($unit['course_id'] ?? '')) {
                $allowed = true;
                $allowEdit = $allowEdit || $permission === 'editor';
            }
        }

        if (!$allowed) {
            die('No tienes permiso para esta unidad.');
        }

        if ($mode === 'edit' && !$allowEdit) {
            die('Tu perfil es sólo de visualización.');
        }

        $stmtActivities = $pdo->prepare('SELECT id, type, created_at FROM activities WHERE unit_id = :unit_id ORDER BY position ASC, id ASC');
        $stmtActivities->execute(['unit_id' => $unitId]);
        $activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        die('No fue posible cargar la unidad.');
    }
} else {
    die('DATABASE_URL no está configurada.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unidad Docente</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
.back{display:inline-block;margin-bottom:15px;color:#1f66cc;text-decoration:none;font-weight:700}
.row{padding:10px;border:1px solid #dee6f1;border-radius:8px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#1f66cc;color:#fff;text-decoration:none;font-weight:700}
</style>
</head>
<body>
<a class="back" href="dashboard.php">← Volver a panel docente</a>
<div class="card">
    <h1 style="margin-top:0"><?php echo htmlspecialchars((string) ($unit['name'] ?? 'Unidad')); ?></h1>
    <p>Modo: <strong><?php echo htmlspecialchars($mode === 'edit' ? 'Edición' : 'Visualización'); ?></strong></p>
</div>

<div class="card">
    <h2 style="margin-top:0">Actividades de la unidad</h2>
    <?php if (empty($activities)) { ?>
        <p>No hay actividades registradas.</p>
    <?php } else { ?>
        <?php foreach ($activities as $activity) { ?>
            <div class="row">
                <div>
                    <strong><?php echo htmlspecialchars(strtoupper((string) ($activity['type'] ?? 'actividad'))); ?></strong><br>
                    <small><?php echo htmlspecialchars((string) ($activity['created_at'] ?? '')); ?></small>
                </div>
                <div>
                    <a class="btn" href="../activities/<?php echo urlencode((string) ($activity['type'] ?? '')); ?>/viewer.php?id=<?php echo urlencode((string) ($activity['id'] ?? '')); ?>&unit=<?php echo urlencode($unitId); ?>">Abrir</a>
                    <?php if ($mode === 'edit' && $allowEdit) { ?>
                        <a class="btn" style="background:#0f8f4a" href="../activities/<?php echo urlencode((string) ($activity['type'] ?? '')); ?>/editor.php?id=<?php echo urlencode((string) ($activity['id'] ?? '')); ?>&unit=<?php echo urlencode($unitId); ?>">Editar</a>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>

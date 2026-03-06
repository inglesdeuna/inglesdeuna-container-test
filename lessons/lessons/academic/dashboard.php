<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'Docente');

$dataDir = __DIR__ . '/data';
$accountsFile = $dataDir . '/teacher_accounts.json';

$accounts = file_exists($accountsFile) ? json_decode((string) file_get_contents($accountsFile), true) : [];
$accounts = is_array($accounts) ? $accounts : [];

$myAccounts = array_values(array_filter($accounts, function ($account) use ($teacherId) {
    return (string) ($account['teacher_id'] ?? '') === $teacherId;
}));

$unitsByCourse = [];
$unitsByPhase = [];

if (getenv('DATABASE_URL')) {
    try {
        require __DIR__ . '/../config/db.php';

        $stmtUnits = $pdo->query("SELECT id, name, course_id, phase_id FROM units ORDER BY id ASC");
        $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($units as $unit) {
            if (!empty($unit['course_id'])) {
                $unitsByCourse[(string) $unit['course_id']][] = $unit;
            }
            if (!empty($unit['phase_id'])) {
                $unitsByPhase[(string) $unit['phase_id']][] = $unit;
            }
        }
    } catch (Throwable $e) {
        $unitsByCourse = [];
        $unitsByPhase = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Docente</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.logout{color:#dc2626;text-decoration:none;font-weight:700}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
.unit{padding:10px;border:1px solid #dce4f0;border-radius:8px;margin:8px 0;display:flex;justify-content:space-between;gap:12px;align-items:center}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#1f4ec9;font-size:12px;font-weight:700}
.btn{display:inline-block;padding:8px 12px;border-radius:8px;background:#1f66cc;color:#fff;text-decoration:none;font-weight:700}
.small{font-size:13px;color:#5b6577}
</style>
</head>
<body>
<div class="top">
    <h1>👩‍🏫 Panel Docente</h1>
    <a class="logout" href="logout.php">Cerrar sesión</a>
</div>

<div class="card">
    Bienvenido, <strong><?php echo htmlspecialchars($teacherName); ?></strong>.
</div>

<?php if (empty($myAccounts)) { ?>
    <div class="card">No tienes asignaciones activas todavía.</div>
<?php } else { ?>
    <?php foreach ($myAccounts as $account) { ?>
        <?php
            $scope = (string) ($account['scope'] ?? 'technical');
            $targetId = (string) ($account['target_id'] ?? '');
            $targetName = (string) ($account['target_name'] ?? 'Asignación');
            $permission = (string) ($account['permission'] ?? 'viewer');
            $units = $scope === 'english'
                ? ($unitsByPhase[$targetId] ?? [])
                : ($unitsByCourse[$targetId] ?? []);
        ?>
        <div class="card">
            <h2 style="margin-top:0"><?php echo htmlspecialchars($targetName); ?></h2>
            <div class="small">
                <span class="badge"><?php echo htmlspecialchars($scope); ?></span>
                <span class="badge"><?php echo htmlspecialchars($permission === 'editor' ? 'Puede editar' : 'Sólo ver'); ?></span>
            </div>

            <?php if (empty($units)) { ?>
                <p class="small">No hay unidades encontradas para esta asignación.</p>
            <?php } else { ?>
                <?php foreach ($units as $unit) { ?>
                    <div class="unit">
                        <div><strong><?php echo htmlspecialchars((string) ($unit['name'] ?? 'Unidad')); ?></strong></div>
                        <div>
                            <a class="btn" href="teacher_unit.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=view">Ver</a>
                            <?php if ($permission === 'editor') { ?>
                                <a class="btn" href="teacher_unit.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=edit" style="background:#0f8f4a">Editar</a>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>
<?php } ?>
</body>
</html>

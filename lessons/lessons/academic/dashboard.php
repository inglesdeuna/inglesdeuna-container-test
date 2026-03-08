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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_scope_label(string $scope): string
{
    return $scope === 'english' ? 'Estudiantes' : 'Docentes';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel Docente</title>
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
    --green:#0f8f4a;
    --green-hover:#0b743c;
    --danger:#dc2626;
    --badge-bg:#eef2ff;
    --badge-text:#1f4ec9;
    --shadow:0 8px 24px rgba(0,0,0,.08);
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    padding:30px;
    color:var(--text);
    font-size:11px;
}

.wrapper{
    max-width:980px;
    margin:0 auto;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.page-title{
    margin:0;
    color:var(--title);
    font-size:25px;
    font-weight:700;
}

.logout{
    color:var(--danger);
    text-decoration:none;
    font-weight:700;
    font-size:11px;
}

.card{
    background:var(--card);
    border-radius:14px;
    padding:18px;
    box-shadow:var(--shadow);
    border:1px solid var(--line);
    margin-bottom:16px;
}

.welcome{
    font-size:11px;
    color:var(--text);
}

.assignment-title{
    margin:0 0 10px;
    font-size:17px;
    font-weight:700;
    color:#243b63;
}

.small{
    font-size:10px;
    color:var(--muted);
}

.badges{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:var(--badge-bg);
    color:var(--badge-text);
    font-size:10px;
    font-weight:700;
}

.unit{
    padding:12px;
    border:1px solid var(--line);
    border-radius:10px;
    margin:8px 0;
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
    background:#ffffff;
}

.unit-name{
    font-size:11px;
    font-weight:700;
    color:#243b63;
}

.unit-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    background:#ffffff;
    padding:4px;
    border-radius:10px;
}

.btn{
    display:inline-block;
    padding:7px 11px;
    border-radius:8px;
    background:var(--blue);
    color:#fff;
    text-decoration:none;
    font-weight:700;
    font-size:10px;
    transition:background .2s ease;
}

.btn:hover{
    background:var(--blue-hover);
}

.btn-edit{
    background:var(--green);
}

.btn-edit:hover{
    background:var(--green-hover);
}

.empty{
    font-size:10px;
    color:var(--muted);
    margin:0;
}

@media (max-width: 768px){
    body{
        padding:20px;
    }

    .page-title{
        font-size:21px;
    }

    .unit{
        flex-direction:column;
        align-items:flex-start;
    }

    .unit-actions{
        width:100%;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <div class="top">
        <h1 class="page-title">👩‍🏫 Panel Docente</h1>
        <a class="logout" href="logout.php">Cerrar sesión</a>
    </div>

    <div class="card">
        <div class="welcome">
            Bienvenido, <strong><?php echo h($teacherName); ?></strong>.
        </div>
    </div>

    <?php if (empty($myAccounts)) { ?>
        <div class="card">
            <p class="empty">No tienes asignaciones activas todavía.</p>
        </div>
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
                <h2 class="assignment-title"><?php echo h($targetName); ?></h2>

                <div class="badges">
                    <span class="badge"><?php echo h(normalize_scope_label($scope)); ?></span>
                    <span class="badge"><?php echo h($permission === 'editor' ? 'Puede editar' : 'Sólo ver'); ?></span>
                </div>

                <?php if (empty($units)) { ?>
                    <p class="empty">No hay unidades encontradas para esta asignación.</p>
                <?php } else { ?>
                    <?php foreach ($units as $unit) { ?>
                        <div class="unit">
                            <div class="unit-name">
                                <?php echo h((string) ($unit['name'] ?? 'Unidad')); ?>
                            </div>

                            <div class="unit-actions">
                                <a class="btn" href="teacher_unit.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=view">Ver</a>
                                <?php if ($permission === 'editor') { ?>
                                    <a class="btn btn-edit" href="teacher_unit.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=edit">Editar</a>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>

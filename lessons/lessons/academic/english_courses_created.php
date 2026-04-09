<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER LEVELS, PHASES Y UNITS
=============================== */
$stmt = $pdo->prepare("
    SELECT
        l.id   AS level_id,
        l.name AS level_name,
        p.id   AS phase_id,
        p.name AS phase_name,
        u.id   AS unit_id,
        u.name AS unit_name
    FROM english_levels l
    LEFT JOIN english_phases p ON p.level_id = l.id
    LEFT JOIN units u ON u.phase_id = p.id
    ORDER BY l.id ASC, p.id ASC, u.id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Agrupar Level → Phase → Units */
$levels = [];
foreach ($rows as $row) {
    $levelId = $row['level_id'];
    if (!isset($levels[$levelId])) {
        $levels[$levelId] = [
            'id'     => $levelId,
            'name'   => $row['level_name'],
            'phases' => []
        ];
    }
    if (!empty($row['phase_id'])) {
        $phaseId = $row['phase_id'];
        if (!isset($levels[$levelId]['phases'][$phaseId])) {
            $levels[$levelId]['phases'][$phaseId] = [
                'id'    => $phaseId,
                'name'  => $row['phase_name'],
                'units' => []
            ];
        }
        if (!empty($row['unit_id'])) {
            $levels[$levelId]['phases'][$phaseId]['units'][] = [
                'id'   => $row['unit_id'],
                'name' => $row['unit_name']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cursos creados - English</title>

<style>
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --green:#2f9e44;
    --green-dark:#237a35;
    --gray:#6f7e73;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}
body{
    font-family: Arial, sans-serif;
    background:var(--bg);
    padding:40px;
    color:var(--text);
}

.container{
    max-width:1000px;
    margin:0 auto;
}

.back{
    display:inline-block;
    margin-bottom:25px;
    color:#fff;
    padding:12px 16px;
    border-radius:12px;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    transition:filter .2s, transform .15s;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
}
.back:hover{
    filter:brightness(1.06);
    transform:translateY(-1px);
}

.card{
    background:var(--card);
    padding:30px;
    border-radius:18px;
    box-shadow:var(--shadow);
    border:1px solid var(--line);
    margin-bottom:25px;
}

.level-title{
    font-size:20px;
    font-weight:700;
    margin-bottom:15px;
}

.phase-item{
    background:#f7fcf8;
    border:1px solid var(--line);
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    flex-direction:column;
    gap:0;
}
.phase-item-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.phase-item-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
}
.unit-list-collapse{
    display:none;
    margin-top:14px;
    padding-top:12px;
    border-top:1px solid var(--line);
}
.unit-list-collapse.open{ display:block; }
.unit-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:9px 12px;
    border-radius:10px;
    background:#fff;
    border:1px solid var(--line);
    margin-bottom:8px;
    font-size:14px;
}
.unit-row:last-child{ margin-bottom:0; }
.unit-row span{ font-weight:600; color:var(--text); }
.btn-toggle{
    background:none;
    border:1px solid var(--green);
    color:var(--green);
    border-radius:10px;
    padding:6px 12px;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    transition:background .15s;
}
.btn-toggle:hover{ background:#eaf5ec; }

.btn{
    display:inline-block;
    color:#fff;
    padding:12px 16px;
    border-radius:12px;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    transition:filter .2s, transform .15s;
}
.btn:hover{
    filter:brightness(1.06);
    transform:translateY(-1px);
}
.btn-primary{ background:linear-gradient(180deg,#41b95a,#2f9e44); }
.btn-secondary{ background:linear-gradient(180deg,#7b8b7f,#66756a); }
</style>
</head>

<body>

<div class="container">

    <a class="back" href="../admin/dashboard.php">← Volver</a>

    <div class="card">
        <h2>📋 Cursos creados - English</h2>

        <?php if (empty($levels)): ?>
            <p>No hay estructura creada.</p>
        <?php else: ?>

            <?php foreach ($levels as $level): ?>

                <div class="card">
                    <div class="level-title">
                        <?= htmlspecialchars($level['name']); ?>
                    </div>

                    <?php if (empty($level['phases'])): ?>
                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                            <span style="color:var(--gray);font-size:14px;">No hay phases creadas.</span>
                            <a class="btn btn-secondary"
                               href="english_structure_phases.php?level=<?= urlencode($level['id']); ?>">
                                Agregar phases →
                            </a>
                        </div>
                    <?php else: ?>

                        <?php foreach ($level['phases'] as $phase):
                              $unitCount = count($phase['units']);
                        ?>
                            <div class="phase-item">
                                <div class="phase-item-header">
                                    <strong><?= htmlspecialchars($phase['name']); ?></strong>
                                    <div class="phase-item-actions">
                                        <?php if ($unitCount > 0): ?>
                                            <button class="btn-toggle" type="button" onclick="toggleUnits(this)">
                                                Ver unidades (<?= $unitCount; ?>)
                                            </button>
                                        <?php endif; ?>
                                        <a class="btn btn-primary"
                                           href="english_units_view.php?phase=<?= urlencode($phase['id']); ?>">
                                            Administrar →
                                        </a>
                                    </div>
                                </div>

                                <?php if ($unitCount > 0): ?>
                                    <div class="unit-list-collapse">
                                        <?php foreach ($phase['units'] as $unit): ?>
                                            <div class="unit-row">
                                                <span><?= htmlspecialchars($unit['name']); ?></span>
                                                <a class="btn btn-primary" style="padding:6px 12px;font-size:13px;"
                                                   href="english_units_view.php?phase=<?= urlencode($phase['id']); ?>">
                                                    Editar →
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

<script>
function toggleUnits(btn) {
    var phaseItem = btn.closest('.phase-item');
    var list = phaseItem ? phaseItem.querySelector('.unit-list-collapse') : null;
    if (!list) return;
    var isOpen = list.classList.contains('open');
    list.classList.toggle('open', !isOpen);
    var count = list.querySelectorAll('.unit-row').length;
    btn.textContent = isOpen ? 'Ver unidades (' + count + ')' : 'Ocultar unidades';
}
</script>

</body>
</html>

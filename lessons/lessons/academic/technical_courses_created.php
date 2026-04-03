<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   AUTO-MIGRATE: technical_modules
=============================== */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS technical_modules (
            id SERIAL PRIMARY KEY,
            course_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
} catch (Throwable $e) {}

/* ===============================
   OBTENER PROGRAMA TÉCNICO
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT id FROM programs
    WHERE slug = 'prog_technical'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa técnico no encontrado.");
}

$programId = $program["id"];

/* ===============================
   OBTENER SEMESTRES Y SUS MÓDULOS
=============================== */
$stmt = $pdo->prepare("
    SELECT
        c.id   AS semester_id,
        c.name AS semester_name,
        m.id   AS module_id,
        m.name AS module_name,
        u.id   AS unit_id,
        u.name AS unit_name
    FROM courses c
    LEFT JOIN technical_modules m ON m.course_id = c.id
    LEFT JOIN units u ON u.module_id = m.id
    WHERE c.program_id = :program_id
    ORDER BY c.id ASC, m.id ASC, u.id ASC
");
$stmt->execute(["program_id" => $programId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Agrupar por Semestre → Módulo → Unidades */
$semesters = [];
foreach ($rows as $row) {
    $semId = $row['semester_id'];
    if (!isset($semesters[$semId])) {
        $semesters[$semId] = [
            'id'      => $semId,
            'name'    => $row['semester_name'],
            'modules' => []
        ];
    }
    if (!empty($row['module_id'])) {
        $modId = $row['module_id'];
        if (!isset($semesters[$semId]['modules'][$modId])) {
            $semesters[$semId]['modules'][$modId] = [
                'id'    => $modId,
                'name'  => $row['module_name'],
                'units' => []
            ];
        }
        if (!empty($row['unit_id'])) {
            $semesters[$semId]['modules'][$modId]['units'][] = [
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
<title>Cursos creados - Técnico</title>

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

.semester-title{
    font-size:20px;
    font-weight:700;
    margin-bottom:15px;
}

.module-item{
    background:#f7fcf8;
    border:1px solid var(--line);
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    flex-direction:column;
    gap:0;
}
.module-item-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.module-item-actions{
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
.btn-primary{
    background:linear-gradient(180deg,#41b95a,#2f9e44);
}
.btn-secondary{
    background:linear-gradient(180deg,#7b8b7f,#66756a);
}
</style>
</head>

<body>

<div class="container">

    <a class="back" href="../admin/dashboard.php">
        ← Volver
    </a>

    <div class="card">
        <h2>📋 Cursos creados - Técnico</h2>

        <?php if (empty($semesters)): ?>
            <p>No hay estructura creada.</p>
        <?php else: ?>

            <?php foreach ($semesters as $semester): ?>

                <div class="card">
                    <div class="semester-title">
                        <?= htmlspecialchars($semester['name']); ?>
                    </div>

                    <?php if (empty($semester['modules'])): ?>
                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                            <span style="color:var(--gray);font-size:14px;">No hay módulos creados.</span>
                            <a class="btn btn-secondary"
                               href="technical_modules_view.php?course=<?= urlencode($semester['id']); ?>">
                                Agregar módulos →
                            </a>
                        </div>
                    <?php else: ?>

                        <?php foreach ($semester['modules'] as $module):
                              $unitCount = count($module['units']);
                        ?>
                            <div class="module-item">
                                <div class="module-item-header">
                                    <strong><?= htmlspecialchars($module['name']); ?></strong>
                                    <div class="module-item-actions">
                                        <?php if ($unitCount > 0): ?>
                                            <button class="btn-toggle" type="button" onclick="toggleUnits(this)">
                                                Ver unidades (<?= $unitCount; ?>)
                                            </button>
                                        <?php endif; ?>
                                        <a class="btn btn-primary"
                                           href="technical_units_view.php?module=<?= urlencode($module['id']); ?>">
                                            Administrar →
                                        </a>
                                    </div>
                                </div>

                                <?php if ($unitCount > 0): ?>
                                    <div class="unit-list-collapse">
                                        <?php foreach ($module['units'] as $unit): ?>
                                            <div class="unit-row">
                                                <span><?= htmlspecialchars($unit['name']); ?></span>
                                                <a class="btn btn-primary" style="padding:6px 12px;font-size:13px;"
                                                   href="technical_units_view.php?module=<?= urlencode($module['id']); ?>">
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
    var moduleItem = btn.closest('.module-item');
    var list = moduleItem ? moduleItem.querySelector('.unit-list-collapse') : null;
    if (!list) return;
    var isOpen = list.classList.contains('open');
    list.classList.toggle('open', !isOpen);
    var count = list.querySelectorAll('.unit-row').length;
    btn.textContent = isOpen ? 'Ver unidades (' + count + ')' : 'Ocultar unidades';
}
</script>

</body>
</html>

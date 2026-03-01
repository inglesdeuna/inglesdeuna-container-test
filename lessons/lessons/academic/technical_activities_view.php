<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$unitId = $_GET["unit"] ?? null;

if (!$unitId) {
    die("Unidad no especificada.");
}

/* ===============================
   OBTENER UNIDAD
=============================== */
$stmtUnit = $pdo->prepare("
    SELECT * FROM units
    WHERE id = :id
    LIMIT 1
");
$stmtUnit->execute(["id" => $unitId]);
$unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ===============================
   ELIMINAR ACTIVIDAD (validando unidad)
=============================== */
if (isset($_GET["delete"])) {
    $deleteId = (int) $_GET["delete"];

    $stmtDelete = $pdo->prepare("
        DELETE FROM activities
        WHERE id = :id AND unit_id = :unit_id
    ");
    $stmtDelete->execute([
        "id" => $deleteId,
        "unit_id" => $unitId
    ]);

    header("Location: technical_activities_view.php?unit=" . urlencode($unitId));
    exit;
}

/* ===============================
   LISTAR ACTIVIDADES
=============================== */
$stmtActivities = $pdo->prepare("
    SELECT * FROM activities
    WHERE unit_id = :unit_id
    ORDER BY id ASC
");
$stmtActivities->execute(["unit_id" => $unitId]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades - <?= htmlspecialchars($unit["name"]) ?></title>
<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    padding:40px;
}
.container{
    max-width:900px;
    margin:auto;
}
.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}
.item{
    background:#eef2ff;
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.btn{
    padding:6px 12px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    margin-left:5px;
}
.btn-view{ background:#2563eb; color:#fff; }
.btn-edit{ background:#16a34a; color:#fff; }
.btn-delete{ background:#dc2626; color:#fff; }
.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#ffffff;
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
}
</style>
</head>
<body>
<div class="container">

<a class="back" href="technical_units_view.php?course=<?= urlencode($unit["course_id"]) ?>">← Volver</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($unit["name"]) ?> - Actividades</h2>

    <?php if (empty($activities)): ?>
        <p>No hay actividades creadas.</p>
    <?php else: ?>
        <?php foreach ($activities as $activity): ?>
            <div class="item">
                <strong><?= htmlspecialchars($activity["type"]) ?></strong>
                <div>
                    <a class="btn btn-view"
   href="../activities/<?= htmlspecialchars($activity["type"]) ?>/viewer.php?id=<?= $activity["id"] ?>&unit=<?= $unitId ?>">
   Ver
</a>

<a class="btn btn-edit"
   href="../activities/<?= htmlspecialchars($activity["type"]) ?>/editor.php?id=<?= $activity["id"] ?>&unit=<?= $unitId ?>">
   Editar
</a>

                    <a class="btn btn-delete"
                       href="technical_activities_view.php?unit=<?= $unitId ?>&delete=<?= $activity["id"] ?>"
                       onclick="return confirm('¿Eliminar actividad?')">
                        Eliminar
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>
</body>
</html>

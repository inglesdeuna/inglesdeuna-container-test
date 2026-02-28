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
    SELECT u.*, c.name AS course_name
    FROM units u
    JOIN courses c ON u.course_id = c.id
    WHERE u.id = :id
    LIMIT 1
");

$stmtUnit->execute(["id" => $unitId]);
$unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ===============================
   ELIMINAR ACTIVIDAD
=============================== */
if (isset($_GET["delete"])) {

    $activityId = (int) $_GET["delete"];

    $stmtDelete = $pdo->prepare("
        DELETE FROM activities
        WHERE id = :id
        AND unit_id = :unit_id
    ");

    $stmtDelete->execute([
        "id" => $activityId,
        "unit_id" => $unitId
    ]);

    header("Location: technical_activities_view.php?unit=" . $unitId);
    exit;
}

/* ===============================
   LISTAR ACTIVIDADES
=============================== */
$stmtActivities = $pdo->prepare("
    SELECT *
    FROM activities
    WHERE unit_id = :unit_id
    ORDER BY position ASC, id ASC
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
body {
    font-family: Arial;
    background: #f4f8ff;
    padding: 40px;
}

.container {
    max-width: 900px;
    margin: auto;
}

.back {
    display: inline-block;
    margin-bottom: 25px;
    background: #6b7280;
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.card {
    background: #fff;
    padding: 25px;
    border-radius: 14px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
}

.item {
    background: #eef2ff;
    padding: 15px 18px;
    border-radius: 10px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.actions a {
    margin-left: 8px;
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    font-size: 14px;
}

.btn-view {
    background: #2563eb;
    color: white;
}

.btn-edit {
    background: #16a34a;
    color: white;
}

.btn-delete {
    background: #dc2626;
    color: white;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="technical_units_view.php?course=<?= urlencode($unit["course_id"]) ?>">
← Volver
</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($unit["course_name"]) ?>  
        — <?= htmlspecialchars($unit["name"]) ?></h2>

    <hr style="margin:20px 0;">

    <?php if (empty($activities)): ?>
        <p>No hay actividades creadas.</p>
    <?php else: ?>

        <?php foreach ($activities as $activity): ?>
            <div class="item">

                <strong><?= strtoupper($activity["type"]) ?></strong>

                <div class="actions">

                    <!-- VER -->
                    <a class="btn-view"
                       href="../activities/hub/view_activity.php?id=<?= $activity["id"] ?>">
                        Ver
                    </a>

                    <!-- EDITAR -->
                    <a class="btn-edit"
                       href="../activities/hub/edit_activity.php?id=<?= $activity["id"] ?>">
                        Editar
                    </a>

                    <!-- ELIMINAR -->
                    <a class="btn-delete"
                       onclick="return confirm('¿Eliminar actividad?')"
                       href="technical_activities_view.php?unit=<?= $unitId ?>&delete=<?= $activity["id"] ?>">
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

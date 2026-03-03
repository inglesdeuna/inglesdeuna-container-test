<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$unit_id = $_GET["unit"] ?? null;

if (!$unit_id) {
    die("Unidad no especificada.");
}

/* ===============================
   OBTENER UNIT + CONTEXTO
=============================== */
$stmt = $pdo->prepare("
    SELECT u.*, 
           c.name AS course_name,
           p.name AS phase_name,
           l.name AS level_name
    FROM units u
    LEFT JOIN courses c ON u.course_id = c.id
    LEFT JOIN english_phases p ON u.phase_id = p.id
    LEFT JOIN english_levels l ON p.level_id = l.id
    WHERE u.id = :id
    LIMIT 1
");

$stmt->execute(["id" => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ===============================
   DETECTAR BOTÓN VOLVER
=============================== */
if (!empty($unit["course_id"])) {

    // 🔵 Programa Técnico
    $backUrl = "technical_units.php?course=" . urlencode($unit["course_id"]);

} elseif (!empty($unit["phase_id"])) {

    // 🟢 English
    $backUrl = "english_structure_units.php?phase=" . urlencode($unit["phase_id"]);

} else {

    $backUrl = "../admin/dashboard.php";
}

/* ===============================
   OBTENER ACTIVIDADES
=============================== */
$stmtActivities = $pdo->prepare("
    SELECT *
    FROM activities
    WHERE unit_id = :unit_id
    ORDER BY id ASC
");

$stmtActivities->execute(["unit_id" => $unit_id]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($unit["name"]); ?></title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef2f7;
    padding:40px;
}
.container{
    max-width:1100px;
    margin:0 auto;
}
.back{
    display:inline-block;
    margin-bottom:25px;
    background:#6b7280;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
.card{
    background:#ffffff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.08);
    margin-bottom:25px;
}
.activity{
    background:#22c55e;
    padding:18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    color:#fff;
}
.btn{
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    color:#fff;
}
.btn-blue{ background:#2563eb; }
.btn-red{ background:#dc2626; }
.btn-dark{ background:#15803d; }
</style>
</head>

<body>

<div class="container">

<a class="back" href="<?= $backUrl; ?>">
← Volver
</a>

<div class="card">
    <h2><?= htmlspecialchars($unit["name"]); ?></h2>

    <?php if (!empty($unit["course_name"])): ?>
        <p><strong>Curso:</strong> <?= htmlspecialchars($unit["course_name"]); ?></p>
    <?php elseif (!empty($unit["phase_name"])): ?>
        <p><strong>Level:</strong> <?= htmlspecialchars($unit["level_name"]); ?></p>
        <p><strong>Phase:</strong> <?= htmlspecialchars($unit["phase_name"]); ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Arrastra para ordenar</h3>

    <?php if (empty($activities)): ?>
        <p>No hay actividades creadas.</p>
    <?php else: ?>

        <?php foreach ($activities as $activity): ?>
            <div class="activity">
                <div>
                    <strong><?= htmlspecialchars(strtoupper($activity["type"])); ?></strong><br>
                    Tipo: <?= htmlspecialchars($activity["type"]); ?><br>
                    Creado: <?= htmlspecialchars($activity["created_at"]); ?>
                </div>

                <div>
                    <a class="btn btn-dark"
                       href="../activities/<?= urlencode($activity["type"]); ?>/index.php?activity=<?= urlencode($activity["id"]); ?>">
                       Abrir
                    </a>

                    <a class="btn btn-blue"
                       href="../activities/<?= urlencode($activity["type"]); ?>/edit.php?activity=<?= urlencode($activity["id"]); ?>">
                       Editar
                    </a>

                    <a class="btn btn-red"
                       href="../activities/delete.php?id=<?= urlencode($activity["id"]); ?>&unit=<?= urlencode($unit_id); ?>"
                       onclick="return confirm('¿Eliminar actividad?');">
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

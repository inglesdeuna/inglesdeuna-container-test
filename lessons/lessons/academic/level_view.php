<?php
session_start();
require_once "../config/db.php";

$level_id = $_GET['level'] ?? null;

if (!$level_id) {
    die("Nivel no especificado.");
}

/* ==========================
   OBTENER LEVEL
========================== */
$stmtLevel = $pdo->prepare("
    SELECT * FROM levels
    WHERE id = :id
");
$stmtLevel->execute(['id' => $level_id]);
$level = $stmtLevel->fetch(PDO::FETCH_ASSOC);

if (!$level) {
    die("Nivel no encontrado.");
}

/* ==========================
   OBTENER COURSE
========================== */
$stmtCourse = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
");
$stmtCourse->execute(['id' => $level['course_id']]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

/* ==========================
   OBTENER UNITS
========================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE level_id = :level_id
    ORDER BY position ASC
");
$stmtUnits->execute(['level_id' => $level_id]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($level['name']); ?></title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:20px;
}

.back{
    display:inline-block;
    background:#6b7280;
    margin-bottom:20px;
    padding:8px 14px;
    border-radius:8px;
    color:#fff;
    text-decoration:none;
    font-weight:600;
}

.unit-box{
    background:#2563eb;
    border-radius:12px;
    padding:16px;
    margin-bottom:12px;
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.unit-title{
    font-weight:bold;
}

.btn{
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
    font-size:13px;
    font-weight:600;
    color:#fff;
    background:#1e40af;
}
</style>
</head>

<body>

<a class="back" href="course_view.php?course=<?= htmlspecialchars($course['id']); ?>">
← Volver al Curso
</a>

<div class="card">
    <h2><?= htmlspecialchars($level['name']); ?></h2>
    <p><strong>Curso:</strong> <?= htmlspecialchars($course['name']); ?></p>
</div>

<div class="card">
    <h3>Unidades</h3>

    <?php if (empty($units)): ?>
        <p>No hay unidades en este nivel.</p>
    <?php else: ?>

        <?php foreach ($units as $unit): ?>

            <div class="unit-box">

                <div class="unit-title">
                    <?= htmlspecialchars($unit['name']); ?>
                </div>

                <a class="btn"
                   href="unit_view.php?unit=<?= htmlspecialchars($unit['id']); ?>">
                   Entrar
                </a>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>

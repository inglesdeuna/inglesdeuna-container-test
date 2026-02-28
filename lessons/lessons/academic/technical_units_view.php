<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

/* ===============================
   OBTENER CURSO
=============================== */
$stmtCourse = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");

$stmtCourse->execute(["id" => $courseId]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ===============================
   LISTAR UNIDADES
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course_id
    ORDER BY id ASC
");

$stmtUnits->execute(["course_id" => $courseId]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?> - Unidades</title>

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
    background:#2563eb;
    color:#ffffff;
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

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

<a class="back" href="technical_courses_created.php">← Volver</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($course["name"]) ?> - Unidades</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn"
                   href="technical_activities_view.php?unit=<?= urlencode($unit["id"]) ?>">
                    Actividades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</div>

</body>
</html>

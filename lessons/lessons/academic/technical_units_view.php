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
   LISTAR UNITS
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
<title><?= htmlspecialchars($course["name"]) ?> — Unidades</title>

<style>
body {
    font-family: Arial;
    background: #f4f8ff;
    padding: 40px;
}

.card {
    background: #fff;
    padding: 25px;
    border-radius: 14px;
    max-width: 900px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
    margin-bottom: 25px;
}

.item {
    background: #eef2ff;
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn {
    background: #2563eb;
    color: #fff;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.back {
    display: inline-block;
    margin-bottom: 25px;
    background: #6b7280;
    color: #fff;
    padding: 10px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}
</style>
</head>

<body>

<a class="back" href="technical_courses_created.php">Volver</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($course["name"]) ?></h2>
    <h3>Unidades creadas</h3>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn"
                   href="../academic/unit_view.php?unit=<?= urlencode($unit["id"]) ?>">
                    Ver actividades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

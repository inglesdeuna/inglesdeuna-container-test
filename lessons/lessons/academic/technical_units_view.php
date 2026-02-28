<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Semestre no especificado.");
}

/* ===============================
   OBTENER SEMESTRE
=============================== */

$stmt = $pdo->prepare("
    SELECT name FROM courses
    WHERE id = :id
    LIMIT 1
");

$stmt->execute(["id" => $courseId]);
$semester = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$semester) {
    die("Semestre no encontrado.");
}

/* ===============================
   LISTAR UNIDADES
=============================== */

$stmtUnits = $pdo->prepare("
    SELECT id, name
    FROM units
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
<title><?= htmlspecialchars($semester["name"]) ?></title>

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
    padding: 10px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.card {
    background: #fff;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
}

.item {
    background: #eef2ff;
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn {
    background: #2563eb;
    color: #fff;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.btn-delete {
    background: #dc2626;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="technical_courses_created.php">← Volver</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($semester["name"]) ?> - Unidades</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <div>
                    <a class="btn"
                       href="technical_activities_view.php?unit=<?= urlencode($unit["id"]) ?>">
                        Ver actividades →
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</div>

</body>
</html>

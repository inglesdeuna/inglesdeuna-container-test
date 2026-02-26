<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   RECIBIR CURSO (SEMESTRE)
=============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

/* ===============================
   OBTENER SEMESTRE
=============================== */
$stmtCourse = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");
$stmtCourse->execute([
    "id" => $courseId
]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Semestre no encontrado.");
}

/* ===============================
   OBTENER UNIDADES DEL SEMESTRE
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course_id
    ORDER BY created_at ASC
");
$stmtUnits->execute([
    "course_id" => $courseId
]);

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

.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:25px;
    max-width:900px;
}

.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#ffffff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.unit-item{
    background:#e2e8f0;
    padding:18px;
    border-radius:12px;
    margin-bottom:15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    background:#2563eb;
    color:#ffffff;
    padding:10px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    font-size:14px;
    display:inline-block;
}
</style>
</head>

<body>

<a class="back" href="technical_created.php">
← Volver a Semestres
</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($course["name"]) ?> — Unidades</h2>
</div>

<div class="card">
    <h3>📋 Unidades creadas</h3>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas en este semestre.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="unit-item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn"
                   href="unit_view.php?unit=<?= urlencode($unit["id"]) ?>">
                   Ver Actividades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>

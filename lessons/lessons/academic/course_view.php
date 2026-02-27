<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   RECIBIR PROGRAMA
=============================== */
$programId = $_GET["program"] ?? null;

if (!$programId) {
    die("Programa no especificado.");
}

/* ===============================
   OBTENER PROGRAMA
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT * FROM programs
    WHERE id = :id
    LIMIT 1
");
$stmtProgram->execute([
    "id" => $programId
]);
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa no encontrado.");
}

/* ===============================
   OBTENER SEMESTRES DEL PROGRAMA
=============================== */
$stmtCourses = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");
$stmtCourses->execute([
    "program_id" => $programId
]);

$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($program["name"]) ?> - Semestres</title>
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
.course-item{
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

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($program["name"]) ?> — Semestres</h2>
</div>

<div class="card">
    <h3>📋 Semestres creados</h3>

    <?php if (empty($courses)): ?>
        <p>No hay semestres creados en este programa.</p>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div class="course-item">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>
                <a class="btn" href="technical_units.php?course=<?= urlencode($course["id"]) ?>">
                   Ver Unidades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

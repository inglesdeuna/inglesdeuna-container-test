<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA TÉCNICO
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT id, name 
    FROM programs 
    WHERE slug = 'prog_technical'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa técnico no encontrado.");
}

/* ===============================
   OBTENER CURSOS (SEMESTRES)
=============================== */
$stmtCourses = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program_id
    ORDER BY created_at ASC
");
$stmtCourses->execute([
    "program_id" => $program["id"]
]);

$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos creados — Programa Técnico</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    padding:40px;
}

.back{
    display:inline-block;
    margin-bottom:25px;
    background:#6b7280;
    color:#ffffff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:25px;
    max-width:900px;
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
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">← Volver</a>

<div class="card">
    <h2>📘 Semestres creados</h2>

    <?php if (empty($courses)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div class="item">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>

                <a class="btn"
                   href="technical_units_view.php?course=<?= urlencode($course["id"]) ?>">
                    Ver →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

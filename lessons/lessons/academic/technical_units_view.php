<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId || !ctype_digit($courseId)) {
    die("Curso no válido.");
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
    ORDER BY created_at ASC
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
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --muted:#5d7465;
    --green:#2f9e44;
    --green-dark:#237a35;
    --gray:#6f7e73;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}
body{
    font-family: Arial, sans-serif;
    background: var(--bg);
    padding: 40px;
    color: var(--text);
}
.container{
    max-width: 850px;
    margin: 0 auto;
}
.card{
    background: var(--card);
    padding: 30px;
    border-radius: 18px;
    box-shadow: var(--shadow);
    border: 1px solid var(--line);
    margin-bottom: 25px;
}
.back{
    display: inline-block;
    margin-bottom: 25px;
    background: linear-gradient(180deg,#7b8b7f,#66756a);
    color: #ffffff;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}
.unit-item{
    background: #f7fcf8;
    border: 1px solid var(--line);
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.unit-item strong{
    font-size: 15px;
}
.btn{
    background: linear-gradient(180deg,var(--green),var(--green-dark));
    color: #ffffff;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="technical_courses_created.php">← Volver a Semestres</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($course["name"]) ?> — Unidades</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas en este semestre.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="unit-item">
                <strong><?= htmlspecialchars(mb_strtoupper($unit["name"], 'UTF-8')) ?></strong>
                <a class="btn"
                   href="technical_activities_view.php?unit=<?= urlencode($unit["id"]) ?>">
                   Ver Actividades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

</body>
</html>

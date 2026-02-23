<?php
session_start();
require_once "../config/db.php";

$course_id = $_GET['course'] ?? null;

if (!$course_id) {
    die("Curso no especificado.");
}

/* ==========================
   OBTENER COURSE
========================== */
$stmtCourse = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
");
$stmtCourse->execute(['id' => $course_id]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ==========================
   OBTENER LEVELS
========================== */
$stmtLevels = $pdo->prepare("
    SELECT * FROM levels
    WHERE course_id = :course_id
    ORDER BY created_at ASC
");
$stmtLevels->execute(['course_id' => $course_id]);
$levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course['name']); ?></title>

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

.level-box{
    background:#16a34a;
    border-radius:12px;
    padding:16px;
    margin-bottom:12px;
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.level-title{
    font-weight:bold;
}

.btn{
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
    font-size:13px;
    font-weight:600;
    color:#fff;
    background:#14532d;
}
</style>
</head>

<body>

<a class="back" href="dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
    <h2><?= htmlspecialchars($course['name']); ?></h2>
</div>

<div class="card">
    <h3>Niveles</h3>

    <?php if (empty($levels)): ?>
        <p>No hay niveles creados para este curso.</p>
    <?php else: ?>

        <?php foreach ($levels as $level): ?>

            <div class="level-box">

                <div class="level-title">
                    <?= htmlspecialchars($level['name']); ?>
                </div>

                <a class="btn"
                   href="level_view.php?level=<?= htmlspecialchars($level['id']); ?>">
                   Entrar
                </a>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>

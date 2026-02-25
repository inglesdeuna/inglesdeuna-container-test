<?php
session_start();
require_once "../config/db.php";

$courseParam = $_GET["course"] ?? null;

if (!$courseParam) {
    die("Curso no especificado.");
}

/* Buscar semestre */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(["id" => $courseParam]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Semestre no encontrado.");
}

$courseId = $course["id"];

/* Obtener unidades */
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
body{
    font-family:Arial;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:14px;
    margin-bottom:20px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.item{
    background:#e5e7eb;
    padding:18px;
    border-radius:10px;
    margin-bottom:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    padding:10px 16px;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    color:#fff;
}

.btn-open{
    background:#2563eb;
}
.btn-open:hover{
    background:#1d4ed8;
}

.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
}
</style>
</head>

<body>

<a class="back" href="technical_created.php">
← Volver a Cursos creados
</a>

<div class="card">
    <h2>📘 <?= htmlspecialchars($course["name"]) ?> — Unidades</h2>

    <?php if(empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>

        <?php foreach($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn btn-open"
                   href="unit_view.php?unit=<?= urlencode($unit["id"]) ?>">
                   Ver Actividades →
                </a>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>

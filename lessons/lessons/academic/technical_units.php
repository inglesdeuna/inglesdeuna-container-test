<?php
session_start();
require_once "../config/db.php";

$courseParam = $_GET["course"] ?? null;

if (!$courseParam) {
    die("Curso no especificado.");
}

/* =========================
   OBTENER CURSO
========================= */
$stmt = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
$stmt->execute(["id" => $courseParam]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

$courseId = $course["id"];

/* =========================
   OBTENER UNIDADES
========================= */
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
<title><?= htmlspecialchars($course["name"]); ?> — Unidades</title>

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
    background:#f1f5f9;
    padding:18px;
    border-radius:12px;
    margin-bottom:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn-blue{
    background:#2563eb;
    color:#ffffff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    display:inline-block;
    transition:0.2s ease;
}

.btn-blue:hover{
    background:#1d4ed8;
}
</style>
</head>

<body>

<a class="back" href="technical_created.php">
← Volver a Cursos creados
</a>

<div class="card">
    <h2><?= htmlspecialchars($course["name"]); ?> — Unidades</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]); ?></strong>

                <!-- AQUI VA AL CHECKLIST -->
                <a class="btn-blue"
                   href="../activities/hub/index.php?unit=<?= urlencode($unit["id"]); ?>">
                    Crear / Administrar Actividades →
                </a>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

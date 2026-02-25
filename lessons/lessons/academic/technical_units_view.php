<?php
session_start();
require_once "../config/db.php";

$courseParam = $_GET["course"] ?? null;

if (!$courseParam) {
    die("Curso no especificado.");
}

/* ==========================
   BUSCAR CURSO
========================== */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(["id" => $courseParam]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ==========================
   LISTAR UNIDADES DEL CURSO
========================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course_id
    ORDER BY created_at ASC
");
$stmtUnits->execute([
    "course_id" => $course["id"]
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
    font-family: Arial;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:20px;
    max-width:900px;
}

.back{
    display:inline-block;
    background:#6b7280;
    margin-bottom:20px;
    padding:10px 16px;
    border-radius:8px;
    color:#ffffff;
    text-decoration:none;
    font-weight:600;
}

.unit-row{
    background:#e5e7eb;
    padding:18px;
    border-radius:10px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    background:#2563eb;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:bold;
}
</style>
</head>
<body>

<a class="back" href="technical_created.php">
← Volver a Semestres
</a>

<div class="card">
    <h2><?= htmlspecialchars($course["name"]) ?> — Unidades</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>

        <?php foreach ($units as $unit): ?>

            <div class="unit-row">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn"
                   href="unit_view.php?unit=<?= urlencode($unit["id"]) ?>">
                    Ver actividades →
                </a>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>
</div>

</body>
</html>

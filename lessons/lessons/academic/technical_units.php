<?php
session_start();
require_once "../config/db.php";

/* ==========================
   VALIDAR CURSO
========================== */
$courseParam = $_GET["course"] ?? null;

if (!$courseParam) {
    die("Curso no especificado.");
}

/* Buscar curso por ID */
$stmt = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
$stmt->execute(["id" => $courseParam]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

$courseId = $course["id"];

/* ==========================
   CREAR UNIDAD
========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    // Verificar si ya existe
    $check = $pdo->prepare("
        SELECT id FROM units 
        WHERE course_id = :course_id AND name = :name 
        LIMIT 1
    ");
    $check->execute([
        "course_id" => $courseId,
        "name" => $unitName
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $unitId = $existing["id"];
    } else {
        $unitId = uniqid("unit_");

        $stmtInsert = $pdo->prepare("
            INSERT INTO units (id, course_id, name)
            VALUES (:id, :course_id, :name)
        ");

        $stmtInsert->execute([
            "id" => $unitId,
            "course_id" => $courseId,
            "name" => $unitName
        ]);
    }

    header("Location: technical_units.php?course=" . urlencode($courseId));
    exit;
}

/* ==========================
   LISTAR UNIDADES
========================== */
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
    background:#6b7280;
    margin-bottom:20px;
    padding:8px 14px;
    border-radius:8px;
    color:#ffffff;
    text-decoration:none;
    font-weight:600;
}

h1{
    margin-bottom:20px;
    color:#1e3a8a;
}

input{
    width:100%;
    padding:12px;
    margin-top:10px;
    border-radius:8px;
    border:1px solid #ddd;
}

button{
    margin-top:15px;
    padding:10px 18px;
    background:#2563eb;
    color:#ffffff;
    border:none;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}

.unit-item{
    background:#eef2ff;
    padding:15px 20px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn-view{
    background:#2563eb;
    color:#ffffff;
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
</style>
</head>

<body>

<a class="back" href="technical_created.php">
← Volver a Cursos creados
</a>

<div class="card">
    <h1>📘 <?= htmlspecialchars($course["name"]); ?> — Unidades</h1>

    <h3>➕ Crear Unidad</h3>
    <form method="POST">
        <input type="text" name="unit_name" required placeholder="Ej: Unidad 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h3>📋 Unidades creadas</h3>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="unit-item">
                <div>
                    <?= htmlspecialchars($unit["name"]); ?>
                </div>

                <!-- RUTA CORRECTA -->
                <a class="btn-view"
                   href="unit_view.php?unit=<?= urlencode($unit["id"]); ?>">
                   Ver Actividades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

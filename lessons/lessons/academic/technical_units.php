<?php
session_start();
require_once "../config/db.php";

/* ==========================
   VALIDAR COURSE PARAM
========================== */

$courseParam = $_GET["course"] ?? null;

if (!$courseParam) {
    die("Curso no especificado.");
}

/* ==========================
   BUSCAR CURSO POR ID
========================== */

$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :param
    LIMIT 1
");
$stmt->execute(["param" => $courseParam]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

/* Compatibilidad por nombre */
if (!$course) {
    $stmt = $pdo->prepare("
        SELECT * FROM courses
        WHERE name = :param
        LIMIT 1
    ");
    $stmt->execute(["param" => strtoupper($courseParam)]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$course) {
    die("Curso no encontrado.");
}

$courseId = $course["id"];

/* ==========================
   CREAR UNIDAD
========================== */

if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    // Buscar si ya existe
    $check = $pdo->prepare("
        SELECT id FROM units
        WHERE course_id = :course_id
        AND name = :name
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

    header("Location: ../activities/hub/index.php?unit=" . urlencode($unitId));
    exit;
}

/* ==========================
   LISTAR UNIDADES
========================== */

$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course_id
    ORDER BY name ASC
");
$stmtUnits->execute(["course_id" => $courseId]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>

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
    margin-bottom:25px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    max-width:800px;
}

.item{
    background:#f3f4f6;
    padding:15px;
    border-radius:10px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

input{
    width:100%;
    padding:12px;
    margin-top:10px;
}

button{
    margin-top:15px;
    padding:12px 18px;
    background:#2563eb;
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:700;
}

a{
    text-decoration:none;
    font-weight:bold;
    color:#2563eb;
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

    <h3>➕ Crear Unidad</h3>

    <form method="post">
        <input type="text" name="unit_name" required placeholder="Ej: Unidad 1">
        <button>Crear</button>
    </form>
</div>

<div class="card">
    <h3>📋 Unidades creadas</h3>

    <?php if(empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>

        <?php foreach($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a href="../activities/hub/index.php?unit=<?= urlencode($unit["id"]) ?>">
                    Ver actividades →
                </a>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>

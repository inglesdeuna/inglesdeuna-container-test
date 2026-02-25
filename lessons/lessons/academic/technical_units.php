<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

/* ===============================
   OBTENER CURSO
=============================== */
$stmt = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
$stmt->execute(["id" => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ===============================
   CREAR UNIDAD
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

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

    if (!$existing) {

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
<title><?= htmlspecialchars($course["name"]) ?> — Gestionar Unidades</title>

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

.item{
    background:#eef2ff;
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn-blue{
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

<a class="back" href="courses_manager.php?program=prog_technical">
← Volver
</a>

<div class="card">
    <h2>➕ Crear Unidad</h2>

    <form method="POST">
        <input type="text" name="unit_name" required placeholder="Ej: UNIDAD 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Unidades creadas</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn-blue"
                   href="../activities/hub/index.php?unit=<?= urlencode($unit["id"]) ?>">
                    Crear →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

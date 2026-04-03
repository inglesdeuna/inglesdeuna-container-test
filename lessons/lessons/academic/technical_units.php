<?php
session_start();

/* ===============================
   VALIDAR LOGIN ADMIN
=============================== */
if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   RECIBIR CURSO (ID INTEGER)
=============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId || !ctype_digit($courseId)) {
    die("Curso no válido.");
}

/* ===============================
   OBTENER CURSO
=============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM courses
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(["id" => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ===============================
   CREAR UNIDAD
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = mb_strtoupper(trim($_POST["unit_name"]), 'UTF-8');

    // Verificar si ya existe
    $check = $pdo->prepare("
        SELECT id FROM units
        WHERE course_id = :course_id
        AND name = :name
        LIMIT 1
    ");

    $check->execute([
        "course_id" => $courseId,
        "name"      => $unitName
    ]);

    $existingUnit = $check->fetch(PDO::FETCH_ASSOC);

    if ($existingUnit) {
        // 🔥 REDIRIGE AL HUB (CHECKLIST)
        header("Location: ../activities/hub/index.php?unit=" . urlencode($existingUnit["id"]));
        exit;
    }

    // Insertar nueva unidad
    $stmtInsert = $pdo->prepare("
        INSERT INTO units (course_id, name, created_at)
        VALUES (:course_id, :name, NOW())
    ");

    $stmtInsert->execute([
        "course_id" => $courseId,
        "name"      => $unitName
    ]);

    $newUnitId = $pdo->lastInsertId();

    // 🔥 REDIRIGE AL HUB (CHECKLIST)
    header("Location: ../activities/hub/index.php?unit=" . urlencode($newUnitId));
    exit;
}

/* ===============================
   LISTAR UNIDADES
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT *
    FROM units
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
<title><?= htmlspecialchars(strtoupper($course["name"])) ?> — Gestionar Unidades</title>

<style>
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --muted:#5d7465;
    --green:#2f9e44;
    --green-dark:#237a35;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}

body{
    font-family: Arial, sans-serif;
    background: var(--bg);
    padding: 40px;
    color: var(--text);
}

.back{
    display: inline-block;
    margin-bottom: 25px;
    background: linear-gradient(180deg,#7b8b7f,#66756a);
    color: #fff;
    padding: 10px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.card{
    background: var(--card);
    padding: 25px;
    border-radius: 14px;
    max-width: 800px;
    box-shadow: var(--shadow);
    border: 1px solid var(--line);
    margin-bottom: 25px;
}

input{
    width: 100%;
    padding: 12px;
    margin-top: 10px;
    border-radius: 8px;
    border: 1px solid var(--line);
    box-sizing: border-box;
}

button{
    margin-top: 15px;
    padding: 10px 18px;
    background: linear-gradient(180deg,var(--green),var(--green-dark));
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}

.item{
    background: #f7fcf8;
    border: 1px solid var(--line);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn{
    background: linear-gradient(180deg,var(--green),var(--green-dark));
    color: #fff;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}
</style>
</head>

<body>

<a class="back" href="courses_manager.php?program=prog_technical">← Volver</a>

<div class="card">
    <h2>➕ Crear Unidad</h2>

    <form method="POST">
        <input type="text" name="unit_name" required placeholder="Ej: UNIDAD 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Unidades creadas — <?= htmlspecialchars(strtoupper($course["name"])) ?></h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <a class="btn" href="../activities/hub/index.php?unit=<?= urlencode($unit["id"]) ?>">
                    Gestionar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$phase_id = $_GET["phase"] ?? null;

if (!$phase_id) {
    die("Phase no especificada.");
}

/* ===============================
   OBTENER PHASE
=============================== */
$stmtPhase = $pdo->prepare("
    SELECT p.name AS phase_name, l.id AS level_id, l.name AS level_name
    FROM english_phases p
    JOIN english_levels l ON p.level_id = l.id
    WHERE p.id = :id
    LIMIT 1
");

$stmtPhase->execute(["id" => $phase_id]);
$phase = $stmtPhase->fetch(PDO::FETCH_ASSOC);

if (!$phase) {
    die("Phase no encontrada.");
}

/* ===============================
   CREAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    $stmtInsert = $pdo->prepare("
        INSERT INTO units (name, phase_id, created_at, active, position)
        VALUES (:name, :phase_id, NOW(), true, 0)
        RETURNING id
    ");

    $stmtInsert->execute([
        "name" => $unitName,
        "phase_id" => $phase_id
    ]);

    $newUnitId = $stmtInsert->fetchColumn();

    // 🔥 REDIRECCIÓN AL HUB ENGLISH
    header("Location: ../activities/hub/index_english.php?unit=" . urlencode($newUnitId));
    exit;
}

/* ===============================
   OBTENER UNITS
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT *
    FROM units
    WHERE phase_id = :phase_id
    ORDER BY id ASC
");
$stmtUnits->execute(["phase_id" => $phase_id]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Units - English</title>
<style>
body{
    font-family: Arial, sans-serif;
    background:#eef2f7;
    padding:40px;
}
.container{ max-width:1000px; margin:0 auto; }
.back{
    display:inline-block;
    margin-bottom:25px;
    background:#6b7280;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
.card{
    background:#ffffff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.08);
    margin-bottom:25px;
}
input{
    width:100%;
    padding:12px;
    border-radius:8px;
    border:1px solid #ddd;
}
button{
    margin-top:15px;
    padding:10px 18px;
    background:#2563eb;
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}
.unit-item{
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
    color:#fff;
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="english_structure_phases.php?level=<?= urlencode($phase["level_id"]); ?>">
← Volver
</a>

<div class="card">
    <h2>➕ Crear Unit (<?= htmlspecialchars($phase["phase_name"]); ?>)</h2>

    <form method="POST">
        <input type="text" name="unit_name" required placeholder="Ej: UNIT 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Units creadas</h2>

    <?php if (empty($units)): ?>
        <p>No hay unidades creadas.</p>
    <?php else: ?>

        <?php foreach ($units as $unit): ?>
            <div class="unit-item">
                <strong><?= htmlspecialchars($unit["name"]); ?></strong>

                <a class="btn"
                   href="../activities/hub/index_english.php?unit=<?= urlencode($unit["id"]); ?>">
                   Administrar →
                </a>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

</div>
</body>
</html>

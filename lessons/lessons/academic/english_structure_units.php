<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$phaseId = $_GET["phase"] ?? null;
if (!$phaseId) {
    die("Phase no especificada.");
}

/* ===============================
   OBTENER PHASE
=============================== */
$stmtPhase = $pdo->prepare("SELECT * FROM english_phases WHERE id = :id LIMIT 1");
$stmtPhase->execute(["id" => $phaseId]);
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
        INSERT INTO units (phase_id, name, created_at)
        VALUES (:phase_id, :name, NOW())
    ");

    $stmtInsert->execute([
        "phase_id" => $phaseId,
        "name" => $unitName
    ]);

    $newUnitId = $pdo->lastInsertId();

    // 🔥 VA AL CHECKLIST (HUB)
    header("Location: ../activities/hub/index.php?unit=" . urlencode($newUnitId));
    exit;
}

/* ===============================
   LISTAR UNITS
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT *
    FROM units
    WHERE phase_id = :phase_id
    ORDER BY created_at ASC
");
$stmtUnits->execute(["phase_id" => $phaseId]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>English - Units</title>

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

<a class="back" href="english_structure_phases.php?level=<?= urlencode($phase["level_id"]) ?>">
← Volver
</a>

<div class="card">
    <h2>➕ Crear Unit (<?= htmlspecialchars($phase["name"]) ?>)</h2>

    <form method="POST">
        <input type="text" name="unit_name" required placeholder="Ej: UNIT 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Units creadas</h2>

    <?php if (empty($units)): ?>
        <p>No hay units creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="item">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>

                <!-- 🔥 ADMINISTRAR → VA AL HUB -->
                <a class="btn-blue"
                   href="../activities/hub/index.php?unit=<?= urlencode($unit["id"]) ?>">
                    Administrar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

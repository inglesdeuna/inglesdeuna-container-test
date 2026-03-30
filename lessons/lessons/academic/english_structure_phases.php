<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$levelId = $_GET["level"] ?? null;
if (!$levelId) {
    die("Level no especificado.");
}

/* ===============================
   OBTENER LEVEL
=============================== */
$stmtLevel = $pdo->prepare("SELECT * FROM english_levels WHERE id = :id LIMIT 1");
$stmtLevel->execute(["id" => $levelId]);
$level = $stmtLevel->fetch(PDO::FETCH_ASSOC);

if (!$level) {
    die("Level no encontrado.");
}

/* ===============================
   CREAR PHASE
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["phase_name"])) {

    $phaseName = strtoupper(trim($_POST["phase_name"]));

    $stmtInsert = $pdo->prepare("
        INSERT INTO english_phases (level_id, name, created_at)
        VALUES (:level_id, :name, NOW())
    ");

    $stmtInsert->execute([
        "level_id" => $levelId,
        "name" => $phaseName
    ]);

    header("Location: english_structure_phases.php?level=" . urlencode($levelId));
    exit;
}

/* ===============================
   LISTAR PHASES
=============================== */
$stmtPhases = $pdo->prepare("
    SELECT *
    FROM english_phases
    WHERE level_id = :level_id
    ORDER BY created_at ASC
");
$stmtPhases->execute(["level_id" => $levelId]);
$phases = $stmtPhases->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>English - Phases</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f0faf4;
    padding:40px;
}

.back{
    display:inline-block;
    margin-bottom:25px;
    background:#4b7a59;
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
    border:1px solid #a7f3d0;
}

button{
    margin-top:15px;
    padding:10px 18px;
    background:#16a34a;
    color:#ffffff;
    border:none;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}

.item{
    background:#dcfce7;
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn-blue{
    background:#16a34a;
    color:#ffffff;
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
</style>
</head>

<body>

<a class="back" href="english_structure_levels.php">
← Volver
</a>

<div class="card">
    <h2>➕ Crear Phase (<?= htmlspecialchars($level["name"]) ?>)</h2>

    <form method="POST">
        <input type="text" name="phase_name" required placeholder="Ej: PHASE 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Phases creadas</h2>

    <?php if (empty($phases)): ?>
        <p>No hay phases creadas.</p>
    <?php else: ?>
        <?php foreach ($phases as $phase): ?>
            <div class="item">
                <strong><?= htmlspecialchars($phase["name"]) ?></strong>

                <a class="btn-blue"
                   href="english_structure_units.php?phase=<?= urlencode($phase["id"]) ?>">
                    Administrar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$phaseId = $_GET["phase"] ?? null;

if (!$phaseId) {
    die("Phase requerida");
}

/* Validar que la phase exista */
$check = $pdo->prepare("SELECT id, name FROM english_phases WHERE id = :id");
$check->execute(["id" => $phaseId]);
$phase = $check->fetch(PDO::FETCH_ASSOC);

if (!$phase) {
    die("Phase no válida");
}

/* Obtener unidades */
$stmt = $pdo->prepare("
    SELECT id, name 
    FROM units 
    WHERE phase_id = :id
    ORDER BY id ASC
");

$stmt->execute(["id" => $phaseId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unidades - Cursos Creados</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef2f7;
    padding:40px;
}

.container{
    max-width:1000px;
    margin:0 auto;
}

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
    transition:.2s;
}

.btn:hover{
    background:#1d4ed8;
}
</style>
</head>

<body>

<div class="container">

    <a class="back" href="english_courses_created.php">
        ← Volver
    </a>

    <div class="card">
        <h2>📘 Unidades - <?= htmlspecialchars($phase["name"]); ?></h2>

        <?php if (empty($units)): ?>
            <p>No hay unidades creadas.</p>
        <?php else: ?>

            <?php foreach ($units as $u): ?>
                <div class="unit-item">
                    <strong><?= htmlspecialchars($u["name"]); ?></strong>

                    <a class="btn"
                       href="unit_view.php?unit=<?= urlencode($u["id"]) ?>&source=created">
                       Ver actividades →
                    </a>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

</body>
</html>

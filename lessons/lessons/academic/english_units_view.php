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
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --green:#2f9e44;
    --green-dark:#237a35;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}
body{
    font-family: Arial, sans-serif;
    background:var(--bg);
    padding:40px;
    color:var(--text);
}

.container{
    max-width:1000px;
    margin:0 auto;
}

.back{
    display:inline-block;
    margin-bottom:25px;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.card{
    background:var(--card);
    padding:30px;
    border-radius:18px;
    box-shadow:var(--shadow);
    border:1px solid var(--line);
}

.unit-item{
    background:#f7fcf8;
    border:1px solid var(--line);
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#fff;
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    transition:.2s;
}

.btn:hover{
    filter:brightness(1.06);
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

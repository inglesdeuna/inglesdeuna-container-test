<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA TÉCNICO
=============================== */

$stmtProgram = $pdo->prepare("
    SELECT id FROM programs
    WHERE slug = 'prog_technical'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa técnico no encontrado.");
}

$programId = $program["id"];

/* ===============================
   LISTAR SEMESTRES
=============================== */

$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");

$stmt->execute(["program_id" => $programId]);
$semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres creados</title>

<style>
body {
    font-family: Arial;
    background: #f4f8ff;
    padding: 40px;
}

.container {
    max-width: 900px;
    margin: auto;
}

.back {
    display: inline-block;
    margin-bottom: 25px;
    background: #6b7280;
    color: #fff;
    padding: 10px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.card {
    background: #fff;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
}

.item {
    background: #eef2ff;
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn {
    background: #2563eb;
    color: #fff;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.btn-delete {
    background: #dc2626;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="../admin/dashboard.php">← Volver</a>

<div class="card">
    <h2>📘 Semestres creados</h2>

    <?php if (empty($semesters)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach ($semesters as $semester): ?>
            <div class="item">
                <strong><?= htmlspecialchars($semester["name"]) ?></strong>

                <div>
                    <a class="btn"
                       href="technical_units.php?course=<?= urlencode($semester["id"]) ?>">
                        Ver →
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</div>

</body>
</html>

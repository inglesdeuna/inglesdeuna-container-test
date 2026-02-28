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
    SELECT * FROM programs
    WHERE slug = 'prog_technical'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa técnico no encontrado.");
}

/* ===============================
   OBTENER SEMESTRES
=============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");
$stmt->execute(["program_id" => $program["id"]]);
$semestres = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres creados</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    margin:0;
    padding:40px;
}

.container{
    max-width:1100px;
    margin:0 auto;
}

h1{
    margin-bottom:30px;
}

.card{
    background:#ffffff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
    margin-bottom:25px;
}

.item{
    background:#eef2ff;
    padding:18px 20px;
    border-radius:12px;
    margin-bottom:15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    padding:10px 20px;
    border-radius:10px;
    text-decoration:none;
    font-weight:bold;
    color:#fff;
}

.btn-blue{ background:#2563eb; }
.btn-red{ background:#dc2626; }
.btn-gray{
    background:#6b7280;
    display:inline-block;
    margin-bottom:25px;
}
</style>
</head>

<body>

<div class="container">

    <a class="btn btn-gray" href="../admin/dashboard.php">
        ← Volver
    </a>

    <h1>📘 Semestres creados</h1>

    <div class="card">

        <?php if (empty($semestres)): ?>
            <p>No hay semestres creados.</p>
        <?php else: ?>
            <?php foreach ($semestres as $semestre): ?>
                <div class="item">
                    <strong><?= htmlspecialchars($semestre["name"]) ?></strong>

                    <div>
                        <a class="btn btn-blue"
                           href="technical_units_view.php?course=<?= urlencode($course["id"]) ?>"
                           Ver →
                        </a>

                        <a class="btn btn-red"
                           href="delete_course.php?id=<?= urlencode($semestre["id"]) ?>"
                           onclick="return confirm('¿Eliminar semestre?');">
                           Eliminar
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

</div>

</body>
</html>

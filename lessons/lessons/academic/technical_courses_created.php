<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* =========================
   OBTENER SEMESTRES (courses técnicos)
========================= */

$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = 'prog_technical'
    ORDER BY name ASC
");

$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres creados</title>

<style>
body{
    font-family: Arial;
    background:#f4f8ff;
    padding:40px;
}

.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    max-width:900px;
}

.item{
    background:#eef2ff;
    padding:15px;
    border-radius:10px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    padding:8px 14px;
    border-radius:6px;
    text-decoration:none;
    font-weight:600;
    color:#fff;
}

.btn-blue{ background:#2563eb; }
.btn-red{ background:#dc2626; }
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">← Volver</a>

<div class="card">
    <h2>📘 Semestres creados</h2>

    <?php if(empty($courses)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach($courses as $course): ?>
            <div class="item">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>

                <div>
                    <a class="btn btn-blue"
                       href="technical_units_view.php?course=<?= urlencode($course["id"]) ?>">
                       Ver
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

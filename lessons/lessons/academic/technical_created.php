<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER SOLO SEMESTRES
=============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = 'prog_technical'
    ORDER BY name ASC
");

$stmt->execute();
$semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos creados</title>

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
    max-width:900px;
}

.item{
    background:#f1f5f9;
    padding:18px;
    border-radius:12px;
    margin-bottom:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn-blue{
    background:#2563eb;
    color:#ffffff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.btn-blue:hover{
    background:#1d4ed8;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
    <h2>📘 Programa Técnico — Cursos creados</h2>

    <?php if (empty($semesters)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach ($semesters as $semester): ?>
            <div class="item">
                <strong><?= htmlspecialchars($semester["name"]); ?></strong>

                <a class="btn-blue"
                   href="technical_units_view.php?course=<?= urlencode($semester["id"]); ?>">
                    Ver Unidades →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER LEVELS Y PHASES
=============================== */

$stmt = $pdo->prepare("
    SELECT 
        l.id AS level_id,
        l.name AS level_name,
        p.id AS phase_id,
        p.name AS phase_name
    FROM english_levels l
    LEFT JOIN english_phases p ON p.level_id = l.id
    ORDER BY l.id ASC, p.id ASC
");

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Agrupar por Level */
$levels = [];

foreach ($rows as $row) {
    $levelId = $row['level_id'];

    if (!isset($levels[$levelId])) {
        $levels[$levelId] = [
            'name' => $row['level_name'],
            'phases' => []
        ];
    }

    if (!empty($row['phase_id'])) {
        $levels[$levelId]['phases'][] = [
            'id' => $row['phase_id'],
            'name' => $row['phase_name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos creados - English</title>

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
    margin-bottom:25px;
}

.level-title{
    font-size:20px;
    margin-bottom:15px;
}

.phase-item{
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

    <a class="back" href="../admin/dashboard.php">
        ← Volver
    </a>

    <div class="card">
        <h2>📋 Cursos creados - English</h2>

        <?php if (empty($levels)): ?>
            <p>No hay estructura creada.</p>
        <?php else: ?>

            <?php foreach ($levels as $level): ?>

                <div class="card">
                    <div class="level-title">
                        <?= htmlspecialchars($level['name']); ?>
                    </div>

                    <?php if (empty($level['phases'])): ?>
                        <p>No hay fases creadas.</p>
                    <?php else: ?>

                        <?php foreach ($level['phases'] as $phase): ?>
                            <div class="phase-item">
                                <strong><?= htmlspecialchars($phase['name']); ?></strong>

                                <a class="btn"
                                   href="english_structure_units.php?phase=<?= urlencode($phase['id']); ?>">
                                   Ver →
                                </a>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

</body>
</html>

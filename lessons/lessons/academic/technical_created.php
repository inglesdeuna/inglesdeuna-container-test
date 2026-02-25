<?php
session_start();
require_once "../config/db.php";

/* ==========================
   VALIDAR PROGRAMA TÉCNICO
========================== */

$programId = "prog_technical";

/* ==========================
   OBTENER SOLO 4 SEMESTRES
========================== */

$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
    LIMIT 4
");

$stmt->execute([
    "program" => $programId
]);

$semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programa Técnico — Cursos creados</title>

<style>
body{
    font-family:Arial;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:14px;
    margin-bottom:20px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.semester-box{
    background:#e5e7eb;
    padding:18px;
    border-radius:10px;
    margin-bottom:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.semester-title{
    font-weight:bold;
    font-size:16px;
}

.btn{
    padding:10px 16px;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    color:#fff;
}

.btn-open{
    background:#2563eb;
}
.btn-open:hover{
    background:#1d4ed8;
}

.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
    <h2>📘 Programa Técnico — Cursos creados</h2>

    <?php if(empty($semesters)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>

        <?php foreach($semesters as $semester): ?>

            <div class="semester-box">

                <div class="semester-title">
                    <?= htmlspecialchars($semester["name"]); ?>
                </div>

                <a class="btn btn-open"
                   href="technical_units.php?course=<?= urlencode($semester["id"]); ?>">
                   Ver Unidades →
                </a>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>

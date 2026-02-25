<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programId = "prog_technical";

/* ===============================
   OBTENER SEMESTRES
=============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");

$stmt->execute(["program" => $programId]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programa Técnico - Cursos creados</title>

<style>
body{
    font-family:Arial;
    background:#f4f8ff;
    padding:40px;
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

h1{
    color:#2563eb;
    margin-bottom:30px;
}

.semester-card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:25px;
}

.semester-title{
    font-size:18px;
    font-weight:bold;
    margin-bottom:15px;
}

.unit-item{
    background:#eef2ff;
    padding:12px 15px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.unit-name{
    font-weight:600;
}

.btn{
    background:#2563eb;
    color:#fff;
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
}

.btn-secondary{
    background:#16a34a;
}

.empty{
    color:#6b7280;
    font-size:14px;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<h1>📘 Programa Técnico — Cursos creados</h1>

<?php if (empty($courses)): ?>

    <p>No hay semestres creados.</p>

<?php else: ?>

<?php foreach ($courses as $course): ?>

    <div class="semester-card">

        <div class="semester-title">
            <?= htmlspecialchars($course["name"]); ?>
        </div>

        <?php
        /* ===============================
           OBTENER UNIDADES DEL SEMESTRE
        =============================== */
        $stmtUnits = $pdo->prepare("
            SELECT *
            FROM units
            WHERE course_id = :course
            ORDER BY name ASC
        ");
        $stmtUnits->execute(["course" => $course["id"]]);
        $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <?php if (empty($units)): ?>

            <div class="empty">Sin unidades creadas.</div>

        <?php else: ?>

            <?php foreach ($units as $unit): ?>

                <div class="unit-item">

                    <div class="unit-name">
                        <?= htmlspecialchars($unit["name"]); ?>
                    </div>

                    <a class="btn"
                       href="../academic/unit_view.php?unit=<?= urlencode($unit["id"]); ?>">
                        Ver actividades →
                    </a>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

        <a class="btn btn-secondary"
           href="technical_units.php?course=<?= urlencode($course["id"]); ?>">
            Gestionar unidades →
        </a>

    </div>

<?php endforeach; ?>

<?php endif; ?>

</body>
</html>

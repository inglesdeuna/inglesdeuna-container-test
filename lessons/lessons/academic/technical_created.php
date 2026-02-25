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
    SELECT * FROM courses
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
<title>Semestres creados</title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:30px}
.grid{display:grid;gap:30px;max-width:900px}
.card{background:#fff;padding:25px;border-radius:14px;box-shadow:0 6px 14px rgba(0,0,0,.08)}
.card h2{margin-bottom:15px}
.unit{background:#f1f5ff;padding:12px 15px;border-radius:8px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
.unit a{text-decoration:none;font-weight:bold;color:#2563eb}
.empty{color:#6b7280}
.back{display:inline-block;margin-bottom:25px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
.semester-btn{display:inline-block;margin-top:15px;background:#2563eb;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:bold}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
  ← Volver al Dashboard
</a>

<h1>📘 Programa Técnico — Cursos creados</h1>

<div class="grid">

<?php if (empty($courses)): ?>
    <p>No hay semestres creados.</p>
<?php else: ?>

<?php foreach ($courses as $course): ?>

<div class="card">
    <h2><?= htmlspecialchars($course["name"]) ?></h2>

    <?php
    /* OBTENER UNIDADES DEL SEMESTRE */
    $stmtUnits = $pdo->prepare("
        SELECT * FROM units
        WHERE course_id = :course
        ORDER BY name ASC
    ");
    $stmtUnits->execute(["course" => $course["id"]]);
    $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if (empty($units)): ?>
        <div class="empty">Sin unidades creadas.</div>
    <?php else: ?>
        <?php foreach ($units as $u): ?>
            <div class="unit">
                <span><?= htmlspecialchars($u["name"]) ?></span>
                <a href="../activities/hub/index.php?unit=<?= urlencode($u["id"]) ?>">
                    Administrar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <a class="semester-btn" href="technical_units.php?course=<?= urlencode($course["id"]) ?>">
        Gestionar Unidades →
    </a>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>

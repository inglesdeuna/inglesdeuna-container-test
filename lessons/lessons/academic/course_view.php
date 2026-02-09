<?php
session_start();

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR COURSE
   =============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado");
}

/* ===============================
   BUSCAR COURSE EN DB
   =============================== */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    "id" => $courseId
]);

$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado");
}

/* ===============================
   OBTENER UNITS (si existen)
   =============================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course
    ORDER BY position ASC
");

$stmtUnits->execute([
    "course" => $courseId
]);

$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:20px}
.unit{background:#eef2ff;padding:15px;margin-top:10px;border-radius:10px}
a{text-decoration:none;color:#2563eb;font-weight:bold}
</style>
</head>
<body>

<div class="card">
<h1>📘 <?= htmlspecialchars($course["name"]) ?></h1>
<p>ID: <?= htmlspecialchars($course["id"]) ?></p>
</div>

<div class="card">
<h2>📚 Units del curso</h2>

<?php if (empty($units)): ?>
<p>No hay units aún.</p>
<?php else: ?>
<?php foreach ($units as $u): ?>
<div class="unit">
<?= htmlspecialchars($u["name"]) ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>

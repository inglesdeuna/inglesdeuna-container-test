<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

/* ===============================
   OBTENER CURSO
=============================== */
$stmtCourse = $pdo->prepare("
    SELECT * FROM courses WHERE id = :id LIMIT 1
");
$stmtCourse->execute(["id" => $courseId]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ===============================
   OBTENER UNIDADES
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course_id
    ORDER BY created_at ASC
");
$stmtUnits->execute([
    "course_id" => $courseId
]);

$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?> — Unidades</title>
<style>
body{
    font-family: Arial;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:16px;
    max-width:900px;
    margin:auto;
}

.item{
    display:flex;
    justify-content:space-between;
    padding:12px 0;
    border-bottom:1px solid #eee;
}

.btn{
    background:#2563eb;
    color:#fff;
    padding:6px 14px;
    border-radius:6px;
    text-decoration:none;
}
</style>
</head>

<body>

<div class="card">
<h2>📚 Unidades — <?= htmlspecialchars($course["name"]) ?></h2>

<?php if(empty($units)): ?>
<p>No hay unidades creadas.</p>
<?php else: ?>
<?php foreach($units as $unit): ?>
<div class="item">
    <strong><?= htmlspecialchars($unit["name"]) ?></strong>
    <a class="btn"
       href="unit_view.php?unit=<?= urlencode($unit["id"]) ?>">
       Ver →
    </a>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>

<?php
session_start();

/* ===============================
   SEGURIDAD ADMIN
=============================== */
if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ===============================
   DB CONNECTION
=============================== */
require_once __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR COURSE ID
=============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado");
}

/* ===============================
   BUSCAR COURSE EN DB
=============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM courses
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
   CREAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $unitName = trim($_POST["unit_name"] ?? "");

    if ($unitName !== "") {

        $unitId = uniqid("unit_");

        // calcular posición automática
        $stmtPos = $pdo->prepare("
            SELECT COUNT(*) FROM units
            WHERE course_id = :course
        ");

        $stmtPos->execute([
            "course" => $courseId
        ]);

        $position = $stmtPos->fetchColumn() + 1;

        // insertar unit
        $stmtInsert = $pdo->prepare("
            INSERT INTO units (id, course_id, name, position)
            VALUES (:id, :course, :name, :pos)
        ");

        $stmtInsert->execute([
            "id" => $unitId,
            "course" => $courseId,
            "name" => $unitName,
            "pos" => $position
        ]);

        header("Location: course_view.php?course=" . urlencode($courseId));
        exit;
    }
}

/* ===============================
   OBTENER UNITS
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT *
    FROM units
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
<title>Curso</title>

<style>
body{
    font-family: Arial;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:14px;
    margin-bottom:25px;
    max-width:700px;
}

.unit{
    background:#fff;
    padding:14px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    box-shadow:0 3px 6px rgba(0,0,0,.08);
}

button{
    padding:10px 16px;
    border:none;
    border-radius:8px;
    background:#2563eb;
    color:white;
    font-weight:bold;
}

input{
    width:100%;
    padding:12px;
    margin-top:10px;
}
</style>

</head>

<body>

<h1>📘 <?= htmlspecialchars($course["name"]) ?></h1>
<p>ID: <?= htmlspecialchars($course["id"]) ?></p>

<div class="card">
<h2>➕ Crear Unit</h2>

<form method="POST">
<input type="text" name="unit_name" placeholder="Ej: Unit 1" required>
<button>Crear Unit</button>
</form>

</div>

<div class="card">
<h2>📚 Units del curso</h2>

<?php if (empty($units)): ?>
<p>No hay units aún.</p>
<?php else: ?>

<?php foreach ($units as $u): ?>
<div class="unit">
<strong><?= htmlspecialchars($u["name"]) ?></strong>

<a href="unit_view.php?unit=<?= urlencode($u["id"]) ?>">
<button>Abrir →</button>
</a>

</div>
<?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>

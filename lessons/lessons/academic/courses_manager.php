<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programId = $_GET["program"] ?? null;

if (!$programId) {
    die("Programa no especificado");
}

/* ===============================
   SEMESTRES FIJOS
=============================== */
$semesters = [
    "SEMESTRE 1",
    "SEMESTRE 2",
    "SEMESTRE 3",
    "SEMESTRE 4"
];

/* ===============================
   OBTENER SEMESTRES CREADOS
=============================== */
$stmt = $pdo->prepare("
    SELECT id, name FROM courses
    WHERE program_id = :program
");
$stmt->execute(["program" => $programId]);
$createdCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$createdMap = [];
foreach ($createdCourses as $c) {
    $createdMap[$c["name"]] = $c["id"];
}

/* ===============================
   CREAR / ACCEDER
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["semester"])) {

    $name = $_POST["semester"];

    if (isset($createdMap[$name])) {
        $courseId = $createdMap[$name];
    } else {
        $courseId = uniqid("course_");

        $stmt = $pdo->prepare("
            INSERT INTO courses (id, program_id, name)
            VALUES (:id, :program_id, :name)
        ");

        $stmt->execute([
            "id" => $courseId,
            "program_id" => $programId,
            "name" => $name
        ]);
    }

    header("Location: technical_units.php?course=" . urlencode($courseId));
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programa Técnico</title>

<style>
body{
    font-family:Arial;
    background:#f4f8ff;
    padding:40px;
}

h1{
    color:#2563eb;
    margin-bottom:30px;
}

.grid{
    display:grid;
    grid-template-columns: repeat(2, 1fr);
    gap:25px;
    max-width:900px;
}

.card{
    background:#fff;
    padding:30px;
    border-radius:14px;
    box-shadow:0 6px 14px rgba(0,0,0,.08);
    text-align:center;
    position:relative;
}

.card h2{
    margin-bottom:15px;
}

.status{
    font-size:14px;
    margin-bottom:20px;
    font-weight:bold;
}

.status.available{
    color:#16a34a;
}

.status.created{
    color:#2563eb;
}

button{
    padding:12px 22px;
    border:none;
    border-radius:8px;
    font-weight:bold;
    cursor:pointer;
}

button.available{
    background:#16a34a;
    color:#fff;
}

button.created{
    background:#2563eb;
    color:#fff;
}

button:hover{
    opacity:.9;
}

.back{
    display:inline-block;
    margin-bottom:25px;
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

<h1>📘 Programa Técnico</h1>

<form method="post">
<div class="grid">

<?php foreach ($semesters as $semester): 
    $isCreated = isset($createdMap[$semester]);
?>

<div class="card">
    <h2><?= $semester ?></h2>

    <?php if ($isCreated): ?>
        <div class="status created">Creado</div>
        <button class="created" name="semester" value="<?= $semester ?>">
            Continuar →
        </button>
    <?php else: ?>
        <div class="status available">Disponible</div>
        <button class="available" name="semester" value="<?= $semester ?>">
            Crear →
        </button>
    <?php endif; ?>

</div>

<?php endforeach; ?>

</div>
</form>

</body>
</html>

<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programSlug = $_GET["program"] ?? null;

if (!$programSlug) {
    die("Programa no especificado.");
}

/* =====================================
   OBTENER PROGRAM ID REAL (INTEGER)
===================================== */
$stmtProgram = $pdo->prepare("
    SELECT id, name
    FROM programs
    WHERE slug = :slug
    LIMIT 1
");
$stmtProgram->execute(["slug" => $programSlug]);
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa no encontrado.");
}

$programId = $program["id"];

/* =====================================
   CREAR SEMESTRE MANUAL
===================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

    $name = strtoupper(trim($_POST["course_name"]));

    $check = $pdo->prepare("
        SELECT id FROM courses
        WHERE program_id = :program_id
        AND name = :name
        LIMIT 1
    ");
    $check->execute([
        "program_id" => $programId,
        "name" => $name
    ]);

    if (!$check->fetch()) {
        $insert = $pdo->prepare("
            INSERT INTO courses (program_id, name, slug)
            VALUES (:program_id, :name, :slug)
        ");

        $insert->execute([
            "program_id" => $programId,
            "name" => $name,
            "slug" => strtolower(str_replace(" ", "_", $name))
        ]);
    }

    header("Location: courses_manager.php?program=" . urlencode($programSlug));
    exit;
}

/* =====================================
   LISTAR CURSOS
===================================== */
$stmtCourses = $pdo->prepare("
    SELECT *
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");

$stmtCourses->execute(["program_id" => $programId]);
$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($program["name"]) ?></title>

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
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    max-width:900px;
    margin-bottom:25px;
}

button{
    padding:10px 18px;
    border:none;
    border-radius:8px;
    background:#2563eb;
    color:#fff;
    font-weight:bold;
    cursor:pointer;
}

input{
    padding:10px;
    width:100%;
    border-radius:8px;
    border:1px solid #ddd;
    margin-bottom:10px;
}

.item{
    background:#eef2ff;
    padding:15px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    background:#2563eb;
    color:#fff;
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
    font-weight:bold;
}

.back{
    display:inline-block;
    margin-bottom:20px;
    padding:8px 15px;
    background:#6b7280;
    color:#fff;
    border-radius:8px;
    text-decoration:none;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">Volver</a>

<div class="card">
    <h2>➕ Crear Semestre</h2>

    <form method="POST">
        <input type="text" name="course_name" required placeholder="Ej: SEMESTRE 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Semestres creados</h2>

    <?php if (empty($courses)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div class="item">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>

                <a class="btn"
                   href="technical_units.php?course=<?= urlencode($course["slug"]) ?>">
                    Gestionar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

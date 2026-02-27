<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA POR SLUG
=============================== */

$programSlug = $_GET["program"] ?? null;

if (!$programSlug) {
    die("Programa no especificado.");
}

$stmtProgram = $pdo->prepare("
    SELECT * FROM programs
    WHERE slug = :slug
    LIMIT 1
");

$stmtProgram->execute([
    "slug" => $programSlug
]);

$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa no encontrado.");
}

$programId = $program["id"];

/* ===============================
   OBTENER CURSOS (SEMESTRES)
=============================== */

$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program_id
    ORDER BY name ASC
");

$stmt->execute([
    "program_id" => $programId
]);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($program["name"]) ?></title>

<style>
body {
    font-family: Arial;
    background: #f4f8ff;
    padding: 40px;
}

.card {
    background: #fff;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
    max-width: 800px;
    margin-bottom: 25px;
}

.btn {
    display:inline-block;
    padding:10px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    color:#fff;
    background:#2563eb;
}

.back {
    display:inline-block;
    margin-bottom:20px;
    padding:10px 16px;
    background:#6b7280;
    color:#fff;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">Volver</a>

<div class="card">
    <h2><?= htmlspecialchars($program["name"]) ?></h2>

    <?php if (empty($courses)): ?>
        <p>No hay cursos creados.</p>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div style="margin-bottom:10px;">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>
                <a class="btn"
                   href="technical_units.php?course=<?= urlencode($course["id"]) ?>">
                   Administrar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

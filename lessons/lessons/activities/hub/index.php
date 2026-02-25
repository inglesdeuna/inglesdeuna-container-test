<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../../admin/login.php");
    exit;
}

require __DIR__ . "/../../config/db.php";

$unitId = $_GET["unit"] ?? null;

if (!$unitId) {
    die("Unidad no especificada.");
}

/* ===============================
   OBTENER UNIDAD
=============================== */
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS course_name, c.program_id
    FROM units u
    JOIN courses c ON u.course_id = c.id
    WHERE u.id = :unit
    LIMIT 1
");

$stmt->execute(["unit" => $unitId]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no válida.");
}

$programLabel = $unit["program_id"] === "prog_technical"
    ? "Programa Técnico"
    : "Programa Inglés";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>HUB Actividades</title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:30px}
.header-box{
    background:#fff;
    padding:20px 25px;
    border-radius:12px;
    margin-bottom:25px;
    box-shadow:0 6px 14px rgba(0,0,0,.08)
}
.header-box h2{
    margin-bottom:8px;
}
.meta{
    font-size:14px;
    color:#6b7280;
}
.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#fff;
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none
}
</style>
</head>

<body>

<a class="back" href="../../academic/technical_created.php">
  ← Volver a Cursos
</a>

<div class="header-box">
    <h2><?= htmlspecialchars($unit["name"]) ?></h2>
    <div class="meta">
        <?= htmlspecialchars($programLabel) ?> |
        <?= htmlspecialchars($unit["course_name"]) ?> |
        ID Unidad: <?= htmlspecialchars($unitId) ?>
    </div>
</div>

<!-- AQUI CONTINÚA TU HUB ACTUAL -->

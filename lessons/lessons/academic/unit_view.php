<?php
session_start();

/* ===============================
   SEGURIDAD
=============================== */
if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

/* ===============================
   DB
=============================== */
require_once __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR UNIT
=============================== */
$unitId = $_GET["unit"] ?? null;

if (!$unitId) {
    die("Unit no especificada");
}

/* ===============================
   BUSCAR UNIT
=============================== */
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS course_name
    FROM units u
    JOIN courses c ON c.id = u.course_id
    WHERE u.id = :id
    LIMIT 1
");

$stmt->execute([
    "id" => $unitId
]);

$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada");
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unit</title>

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
button{
    padding:10px 16px;
    border:none;
    border-radius:8px;
    background:#2563eb;
    color:white;
    font-weight:bold;
}
</style>

</head>
<body>

<h1>📚 <?= htmlspecialchars($unit["name"]) ?></h1>

<div class="card">
<p><strong>ID:</strong> <?= htmlspecialchars($unit["id"]) ?></p>
<p><strong>Curso:</strong> <?= htmlspecialchars($unit["course_name"]) ?></p>
<p><strong>Posición:</strong> <?= htmlspecialchars($unit["position"]) ?></p>
</div>

<div class="card">
<h2>🎮 Actividades</h2>

<a href="../activities/hangman/editor.php?unit=<?= urlencode($unitId) ?>">
<button>➕ Hangman</button>
</a>

<br><br>

<a href="../activities/drag_drop/editor.php?unit=<?= urlencode($unitId) ?>">
<button>➕ Drag & Drop</button>
</a>

<br><br>

<a href="../activities/match/viewer.php?unit=<?= urlencode($unitId) ?>">
<button>🧩 Match</button>
</a>

<br><br>

<a href="../activities/pronunciation/viewer.php?unit=<?= urlencode($unitId) ?>">
<button>🎧 Pronunciation</button>
</a>
 
</body>
</html>

<?php
session_start();

/**
 * UNIT VIEW
 * Vista de una unidad y acceso a actividades
 */

// 🔐 ADMIN o DOCENTE
if (
  !isset($_SESSION["admin_logged"]) &&
  !isset($_SESSION["academic_logged"])
) {
  header("Location: login.php");
  exit;
}

/* ==========================
   VALIDAR UNIDAD
   ========================== */
$unitId = $_GET["unit"] ?? null;
if (!$unitId) {
  die("Unidad no especificada");
}

/* ==========================
   DATA
   ========================== */
$baseDir = __DIR__ . "/data";
$unitsFile   = $baseDir . "/units.json";
$coursesFile = $baseDir . "/courses.json";

if (!file_exists($unitsFile)) {
  die("Archivo de unidades no encontrado");
}

$units   = json_decode(file_get_contents($unitsFile), true) ?? [];
$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

$units   = is_array($units)   ? $units   : [];
$courses = is_array($courses) ? $courses : [];

/* ==========================
   BUSCAR UNIDAD
   ========================== */
$unit = null;
foreach ($units as $u) {
  if (($u["id"] ?? null) === $unitId) {
    $unit = $u;
    break;
  }
}
if (!$unit) {
  die("Unidad no encontrada");
}

/* ==========================
   BUSCAR CURSO
   ========================== */
$courseName = "";
foreach ($courses as $c) {
  if (($c["id"] ?? null) === ($unit["course_id"] ?? null)) {
    $courseName = $c["name"];
    break;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($unit["name"]) ?></title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
.card{
  background:#fff;
  padding:30px;
  border-radius:16px;
  max-width:700px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
h1{color:#2563eb;}
.btn{
  display:inline-block;
  margin-top:20px;
  padding:14px 22px;
  background:#16a34a;
  color:#fff;
  border-radius:10px;
  text-decoration:none;
  font-weight:700;
}
.back{
  display:inline-block;
  margin-top:20px;
  margin-left:15px;
  color:#2563eb;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<div class="card">
  <h1>📘 <?= htmlspecialchars($unit["name"]) ?></h1>

  <?php if ($courseName): ?>
    <p><strong>Curso:</strong> <?= htmlspecialchars($courseName) ?></p>
  <?php endif; ?>

  <a class="btn"
     href="/lessons/lessons/activities/hub/index.php?unit=<?= urlencode($unitId) ?>">
    📦 Abrir actividades
  </a>

  <a class="back"
     href="course_view.php?course=<?= urlencode($unit["course_id"]) ?>">
    ← Volver al curso
  </a>
</div>

</body>
</html>

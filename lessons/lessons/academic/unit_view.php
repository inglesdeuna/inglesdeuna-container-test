<?php
session_start();

/* ACCESO: cualquiera logueado */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"]) &&
  !isset($_SESSION["student_id"])
) {
  header("Location: login.php");
  exit;
}

/* PARAMETROS */
$courseId = $_GET["course"] ?? null;
$unitId   = $_GET["unit"] ?? null;

if (!$courseId || !$unitId) {
  die("Curso o unidad no especificados");
}

/* ARCHIVO */
$file = dirname(__DIR__) . "/academic/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($courses)) $courses = [];

/* BUSCAR CURSO Y UNIDAD */
$courseIndex = null;
$unitIndex   = null;

foreach ($courses as $ci => $c) {
  if (($c["id"] ?? null) === $courseId) {
    $courseIndex = $ci;
    foreach ($c["units"] ?? [] as $ui => $u) {
      if (($u["id"] ?? null) === $unitId) {
        $unitIndex = $ui;
        break;
      }
    }
    break;
  }
}

if ($courseIndex === null || $unitIndex === null) {
  die("Unidad no encontrada");
}

$course = $courses[$courseIndex];
$unit   = $course["units"][$unitIndex];
$activities = $unit["activities"] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($unit["name"]) ?></title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:25px;border-radius:14px;max-width:700px}
.item{margin-top:10px;padding:12px;background:#eef2ff;border-radius:8px}
a{color:#2563eb;text-decoration:none;font-weight:700}
</style>
</head>
<body>

<div class="box">
  <h2>📘 <?= htmlspecialchars($course["name"]) ?></h2>
  <h3>📦 <?= htmlspecialchars($unit["name"]) ?></h3>

  <hr>

  <h3>🎮 Actividades</h3>

  <?php if (empty($activities)): ?>
    <p>No hay actividades.</p>
  <?php else: ?>
    <?php foreach ($activities as $a): ?>
      <?php if (($a["type"] ?? "") === "hangman"): ?>
        <div class="item">
          🎯 Hangman — <?= htmlspecialchars($a["data"]["word"] ?? "") ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <hr>

  <a href="course_view.php?course=<?= urlencode($courseId) ?>">
    ← Volver al curso
  </a>
</div>

</body>
</html>

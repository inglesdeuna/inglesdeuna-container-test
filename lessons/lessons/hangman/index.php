<?php
session_start();

/* =====================
   VALIDAR PARÁMETROS
   ===================== */
$courseId = $_GET["course"] ?? null;
$unitId   = $_GET["unit"] ?? null;

if (!$courseId || !$unitId) {
  die("Curso o unidad no especificados");
}

/* =====================
   ARCHIVO DE CURSOS (ÚNICO)
   ===================== */
$file = dirname(__DIR__) . "/academic/courses.json";

$courses = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!is_array($courses)) {
  $courses = [];
}

/* =====================
   BUSCAR CURSO Y UNIDAD
   ===================== */
$courseIndex = null;
$unitIndex   = null;

foreach ($courses as $ci => $course) {
  if (($course["id"] ?? null) === $courseId) {
    $courseIndex = $ci;

    if (isset($course["units"]) && is_array($course["units"])) {
      foreach ($course["units"] as $ui => $unit) {
        if (($unit["id"] ?? null) === $unitId) {
          $unitIndex = $ui;
          break;
        }
      }
    }
    break;
  }
}

if ($courseIndex === null || $unitIndex === null) {
  die("Curso o unidad no encontrados");
}

/* =====================
   ASEGURAR ACTIVITIES
   ===================== */
if (
  !isset($courses[$courseIndex]["units"][$unitIndex]["activities"]) ||
  !is_array($courses[$courseIndex]["units"][$unitIndex]["activities"])
) {
  $courses[$courseIndex]["units"][$unitIndex]["activities"] = [];
}

/* =====================
   GUARDAR ACTIVIDAD
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["word"])) {

  $courses[$courseIndex]["units"][$unitIndex]["activities"][] = [
    "type" => "hangman",
    "data" => [
      "word" => trim($_POST["word"])
    ]
  ];

  file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));

  // volver al editor (para ver la lista actualizada)
  header("Location: index.php?course=" . urlencode($courseId) . "&unit=" . urlencode($unitId));
  exit;
}

/* =====================
   ACTIVIDADES EXISTENTES
   ===================== */
$activities = $courses[$courseIndex]["units"][$unitIndex]["activities"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:25px;border-radius:14px;max-width:500px}
.item{margin-top:8px;padding:8px;background:#eef2ff;border-radius:6px}
</style>
</head>
<body>

<div class="box">
  <h2>🎯 Hangman – Editor</h2>

  <form method="post">
    <input type="text" name="word" placeholder="Palabra" required>
    <button type="submit">Guardar actividad</button>
  </form>

  <hr>

  <h3>Actividades guardadas</h3>

  <?php if (empty($activities)): ?>
    <p>No hay actividades aún.</p>
  <?php else: ?>
    <?php foreach ($activities as $a): ?>
      <?php if (($a["type"] ?? "") === "hangman"): ?>
        <div class="item">
          <?= htmlspecialchars($a["data"]["word"] ?? "") ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <hr>

  <a href="../academic/course_view.php?course=<?= urlencode($courseId) ?>">
    ← Volver al curso
  </a>
</div>

</body>
</html>

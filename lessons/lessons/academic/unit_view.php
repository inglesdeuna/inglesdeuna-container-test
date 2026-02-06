<?php
session_start();

$courseId = $_GET["course"] ?? null;
$unitId   = $_GET["unit"] ?? null;
if (!$courseId || !$unitId) die("Parámetros faltantes");

$file = dirname(__DIR__) . "/academic/courses.json";
$courses = json_decode(file_get_contents($file), true);
if (!is_array($courses)) $courses = [];

$courseIndex = $unitIndex = null;

foreach ($courses as $ci => $c) {
  if ($c["id"] === $courseId) {
    $courseIndex = $ci;
    foreach ($c["units"] as $ui => $u) {
      if ($u["id"] === $unitId) {
        $unitIndex = $ui;
        break;
      }
    }
    break;
  }
}

if ($courseIndex === null || $unitIndex === null) die("No encontrado");

$course = $courses[$courseIndex];
$unit   = $course["units"][$unitIndex];
$activities = $unit["activities"] ?? [];
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Unidad</title></head>
<body>

<h2>📘 <?= htmlspecialchars($course["name"]) ?></h2>
<h3>📦 <?= htmlspecialchars($unit["name"]) ?></h3>

<h3>🎮 Actividades</h3>

<?php if (empty($activities)): ?>
  <p>No hay actividades.</p>
<?php else: ?>
  <?php foreach ($activities as $a): ?>
    <div>🎯 Hangman — <?= htmlspecialchars($a["data"]["word"] ?? "") ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<br>
<a href="course_view.php?course=<?= urlencode($courseId) ?>">← Volver</a>

</body>
</html>

<?php
session_start();

/* LOGIN */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"])
) {
  header("Location: login.php");
  exit;
}

/* ARCHIVO */
$file = dirname(__DIR__) . "/academic/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($courses)) $courses = [];

/* VALIDAR CURSO */
$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");

$courseIndex = null;
foreach ($courses as $i => $c) {
  if (($c["id"] ?? null) === $courseId) {
    $courseIndex = $i;
    break;
  }
}
if ($courseIndex === null) die("Curso no encontrado");

$course = $courses[$courseIndex];
$course["units"] = is_array($course["units"]) ? $course["units"] : [];

/* CREAR UNIDAD */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

  $courses[$courseIndex]["units"][] = [
    "id" => "unit_" . time(),
    "name" => trim($_POST["unit_name"]),
    "activities" => []
  ];

  file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>
</head>
<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<h2>📚 Unidades</h2>

<?php if (empty($course["units"])): ?>
  <p>No hay unidades creadas.</p>
<?php else: ?>
  <?php foreach ($course["units"] as $u): ?>
    <div>
      <?= htmlspecialchars($u["name"]) ?>
      <a href="unit_view.php?course=<?= urlencode($courseId) ?>&unit=<?= urlencode($u["id"]) ?>">
  ✏️ Editor
</a>

    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h3>➕ Crear unidad</h3>
<form method="post">
  <input type="text" name="unit_name" required>
  <button>Agregar</button>
</form>

</body>
</html>


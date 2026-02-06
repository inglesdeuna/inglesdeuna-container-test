<?php
session_start();

if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"]) &&
  !isset($_SESSION["student_id"])
) {
  header("Location: login.php");
  exit;
}

$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");

$file = dirname(__DIR__) . "/academic/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($courses)) $courses = [];

$courseIndex = null;
foreach ($courses as $i => $c) {
  if (($c["id"] ?? null) === $courseId) {
    $courseIndex = $i;
    break;
  }
}
if ($courseIndex === null) die("Curso no encontrado");

$course = $courses[$courseIndex];
$course["units"] = $course["units"] ?? [];

require_once __DIR__ . "/helpers.php";
$userRole = getUserRole($course, $_SESSION);
$canEdit  = ($userRole === "editor");

/* CREAR UNIDAD */
if ($canEdit && $_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {
  $course["units"][] = [
    "id" => "unit_" . time(),
    "name" => trim($_POST["unit_name"]),
    "activities" => []
  ];
  $courses[$courseIndex] = $course;
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

<?php if ($canEdit): ?>
  <a href="roles.php?course=<?= urlencode($courseId) ?>">👥 Roles</a>
<?php endif; ?>

<h2>📚 Unidades</h2>

<?php if (empty($course["units"])): ?>
  <p>No hay unidades creadas.</p>
<?php else: ?>
  <?php foreach ($course["units"] as $u): ?>
    <div>
      <?= htmlspecialchars($u["name"]) ?>

      <a href="unit_viewer.php?course=<?= urlencode($courseId) ?>&unit=<?= urlencode($u["id"]) ?>">
        👀 Ver
      </a>

      <?php if ($canEdit): ?>
        <a href="unit_view.php?course=<?= urlencode($courseId) ?>&unit=<?= urlencode($u["id"]) ?>">
          ✏️ Editor
        </a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($canEdit): ?>
  <form method="post">
    <input name="unit_name" placeholder="Nueva unidad" required>
    <button>Agregar</button>
  </form>
<?php endif; ?>

</body>
</html>

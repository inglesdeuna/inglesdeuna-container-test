<?php
session_start();

/* =====================
   LOGIN GENERAL
   ===================== */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"]) &&
  !isset($_SESSION["student_id"])
) {
  header("Location: login.php");
  exit;
}

/* =====================
   ARCHIVO
   ===================== */
$file = __DIR__ . "/courses.json";
$courses = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!is_array($courses)) {
  $courses = [];
}

/* =====================
   VALIDAR CURSO
   ===================== */
$courseId = $_GET["course"] ?? null;
if (!$courseId) {
  die("Curso no especificado");
}

$courseIndex = null;

foreach ($courses as $i => $c) {
  if (is_array($c) && ($c["id"] ?? null) === $courseId) {
    $courseIndex = $i;
    break;
  }
}

if ($courseIndex === null) {
  die("Curso no encontrado");
}

/* =====================
   CURSO SEGURO
   ===================== */
$course = $courses[$courseIndex];

$course["units"] = is_array($course["units"] ?? null) ? $course["units"] : [];
$course["students"] = is_array($course["students"] ?? null) ? $course["students"] : [];
$course["teacher"] = is_array($course["teacher"] ?? null) ? $course["teacher"] : null;

/* =====================
   PERMISOS
   ===================== */
$canEdit = isset($_SESSION["admin_id"]) || isset($_SESSION["teacher_id"]);

/* =====================
   CREAR UNIDAD (POST)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_unit"]) && $canEdit) {

  $unitName = trim($_POST["unit_name"]);

  if ($unitName !== "") {
    $courses[$courseIndex]["units"][] = [
      "id" => "unit_" . time(),
      "name" => $unitName,
      "activities" => []
    ];

    file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));
  }

  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:20px}
.unit{padding:10px;border-bottom:1px solid #e5e7eb}
a{margin-left:10px;color:#2563eb;text-decoration:none}
</style>
</head>
<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<div class="card">
<h2>📚 Unidades</h2>

<?php if (empty($course["units"])): ?>
  <p>No hay unidades creadas.</p>
<?php else: ?>
  <?php foreach ($course["units"] as $u): ?>
    <?php if (!is_array($u)) continue; ?>
    <div class="unit">
      <strong><?= htmlspecialchars($u["name"]) ?></strong>
      <a href="../hangman/index.php?course=<?= urlencode($courseId) ?>&unit=<?= urlencode($u["id"]) ?>">
        ✏️ Editor
      </a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div class="card">
  <h3>➕ Crear unidad</h3>
  <form method="post">
    <input type="text" name="unit_name" required>
    <button name="add_unit">Agregar</button>
  </form>
</div>
<?php endif; ?>

</body>
</html>

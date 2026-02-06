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
   ARCHIVOS
   ===================== */
$coursesFile = __DIR__ . "/courses.json";
$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

/* =====================
   VALIDAR CURSO
   ===================== */
$courseId = $_GET["course"] ?? null;
if (!$courseId) {
  die("Curso no especificado");
}

$courseIndex = null;

foreach ($courses as $i => $c) {
  if (isset($c["id"]) && $c["id"] === $courseId) {
    $courseIndex = $i;
    break;
  }
}

if ($courseIndex === null) {
  die("Curso no encontrado");
}

/* =====================
   NORMALIZAR CURSO
   ===================== */
$courses[$courseIndex]["students"] = $courses[$courseIndex]["students"] ?? [];
$courses[$courseIndex]["units"]    = $courses[$courseIndex]["units"] ?? [];
$courses[$courseIndex]["teacher"]  = $courses[$courseIndex]["teacher"] ?? null;

/* normalizar teacher */
if (is_string($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = [
    "id" => $courses[$courseIndex]["teacher"],
    "permission" => "editor"
  ];
}

if (!is_array($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = null;
}

/* guardar normalización */
file_put_contents(
  $coursesFile,
  json_encode($courses, JSON_PRETTY_PRINT)
);

/* curso FINAL y único */
$course = $courses[$courseIndex];

/* =====================
   PERMISOS
   ===================== */
$canEdit = false;

if (isset($_SESSION["admin_id"])) {
  $canEdit = true;
}

if (
  isset($_SESSION["teacher_id"]) &&
  is_array($course["teacher"]) &&
  ($course["teacher"]["id"] ?? null) === $_SESSION["teacher_id"]
) {
  $canEdit = true;
}

/* =====================
   CREAR UNIDAD
   ===================== */
if (
  $_SERVER["REQUEST_METHOD"] === "POST" &&
  isset($_POST["add_unit"]) &&
  $canEdit
) {
  $unitName = trim($_POST["unit_name"]);

  if ($unitName !== "") {
    $courses[$courseIndex]["units"][] = [
      "id" => "unit_" . time(),
      "name" => $unitName,
      "activities" => []
    ];

    file_put_contents(
      $coursesFile,
      json_encode($courses, JSON_PRETTY_PRINT)
    );
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
.section{background:#fff;padding:25px;border-radius:14px;margin-bottom:30px}
.unit{padding:12px;border-bottom:1px solid #e5e7eb}
a{margin-left:10px;text-decoration:none;color:#2563eb}
</style>
</head>
<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<div class="section">
<h2>📚 Unidades</h2>

<?php if (empty($course["units"])): ?>
  <p>No hay unidades creadas.</p>
<?php else: ?>
  <?php foreach ($course["units"] as $u): ?>
    <div class="unit">
      <strong><?= htmlspecialchars($u["name"]) ?></strong>

      <a href="unit_view.php?course=<?= urlencode($courseId) ?>&unit=<?= urlencode($u["id"]) ?>">
        👁 Ver
      </a>

      <?php if ($canEdit): ?>
        <a href="../hangman/index.php?course=<?= urlencode($courseId) ?>&unit=<?= urlencode($u["id"]) ?>">
          ✏️ Editor
        </a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<hr>
<form method="post">
  <input type="text" name="unit_name" placeholder="Nombre de la unidad" required>
  <button name="add_unit">Agregar unidad</button>
</form>
<?php endif; ?>

</div>

</body>
</html>

<?php
session_start();

/* SOLO ADMIN O DOCENTE */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"])
) {
  header("Location: login.php");
  exit;
}

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/courses.json";
$teachersFile = __DIR__ . "/teachers.json";
$studentsFile = __DIR__ . "/students.json";

$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$teachers = file_exists($teachersFile) ? json_decode(file_get_contents($teachersFile), true) : [];
$students = file_exists($studentsFile) ? json_decode(file_get_contents($studentsFile), true) : [];

if (!is_array($courses))  $courses = [];
if (!is_array($teachers)) $teachers = [];
if (!is_array($students)) $students = [];

/* CURSO */
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

$courses[$courseIndex]["students"] = $courses[$courseIndex]["students"] ?? [];
$courses[$courseIndex]["teacher"]  = $courses[$courseIndex]["teacher"]  ?? null;

/* ASIGNAR DOCENTE */
if (isset($_POST["assign_teacher"])) {
  $tid = $_POST["teacher_id"] ?? null;
  if ($tid) {
    $courses[$courseIndex]["teacher"] = [
      "id" => $tid,
      "role" => "editor"
    ];
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
}

/* AGREGAR ESTUDIANTE */
if (isset($_POST["add_student"])) {
  $sid = $_POST["student_id"] ?? null;
  if ($sid) {
    $exists = false;
    foreach ($courses[$courseIndex]["students"] as $s) {
      if ($s["id"] === $sid) $exists = true;
    }
    if (!$exists) {
      $courses[$courseIndex]["students"][] = [
        "id" => $sid,
        "role" => "viewer"
      ];
      file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
    }
  }
}

/* QUITAR ESTUDIANTE */
if (isset($_GET["remove_student"])) {
  $courses[$courseIndex]["students"] = array_values(
    array_filter(
      $courses[$courseIndex]["students"],
      fn($s) => $s["id"] !== $_GET["remove_student"]
    )
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
}

$course = $courses[$courseIndex];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Roles</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:25px;border-radius:14px;max-width:700px}
.remove{color:red;text-decoration:none;margin-left:10px}
</style>
</head>
<body>

<div class="box">
<h2>👥 Roles — <?= htmlspecialchars($course["name"]) ?></h2>

<h3>👩‍🏫 Docente</h3>

<?php if ($course["teacher"]): ?>
  <p><?= htmlspecialchars($course["teacher"]["id"]) ?></p>
<?php endif; ?>

<form method="post">
  <select name="teacher_id" required>
    <option value="">Asignar docente</option>
    <?php foreach ($teachers as $t): ?>
      <option value="<?= htmlspecialchars($t["id"]) ?>">
        <?= htmlspecialchars($t["name"]) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button name="assign_teacher">Asignar</button>
</form>

<hr>

<h3>👨‍🎓 Estudiantes</h3>

<?php if (empty($course["students"])): ?>
  <p>No hay estudiantes.</p>
<?php else: ?>
  <ul>
    <?php foreach ($course["students"] as $s): ?>
      <li>
        <?= htmlspecialchars($s["id"]) ?>
        <a class="remove" href="?course=<?= urlencode($courseId) ?>&remove_student=<?= urlencode($s["id"]) ?>">❌</a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<form method="post">
  <select name="student_id" required>
    <option value="">Agregar estudiante</option>
    <?php foreach ($students as $s): ?>
      <option value="<?= htmlspecialchars($s["id"]) ?>">
        <?= htmlspecialchars($s["name"]) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button name="add_student">Agregar</button>
</form>

<hr>

<a href="course_view.php?course=<?= urlencode($courseId) ?>">← Volver al curso</a>

</div>

</body>
</html>

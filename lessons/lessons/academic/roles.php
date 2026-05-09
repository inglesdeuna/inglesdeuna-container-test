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
$coursesFile = dirname(__DIR__) . "/academic/courses.json";
$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';
$studentsFile = $dataDir . '/students.json';
$legacyTeachersFile = __DIR__ . '/teachers.json';
$legacyStudentsFile = __DIR__ . '/students.json';

if (!is_dir($dataDir)) {
  mkdir($dataDir, 0777, true);
}

if (!file_exists($teachersFile) && file_exists($legacyTeachersFile)) {
  copy($legacyTeachersFile, $teachersFile);
}

if (!file_exists($studentsFile) && file_exists($legacyStudentsFile)) {
  copy($legacyStudentsFile, $studentsFile);
}

if (!file_exists($teachersFile)) {
  file_put_contents($teachersFile, '[]');
}

if (!file_exists($studentsFile)) {
  file_put_contents($studentsFile, '[]');
}

$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$teachers = json_decode((string) file_get_contents($teachersFile), true);
$students = json_decode((string) file_get_contents($studentsFile), true);

if (!is_array($courses))  $courses = [];
if (!is_array($teachers)) $teachers = [];
if (!is_array($students)) $students = [];

$courseId = isset($_GET['course']) ? (string) $_GET['course'] : '';
$courseIndex = -1;

foreach ($courses as $idx => $course) {
  $id = isset($course['id']) ? (string) $course['id'] : '';
  if ($id === $courseId) {
    $courseIndex = (int) $idx;
    break;
  }
}

if ($courseIndex < 0) {
  http_response_code(404);
  die('Curso no encontrado');
}

if (!isset($courses[$courseIndex]['teacher']) || !is_array($courses[$courseIndex]['teacher'])) {
  $courses[$courseIndex]['teacher'] = null;
}
if (!isset($courses[$courseIndex]['students']) || !is_array($courses[$courseIndex]['students'])) {
  $courses[$courseIndex]['students'] = [];
}

/* ASIGNAR DOCENTE */
if (isset($_POST['assign_teacher'])) {
  $teacherId = isset($_POST['teacher_id']) ? (string) $_POST['teacher_id'] : '';
  if ($teacherId !== '') {
    $courses[$courseIndex]['teacher'] = [
      'id' => $teacherId,
      'role' => 'editor'
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

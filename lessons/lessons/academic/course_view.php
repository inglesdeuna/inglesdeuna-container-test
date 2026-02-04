<?php
/* =====================================================
   COURSE VIEW – TEACHERS PANEL (ACADEMIC)
   VERSION ESTABLE – PERMISSION EN DOCENTE
   ===================================================== */

$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");
$courseParam = urlencode($courseId);

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/courses.json";
$teachersFile = __DIR__ . "/teachers.json";
$studentsFile = __DIR__ . "/students.json";

/* DATOS */
$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$teachers = file_exists($teachersFile) ? json_decode(file_get_contents($teachersFile), true) : [];
$students = file_exists($studentsFile) ? json_decode(file_get_contents($studentsFile), true) : [];

/* CURSO */
$courseIndex = null;
$course = null;
foreach ($courses as $i => $c) {
  if (($c["id"] ?? null) === $courseId) {
    $courseIndex = $i;
    $course = $c;
    break;
  }
}
if ($courseIndex === null) die("Curso no encontrado");

/* CAMPOS */
$courses[$courseIndex]["students"] = $courses[$courseIndex]["students"] ?? [];
$courses[$courseIndex]["teacher"]  = $courses[$courseIndex]["teacher"]  ?? null;

/* NORMALIZAR DOCENTE */
if (is_string($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = [
    "id" => $courses[$courseIndex]["teacher"],
    "permission" => "editor"
  ];
} elseif (!is_array($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = null;
}

/* MAPAS */
$studentMap = [];
foreach ($students as $s) if (isset($s["id"])) $studentMap[$s["id"]] = $s;

/* ASIGNAR DOCENTE */
if ($_SERVER["REQUEST_METHOD"]==="POST" && isset($_POST["assign_teacher"])) {
  $courses[$courseIndex]["teacher"] = [
    "id" => $_POST["teacher_id"],
    "permission" => "editor"
  ];
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* CAMBIAR PERMISSION DOCENTE */
if ($_SERVER["REQUEST_METHOD"]==="POST" && isset($_POST["update_teacher_permission"])) {
  $courses[$courseIndex]["teacher"]["permission"] = $_POST["permission"];
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* NOMBRE DOCENTE */
$teacherName = "";
foreach ($teachers as $t)
  if (($t["id"] ?? null) === ($courses[$courseIndex]["teacher"]["id"] ?? null))
    $teacherName = $t["name"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>
</head>
<body>

<h1><?= htmlspecialchars($course["name"]) ?></h1>

<h2>👩‍🏫 Docente</h2>

<?php if ($courses[$courseIndex]["teacher"]): ?>
  <strong><?= htmlspecialchars($teacherName) ?></strong>
  <form method="post" style="display:inline">
    <input type="hidden" name="teacher_id" value="<?= htmlspecialchars($courses[$courseIndex]["teacher"]["id"]) ?>">
    <select name="permission" onchange="this.form.submit()">
      <option value="viewer" <?= $courses[$courseIndex]["teacher"]["permission"]==="viewer"?"selected":"" ?>>viewer</option>
      <option value="editor" <?= $courses[$courseIndex]["teacher"]["permission"]==="editor"?"selected":"" ?>>editor</option>
    </select>
    <input type="hidden" name="update_teacher_permission" value="1">
  </form>
<?php else: ?>
  <form method="post">
    <select name="teacher_id" required>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= $t["id"] ?>"><?= htmlspecialchars($t["name"]) ?></option>
      <?php endforeach; ?>
    </select>
    <button name="assign_teacher">Asignar</button>
  </form>
<?php endif; ?>

</body>
</html>

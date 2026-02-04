<?php
/* =====================================================
   COURSE VIEW – TEACHERS PANEL (ACADEMIC)
   VERSION LIMPIA Y ESTABLE
   ===================================================== */

/* VALIDAR CURSO */
$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");
$courseParam = urlencode($courseId);

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/courses.json";
$unitsFile    = __DIR__ . "/units.json";
$teachersFile = __DIR__ . "/teachers.json";
$studentsFile = __DIR__ . "/students.json";

/* CARGAR DATOS */
$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$units    = file_exists($unitsFile)    ? json_decode(file_get_contents($unitsFile), true)    : [];
$teachers = file_exists($teachersFile) ? json_decode(file_get_contents($teachersFile), true) : [];
$students = file_exists($studentsFile) ? json_decode(file_get_contents($studentsFile), true) : [];

/* BUSCAR CURSO */
$courseIndex = null;
$course = null;
foreach ($courses as $i => $c) {
  if (($c["id"] ?? null) === $courseId) {
    $courseIndex = $i;
    $course = $c;
    break;
  }
}
if (!$course) die("Curso no encontrado");

/* ASEGURAR CAMPOS */
$courses[$courseIndex]["units"]    = $courses[$courseIndex]["units"]    ?? [];
$courses[$courseIndex]["teacher"]  = $courses[$courseIndex]["teacher"]  ?? null;
$courses[$courseIndex]["students"] = $courses[$courseIndex]["students"] ?? [];

/* NORMALIZAR STUDENTS → SOLO IDS */
$courses[$courseIndex]["students"] = array_values(
  array_filter($courses[$courseIndex]["students"], "is_string")
);
file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

/* MAPAS */
$unitMap = [];
foreach ($units as $u) if (isset($u["id"])) $unitMap[$u["id"]] = $u;

$studentMap = [];
foreach ($students as $s) if (isset($s["id"])) $studentMap[$s["id"]] = $s;

/* =====================
   ASIGNAR DOCENTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["assign_teacher"])) {
  $courses[$courseIndex]["teacher"] = $_POST["teacher_id"] ?? null;
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   AGREGAR UNIDAD
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_unit"])) {
  $uid = $_POST["unit_id"] ?? null;
  if ($uid && !in_array($uid, $courses[$courseIndex]["units"], true)) {
    $courses[$courseIndex]["units"][] = $uid;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* QUITAR UNIDAD */
if (isset($_GET["remove_unit"])) {
  $courses[$courseIndex]["units"] = array_values(
    array_diff($courses[$courseIndex]["units"], [$_GET["remove_unit"]])
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   AGREGAR ESTUDIANTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_student"])) {
  $sid = $_POST["student_id"] ?? null;
  if ($sid && !in_array($sid, $courses[$courseIndex]["students"], true)) {
    $courses[$courseIndex]["students"][] = $sid;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* QUITAR ESTUDIANTE */
if (isset($_GET["remove_student"])) {
  $courses[$courseIndex]["students"] = array_values(
    array_diff($courses[$courseIndex]["students"], [$_GET["remove_student"]])
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* NOMBRE DOCENTE */
$teacherName = "";
foreach ($teachers as $t) {
  if (($t["id"] ?? null) === $courses[$courseIndex]["teacher"]) {
    $teacherName = $t["name"];
    break;
  }
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
.remove{color:#dc2626;text-decoration:none}
</style>
</head>
<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<!-- DOCENTE -->
<div class="section">
<h2>👩‍🏫 Docente</h2>
<?= $teacherName ? htmlspecialchars($teacherName) : "No asignado" ?>
</div>

<!-- ESTUDIANTES -->
<div class="section">
<h2>👨‍🎓 Estudiantes</h2>

<ul>
<?php foreach ($courses[$courseIndex]["students"] as $sid):
  if (!isset($studentMap[$sid])) continue;
?>
<li>
  <?= htmlspecialchars($studentMap[$sid]["name"]) ?>
  <a class="remove" href="?course=<?= $courseParam ?>&remove_student=<?= urlencode($sid) ?>">❌</a>
</li>
<?php endforeach; ?>
</ul>

<form method="post">
  <select name="student_id" required>
    <option value="">Agregar estudiante</option>
    <?php foreach ($students as $s): ?>
      <option value="<?= htmlspecialchars($s["id"]) ?>">
        <?= htmlspecialchars($s["name"]) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" name="add_student">Agregar</button>
</form>
</div>

</body>
</html>

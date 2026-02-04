<?php
/* =====================================================
   COURSE VIEW – TEACHERS PANEL (ACADEMIC)
   MODELO DRIVE / STUDENTS CON PERMISSION
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
$courses = file_exists($coursesFile)
  ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($coursesFile)), true) ?? []
  : [];

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

/* NORMALIZAR STUDENTS */
$normalized = [];
foreach ($courses[$courseIndex]["students"] as $s) {
  if (is_string($s)) {
    $normalized[] = ["id" => $s, "permission" => "viewer"];
  } elseif (is_array($s) && isset($s["id"])) {
    $normalized[] = [
      "id" => $s["id"],
      "permission" => $s["permission"] ?? "viewer"
    ];
  }
}
$courses[$courseIndex]["students"] = $normalized;
file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

/* MAPAS */
$unitMap = [];
foreach ($units as $u) if (isset($u["id"])) $unitMap[$u["id"]] = $u;

$studentMap = [];
foreach ($students as $s) if (isset($s["id"])) $studentMap[$s["id"]] = $s;

/* =====================
   POST: ASIGNAR DOCENTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["assign_teacher"])) {
  $courses[$courseIndex]["teacher"] = $_POST["teacher_id"] ?? null;
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   POST: AGREGAR UNIDAD
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
    array_filter($courses[$courseIndex]["units"], fn($u) => $u !== $_GET["remove_unit"])
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   POST: AGREGAR ESTUDIANTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_student"])) {
  $sid = $_POST["student_id"] ?? null;
  if ($sid) {
    foreach ($courses[$courseIndex]["students"] as $s)
      if (($s["id"] ?? null) === $sid) { if (($s["id"] ?? null) === $sid) { 
  header("Location: course_view.php?course=$courseParam"); 
  exit; 
}
 
    $courses[$courseIndex]["students"][] = ["id" => $sid, "permission" => "viewer"];
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   POST: CAMBIAR PERMISSION
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_permission"])) {
  $sid  = $_POST["student_id"] ?? null;
  $perm = $_POST["permission"] ?? "viewer";

  foreach ($courses[$courseIndex]["students"] as &$s) {
    if (($s["id"] ?? null) === $sid) {
      $s["permission"] = $perm;
      break;
    }
  }
  unset($s);

  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* QUITAR ESTUDIANTE */
if (isset($_GET["remove_student"])) {
  $courses[$courseIndex]["students"] = array_values(
    array_filter(
      $courses[$courseIndex]["students"],
      fn($s) => ($s["id"] ?? null) !== $_GET["remove_student"]
    )
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* NOMBRE DOCENTE */
$teacherName = "";
foreach ($teachers as $t)
  if (($t["id"] ?? null) === $courses[$courseIndex]["teacher"]) $teacherName = $t["name"];
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

<!-- ESTUDIANTES -->
<div class="section">
<h2>👨‍🎓 Estudiantes</h2>

<ul>
<?php foreach ($courses[$courseIndex]["students"] as $s):
  $sid = $s["id"];
  if (!isset($studentMap[$sid])) continue;
?>
<li>
  <?= htmlspecialchars($studentMap[$sid]["name"]) ?>

  <form method="post" style="display:inline">
    <input type="hidden" name="student_id" value="<?= htmlspecialchars($sid) ?>">
    <select name="permission" onchange="this.form.submit()">
      <option value="viewer" <?= ($s["permission"]==="viewer")?"selected":"" ?>>viewer</option>
      <option value="editor" <?= ($s["permission"]==="editor")?"selected":"" ?>>editor</option>
    </select>
    <input type="hidden" name="update_permission" value="1">
  </form>

  <a class="remove" href="?course=<?= $courseId ?>&remove_student=<?= $sid ?>">❌</a>
</li>
<?php endforeach; ?>
</ul>

</div>
</body>
</html>

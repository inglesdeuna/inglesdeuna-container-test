<?php
session_start();

/* =====================================================
   ACCESO A CREAR CURSOS
   Solo ADMIN o TEACHER
   ===================================================== */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"])
) {
  header("Location: login.php");
  exit;
}

/* =====================================================
   IDENTIDAD DEL USUARIO
   ===================================================== */
$role = null;
$userId = null;

if (isset($_SESSION["admin_id"])) {
  $role = "admin";
  $userId = $_SESSION["admin_id"];
} elseif (isset($_SESSION["teacher_id"])) {
  $role = "teacher";
  $userId = $_SESSION["teacher_id"];
} elseif (isset($_SESSION["student_id"])) {
  $role = "student";
  $userId = $_SESSION["student_id"];
}

/* =====================================================
   VALIDAR CURSO
   ===================================================== */
$courseId = $_GET["course"] ?? null;
if (!$courseId) {
  die("Curso no especificado");
}
$courseParam = urlencode($courseId);
$_SESSION["last_course_id"] = $courseId;

/* =====================================================
   ARCHIVOS
   ===================================================== */
$coursesFile  = __DIR__ . "/courses.json";
$teachersFile = __DIR__ . "/teachers.json";
$studentsFile = __DIR__ . "/students.json";

$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$teachers = file_exists($teachersFile) ? json_decode(file_get_contents($teachersFile), true) : [];
$students = file_exists($studentsFile) ? json_decode(file_get_contents($studentsFile), true) : [];

/* =====================================================
   BUSCAR CURSO
   ===================================================== */
$courseIndex = null;
$course = null;

foreach ($courses as $i => $c) {
  if (!empty($c["id"]) && $c["id"] === $courseId) {
    $courseIndex = $i;
    $course = $c;
    break;
  }
}

if ($course === null) {
  die("Curso no encontrado");
}

/* =====================================================
   NORMALIZAR ESTRUCTURA
   ===================================================== */
$courses[$courseIndex]["students"] = $courses[$courseIndex]["students"] ?? [];
$courses[$courseIndex]["teacher"]  = $courses[$courseIndex]["teacher"]  ?? null;

/* --- students: solo IDs string --- */
$cleanStudents = [];
foreach ($courses[$courseIndex]["students"] as $s) {
  if (is_string($s)) {
    $cleanStudents[] = $s;
  } elseif (is_array($s) && isset($s["id"])) {
    $cleanStudents[] = $s["id"];
  }
}
$courses[$courseIndex]["students"] = array_values(array_unique($cleanStudents));

/* --- teacher --- */
if (is_string($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = [
    "id" => $courses[$courseIndex]["teacher"],
    "permission" => "editor"
  ];
}
if (!is_array($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = null;
}

/* Guardar normalización */
file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

$course = $courses[$courseIndex];

/* =====================================================
   PERMISOS
   ===================================================== */
$canEdit = false;

if ($role === "admin") {
  $canEdit = true;
}

if (
  $role === "teacher" &&
  !empty($course["teacher"]["id"]) &&
  $course["teacher"]["id"] === $userId &&
  $course["teacher"]["permission"] === "editor"
) {
  $canEdit = true;
}

/* =====================================================
   MAPAS
   ===================================================== */
$teacherMap = [];
foreach ($teachers as $t) {
  if (!empty($t["id"])) {
    $teacherMap[$t["id"]] = $t;
  }
}

$studentMap = [];
foreach ($students as $s) {
  if (!empty($s["id"])) {
    $studentMap[$s["id"]] = $s;
  }
}

/* =====================================================
   ACCIONES (solo si puede editar)
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $canEdit) {

  if (isset($_POST["assign_teacher"])) {
    $tid = $_POST["teacher_id"] ?? null;
    if ($tid) {
      $courses[$courseIndex]["teacher"] = [
        "id" => $tid,
        "permission" => "editor"
      ];
    }
  }

  if (isset($_POST["update_teacher_permission"])) {
    $courses[$courseIndex]["teacher"]["permission"] =
      $_POST["permission"] === "viewer" ? "viewer" : "editor";
  }

  if (isset($_POST["add_student"])) {
    $sid = $_POST["student_id"] ?? null;
    if ($sid && !in_array($sid, $courses[$courseIndex]["students"], true)) {
      $courses[$courseIndex]["students"][] = $sid;
    }
  }

  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam");
  exit;
}

if (isset($_GET["remove_student"]) && $canEdit) {
  $courses[$courseIndex]["students"] = array_values(
    array_diff($courses[$courseIndex]["students"], [$_GET["remove_student"]])
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam");
  exit;
}

/* =====================================================
   DATOS PARA VISTA
   ===================================================== */
$teacherName = "";
if (!empty($course["teacher"]["id"]) && isset($teacherMap[$course["teacher"]["id"]])) {
  $teacherName = $teacherMap[$course["teacher"]["id"]]["name"];
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
.remove{color:#dc2626;text-decoration:none;margin-left:10px}
select,button{padding:6px}
</style>
</head>
<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>
<p><a href="logout.php">🚪 Cerrar sesión</a></p>

<!-- DOCENTE -->
<div class="section">
<h2>👩‍🏫 Docente</h2>

<?php if (!empty($course["teacher"])): ?>
  <p><strong><?= htmlspecialchars($teacherName) ?></strong></p>

  <?php if ($canEdit): ?>
    <form method="post">
      <select name="permission" onchange="this.form.submit()">
        <option value="viewer" <?= $course["teacher"]["permission"]==="viewer"?"selected":"" ?>>viewer</option>
        <option value="editor" <?= $course["teacher"]["permission"]==="editor"?"selected":"" ?>>editor</option>
      </select>
      <input type="hidden" name="update_teacher_permission" value="1">
    </form>
  <?php else: ?>
    <small>(<?= htmlspecialchars($course["teacher"]["permission"]) ?>)</small>
  <?php endif; ?>

<?php else: ?>
  <p>No asignado</p>
<?php endif; ?>

<?php if ($canEdit): ?>
<form method="post">
  <select name="teacher_id" required>
    <option value="">Seleccionar docente</option>
    <?php foreach ($teachers as $t): ?>
      <option value="<?= htmlspecialchars($t["id"]) ?>"><?= htmlspecialchars($t["name"]) ?></option>
    <?php endforeach; ?>
  </select>
  <button name="assign_teacher">Asignar</button>
</form>
<?php endif; ?>
</div>

<!-- ESTUDIANTES -->
<div class="section">
<h2>👨‍🎓 Estudiantes</h2>

<?php if (empty($course["students"])): ?>
  <p>No hay estudiantes asignados.</p>
<?php else: ?>
<ul>
<?php foreach ($course["students"] as $sid): ?>
  <?php if (!isset($studentMap[$sid])) continue; ?>
  <li>
    <?= htmlspecialchars($studentMap[$sid]["name"]) ?>
    <?php if ($canEdit): ?>
      <a class="remove" href="?course=<?= $courseParam ?>&remove_student=<?= urlencode($sid) ?>">❌</a>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($canEdit): ?>
<form method="post">
  <select name="student_id" required>
    <option value="">Agregar estudiante</option>
    <?php foreach ($students as $s): ?>
      <option value="<?= htmlspecialchars($s["id"]) ?>"><?= htmlspecialchars($s["name"]) ?></option>
    <?php endforeach; ?>
  </select>
  <button name="add_student">Agregar</button>
</form>
<?php endif; ?>
</div>

</body>
</html>

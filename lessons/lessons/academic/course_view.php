<?php
session_start();

/*
  LOGIN SIMULADO (TEMPORAL)
  En el siguiente paso esto vendrá de un login real
*/
if (!isset($_SESSION["teacher_id"])) {
  $_SESSION["teacher_id"] = "teacher_1"; // ID existente en teachers.json
}

/* =====================================================
   COURSE VIEW – TEACHERS PANEL (ACADEMIC)
   VERSION FINAL ESTABLE (SIN FATAL ERRORS)
   ===================================================== */

/* VALIDAR CURSO */
$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");
$courseParam = urlencode($courseId);

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/courses.json";
$teachersFile = __DIR__ . "/teachers.json";
$studentsFile = __DIR__ . "/students.json";

/* CARGAR DATOS */
$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$teachers = file_exists($teachersFile) ? json_decode(file_get_contents($teachersFile), true) : [];
$students = file_exists($studentsFile) ? json_decode(file_get_contents($studentsFile), true) : [];

/* BUSCAR CURSO */
$courseIndex = null;
$course = null;
foreach ($courses as $i => $c) {
  if (isset($c["id"]) && $c["id"] === $courseId) {
    $courseIndex = $i;
    $course = $c;
    break;
  }
}
if ($courseIndex === null) die("Curso no encontrado");

/* ASEGURAR CAMPOS */
$courses[$courseIndex]["students"] = $courses[$courseIndex]["students"] ?? [];
$courses[$courseIndex]["teacher"]  = $courses[$courseIndex]["teacher"]  ?? null;

/* 🔐 NORMALIZAR STUDENTS → SOLO STRINGS */
$cleanStudents = [];
foreach ($courses[$courseIndex]["students"] as $s) {
  if (is_string($s)) {
    $cleanStudents[] = $s;
  } elseif (is_array($s) && isset($s["id"]) && is_string($s["id"])) {
    $cleanStudents[] = $s["id"];
  }
}
$courses[$courseIndex]["students"] = array_values(array_unique($cleanStudents));

/* NORMALIZAR DOCENTE */
if (is_string($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = [
    "id" => $courses[$courseIndex]["teacher"],
    "permission" => "editor"
  ];
} elseif (!is_array($courses[$courseIndex]["teacher"])) {
  $courses[$courseIndex]["teacher"] = null;
}

/* GUARDAR NORMALIZACIÓN */
file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

/* DEFINIR PERMISO */
$loggedTeacherId = $_SESSION["teacher_id"] ?? null;

/*
  Todavía NO usamos permisos.
  Solo identificamos al docente logueado.
*/
$canEdit = (
  $loggedTeacherId &&
  isset($courses[$courseIndex]["teacher"]["id"]) &&
  $courses[$courseIndex]["teacher"]["id"] === $loggedTeacherId &&
  $courses[$courseIndex]["teacher"]["permission"] === "editor"
);
/*
  SEGURIDAD:
  Si hay intento de edición y NO puede editar → bloquear
*/
if (
  ($_SERVER["REQUEST_METHOD"] === "POST" || isset($_GET["remove_student"])) &&
  !$canEdit
) {
  // No permitido editar este curso
  header("Location: course_view.php?course=$courseParam");
  exit;
}


/* MAPA ESTUDIANTES */
$studentMap = [];
foreach ($students as $s) {
  if (isset($s["id"])) {
    $studentMap[$s["id"]] = $s;
  }
}

/* =====================
   ASIGNAR DOCENTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["assign_teacher"]) && $canEdit) {
  $tid = $_POST["teacher_id"] ?? null;
  if ($tid) {
    $courses[$courseIndex]["teacher"] = [
      "id" => $tid,
      "permission" => "editor"
    ];
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   CAMBIAR PERMISSION DOCENTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_teacher_permission"]) && $canEdit) {
  $courses[$courseIndex]["teacher"]["permission"] = $_POST["permission"] ?? "viewer";
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   AGREGAR ESTUDIANTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_student"]) && $canEdit) {
  $sid = $_POST["student_id"] ?? null;
  if ($sid && !in_array($sid, $courses[$courseIndex]["students"], true)) {
    $courses[$courseIndex]["students"][] = $sid;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* =====================
   QUITAR ESTUDIANTE
   ===================== */
if (isset($_GET["remove_student"]) && $canEdit) {
  $courses[$courseIndex]["students"] = array_values(
    array_diff($courses[$courseIndex]["students"], [$_GET["remove_student"]])
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=$courseParam"); exit;
}

/* NOMBRE DOCENTE */
$teacherName = "";
foreach ($teachers as $t) {
  if (isset($courses[$courseIndex]["teacher"]["id"]) &&
      $t["id"] === $courses[$courseIndex]["teacher"]["id"]) {
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
.remove{color:#dc2626;text-decoration:none;margin-left:10px}
select,button{padding:6px}
</style>
</head>
<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<!-- DOCENTE -->
<div class="section">
<h2>👩‍🏫 Docente</h2>

<?php if ($courses[$courseIndex]["teacher"]): ?>
  <p><strong><?= htmlspecialchars($teacherName) ?></strong></p>

  <?php if ($canEdit): ?>
  <form method="post" style="display:inline">
    <select name="permission" onchange="this.form.submit()">
      <option value="viewer" <?= $courses[$courseIndex]["teacher"]["permission"]==="viewer"?"selected":"" ?>>viewer</option>
      <option value="editor" <?= $courses[$courseIndex]["teacher"]["permission"]==="editor"?"selected":"" ?>>editor</option>
    </select>
    <input type="hidden" name="update_teacher_permission" value="1">
  </form>
  <?php else: ?>
    <small>(<?= htmlspecialchars($courses[$courseIndex]["teacher"]["permission"]) ?>)</small>
  <?php endif; ?>

<?php else: ?>
  <p>No asignado</p>

  <?php if ($canEdit): ?>
  <form method="post">
    <select name="teacher_id" required>
      <option value="">Seleccionar docente</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= htmlspecialchars($t["id"]) ?>">
          <?= htmlspecialchars($t["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button name="assign_teacher">Asignar</button>
  </form>
  <?php endif; ?>

<?php endif; ?>
</div>

<!-- ESTUDIANTES -->
<div class="section">
<h2>👨‍🎓 Estudiantes</h2>

<?php if (empty($courses[$courseIndex]["students"])): ?>
  <p>No hay estudiantes asignados.</p>
<?php else: ?>
<ul>
<?php foreach ($courses[$courseIndex]["students"] as $sid):
  if (!is_string($sid) || !isset($studentMap[$sid])) continue;
?>
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
      <option value="<?= htmlspecialchars($s["id"]) ?>">
        <?= htmlspecialchars($s["name"]) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" name="add_student">Agregar</button>
</form>
<?php endif; ?>

</div>

</body>
</html>

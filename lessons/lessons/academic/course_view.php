<?php
/* =====================================================
   COURSE VIEW – TEACHERS PANEL (ACADEMIC)
   MODELO DRIVE / STUDENTS CON PERMISSION
   ===================================================== */

/* VALIDAR CURSO */
$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/courses.json";
$unitsFile    = __DIR__ . "/units.json";
$teachersFile = __DIR__ . "/teachers.json";
$studentsFile = __DIR__ . "/students.json";

/* CARGAR DATOS */
$courses = [];
if (file_exists($coursesFile)) {
  $raw = file_get_contents($coursesFile);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $courses = json_decode($raw, true) ?? [];
}

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
if (!isset($courses[$courseIndex]["units"]))    $courses[$courseIndex]["units"] = [];
if (!isset($courses[$courseIndex]["teacher"]))  $courses[$courseIndex]["teacher"] = null;
if (!isset($courses[$courseIndex]["students"])) $courses[$courseIndex]["students"] = [];
/* NORMALIZAR STUDENTS (strings → objetos) */
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


/* GUARDAR NORMALIZACIÓN */
file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

/* MAPAS */
$unitMap = [];
foreach ($units as $u) {
  if (isset($u["id"])) $unitMap[$u["id"]] = $u;
}

$studentMap = [];
foreach ($students as $s) {
  if (isset($s["id"])) $studentMap[$s["id"]] = $s;
}

/* =====================
   ASIGNAR DOCENTE
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["assign_teacher"])) {
  $teacherId = $_POST["teacher_id"] ?? null;
  if ($teacherId) {
    $courses[$courseIndex]["teacher"] = $teacherId;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}

/* =====================
   AGREGAR UNIDAD
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_unit"])) {
  $unitId = $_POST["unit_id"] ?? null;
  if ($unitId && !in_array($unitId, $courses[$courseIndex]["units"], true)) {
    $courses[$courseIndex]["units"][] = $unitId;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}

/* QUITAR UNIDAD */
if (isset($_GET["remove_unit"])) {
  $remove = $_GET["remove_unit"];
  $courses[$courseIndex]["units"] = array_values(
    array_filter(
      $courses[$courseIndex]["units"],
      fn($u) => $u !== $remove
    )
  );
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}

/* =====================
   AGREGAR ESTUDIANTE (MODELO NUEVO)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_student"])) {
  $sid = $_POST["student_id"] ?? null;

  if ($sid) {
    $exists = false;
    foreach ($courses[$courseIndex]["students"] as $s) {
      if (($s["id"] ?? null) === $sid) {
        $exists = true;
        break;
      }
    }

    if (!$exists) {
      $courses[$courseIndex]["students"][] = [
        "id" => $sid,
        "permission" => "viewer"
      ];
      file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
    }
  }

  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}

/* QUITAR ESTUDIANTE (MODELO NUEVO) */
if (isset($_GET["remove_student"])) {
  $remove = $_GET["remove_student"];

  $courses[$courseIndex]["students"] = array_values(
    array_filter(
      $courses[$courseIndex]["students"],
      fn($s) => ($s["id"] ?? null) !== $remove
    )
  );

  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
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
body{font-family:Arial,Helvetica,sans-serif;background:#f4f8ff;padding:40px}
h1{color:#2563eb}
.section{background:#fff;padding:25px;border-radius:14px;box-shadow:0 10px 25px rgba(0,0,0,.08);margin-bottom:30px}
table{width:100%;border-collapse:collapse}
th,td{padding:14px;border-bottom:1px solid #eee}
th{background:#f1f5ff;text-align:left}
.actions a{margin-right:10px;font-weight:700;text-decoration:none}
.preview{color:#2563eb}
.edit{color:#16a34a}
.remove{color:#dc2626}
select,button{padding:10px;font-size:14px}
</style>
</head>

<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<!-- DOCENTE -->
<div class="section">
  <h2>👩‍🏫 Docente</h2>

  <?php if ($teacherName): ?>
    <p><strong><?= htmlspecialchars($teacherName) ?></strong></p>
  <?php else: ?>
    <p>No hay docente asignado.</p>
  <?php endif; ?>

  <form method="post">
    <select name="teacher_id" required>
      <option value="">Seleccionar docente</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= htmlspecialchars($t["id"]) ?>">
          <?= htmlspecialchars($t["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="assign_teacher">Asignar</button>
  </form>
</div>

<!-- ESTUDIANTES -->
<div class="section">
  <h2>👨‍🎓 Estudiantes</h2>

  <?php if (empty($courses[$courseIndex]["students"])): ?>
    <p>No hay estudiantes asignados.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($courses[$courseIndex]["students"] as $s):
  if (!is_array($s) || !isset($s["id"]) || !is_string($s["id"])) continue;
  $sid = $s["id"];

  if (!isset($studentMap[$sid])) continue;
?>
        <li>
          <?= htmlspecialchars($studentMap[$sid]["name"]) ?>
          <a class="remove"
             href="?course=<?= urlencode($courseId) ?>&remove_student=<?= urlencode($sid) ?>">❌</a>
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
    <button type="submit" name="add_student">Agregar</button>
  </form>
</div>

<!-- UNIDADES -->
<div class="section">
  <h2>📚 Unidades</h2>

  <form method="post">
    <select name="unit_id" required>
      <option value="">Agregar unidad</option>
      <?php foreach ($units as $u): ?>
        <option value="<?= htmlspecialchars($u["id"]) ?>">
          <?= htmlspecialchars($u["name"] ?? $u["title"] ?? "Unidad") ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="add_unit">Agregar</button>
  </form>

  <?php if (!empty($courses[$courseIndex]["units"])): ?>
    <table>
      <tr>
        <th>Unidad</th>
        <th>Acciones</th>
      </tr>
      <?php foreach ($courses[$courseIndex]["units"] as $uid):
        if (!isset($unitMap[$uid])) continue;
      ?>
      <tr>
        <td><?= htmlspecialchars($unitMap[$uid]["name"] ?? "Unidad") ?></td>
        <td class="actions">
          <a class="preview" href="unit_course.php?unit=<?= urlencode($uid) ?>" target="_blank">👀 Ver</a>
          <a class="edit" href="units_editor.php" target="_blank">✏️ Editar</a>
          <a class="remove" href="?course=<?= urlencode($courseId) ?>&remove_unit=<?= urlencode($uid) ?>">❌</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

</body>
</html>

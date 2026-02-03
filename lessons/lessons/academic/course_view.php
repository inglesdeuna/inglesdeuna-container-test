<?php
/* ===============================
   COURSE VIEW – ACADEMIC
   =============================== */

$courseId = $_GET["course"] ?? null;
if (!$courseId) die("Curso no especificado");

/* CURSOS */
$coursesFile = __DIR__ . "/courses.json";
$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

/* BUSCAR CURSO */
$courseIndex = null;
$course = null;
foreach ($courses as $i => $c) {
  if ($c["id"] === $courseId) {
    $courseIndex = $i;
    $course = $c;
    break;
  }
}
if (!$course) die("Curso no encontrado");

/* ASEGURAR UNIDADES */
if (!isset($courses[$courseIndex]["units"])) {
  $courses[$courseIndex]["units"] = [];
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
}

/* TODAS LAS UNIDADES */
$unitsFile = __DIR__ . "/units.json";
$allUnits = file_exists($unitsFile)
  ? json_decode(file_get_contents($unitsFile), true)
  : [];

/* AGREGAR UNIDAD */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_unit"])) {
  $unitId = $_POST["unit_id"] ?? null;
  if ($unitId && !in_array($unitId, $courses[$courseIndex]["units"])) {
    $courses[$courseIndex]["units"][] = $unitId;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  }
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}

/* QUITAR UNIDAD */
if (isset($_GET["remove_unit"])) {
  $remove = $_GET["remove_unit"];
  $courses[$courseIndex]["units"] =
    array_values(array_diff($courses[$courseIndex]["units"], [$remove]));
  file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}

/* MAPA DE UNIDADES */
$unitMap = [];
foreach ($allUnits as $u) {
  if (isset($u["id"])) $unitMap[$u["id"]] = $u;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{color:#2563eb}

.section{
  background:#fff;
  padding:25px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  margin-bottom:30px;
}

table{
  width:100%;
  border-collapse:collapse;
}

th,td{
  padding:14px;
  border-bottom:1px solid #eee;
  text-align:left;
}

th{background:#f1f5ff}

.actions a{
  margin-right:8px;
  font-weight:700;
  text-decoration:none;
}

.preview{color:#2563eb}
.edit{color:#16a34a}
.remove{color:#dc2626}

select,button{
  padding:10px;
  font-size:14px;
}
</style>
</head>

<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<!-- AGREGAR UNIDAD -->
<div class="section">
  <h2>➕ Agregar unidad al curso</h2>
  <form method="post">
    <select name="unit_id" required>
      <option value="">Seleccione una unidad</option>
      <?php foreach ($allUnits as $u): ?>
        <option value="<?= htmlspecialchars($u["id"]) ?>">
          <?= htmlspecialchars($u["name"] ?? $u["title"] ?? "Unidad") ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="add_unit">Agregar</button>
  </form>
</div>

<!-- UNIDADES DEL CURSO -->
<div class="section">
  <h2>📚 Unidades del curso</h2>

  <?php if (empty($courses[$courseIndex]["units"])): ?>
    <p>No hay unidades asignadas a este curso.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>Unidad</th>
        <th>Acciones</th>
      </tr>

      <?php foreach ($courses[$courseIndex]["units"] as $uid): 
        if (!isset($unitMap[$uid])) continue;
        $u = $unitMap[$uid];
      ?>
      <tr>
        <td><?= htmlspecialchars($u["name"] ?? $u["title"] ?? "Unidad") ?></td>
        <td class="actions">
          <a class="preview"
             href="unit_course.php?unit=<?= urlencode($uid) ?>"
             target="_blank">👀 Preview</a>

          <a class="edit"
             href="units_editor.php"
             target="_blank">✏️ Editar</a>

          <a class="remove"
             href="?course=<?= urlencode($courseId) ?>&remove_unit=<?= urlencode($uid) ?>">
             ❌ Quitar
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

</body>
</html>

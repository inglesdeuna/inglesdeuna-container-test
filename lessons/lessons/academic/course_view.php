<?php
/* ===============================
   COURSE VIEW – ACADEMIC
   =============================== */

$courseId = $_GET["course"] ?? null;
if (!$courseId) {
  die("Curso no especificado");
}

/* CURSOS */
$coursesFile = __DIR__ . "/courses.json";
$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

/* BUSCAR CURSO */
$course = null;
foreach ($courses as $c) {
  if ($c["id"] === $courseId) {
    $course = $c;
    break;
  }
}

if (!$course) {
  die("Curso no encontrado");
}

/* UNIDADES */
$unitsFile = __DIR__ . "/units.json";
$units = file_exists($unitsFile)
  ? json_decode(file_get_contents($unitsFile), true)
  : [];
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

h1{
  color:#2563eb;
  margin-bottom:30px;
}

.table{
  background:#fff;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  overflow:hidden;
}

.row{
  display:grid;
  grid-template-columns: 1fr 160px 160px;
  padding:18px 24px;
  border-bottom:1px solid #eee;
  align-items:center;
}

.row.header{
  background:#f1f5ff;
  font-weight:700;
}

.row:last-child{
  border-bottom:none;
}

.actions a{
  display:inline-block;
  padding:8px 14px;
  border-radius:8px;
  font-weight:700;
  text-decoration:none;
  font-size:14px;
  margin-right:6px;
}

.preview{
  background:#2563eb;
  color:#fff;
}

.edit{
  background:#16a34a;
  color:#fff;
}
</style>
</head>

<body>

<h1>📘 Curso: <?= htmlspecialchars($course["name"]) ?></h1>

<?php if (empty($units)): ?>
  <p>No hay unidades creadas todavía.</p>
<?php else: ?>

<div class="table">
  <div class="row header">
    <div>Unidad</div>
    <div>Preview</div>
    <div>Editar</div>
  </div>

  <?php foreach ($units as $u): ?>
    <div class="row">
      <div><?= htmlspecialchars($u["name"] ?? $u["title"] ?? "Unidad") ?></div>

      <div class="actions">
        <a class="preview"
           href="unit_course.php?unit=<?= urlencode($u["id"]) ?>"
           target="_blank">
          👀 Ver
        </a>
      </div>

      <div class="actions">
        <a class="edit"
           href="units_editor.php"
           target="_blank">
          ✏️ Editar
        </a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

</body>
</html>

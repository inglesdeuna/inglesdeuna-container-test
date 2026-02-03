<?php
/* ==========================================
   STUDENT DASHBOARD
   ========================================== */

/* SIMULACIÓN DE ESTUDIANTE LOGUEADO
   (luego se reemplaza por sesión real) */
$studentId = $_GET["student"] ?? null;
if (!$studentId) die("Estudiante no especificado");

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/../academic/courses.json";
$studentsFile = __DIR__ . "/../academic/students.json";
$unitsFile    = __DIR__ . "/../academic/units.json";

/* CARGAR DATOS */
$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$students = file_exists($studentsFile) ? json_decode(file_get_contents($studentsFile), true) : [];
$units    = file_exists($unitsFile)    ? json_decode(file_get_contents($unitsFile), true)    : [];

/* VALIDAR ESTUDIANTE */
$student = null;
foreach ($students as $s) {
  if ($s["id"] === $studentId) {
    $student = $s;
    break;
  }
}
if (!$student) die("Estudiante no encontrado");

/* MAPA DE UNIDADES */
$unitMap = [];
foreach ($units as $u) {
  if (isset($u["id"])) $unitMap[$u["id"]] = $u;
}

/* FILTRAR CURSOS DEL ESTUDIANTE */
$myCourses = [];
foreach ($courses as $c) {
  if (!empty($c["students"]) && in_array($studentId, $c["students"])) {
    $myCourses[] = $c;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mis cursos</title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{color:#2563eb}

.course{
  background:#fff;
  padding:25px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  margin-bottom:30px;
}

.unit{
  padding:10px 0;
  border-bottom:1px solid #eee;
  display:flex;
  justify-content:space-between;
  align-items:center;
}

.unit:last-child{border-bottom:none}

.unit a{
  text-decoration:none;
  font-weight:700;
  color:#2563eb;
}
</style>
</head>

<body>

<h1>👋 Hola, <?= htmlspecialchars($student["name"]) ?></h1>

<h2>📘 Mis cursos</h2>

<?php if (empty($myCourses)): ?>
  <p>No tienes cursos asignados.</p>
<?php else: ?>

  <?php foreach ($myCourses as $c): ?>
    <div class="course">
      <h3><?= htmlspecialchars($c["name"]) ?></h3>

      <?php if (empty($c["units"])): ?>
        <p>No hay unidades disponibles.</p>
      <?php else: ?>
        <?php foreach ($c["units"] as $uid):
          if (!isset($unitMap[$uid])) continue;
        ?>
          <div class="unit">
            <span><?= htmlspecialchars($unitMap[$uid]["name"] ?? "Unidad") ?></span>
            <a href="../academic/unit_course.php?unit=<?= urlencode($uid) ?>" target="_blank">
              👀 Ver
            </a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

</body>
</html>

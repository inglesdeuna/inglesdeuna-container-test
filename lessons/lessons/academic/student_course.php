<?php
session_start();

/**
 * CURSO ESTUDIANTE
 * Vista SOLO lectura de unidades y actividades
 */

// 🔐 VALIDACIÓN ESTUDIANTE
if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header("Location: ../student/login.php");
    exit;
}

$assignmentId = $_GET['assignment'] ?? null;
if (!$assignmentId) {
    die("Asignación no especificada");
}

/* ==========================
   DATA
   ========================== */
$baseDir = dirname(__DIR__) . "/admin/data";

$assignmentsFile = $baseDir . "/assignments.json";
$coursesFile     = $baseDir . "/courses.json";
$unitsFile       = $baseDir . "/units.json";

$assignments = file_exists($assignmentsFile)
  ? json_decode(file_get_contents($assignmentsFile), true)
  : [];

$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

$units = file_exists($unitsFile)
  ? json_decode(file_get_contents($unitsFile), true)
  : [];

/* ==========================
   ASIGNACIÓN
   ========================== */
$assignment = null;
foreach ($assignments as $a) {
    if (($a['id'] ?? null) === $assignmentId) {
        $assignment = $a;
        break;
    }
}
if (!$assignment) {
    die("Asignación no encontrada");
}

/* ==========================
   CURSO
   ========================== */
$course = null;
foreach ($courses as $c) {
    if (($c['id'] ?? null) === $assignment['course_id']) {
        $course = $c;
        break;
    }
}
if (!$course) {
    die("Curso no encontrado");
}

/* ==========================
   UNIDADES DEL CURSO
   ========================== */
$courseUnits = array_filter($units, function ($u) use ($course) {
    return ($u['course_id'] ?? null) === $course['id'];
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course['name']) ?></title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#eff6ff;
  padding:40px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:30px;
}
a.back{
  text-decoration:none;
  color:#2563eb;
  font-weight:bold;
}
.unit{
  background:#fff;
  padding:20px;
  border-radius:14px;
  margin-bottom:15px;
  box-shadow:0 4px 8px rgba(0,0,0,.08);
}
.unit a{
  display:inline-block;
  margin-top:10px;
  padding:8px 14px;
  background:#2563eb;
  color:#fff;
  border-radius:6px;
  text-decoration:none;
}
</style>
</head>

<body>

<div class="topbar">
  <h1><?= htmlspecialchars($course['name']) ?></h1>
  <a class="back" href="student_dashboard.php">⬅ Volver</a>
</div>

<p>
  Periodo:
  <strong><?= htmlspecialchars($assignment['period']) ?></strong>
</p>

<h2>📚 Unidades</h2>

<?php if (empty($courseUnits)): ?>
  <p>No hay unidades disponibles.</p>
<?php endif; ?>

<?php foreach ($courseUnits as $u): ?>
  <div class="unit">
    <h3><?= htmlspecialchars($u['name']) ?></h3>

    <a href="/lessons/lessons/activities/hub/index.php?unit=<?= urlencode($u['id']) ?>">
      Ver actividades
    </a>
  </div>
<?php endforeach; ?>

</body>
</html>

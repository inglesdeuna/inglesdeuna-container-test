<?php
session_start();

/**
 * DASHBOARD DOCENTE
 * Acceso exclusivo para docentes logueados
 */

// 🔐 VALIDACIÓN (NO SE CAMBIA)
if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header("Location: login.php");
    exit;
}

$teacherId   = $_SESSION['academic_id']   ?? null;
$teacherName = $_SESSION['academic_name'] ?? 'Docente';

/* ==========================
   DATA
   ========================== */
$baseDir = dirname(__DIR__) . "/admin/data";

$assignmentsFile = $baseDir . "/assignments.json";
$coursesFile     = $baseDir . "/courses.json";

$assignments = file_exists($assignmentsFile)
  ? json_decode(file_get_contents($assignmentsFile), true)
  : [];

$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

/* ==========================
   CURSOS ASIGNADOS AL DOCENTE
   ========================== */
$myAssignments = array_filter($assignments, function ($a) use ($teacherId) {
    return ($a['teacher_id'] ?? null) === $teacherId;
});

function getCourseName($courseId, $courses) {
    foreach ($courses as $c) {
        if (($c['id'] ?? null) === $courseId) {
            return $c['name'];
        }
    }
    return 'Curso';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Docente</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f0fdf4;
  padding:40px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:30px;
}
a.logout{
  color:#dc2626;
  text-decoration:none;
  font-weight:bold;
}
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:30px;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
.card h3{margin-top:0}
.card p{color:#555}
.card a{
  display:inline-block;
  margin-top:12px;
  padding:10px 16px;
  background:#16a34a;
  color:#fff;
  text-decoration:none;
  border-radius:8px;
  font-size:14px;
}
.status{
  font-weight:700;
  color:#16a34a;
}
</style>
</head>

<body>

<div class="topbar">
  <h1>👩‍🏫 Panel Docente</h1>
  <a class="logout" href="logout.php">🚪 Cerrar sesión</a>
</div>

<p>
  Bienvenido,
  <strong><?= htmlspecialchars($teacherName) ?></strong>
</p>

<h2 style="margin-top:40px">📘 Mis cursos</h2>

<?php if (empty($myAssignments)): ?>
  <p>No tienes cursos asignados actualmente.</p>
<?php else: ?>

<div class="grid">
<?php foreach ($myAssignments as $a): ?>
  <div class="card">
    <h3><?= htmlspecialchars(getCourseName($a['course_id'], $courses)) ?></h3>

    <p>
      Periodo:
      <span class="status"><?= htmlspecialchars($a['period']) ?></span>
    </p>

    <a href="course.php?assignment=<?= urlencode($a['id']) ?>">
      Entrar al curso
    </a>
  </div>
<?php endforeach; ?>
</div>

<?php endif; ?>

</body>
</html>

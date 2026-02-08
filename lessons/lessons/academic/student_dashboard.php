<?php
session_start();

/**
 * DASHBOARD ESTUDIANTE
 */

// 🔐 VALIDACIÓN ESTUDIANTE
if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header("Location: ../student/login.php");
    exit;
}

$studentId   = $_SESSION['student_id']   ?? null;
$studentName = $_SESSION['student_name'] ?? 'Estudiante';

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
   CURSOS DEL ESTUDIANTE
   ========================== */
$myAssignments = array_filter($assignments, function ($a) use ($studentId) {
    return in_array($studentId, $a['students'] ?? []);
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
<title>Panel Estudiante</title>

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
.card a{
  display:inline-block;
  margin-top:12px;
  padding:10px 16px;
  background:#2563eb;
  color:#fff;
  border-radius:8px;
  text-decoration:none;
}
</style>
</head>

<body>

<div class="topbar">
  <h1>🎒 Panel Estudiante</h1>
  <a class="logout" href="logout.php">🚪 Cerrar sesión</a>
</div>

<p>
  Bienvenido,
  <strong><?= htmlspecialchars($studentName) ?></strong>
</p>

<h2 style="margin-top:40px">📘 Mis cursos</h2>

<?php if (empty($myAssignments)): ?>
  <p>No tienes cursos asignados aún.</p>
<?php else: ?>

<div class="grid">
<?php foreach ($myAssignments as $a): ?>
  <div class="card">
    <h3><?= htmlspecialchars(getCourseName($a['course_id'], $courses)) ?></h3>
    <p>Periodo: <strong><?= htmlspecialchars($a['period']) ?></strong></p>

    <a href="student_course.php?assignment=<?= urlencode($a['id']) ?>">
      Entrar al curso
    </a>
  </div>
<?php endforeach; ?>
</div>

<?php endif; ?>

</body>
</html>

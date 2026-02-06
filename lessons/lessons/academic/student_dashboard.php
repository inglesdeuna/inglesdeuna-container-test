<?php
session_start();

/* SOLO ESTUDIANTE */
if (!isset($_SESSION["student_id"])) {
  $_SESSION["redirect_after_login"] = "student_dashboard.php";
  header("Location: login_student.php");
  exit;
}

/* ARCHIVO DE CURSOS */
$file = dirname(__DIR__) . "/academic/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($courses)) $courses = [];

$studentId = $_SESSION["student_id"];

/* FILTRAR CURSOS DEL ESTUDIANTE */
$myCourses = [];

foreach ($courses as $c) {
  foreach ($c["students"] ?? [] as $s) {
    if (($s["id"] ?? null) === $studentId) {
      $myCourses[] = $c;
      break;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Estudiante</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:25px;border-radius:14px;max-width:800px}
.course{padding:15px;background:#eef2ff;border-radius:10px;margin-bottom:10px}
a{color:#2563eb;text-decoration:none;font-weight:700}
</style>
</head>
<body>

<div class="box">
  <h1>🎓 Panel del Estudiante</h1>

  <?php if (empty($myCourses)): ?>
    <p>No tienes cursos asignados.</p>
  <?php else: ?>
    <?php foreach ($myCourses as $c): ?>
      <div class="course">
        <strong><?= htmlspecialchars($c["name"]) ?></strong><br>
        <a href="course_view.php?course=<?= urlencode($c["id"]) ?>">
          Ver curso →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <br>
  <a href="logout.php">🚪 Cerrar sesión</a>
</div>

</body>
</html>

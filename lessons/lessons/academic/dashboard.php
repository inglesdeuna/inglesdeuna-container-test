<?php
session_start();

/* SOLO DOCENTE */
if (!isset($_SESSION["teacher_id"])) {
  header("Location: login_teacher.php");
  exit;
}

/* ARCHIVO DE CURSOS */
$file = __DIR__ . "/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($courses)) $courses = [];

$teacherId = $_SESSION["teacher_id"];

/* FILTRAR CURSOS DEL DOCENTE */
$myCourses = [];

foreach ($courses as $c) {
  if (
    isset($c["teacher"]["id"]) &&
    $c["teacher"]["id"] === $teacherId
  ) {
    $myCourses[] = $c;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Docente</title>
<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{margin-bottom:30px}
.course{
  background:#fff;
  padding:20px;
  border-radius:14px;
  box-shadow:0 6px 18px rgba(0,0,0,.08);
  margin-bottom:15px;
}
.course a{
  color:#2563eb;
  font-weight:700;
  text-decoration:none;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:30px;
}
</style>
</head>
<body>

<div class="topbar">
  <h1>👩‍🏫 Panel Docente</h1>
  <a href="logout.php">🚪 Cerrar sesión</a>
</div>

<?php if (empty($myCourses)): ?>
  <p>No tienes cursos asignados.</p>
<?php else: ?>
  <?php foreach ($myCourses as $c): ?>
    <div class="course">
      <strong><?= htmlspecialchars($c["name"]) ?></strong><br><br>
      <a href="course_view.php?course=<?= urlencode($c["id"]) ?>">
        Abrir curso →
      </a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</body>
</html>

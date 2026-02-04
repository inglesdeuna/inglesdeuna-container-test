<?php
session_start();

/* REQUIERE LOGIN */
if (!isset($_SESSION["teacher_id"])) {
  header("Location: login.php");
  exit;
}

$teacherId = $_SESSION["teacher_id"];

/* ARCHIVOS */
$coursesFile  = __DIR__ . "/courses.json";
$teachersFile = __DIR__ . "/teachers.json";

/* DATOS */
$courses  = file_exists($coursesFile)  ? json_decode(file_get_contents($coursesFile), true)  : [];
$teachers = file_exists($teachersFile) ? json_decode(file_get_contents($teachersFile), true) : [];

/* NOMBRE DOCENTE */
$teacherName = "";
foreach ($teachers as $t) {
  if (($t["id"] ?? null) === $teacherId) {
    $teacherName = $t["name"];
    break;
  }
}

/* FILTRAR CURSOS DEL DOCENTE */
$myCourses = [];
foreach ($courses as $c) {
  // Cursos del docente o cursos sin docente asignado
  if (
    !isset($c["teacher"]) ||
    (isset($c["teacher"]["id"]) && $c["teacher"]["id"] === $teacherId)
  ) {
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
body{font-family:Arial;background:#f4f8ff;padding:40px}
.section{background:#fff;padding:25px;border-radius:14px;max-width:600px;margin:auto}
a.course{display:block;padding:12px;margin:10px 0;background:#eef2ff;border-radius:10px;text-decoration:none;color:#111}
a.course:hover{background:#e0e7ff}
</style>
</head>
<body>

<div class="section">
  <h1>📚 Mis cursos</h1>
  <p>Docente: <strong><?= htmlspecialchars($teacherName) ?></strong></p>

  <?php if (empty($myCourses)): ?>
    <p>No tienes cursos asignados.</p>
  <?php else: ?>
    <?php foreach ($myCourses as $c): ?>
      <a class="course"
         href="course_view.php?course=<?= urlencode($c["id"]) ?>">
        <?= htmlspecialchars($c["name"]) ?>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <p style="margin-top:20px">
    <a href="logout.php">🚪 Cerrar sesión</a>
  </p>
</div>

</body>
</html>

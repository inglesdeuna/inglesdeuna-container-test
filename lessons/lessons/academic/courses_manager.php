<?php
session_start();

/* ===============================
   ACCESO: SOLO ADMIN O TEACHER
   =============================== */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"])
) {
  $_SESSION["redirect_after_login"] = "courses_manager.php";
  header("Location: login.php");
  exit;
}

/* ===============================
   ARCHIVO DE CURSOS
   =============================== */
$file = __DIR__ . "/courses.json";
$courses = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!is_array($courses)) {
  $courses = [];
}

/* ===============================
   CREAR CURSO (ÚNICO LUGAR DONDE SE CREAN)
   =============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

  $courseId = "course_" . time();

  $courses[] = [
    "id" => $courseId,
    "name" => trim($_POST["course_name"]),
    "units" => [],
    "teacher" => null,
    "students" => []
  ];

  file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));

  header("Location: course_view.php?course=" . urlencode($courseId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Courses Manager</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:20px}
.course{background:#fff;padding:15px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between}
a{text-decoration:none;color:#2563eb;font-weight:bold}
</style>
</head>
<body>

<h1>🎓 Courses</h1>

<div class="card">
  <h2>➕ Crear curso</h2>
  <form method="post">
    <input type="text" name="course_name" required placeholder="Ej: Intermediate 3">
    <button type="submit">Crear</button>
  </form>
</div>

<div class="card">
  <h2>Cursos creados</h2>

  <?php if (empty($courses)): ?>
    <p>No hay cursos creados.</p>
  <?php else: ?>
    <?php foreach ($courses as $c): ?>
      <?php if (!is_array($c)) continue; ?>
      <div class="course">
        <strong><?= htmlspecialchars($c["name"]) ?></strong>
        <a href="course_view.php?course=<?= urlencode($c["id"]) ?>">Abrir →</a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</body>
</html>

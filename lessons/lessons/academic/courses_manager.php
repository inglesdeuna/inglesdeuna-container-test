<?php
session_start();
echo "<pre>";
var_dump($_SESSION);
exit;

/* Solo ADMIN o TEACHER pueden crear cursos */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"])
) {
  header("Location: login.php");
  exit;
}

/* ===============================
   COURSES MANAGER – ACADEMIC
   =============================== */

$file = __DIR__ . "/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

/* CREAR CURSO */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {
 $courseId = "course_" . time();

$courses[] = [
  "id" => $courseId,
  "name" => trim($_POST["course_name"]),
  "students" => [],
  "teacher" => null,
  "activities" => []
];

file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));

/* 👉 IR AL CONTENEDOR DE ACTIVIDADES */
header("Location: ../hangman/index.php?course=" . urlencode($courseId));
exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Courses Manager</title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  color:#2563eb;
}

.card{
  background:#fff;
  padding:30px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  max-width:600px;
  margin-bottom:30px;
}

input{
  width:100%;
  padding:12px;
  border-radius:8px;
  border:1px solid #ccc;
  margin-bottom:12px;
  font-size:16px;
}

button{
  padding:12px 20px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:10px;
  font-size:16px;
  font-weight:700;
  cursor:pointer;
}

.list{
  max-width:800px;
}

.course{
  background:#fff;
  padding:20px;
  border-radius:12px;
  box-shadow:0 6px 18px rgba(0,0,0,.08);
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:12px;
}

.course a{
  text-decoration:none;
  font-weight:700;
  color:#2563eb;
}
</style>
</head>

<body>

<h1>🎓 Courses</h1>

<div class="card">
  <h2>➕ Crear Curso</h2>
  <form method="post">
    <input type="text" name="course_name" placeholder="Ej: Basic 1" required>
    <button type="submit">Crear curso</button>
  </form>
</div>

<div class="list">
  <h2>Cursos creados</h2>

  <?php if (empty($courses)): ?>
    <p>No hay cursos creados.</p>
  <?php else: ?>
    <?php foreach ($courses as $c): ?>
      <div class="course">
        <strong><?= htmlspecialchars($c["name"]) ?></strong>
        <a href="course_view.php?course=<?= urlencode($c["id"]) ?>">
          Abrir →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</body>
</html>


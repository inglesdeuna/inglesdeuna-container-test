<?php
session_start();

/* =====================
   REQUIERE LOGIN
   ===================== */
if (!isset($_SESSION["teacher_id"])) {
  header("Location: login.php");
  exit;
}

$teacherId = $_SESSION["teacher_id"];

/* =====================
   ARCHIVOS
   ===================== */
$coursesFile  = __DIR__ . "/courses.json";
$teachersFile = __DIR__ . "/teachers.json";

/* =====================
   CARGAR DATOS
   ===================== */
$courses  = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

$teachers = file_exists($teachersFile)
  ? json_decode(file_get_contents($teachersFile), true)
  : [];

/* =====================
   NOMBRE DEL DOCENTE
   ===================== */
$teacherName = "";
foreach ($teachers as $t) {
  if (($t["id"] ?? null) === $teacherId) {
    $teacherName = $t["name"];
    break;
  }
}

/* =====================
   CREAR CURSO NUEVO (REAL)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_course"])) {
  $name = trim($_POST["course_name"] ?? "");

  if ($name !== "") {
    $newCourse = [
      "id" => "course_" . time(),
      "name" => $name,
      "teacher" => [
        "id" => $teacherId,
        "permission" => "editor"
      ],
      "students" => []
    ];

    $courses[] = $newCourse;

    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

    // Ir directo al curso recién creado
    header("Location: course_view.php?course=" . urlencode($newCourse["id"]));
    exit;
  }
}

/* =====================
   FILTRAR CURSOS VISIBLES
   ===================== */
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
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
.section{
  background:#fff;
  padding:25px;
  border-radius:14px;
  max-width:600px;
  margin:auto;
}
a.course{
  display:block;
  padding:12px;
  margin:10px 0;
  background:#eef2ff;
  border-radius:10px;
  text-decoration:none;
  color:#111;
}
a.course:hover{
  background:#e0e7ff;
}
input,button{
  padding:10px;
  font-size:14px;
}
</style>
</head>

<body>

<div class="section">
  <h1>📚 Mis cursos</h1>

  <!-- CREAR CURSO -->
  <form method="post" style="margin-bottom:20px">
    <input type="text"
           name="course_name"
           placeholder="Nombre del nuevo curso"
           required
           style="width:100%;max-width:400px">

    <button type="submit"
            name="create_course"
            style="margin-top:10px">
      ➕ Crear curso
    </button>
  </form>

  <p>
    Docente:
    <strong><?= htmlspecialchars($teacherName) ?></strong>
  </p>

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

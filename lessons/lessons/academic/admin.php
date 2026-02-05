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
$teachersFile = __DIR__ . "/teachers.json";
$coursesFile  = __DIR__ . "/courses.json";

/* =====================
   CARGAR DATOS
   ===================== */
$teachers = file_exists($teachersFile)
  ? json_decode(file_get_contents($teachersFile), true)
  : [];

$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

/* =====================
   VALIDAR ADMIN
   ===================== */
$isAdmin = false;
$adminName = "";

foreach ($teachers as $t) {
  if (
    ($t["id"] ?? null) === $teacherId &&
    ($t["role"] ?? "") === "admin"
  ) {
    $isAdmin = true;
    $adminName = $t["name"];
    break;
  }
}

if (!$isAdmin) {
  die("Acceso restringido");
}

/* =====================
   CREAR CURSO (ADMIN)
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_course"])) {
  $name       = trim($_POST["course_name"] ?? "");
  $teacherSel = $_POST["assign_teacher"] ?? null;
  $perm       = $_POST["permission"] ?? "editor";

  if ($name !== "" && $teacherSel) {
    $newCourse = [
      "id" => "course_" . time(),
      "name" => $name,
      "teacher" => [
        "id" => $teacherSel,
        "permission" => $perm
      ],
      "students" => []
    ];

    $courses[] = $newCourse;
    file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

    header("Location: admin.php");
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Administrador</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.section{background:#fff;padding:25px;border-radius:14px;max-width:700px;margin:auto}
input,select,button{padding:10px;font-size:14px;margin-top:10px}
.course{background:#eef2ff;padding:10px;border-radius:8px;margin-top:8px}
</style>
</head>
<body>

<div class="section">
  <h1>👑 Panel Administrador</h1>
  <p>Administrador: <strong><?= htmlspecialchars($adminName) ?></strong></p>

  <h2>➕ Crear curso</h2>

  <form method="post">
    <input type="text"
           name="course_name"
           placeholder="Nombre del curso"
           required
           style="width:100%">

    <select name="assign_teacher" required style="width:100%">
      <option value="">Asignar docente</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= htmlspecialchars($t["id"]) ?>">
          <?= htmlspecialchars($t["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="permission" style="width:100%">
      <option value="editor">Editor</option>
      <option value="viewer">Viewer</option>
    </select>

    <button type="submit" name="create_course">
      Crear curso
    </button>
  </form>

  <h2 style="margin-top:30px">📚 Cursos existentes</h2>

  <?php foreach ($courses as $c): ?>
    <div class="course">
      <strong><?= htmlspecialchars($c["name"]) ?></strong><br>
      Docente:
      <?= htmlspecialchars($c["teacher"]["id"] ?? "Sin asignar") ?>
    </div>
  <?php endforeach; ?>

  <p style="margin-top:20px">
    <a href="courses.php">📚 Mis cursos</a> |
    <a href="logout.php">🚪 Cerrar sesión</a>
  </p>
</div>

</body>
</html>

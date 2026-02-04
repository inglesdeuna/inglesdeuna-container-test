<?php
session_start();

/* ARCHIVO DOCENTES */
$teachersFile = __DIR__ . "/teachers.json";

/* CARGAR DOCENTES */
$teachers = file_exists($teachersFile)
  ? json_decode(file_get_contents($teachersFile), true)
  : [];

/*
  SI YA ESTÁ LOGUEADO
  → ir al último curso si existe
  → si no, ir a Mis cursos
*/
if (isset($_SESSION["teacher_id"])) {
  if (isset($_SESSION["last_course_id"])) {
    header("Location: course_view.php?course=" . urlencode($_SESSION["last_course_id"]));
  } else {
    header("Location: courses.php");
  }
  exit;
}

$error = "";

/* PROCESAR LOGIN */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $teacherId = $_POST["teacher_id"] ?? null;

  if ($teacherId) {
    foreach ($teachers as $t) {
      if (($t["id"] ?? null) === $teacherId) {

        // LOGIN OK
        $_SESSION["teacher_id"] = $teacherId;

        // REDIRECCIÓN POST-LOGIN
        if (isset($_SESSION["last_course_id"])) {
          header("Location: course_view.php?course=" . urlencode($_SESSION["last_course_id"]));
        } else {
          header("Location: courses.php");
        }
        exit;
      }
    }
    $error = "Docente no válido";
  } else {
    $error = "Seleccione un docente";
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Docente</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:30px;border-radius:14px;max-width:400px;margin:auto}
button,select{padding:10px;width:100%;margin-top:10px}
.error{color:#dc2626;margin-bottom:10px}
</style>
</head>
<body>

<div class="box">
  <h2>👩‍🏫 Login Docente</h2>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <select name="teacher_id" required>
      <option value="">Seleccione su nombre</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= htmlspecialchars($t["id"]) ?>">
          <?= htmlspecialchars($t["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit">Ingresar</button>
  </form>
</div>

</body>
</html>

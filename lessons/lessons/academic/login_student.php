<?php
session_start();

/* =====================
   CARGAR ESTUDIANTES
   ===================== */
$studentsFile = __DIR__ . "/students.json";
$students = [];

if (file_exists($studentsFile)) {
  $raw = file_get_contents($studentsFile);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    $students = $decoded;
  }
}

/* =====================
   SI YA ESTA LOGUEADO
   ===================== */
if (isset($_SESSION["student_id"])) {

  if (isset($_SESSION["redirect_after_login"])) {
    $go = $_SESSION["redirect_after_login"];
    unset($_SESSION["redirect_after_login"]);
    header("Location: $go");
    exit;
  }

  header("Location: student_dashboard.php");
  exit;
}

$error = "";

/* =====================
   PROCESAR LOGIN
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $studentId = $_POST["student_id"] ?? null;

  if (!$studentId) {
    $error = "Seleccione un estudiante";
  } else {
    foreach ($students as $s) {
      if (($s["id"] ?? null) === $studentId) {

        $_SESSION["student_id"] = $studentId;

        if (isset($_SESSION["redirect_after_login"])) {
          $go = $_SESSION["redirect_after_login"];
          unset($_SESSION["redirect_after_login"]);
          header("Location: $go");
          exit;
        }

        header("Location: student_dashboard.php");
        exit;
      }
    }

    $error = "Estudiante no válido";
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Estudiante</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:30px;border-radius:14px;max-width:400px;margin:auto}
button,select{padding:10px;width:100%;margin-top:10px}
.error{color:#dc2626;margin-bottom:10px}
</style>
</head>
<body>

<div class="box">
  <h2>🎓 Login Estudiante</h2>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <select name="student_id" required>
      <option value="">Seleccione su nombre</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= htmlspecialchars($s["id"]) ?>">
          <?= htmlspecialchars($s["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit">Ingresar</button>
  </form>
</div>

</body>
</html>

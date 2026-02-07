<?php
session_start();

/**
 * ACADEMIC LOGIN (DOCENTE)
 * Login temporal sin password
 */

// Si ya está logueado
if (isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true) {
    header("Location: dashboard.php");
    exit;
}

// Cargar docentes
$file = $_SERVER['DOCUMENT_ROOT'] . "/lessons/lessons/teacher.json";
$teachers = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Limpiar sesión
    session_unset();
    session_destroy();
    session_start();

    $teacher_id = $_POST["teacher_id"] ?? "";

    foreach ($teachers as $t) {
        if ($t["id"] === $teacher_id) {

            $_SESSION["academic_logged"] = true;
            $_SESSION["academic_id"]     = $t["id"];
            $_SESSION["academic_name"]   = $t["name"];

            header("Location: dashboard.php");
            exit;
        }
    }

    $error = "Docente no válido";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Docente</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f0fdf4;
  display:flex;
  justify-content:center;
  align-items:center;
  height:100vh;
}
.card{
  background:white;
  padding:30px;
  border-radius:16px;
  width:340px;
  box-shadow:0 10px 25px rgba(0,0,0,.15);
}
h2{text-align:center;color:#16a34a;}
select{
  width:100%;
  padding:10px;
  margin-top:15px;
}
button{
  width:100%;
  margin-top:20px;
  padding:12px;
  border:none;
  border-radius:10px;
  background:#16a34a;
  color:white;
  font-weight:bold;
}
.error{
  color:red;
  font-size:14px;
  margin-top:10px;
  text-align:center;
}
</style>
</head>

<body>

<div class="card">
  <h2>👩‍🏫 Acceso Docente</h2>

  <form method="post">
    <select name="teacher_id" required>
      <option value="">Seleccione docente</option>
      <?php foreach ($teachers as $t): ?>
        <option value="<?= htmlspecialchars($t['id']) ?>">
          <?= htmlspecialchars($t['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button>Ingresar</button>
  </form>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
</div>

</body>
</html>

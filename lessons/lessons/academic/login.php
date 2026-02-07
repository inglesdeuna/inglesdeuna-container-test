<?php
session_start();

/**
 * ACADEMIC LOGIN (DOCENTES)
 * Acceso exclusivo para docentes
 */

// Si ya hay docente logueado, ir directo al dashboard
if (isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true) {
    header("Location: dashboard.php");
    exit;
}

// Cargar docentes (ajusta la ruta si usas otra fuente)
$file = __DIR__ . "/data/teachers.json";
$teachers = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 🔥 LIMPIAR SESIÓN ANTES DE LOGIN
    session_unset();
    session_destroy();
    session_start();

    $email = trim($_POST["email"] ?? "");
    $pass  = trim($_POST["password"] ?? "");

    foreach ($teachers as $t) {
        if ($t["email"] === $email && $t["password"] === $pass) {

            // ✅ SESIÓN EXCLUSIVA ACADEMIC
            $_SESSION["academic_logged"] = true;
            $_SESSION["academic_id"]     = $t["id"] ?? null;
            $_SESSION["academic_email"]  = $t["email"];

            header("Location: dashboard.php");
            exit;
        }
    }

    $error = "Credenciales incorrectas";
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
  width:320px;
  box-shadow:0 10px 25px rgba(0,0,0,.15);
}
h2{text-align:center;color:#16a34a;}
input{
  width:100%;
  padding:10px;
  margin-top:10px;
}
button{
  width:100%;
  margin-top:15px;
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
  <h2>👩‍🏫 Login Docente</h2>

  <form method="post">
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password" required>
    <button>Ingresar</button>
  </form>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
</div>

</body>
</html>

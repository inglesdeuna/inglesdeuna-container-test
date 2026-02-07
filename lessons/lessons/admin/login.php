<?php
session_start();

/**
 * ADMIN LOGIN
 * Este login es EXCLUSIVO para administradores
 * No comparte sesión con academic ni student
 */

// Si ya hay un admin logueado, ir directo al dashboard
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    header("Location: dashboard.php");
    exit;
}

// Cargar usuarios admin
$file = __DIR__ . "/data/users.json";
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // 🔥 LIMPIAR SESIÓN ANTES DE LOGIN
    session_unset();
    session_destroy();
    session_start();

    $email = trim($_POST["email"] ?? "");
    $pass  = trim($_POST["password"] ?? "");

    foreach ($users as $u) {
        if ($u["email"] === $email && $u["password"] === $pass) {

            // ✅ SESIÓN EXCLUSIVA ADMIN
            $_SESSION["admin_logged"] = true;
            $_SESSION["admin_id"]     = $u["id"] ?? null;
            $_SESSION["admin_email"]  = $u["email"];

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
<title>Login Admin</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#eef6ff;
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
h2{text-align:center;color:#2563eb;}
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
  background:#2563eb;
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
  <h2>🔐 Admin Login</h2>

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

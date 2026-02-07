<?php
session_start();

// 🔥 LIMPIAR CUALQUIER SESIÓN PREVIA (docente, student, lo que sea)
session_unset();
session_destroy();

// 🔄 INICIAR SESIÓN LIMPIA SOLO PARA ADMIN
session_start();

$file = __DIR__ . "/data/users.json";
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $email = trim($_POST["email"] ?? "");
  $pass  = trim($_POST["password"] ?? "");

  foreach ($users as $u) {
    if ($u["email"] === $email && $u["password"] === $pass) {
      $_SESSION["user"] = $u;
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
    <div class="error"><?= $error ?></div>
  <?php endif; ?>
</div>

</body>
</html>

<?php
session_start();

/**
 * ACADEMIC DASHBOARD
 * Acceso exclusivo para docentes logueados
 */

// 🔐 VALIDACIÓN CORRECTA (coincide con academic/login.php)
if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Dashboard Docente</title>
<style>
body{
  font-family:Arial, sans-serif;
  background:#f0fdf4;
  padding:40px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:30px;
}
a.logout{
  color:#dc2626;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>
<body>

<div class="topbar">
  <h1>👩‍🏫 Panel Docente</h1>
  <a class="logout" href="logout.php">🚪 Cerrar sesión</a>
</div>

<p>
  Bienvenido,
  <strong><?= htmlspecialchars($_SESSION['academic_name'] ?? 'Docente') ?></strong>
</p>

<p>Dashboard académico funcionando correctamente ✅</p>

</body>
</html>


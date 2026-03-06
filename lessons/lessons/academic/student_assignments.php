<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignaciones Estudiantes</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.wrapper{max-width:1000px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
.back{display:inline-block;margin-bottom:15px;color:#1f66cc;text-decoration:none;font-weight:700}
.btn{display:block;width:100%;padding:14px;border-radius:8px;text-align:center;text-decoration:none;color:#fff;font-weight:700;font-size:20px;margin-top:10px}
.btn-blue{background:linear-gradient(90deg,#2a67c4,#2d71d2)}
.btn-green{background:linear-gradient(90deg,#54b98e,#4fb489)}
</style>
</head>
<body>
<div class="wrapper">
  <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

  <div class="card">
    <h1>🎓 Asignaciones de Estudiantes</h1>
    <p>Selecciona el programa para gestionar asignaciones de estudiantes.</p>

    <a class="btn btn-blue" href="technical_assignments.php">Asignaciones Programa Técnico</a>
    <a class="btn btn-green" href="english_assignments.php">Asignaciones Cursos de Inglés</a>
  </div>
</div>
</body>
</html>

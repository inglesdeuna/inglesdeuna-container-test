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
<title>Asignación de Cursos a Estudiantes</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;margin:0;padding:30px;color:#1f2937}
.wrapper{max-width:1100px;margin:0 auto;background:#e9eef6;border-radius:24px;padding:0 24px 24px 24px}
.header{background:linear-gradient(90deg,#0f4fa8,#2470d9);color:#fff;border-radius:24px 24px 0 0;padding:18px 22px;font-size:38px;font-weight:700}
.subtitle{font-size:15px;font-weight:500;opacity:.95}
.panel{background:#fff;border:1px solid #d9e0ec;border-radius:14px;overflow:hidden;margin-top:18px}
.panel h3{margin:0;padding:12px 16px;border-bottom:1px solid #e3e8f1;font-size:20px}
.panel-body{padding:14px 16px}
.btn{display:block;width:100%;text-align:center;text-decoration:none;color:#fff;font-size:21px;font-weight:700;border-radius:8px;padding:14px;margin-bottom:12px}
.btn:last-child{margin-bottom:0}
.btn-profile{background:linear-gradient(90deg,#6f46dd,#845ef7)}
.btn-english{background:linear-gradient(90deg,#2f8f67,#42b883)}
.btn-technical{background:linear-gradient(90deg,#1d5fbe,#2d71d2)}
.top-actions{display:flex;justify-content:space-between;align-items:center;margin:14px 0 0 0}
.back{color:#0d5bc2;text-decoration:none;font-weight:700}
.helper{font-size:16px;color:#4b5563;line-height:1.45}
</style>
</head>
<body>

<div class="wrapper">
  <div class="header">
    🎓 Asignación de Cursos a Estudiantes
    <div class="subtitle">Perfiles y asignaciones por programa</div>
  </div>

  <div class="top-actions">
    <a class="back" href="../admin/dashboard.php">← Volver al panel</a>
  </div>

  <div class="panel">
    <h3>Flujo recomendado</h3>
    <div class="panel-body">
      <p class="helper">1) Crea primero el perfil del estudiante (usuario y password).<br>2) Luego usa asignaciones de Inglés o Técnico.</p>
      <a class="btn btn-profile" href="student_profiles.php">1. Perfil de estudiante (usuario y password)</a>
      <a class="btn btn-english" href="assignments_editor.php?program=english">2. Asignaciones Inglés</a>
      <a class="btn btn-technical" href="assignments_editor.php?program=technical">3. Asignaciones Programa Técnico</a>
    </div>
  </div>
</div>

</body>
</html>

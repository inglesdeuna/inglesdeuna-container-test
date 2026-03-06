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
.wrapper{max-width:1100px;margin:0 auto;background:#e9eef6;border-radius:24px;padding:18px}
.card{background:#fff;border:1px solid #d9e0ec;border-radius:14px;overflow:hidden}
.card-head{padding:16px 18px;border-bottom:1px solid #e3e8f1}
.card-head h1{margin:0;font-size:42px;color:#17365e}
.subtitle{font-size:15px;color:#5b6b80;margin-top:6px}
.top-actions{display:flex;justify-content:flex-start;align-items:center;margin:12px 0}
.back{color:#0d5bc2;text-decoration:none;font-weight:700}
.panel{background:#fff;border:1px solid #d9e0ec;border-radius:14px;overflow:hidden;margin-top:12px}
.panel h3{margin:0;padding:12px 16px;border-bottom:1px solid #e3e8f1;font-size:20px}
.panel-body{padding:16px;background:#fff}
.helper{font-size:16px;color:#4b5563;line-height:1.45;margin:0 0 14px}
.btn{display:block;width:100%;max-width:680px;margin:0 auto 12px auto;text-align:center;text-decoration:none;color:#fff;font-size:17px;font-weight:700;border-radius:8px;padding:12px 16px}
.btn:last-child{margin-bottom:0}
.btn-profile{background:linear-gradient(90deg,#6f46dd,#845ef7)}
.btn-english{background:linear-gradient(90deg,#2f8f67,#42b883)}
.btn-technical{background:linear-gradient(90deg,#1d5fbe,#2d71d2)}
</style>
</head>
<body>

<div class="wrapper">
  <div class="card">
    <div class="card-head">
      <h1>🎓 Asignación de Cursos a Estudiantes</h1>
      <div class="subtitle">Perfiles y asignaciones por programa</div>
    </div>
  </div>

  <div class="top-actions">
    <a class="back" href="../admin/dashboard.php">← Volver al panel</a>
  </div>

  <div class="panel">
    <h3>Flujo recomendado</h3>
    <div class="panel-body">
      <p class="helper">Crea primero el perfil del estudiante (usuario y password). Luego usa asignaciones de Inglés o Técnico.</p>
      <a class="btn btn-profile" href="student_profiles.php">Perfil de estudiante (usuario y password)</a>
      <a class="btn btn-english" href="assignments_editor.php?program=english">Asignaciones Inglés</a>
      <a class="btn btn-technical" href="assignments_editor.php?program=technical">Asignaciones Programa Técnico</a>
    </div>
  </div>
</div>

</body>
</html>

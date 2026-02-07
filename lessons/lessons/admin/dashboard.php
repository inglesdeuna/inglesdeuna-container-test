<?php
session_start();

/**
 * PANEL PRINCIPAL ADMIN
 * Acceso exclusivo para administradores
 */

// 🔐 VALIDACIÓN ESTRICTA
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Administrador</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{margin-bottom:30px}
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:30px;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
.card h2{margin-top:0}
.card p{color:#555}
.card a{
  display:inline-block;
  margin-top:15px;
  padding:10px 18px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  border-radius:8px;
}
.card a.secondary{background:#16a34a}
.card a.warning{background:#d97706}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:40px;
}
.topbar a{
  color:#dc2626;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<div class="topbar">
  <h1>🎛️ Panel Administrador</h1>
  <a href="logout.php">🚪 Cerrar sesión</a>
</div>

<div class="grid">

  <div class="card">
    <h2>🧱 Estructura Académica</h2>
    <p>
      Define la <strong>estructura académica</strong> del instituto:
      programas, semestres, módulos y unidades.
    </p>
    <a href="../academic/programs_editor.php">Gestionar estructura</a>
  </div>

  <div class="card">
    <h2>📘 Cursos</h2>
    <p>
      Gestiona los <strong>cursos activos</strong> y accede a cada clase.
    </p>
    <a class="secondary" href="../academic/courses_manager.php">Ver cursos</a>
  </div>

  <div class="card">
    <h2>👥 Asignaciones</h2>
    <p>
      Vincula <strong>docentes y estudiantes</strong> a los cursos.
    </p>
    <a class="warning" href="../academic/assignments_users.php">Asignar usuarios</a>
  </div>

</div>

</body>
</html>

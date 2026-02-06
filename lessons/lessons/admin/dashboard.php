<?php
session_start();

/* ==========================
   SEGURIDAD
   ========================== */
if (!isset($_SESSION["admin_id"])) {
  $_SESSION["redirect_after_login"] = "../admin/dashboard.php";
  header("Location: ../academic/login.php");
  exit;
}

/* Identidad */
$role = isset($_SESSION["admin_id"]) ? "admin" : "teacher";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Administrador</title>
<style>
body{
  font-family:Arial;
  background:#f4f8ff;
  padding:40px
}
h1{margin-bottom:30px}
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:30px
}
.card{
  background:#fff;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.08)
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
  border-radius:8px
}
.card a.secondary{
  background:#16a34a
}
.card a.warning{
  background:#d97706
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:40px
}
</style>
</head>
<body>

<div class="topbar">
  <h1>🎛️ Panel Administrador Académico</h1>
  <a href="../academic/logout.php">🚪 Cerrar sesión</a>
</div>

<div class="grid">

  <!-- CREAR -->
  <div class="card">
    <h2>🧱 CREAR</h2>
    <p>
      Define la <strong>estructura académica</strong> del instituto:
      programas, semestres, módulos, unidades y asignaciones.
    </p>
    <a href="../academic/programs_manager.php">Ir a estructura</a>
  </div>

  <!-- CURSOS -->
  <div class="card">
    <h2>📘 CURSOS</h2>
    <p>
      Gestiona los <strong>cursos reales</strong>:
      ver, editar y acceder a cada clase activa.
    </p>
    <a class="secondary" href="../academic/courses_manager.php">Gestionar cursos</a>
  </div>

  <!-- ASIGNAR -->
  <div class="card">
    <h2>👥 ASIGNAR</h2>
    <p>
      Vincula <strong>docentes y estudiantes</strong> a los cursos.
      Controla matrículas y responsables.
    </p>
    <a class="warning" href="../academic/assignments_users.php">Asignar usuarios</a>
  </div>

</div>

</body>
</html>


<?php
session_start();

/* =====================
   ACCESO DOCENTE / ADMIN
   ===================== */
if (
  !isset($_SESSION["admin_id"]) &&
  !isset($_SESSION["teacher_id"])
) {
  header("Location: login.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Académico</title>
<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  margin-bottom:30px;
  color:#1f2937;
}

.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:20px;
  max-width:700px;
}

.card{
  background:#ffffff;
  border-radius:16px;
  padding:30px;
  text-align:center;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  cursor:pointer;
  transition:transform .2s, box-shadow .2s;
}

.card:hover{
  transform:translateY(-4px);
  box-shadow:0 18px 35px rgba(0,0,0,.12);
}

.card h2{
  margin:0;
  font-size:20px;
  color:#2563eb;
}

.card p{
  margin-top:10px;
  color:#6b7280;
  font-size:14px;
}

a{
  text-decoration:none;
}
</style>
</head>
<body>

<h1>🎓 Panel Académico</h1>

<div class="grid">

  <!-- CREAR CURSO -->
  <a href="courses_manager.php">
    <div class="card">
      <h2>➕ Crear curso</h2>
      <p>Inicia un nuevo curso académico</p>
    </div>
  </a>

  <!-- CURSOS -->
  <a href="courses_manager.php">
    <div class="card">
      <h2>📚 Cursos</h2>
      <p>Ver y editar cursos existentes</p>
    </div>
  </a>

  <!-- ROLES -->
  <a href="#">
    <div class="card">
      <h2>👥 Roles</h2>
      <p>Asignar docentes y estudiantes</p>
    </div>
  </a>

</div>

</body>
</html>

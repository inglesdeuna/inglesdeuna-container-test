<?php
session_start();

/* SOLO ADMIN */
if (!isset($_SESSION["admin_id"])) {
  header("Location: login.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Académico – Admin</title>
<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  color:#1f2937;
  margin-bottom:30px;
}

.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
  gap:20px;
  max-width:900px;
}

.card{
  background:#ffffff;
  border-radius:16px;
  padding:30px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  cursor:pointer;
  transition:.2s;
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

a{text-decoration:none}
</style>
</head>
<body>

<h1>🛠️ Panel Académico – Administrador</h1>

<div class="grid">

  <a href="courses_manager.php">
    <div class="card">
      <h2>📚 Cursos</h2>
      <p>Crear y administrar cursos</p>
    </div>
  </a>

  <a href="roles.php">
    <div class="card">
      <h2>👥 Asignaciones</h2>
      <p>Docentes y estudiantes por curso</p>
    </div>
  </a>

  <a href="dashboard.php">
    <div class="card">
      <h2>🎓 Panel Docente</h2>
      <p>Ver experiencia del docente</p>
    </div>
  </a>

  <a href="../hangman/index.php">
    <div class="card">
      <h2>🎮 Actividades</h2>
      <p>Contenedores de actividades</p>
    </div>
  </a>

</div>

</body>
</html>

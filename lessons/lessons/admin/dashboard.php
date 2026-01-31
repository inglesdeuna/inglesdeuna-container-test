<?php
session_start();
if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] !== "admin") {
  header("Location: login.php");
  exit;
}
$user = $_SESSION["user"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{color:#2563eb;}

.grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(260px,1fr));
  gap:24px;
}

.card{
  background:white;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.card h2{margin-top:0;}

.card a{
  display:inline-block;
  margin-top:10px;
  padding:10px 16px;
  background:#2563eb;
  color:white;
  border-radius:10px;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🎓 Panel Administrador</h1>
<p>Bienvenido, <strong><?= htmlspecialchars($user["name"]) ?></strong></p>

<div class="grid">

  <div class="card">
  <h2>📘 Académico</h2>

  <a href="/lessons/lessons/academic/programs_editor.php">
    Programas
  </a><br><br>

  <a href="/lessons/lessons/academic/semesters_editor.php">
    Semestres
  </a><br><br>

  <a href="/lessons/lessons/academic/modules_editor.php">
    Módulos
  </a><br><br>

  <a href="/lessons/lessons/academic/units_editor.php">
    Unidades
  </a><br><br>

  <a href="/lessons/lessons/academic/assignments_editor.php">
    Asignaciones
  </a>
</div>

  <div class="card">
    <h2>👩‍🏫 Docentes</h2>
    <p>Crear y asignar docentes.</p>
    <!-- siguiente paso -->
  </div>

  <div class="card">
    <h2>👧🧒 Estudiantes</h2>
    <p>Crear estudiantes y asignar niveles.</p>
    <!-- siguiente paso -->
  </div>

  <div class="card">
    <h2>📊 Reportes</h2>
    <p>Notas y progreso.</p>
    <!-- siguiente paso -->
  </div>

</div>

</body>
</html>

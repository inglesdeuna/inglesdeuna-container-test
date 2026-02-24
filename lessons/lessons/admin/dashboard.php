<?php
session_start();

/* 🔐 SOLO ADMIN */
if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
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
  display:block;
  margin-top:12px;
  padding:12px 18px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  border-radius:8px;
  font-size:14px;
  text-align:center;
}
.card a.secondary{background:#16a34a}
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
    <h2>🧱 Programas Técnicos</h2>
    <p>Gestionar estructura técnica (Cursos → Units → Actividades).</p>
    <a href="../academic/courses_manager.php?program=prog_technical">
      Administrar Programa Técnico
    </a>
  </div>

  <div class="card">
    <h2>🇺🇸 Cursos de Inglés</h2>
    <p>Gestionar estructura de Inglés (Phase → Level → Unit → Actividades).</p>
    <a class="secondary" href="../academic/english_phases.php?program=prog_english_courses">
      Administrar Cursos de Inglés
    </a>
  </div>

</div>

</body>
</html>

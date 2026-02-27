<?php
session_start();

if (!isset($_SESSION["admin_logged"])) {
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

h1{
    margin-bottom:40px;
}

.dashboard{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:40px;
}

.card{
    background:#ffffff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.card h2{
    margin-bottom:10px;
}

.card p{
    font-size:14px;
    opacity:0.8;
    margin-bottom:20px;
}

.btn-group{
    display:flex;
    flex-direction:column;
    gap:12px;
}

.btn{
    padding:14px;
    border-radius:10px;
    text-decoration:none;
    font-weight:600;
    text-align:center;
    color:#ffffff;
}

.btn-blue{
    background:#2563eb;
}

.btn-blue:hover{
    background:#1d4ed8;
}

.btn-green{
    background:#16a34a;
}

.btn-green:hover{
    background:#15803d;
}

.btn-orange{
    background:#ea580c;
}

.btn-orange:hover{
    background:#c2410c;
}

.logout{
    position:absolute;
    top:40px;
    right:40px;
    color:#dc2626;
    font-weight:bold;
    text-decoration:none;
}
</style>
</head>

<body>

<a class="logout" href="logout.php">Cerrar sesión</a>

<h1>⚙️ Panel Administrador</h1>

<div class="dashboard">

    <!-- ========================= -->
<!-- PROGRAMA TÉCNICO -->
<!-- ========================= -->
<div class="card">
    <h2>📘 Programas Técnicos</h2>
    <p>Gestionar estructura técnica (Cursos → Units → Actividades).</p>

    <div class="btn-group">

        <!-- GESTIONAR ESTRUCTURA -->
        <a class="btn btn-blue" 
           href="../academic/programs_editor.php?program=prog_technical">
           Gestionar estructura
        </a>

        <!-- ASIGNACIONES -->
        <a class="btn btn-orange" 
           href="../academic/assignments.php?program=prog_technical">
           Asignaciones (Docentes / Estudiantes)
        </a>

        <!-- CURSOS CREADOS (SEPARADO) -->
        <a class="btn btn-green" 
           href="../academic/technical_created.php">
           Cursos creados
        </a>

    </div>
</div>

    <!-- ========================= -->
    <!-- CURSOS DE INGLÉS -->
    <!-- ========================= -->
    <div class="card">
        <h2>🎓 Cursos de Inglés</h2>
        <p>Gestionar estructura Inglés (Phase → Level → Unit → Actividades).</p>

        <div class="btn-group">
            <a class="btn btn-green" 
               href="../academic/programs_editor.php?program=prog_english_courses">
               Gestionar estructura
            </a>

            <a class="btn btn-orange" 
               href="../academic/assignments.php?program=prog_english_courses">
               Asignaciones (Docentes / Estudiantes)
            </a>

            <a class="btn btn-blue" 
   href="../academic/courses_manager.php?program=prog_technical">
   Cursos creados
            </a>
        </div>
    </div>

</div>

</body>
</html>

<?php
session_start();

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
<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    margin:0;
}

/* CONTENEDOR CENTRAL */
.container{
    max-width:1200px;
    margin:60px auto;
    padding:0 20px;
}

/* GRID DE TARJETAS */
.grid{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap:40px;
}

/* TARJETAS */
.card{
    background:#ffffff;
    padding:35px;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.08);
}

/* TITULOS */
h1{
    margin-bottom:40px;
    text-align:center;
}

h2{
    margin-top:0;
}

/* BOTONES */
.btn{
    display:block;
    width:100%;
    text-align:center;
    padding:14px;
    margin-top:15px;
    border-radius:10px;
    font-weight:bold;
    text-decoration:none;
    color:#ffffff;
    transition:.2s;
}

.btn-blue{
    background:#2563eb;
}

.btn-green{
    background:#16a34a;
}

.btn-orange{
    background:#ea580c;
}

.btn:hover{
    opacity:.9;
}
</style>
</style>
</head>

<body>

<a class="logout" href="logout.php">Cerrar sesión</a>

<h1>⚙ Panel Administrador</h1>

<div class="container">

    <!-- ========================= -->
    <!-- PROGRAMA TÉCNICO -->
    <!-- ========================= -->
    <div class="card">
        <h2>📘 Programas Técnicos</h2>
        <p>Gestionar estructura técnica (Semestres → Units → Actividades).</p>

        <!-- 1. GESTIONAR -->
        <a class="btn btn-blue"
           href="../academic/programs_editor.php?program=prog_technical">
           Gestionar estructura
        </a>

        <!-- 2. CURSOS CREADOS -->
        <a class="btn btn-green"
           href="../academic/courses_manager.php?program=prog_technical">
           Cursos creados
        </a>

        <!-- 3. ASIGNACIONES -->
        <a class="btn btn-orange"
           href="../academic/assignments.php?program=prog_technical">
           Asignaciones (Docentes / Estudiantes)
        </a>
    </div>


    <!-- ========================= -->
    <!-- CURSOS DE INGLÉS -->
    <!-- ========================= -->
    <div class="card">
        <h2>🎓 Cursos de Inglés</h2>
        <p>Gestionar estructura inglés (Cursos → Units → Actividades).</p>

        <!-- 1. GESTIONAR -->
        <a class="btn btn-blue"
           href="../academic/programs_editor.php?program=prog_english_courses">
           Gestionar estructura
        </a>

        <!-- 2. CURSOS CREADOS -->
        <a class="btn btn-green"
           href="../academic/courses_manager.php?program=prog_english_courses">
           Cursos creados
        </a>

        <!-- 3. ASIGNACIONES -->
        <a class="btn btn-orange"
           href="../academic/assignments.php?program=prog_english_courses">
           Asignaciones (Docentes / Estudiantes)
        </a>
    </div>

</div>

</body>
</html>

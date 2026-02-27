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
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    padding:40px;
}

h1{
    margin-bottom:40px;
}

.container{
    display:flex;
    gap:40px;
}

.card{
    background:#ffffff;
    padding:30px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    width:420px;
}

.card h2{
    margin-bottom:10px;
}

.card p{
    margin-bottom:25px;
    color:#555;
}

.btn{
    display:block;
    width:100%;
    text-align:center;
    padding:12px;
    border-radius:10px;
    text-decoration:none;
    font-weight:600;
    margin-bottom:12px;
}

.btn-blue{
    background:#2563eb;
    color:#fff;
}

.btn-green{
    background:#16a34a;
    color:#fff;
}

.btn-orange{
    background:#ea580c;
    color:#fff;
}

.logout{
    position:absolute;
    top:40px;
    right:40px;
    color:red;
    text-decoration:none;
    font-weight:bold;
}
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

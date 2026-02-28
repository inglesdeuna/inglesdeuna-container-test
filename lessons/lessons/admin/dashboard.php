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
    background:#eef2f7;
    margin:0;
    padding:0;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:25px 60px;
}

.topbar h1{
    font-size:28px;
    margin:0;
}

.logout-btn{
    background:#ef4444;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:bold;
}

.container{
    width:1100px;
    margin:40px auto;
}

.card{
    background:#fff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.08);
    margin-bottom:40px;
}

.card h2{
    margin-top:0;
}

.card p{
    color:#555;
    margin-bottom:25px;
}

.btn{
    display:block;
    width:100%;
    padding:14px;
    margin-bottom:15px;
    border-radius:10px;
    text-align:center;
    text-decoration:none;
    font-weight:bold;
    color:#fff;
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
</style>
</head>

<body>

<div class="topbar">
    <h1>⚙️ Panel Administrador</h1>
    <a href="logout.php" class="logout-btn">Cerrar sesión</a>
</div>

<div class="container">

    <!-- ========================= -->
    <!-- PROGRAMA TÉCNICO -->
    <!-- ========================= -->
    <div class="card">
        <h2>📘 Programas Técnicos</h2>
        <p>Gestionar estructura técnica (Semestres → Units → Actividades).</p>

        <a class="btn btn-blue"
           href="../academic/courses_manager.php?program=prog_technical">
           Gestionar estructura
        </a>

        <a class="btn btn-green"
           href="../academic/technical_courses_created.php">
           Cursos creados
        </a>

        <a class="btn btn-orange"
           href="../academic/technical_assignments.php">
           Asignaciones (Docentes / Estudiantes)
        </a>
    </div>


    <!-- ========================= -->
    <!-- CURSOS DE INGLÉS -->
    <!-- ========================= -->
    <div class="card">
        <h2>🎓 Cursos de Inglés</h2>
        <p>Gestionar estructura inglés (Cursos → Units → Actividades).</p>

        <a class="btn btn-blue"
           href="../academic/courses_manager.php?program=prog_english_courses">
           Gestionar estructura
        </a>

        <a class="btn btn-green"
           href="../academic/english_courses_created.php">
           Cursos creados
        </a>

        <a class="btn btn-orange"
           href="../academic/english_assignments.php">
           Asignaciones (Docentes / Estudiantes)
        </a>
    </div>

</div>

</body>
</html>

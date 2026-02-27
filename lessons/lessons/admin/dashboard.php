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

.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:30px;
    max-width:800px;
}

.card-technical{
    border-left:6px solid #2563eb;
}

.card-english{
    border-left:6px solid #16a34a;
}

h2{
    margin-top:0;
}

.btn{
    display:inline-block;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    margin-right:10px;
    margin-top:10px;
}

.btn-blue{
    background:#2563eb;
    color:#ffffff;
}

.btn-green{
    background:#16a34a;
    color:#ffffff;
}

.btn-orange{
    background:#ea580c;
    color:#ffffff;
}
</style>

</head>
<body>

<h1>Panel Administrador</h1>

<!-- ============================= -->
<!-- PROGRAMA TÉCNICO -->
<!-- ============================= -->
<div class="card card-technical">

    <h2>📘 Programa Técnico</h2>
    <p>Gestionar semestres y unidades técnicas.</p>

    <a class="btn btn-blue"
       href="../academic/programs_editor.php?program=prog_technical">
       Gestionar estructura
    </a>

    <a class="btn btn-green"
       href="../academic/courses_manager.php?program=prog_technical">
       Cursos creados
    </a>

    <a class="btn btn-orange"
       href="../academic/create_course.php?program=prog_technical">
       Crear curso
    </a>

</div>


<!-- ============================= -->
<!-- CURSOS DE INGLÉS -->
<!-- ============================= -->
<div class="card card-english">

    <h2>🎓 Cursos de Inglés</h2>
    <p>Gestionar niveles y unidades de inglés.</p>

    <a class="btn btn-blue"
       href="../academic/programs_editor.php?program=prog_english_courses">
       Gestionar estructura
    </a>

    <a class="btn btn-green"
       href="../academic/courses_manager.php?program=prog_english_courses">
       Cursos creados
    </a>

    <a class="btn btn-orange"
       href="../academic/create_course.php?program=prog_english_courses">
       Crear curso
    </a>

</div>

</body>
</html>

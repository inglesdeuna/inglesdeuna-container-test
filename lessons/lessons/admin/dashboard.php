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
    font-family: "Segoe UI", Arial, sans-serif;
    background:#f4f6fb;
    margin:0;
    padding:0;
}

/* HEADER */
.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:25px 60px;
}

.header h1{
    font-size:28px;
    font-weight:700;
    margin:0;
}

.logout-btn{
    background:#ef4444;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    transition:.2s;
}

.logout-btn:hover{
    background:#dc2626;
}

/* CONTENEDOR PRINCIPAL */
.container{
    max-width:1100px;
    margin:0 auto 60px auto;
    display:flex;
    flex-direction:column;
    gap:40px;
}

/* TARJETAS */
.card{
    background:#ffffff;
    padding:35px 40px;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.07);
}

.card h2{
    margin:0 0 10px 0;
    font-size:22px;
}

.card p{
    margin:0 0 25px 0;
    color:#555;
}

/* BOTONES */
.btn{
    display:block;
    width:100%;
    padding:14px;
    border-radius:10px;
    text-align:center;
    text-decoration:none;
    font-weight:600;
    margin-bottom:15px;
    transition:.2s;
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

.btn-blue:hover{ background:#1d4ed8; }
.btn-green:hover{ background:#15803d; }
.btn-orange:hover{ background:#c2410c; }

</style>
</head>

<body>

<div class="header">
    <h1>⚙️ Panel Administrador</h1>
    <a href="logout.php" class="logout-btn">Cerrar sesión</a>
</div>

<div class="container">

    <!-- ========================== -->
    <!-- PROGRAMA TÉCNICO -->
    <!-- ========================== -->
    <div class="card">
        <h2>📘 Programas Técnicos</h2>
        <p>Gestionar estructura técnica (Semestres → Units → Actividades).</p>

        <a class="btn btn-blue"
           href="../academic/programs_editor.php?program=prog_technical">
            Gestionar estructura
        </a>

        <a class="btn btn-green"
           href="../academic/courses_manager.php?program=prog_technical">
            Cursos creados
        </a>

        <a class="btn btn-orange"
           href="../academic/assignments.php?program=prog_technical">
            Asignaciones (Docentes / Estudiantes)
        </a>
    </div>

    <!-- ========================== -->
    <!-- CURSOS DE INGLÉS -->
    <!-- ========================== -->
    <div class="card">
        <h2>🎓 Cursos de Inglés</h2>
        <p>Gestionar estructura inglés (Cursos → Units → Actividades).</p>

        <a class="btn btn-blue"
           href="../academic/programs_editor.php?program=prog_english_courses">
            Gestionar estructura
        </a>

        <a class="btn btn-green"
           href="../academic/courses_manager.php?program=prog_english_courses">
            Cursos creados
        </a>

        <a class="btn btn-orange"
           href="../academic/assignments.php?program=prog_english_courses">
            Asignaciones (Docentes / Estudiantes)
        </a>
    </div>

</div>

</body>
</html>

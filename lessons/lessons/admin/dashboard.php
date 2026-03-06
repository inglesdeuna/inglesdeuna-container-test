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
:root {
    --blue-1: #0d4ea7;
    --blue-2: #2d77db;
    --page-bg: #edf1f8;
    --card-bg: #f4f5f8;
    --text-main: #2f4460;
    --text-soft: #66748a;
    --line: #dde4ef;
    --btn-blue: #2d71d2;
    --btn-green: #4fb489;
    --btn-orange: #f3ba3b;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: "Segoe UI", Arial, sans-serif;
    background: var(--page-bg);
    color: var(--text-main);
}

.topbar {
    background: linear-gradient(90deg, var(--blue-1), #1a61bd 52%, var(--blue-2));
    color: #fff;
    padding: 12px 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    display: flex;
    align-items: center;
    gap: 18px;
}

.logo-wrap {
    width: 102px;
    height: 102px;
    position: relative;
    flex-shrink: 0;
}

.logo-badge {
    width: 100%;
    height: 100%;
    background: #fff;
    border-radius: 10px;
    border: 4px solid #f6d04d;
    box-shadow: 0 5px 16px rgba(9, 43, 91, .3);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    line-height: 1;
    font-weight: 800;
    position: relative;
    z-index: 2;
}

.logo-badge small {
    font-size: 13px;
    color: #4f5f75;
    margin-top: 4px;
}

.logo-badge .word {
    font-size: 16px;
    letter-spacing: .6px;
}

.logo-shadow-blue,
.logo-shadow-red,
.logo-shadow-green {
    position: absolute;
    border-radius: 10px;
    z-index: 1;
}

.logo-shadow-blue {
    width: 100%;
    height: 100%;
    left: -8px;
    bottom: -8px;
    background: #2f91df;
}

.logo-shadow-red {
    width: 100%;
    height: 100%;
    right: -8px;
    bottom: -8px;
    background: #dc3a32;
}

.logo-shadow-green {
    width: 100%;
    height: 100%;
    left: -8px;
    top: -8px;
    background: #8dbb2b;
}

.topbar h1 {
    margin: 0;
    font-size: 49px;
    font-weight: 700;
}

.logout-btn {
    text-decoration: none;
    color: #fff;
    font-weight: 700;
    font-size: 18px;
    padding: 12px 22px;
    border-radius: 10px;
    background: linear-gradient(180deg, #5aabf6, #4594ec);
}

.page {
    max-width: 1260px;
    margin: 18px auto 0;
    padding: 0 16px 24px;
}

.grid-two {
    display: grid;
    grid-template-columns: repeat(2, minmax(340px, 1fr));
    gap: 18px;
    margin-bottom: 18px;
}

.card {
    background: var(--card-bg);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 22px;
    box-shadow: 0 5px 14px rgba(51, 72, 107, 0.08);
}

.card h2,
.card h3 {
    margin: 0 0 10px;
    color: var(--text-main);
    font-size: 46px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card p {
    margin: 0 0 18px;
    color: var(--text-soft);
    font-size: 15px;
}

.btn {
    display: block;
    width: 100%;
    text-align: center;
    text-decoration: none;
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 10px;
}

.btn:last-child { margin-bottom: 0; }

.btn-blue { background: linear-gradient(90deg, #2a67c4, var(--btn-blue)); }
.btn-green { background: linear-gradient(90deg, #54b98e, var(--btn-green)); }
.btn-orange { background: linear-gradient(90deg, #f0b739, var(--btn-orange)); }

.assignment-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.input-like {
    background: #fff;
    border: 1px solid #d9dfeb;
    border-radius: 6px;
    padding: 10px 12px;
    color: #55647a;
    font-size: 14px;
}

.assignment-actions {
    margin-top: 10px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

@media (max-width: 980px) {
    .topbar {
        padding: 12px 16px;
    }

    .topbar h1 {
        font-size: 28px;
    }

    .logo-wrap {
        width: 70px;
        height: 70px;
    }

    .grid-two {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>
<header class="topbar">
    <div class="brand">
        <div class="logo-wrap" aria-hidden="true">
            <div class="logo-shadow-green"></div>
            <div class="logo-shadow-blue"></div>
            <div class="logo-shadow-red"></div>
            <div class="logo-badge">
                <div class="word">LET'S</div>
                <small>aprende</small>
                <div class="word" style="color:#2b63c7">Inglés</div>
            </div>
        </div>
        <h1>Panel Administrador</h1>
    </div>
    <a href="logout.php" class="logout-btn">Cerrar sesión</a>
</header>

<main class="page">
    <section class="grid-two">
        <article class="card">
            <h2>📘 Programas Técnicos</h2>
            <p>Gestionar estructura semestrales — Units — Actividades).</p>

            <a class="btn btn-blue" href="../academic/courses_manager.php?program=prog_technical">Gestionar estructura</a>
            <a class="btn btn-green" href="../academic/technical_courses_created.php">Cursos creados</a>
            <a class="btn btn-orange" href="../academic/technical_assignments.php">Asignaciones (Docentes / Estudiantes)</a>
        </article>

        <article class="card">
            <h2>🌎 Cursos de Inglés</h2>
            <p>Gestionar estructura (Levels, Phases – Actividades).</p>

            <a class="btn btn-blue" href="../academic/english_structure_levels.php">Gestionar estructura</a>
            <a class="btn btn-green" href="../academic/english_courses_created.php">Cursos creados</a>
            <a class="btn btn-orange" href="../academic/english_assignments.php">Asignaciones (Docentes / Estudiantes)</a>
        </article>
    </section>

    <section class="grid-two">
        <article class="card">
            <h3>🧑‍🏫 Asignación de Docentes</h3>
            <div class="assignment-grid">
                <div class="input-like">Seleccione un Docente</div>
                <div class="input-like">Elige un Curso</div>
                <div class="input-like">Elige un Curso</div>
                <div class="input-like">Selecciona un Semestre</div>
                <div class="input-like">Selecciona un Semestre</div>
            </div>
            <div class="assignment-actions">
                <a class="btn btn-blue" href="../academic/technical_assignments.php">Programa Técnico</a>
                <a class="btn btn-green" href="../academic/english_assignments.php">Cursos Inglés</a>
            </div>
        </article>

        <article class="card">
            <h3>🎓 Asignación de Estudiantes</h3>
            <div class="assignment-grid">
                <div class="input-like">Seleccione un Estudiante</div>
                <div class="input-like">Elige un Curso</div>
                <div class="input-like">Elige un Curso</div>
                <div class="input-like">Selecciona un Semestre</div>
                <div class="input-like">Selecciona un Semestre</div>
            </div>
            <div class="assignment-actions">
                <a class="btn btn-blue" href="../academic/technical_assignments.php">Programa Técnico</a>
                <a class="btn btn-green" href="../academic/english_assignments.php">Cursos Inglés</a>
            </div>
        </article>
    </section>
</main>

</body>
</html>

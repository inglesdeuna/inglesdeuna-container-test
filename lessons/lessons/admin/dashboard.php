<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: login.php');
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
  --blue-1:#0d4ea7; --blue-2:#2d77db; --page-bg:#edf1f8; --card-bg:#f4f5f8;
  --text-main:#2f4460; --text-soft:#66748a; --line:#dde4ef;
  --btn-blue:#2d71d2; --btn-green:#4fb489; --btn-orange:#f3ba3b;
  --btn-purple:#845ef7; --btn-pink:#e64980;
}
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",Arial,sans-serif; background:var(--page-bg); color:var(--text-main); }

.topbar{
  background:linear-gradient(90deg,var(--blue-1),#1a61bd 52%,var(--blue-2));
  color:#fff; padding:12px 56px; display:flex; align-items:center; justify-content:space-between;
}
.brand{ display:flex; align-items:center; gap:18px; }
.logo-wrap{ width:96px; height:96px; flex-shrink:0; border-radius:12px; overflow:hidden; box-shadow:0 5px 14px rgba(14,48,95,.25); background:#fff; }
.logo-wrap img{ width:100%; height:100%; object-fit:cover; display:block; }

.topbar h1{ margin:0; font-size:44px; font-weight:700; }
.logout-btn{
  text-decoration:none; color:#fff; font-weight:700; font-size:18px;
  padding:12px 22px; border-radius:10px; background:linear-gradient(180deg,#5aabf6,#4594ec);
}

.page{ max-width:1260px; margin:18px auto 0; padding:0 16px 24px; }
.grid-two{ display:grid; grid-template-columns:repeat(2,minmax(340px,1fr)); gap:18px; margin-bottom:18px; }
.card{
  background:var(--card-bg); border:1px solid var(--line); border-radius:16px; padding:22px;
  box-shadow:0 5px 14px rgba(51,72,107,.08);
}
.card h2{
  margin:0 0 10px; color:var(--text-main); font-size:42px; display:flex; align-items:center; gap:10px;
}
.card p{ margin:0 0 14px; color:var(--text-soft); font-size:15px; }

.btn{
  display:block; width:100%; text-align:center; text-decoration:none; color:#fff;
  font-size:21px; font-weight:700; border-radius:8px; padding:14px 14px; margin-bottom:12px;
}
.btn:last-child{ margin-bottom:0; }
.btn-blue{ background:linear-gradient(90deg,#2a67c4,var(--btn-blue)); }
.btn-green{ background:linear-gradient(90deg,#54b98e,var(--btn-green)); }
.btn-orange{ background:linear-gradient(90deg,#f0b739,var(--btn-orange)); }
.btn-purple{ background:linear-gradient(90deg,#6f46dd,var(--btn-purple)); }
.btn-pink{ background:linear-gradient(90deg,#d6336c,var(--btn-pink)); }

@media (max-width:980px){
  .topbar{ padding:12px 16px; }
  .topbar h1{ font-size:28px; }
  .logo-wrap{ width:70px; height:70px; }
  .grid-two{ grid-template-columns:1fr; }
  .btn{ font-size:13px; }
}
</style>
</head>
<body>
<header class="topbar">
  <div class="brand">
    <div class="logo-wrap" aria-label="Logo Let&apos;s Aprende Inglés">
      <img src="../hangman/assets/LETS%20NUEVO%20-%20copia.jpeg" alt="Logo Let's Aprende Inglés">
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
    </article>

    <article class="card">
      <h2>🌎 Cursos de Inglés</h2>
      <p>Gestionar estructura (Levels, Phases — Actividades).</p>
      <a class="btn btn-blue" href="../academic/english_structure_levels.php">Gestionar estructura</a>
      <a class="btn btn-green" href="../academic/english_courses_created.php">Cursos creados</a>
    </article>
  </section>

  <section class="grid-two">
    <article class="card">
      <h2>👩‍🏫 Docentes</h2>
      <p>Gestiona el flujo en dos pasos: primero inscripción y luego asignaciones.</p>
      <a class="btn btn-blue" href="../academic/teacher_enrollments.php">Inscripciones</a>
      <a class="btn btn-green" href="../academic/teacher_profiles.php">Asignaciones</a>
    </article>

    <article class="card">
      <h2>🎓 Estudiantes</h2>
      <p>Gestiona el flujo en dos pasos: primero inscripción y luego asignaciones.</p>
      <a class="btn btn-purple" href="../academic/student_enrollments.php">Inscripciones</a>
      <a class="btn btn-pink" href="../academic/student_assignments.php">Asignaciones</a>
    </article>
  </section>
</main>

</body>
</html>

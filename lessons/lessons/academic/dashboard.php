<?php
session_start();

/**
 * PANEL PRINCIPAL ADMIN
 * Acceso exclusivo para administradores
 */

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: login.php");
    exit;
}

/* ==========================
   CONEXIÓN A DB (PDO)
   ========================== */
require_once "../config/db.php";

/* ==========================
   OBTENER CURSOS DESDE DB
   ========================== */

// Programas Técnicos
$stmtTech = $pdo->prepare("
    SELECT id, name 
    FROM courses 
    WHERE program_id = :program
    ORDER BY name ASC
");
$stmtTech->execute(['program' => 'prog_technical']);
$technicalCourses = $stmtTech->fetchAll(PDO::FETCH_ASSOC);

// Cursos de Inglés
$stmtEng = $pdo->prepare("
    SELECT id, name 
    FROM courses 
    WHERE program_id = :program
    ORDER BY name ASC
");
$stmtEng->execute(['program' => 'prog_english']);
$englishCourses = $stmtEng->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Administrador</title>

<style>
body{font-family:Arial,sans-serif;background:#f4f8ff;padding:40px;}
h1{margin-bottom:30px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:30px;}
.card{background:#fff;padding:25px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);}
.card h2,.card h3{margin-top:0}
.card p{color:#555}
.card a,.card button{
  display:inline-block;margin-top:12px;padding:10px 18px;
  background:#2563eb;color:#fff;text-decoration:none;
  border-radius:8px;font-size:14px;border:none;cursor:pointer;
}
.card a.secondary{background:#16a34a}
.card a.warning{background:#d97706}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:40px;}
.topbar a{color:#dc2626;text-decoration:none;font-weight:bold;}
.hidden{display:none;}
.course-link{
  display:block;
  margin-bottom:10px;
  background:#16a34a;
  text-align:center;
}
.course-link.eng{
  background:#2563eb;
}
</style>

<script>
function toggleCursos(){
  const container = document.getElementById("cursosContainer");
  container.classList.toggle("hidden");
}
</script>
</head>
<body>

<div class="topbar">
  <h1>🎛️ Panel Administrador</h1>
  <a href="logout.php">🚪 Cerrar sesión</a>
</div>

<div class="grid">

  <div class="card">
    <h2>🧱 Estructura Académica</h2>
    <p>Define programas, cursos, niveles, unidades y actividades.</p>
    <a href="../academic/programs_editor.php">Gestionar estructura</a>
  </div>

  <div class="card">
    <h2>👥 Asignaciones</h2>
    <p>Ofrece los cursos por periodo y asigna docentes y estudiantes.</p>
    <a class="warning" href="../academic/assignments_editor.php">
      Asignar cursos
    </a>
  </div>

  <div class="card">
    <h2>📚 Cursos</h2>
    <p>Visualiza cursos por programa.</p>
    <button onclick="toggleCursos()">Ver cursos</button>
  </div>

</div>

<!-- ======================
     CONTENEDOR CURSOS
     ====================== -->
<div id="cursosContainer" class="hidden" style="margin-top:40px;">
  <div class="grid">

    <!-- ================== INGLÉS ================== -->
    <div class="card">
      <h3>🇺🇸 Cursos de Inglés</h3>

      <?php if (empty($englishCourses)): ?>
        <p>No hay cursos creados.</p>
      <?php else: ?>
        <?php foreach ($englishCourses as $course): ?>
          <a class="course-link eng"
             href="../academic/course_view.php?course=<?= htmlspecialchars($course['id']); ?>">
             <?= htmlspecialchars($course['name']); ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>

    <!-- ================== TÉCNICOS ================== -->
    <div class="card">
      <h3>💻 Programas Técnicos</h3>

      <?php if (empty($technicalCourses)): ?>
        <p>No hay cursos creados.</p>
      <?php else: ?>
        <?php foreach ($technicalCourses as $course): ?>
          <a class="course-link"
             href="../academic/course_view.php?course=<?= htmlspecialchars($course['id']); ?>">
             <?= htmlspecialchars($course['name']); ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>

  </div>
</div>

</body>
</html>

<?php
session_start();

/**
 * PANEL PRINCIPAL ADMIN
 * Acceso exclusivo para administradores
 */

// 🔐 VALIDACIÓN ESTRICTA
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: login.php");
    exit;
}

/* ==========================
   CARGA DE DATOS
   ========================== */
$baseDir = __DIR__ . "/data";

$coursesFile     = $baseDir . "/courses.json";
$assignmentsFile = $baseDir . "/assignments.json";
$programsFile    = $baseDir . "/programs.json";

$courses     = file_exists($coursesFile) ? json_decode(file_get_contents($coursesFile), true) : [];
$assignments = file_exists($assignmentsFile) ? json_decode(file_get_contents($assignmentsFile), true) : [];
$programs    = file_exists($programsFile) ? json_decode(file_get_contents($programsFile), true) : [];

/* ==========================
   FILTRO POR CATEGORÍA
   ========================== */
$filter = $_GET['filter'] ?? null;

/* ==========================
   HELPERS
   ========================== */
function getCourseAssignments($courseId, $assignments) {
    return array_filter($assignments, function ($a) use ($courseId) {
        return ($a['course_id'] ?? null) === $courseId;
    });
}

function getProgramName($programId, $programs) {
    foreach ($programs as $p) {
        if (($p['id'] ?? null) === $programId) {
            return $p['name'];
        }
    }
    return '';
}
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
.status-active{color:#16a34a;font-weight:700}
.status-inactive{color:#dc2626;font-weight:700}
.hidden{display:none;}
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

<!-- ======================
     BLOQUE PRINCIPAL
     ====================== -->
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
    <p>Visualiza cursos por categoría académica.</p>
    <button onclick="toggleCursos()">Ver categorías</button>
  </div>

</div>

<!-- ======================
     CONTENEDOR CATEGORÍAS
     ====================== -->
<div id="cursosContainer" class="hidden" style="margin-top:40px;">
  <div class="grid">

    <div class="card">
      <h3>🇺🇸 Cursos de Inglés</h3>
      <a href="dashboard.php?filter=phase1">Phase 1</a>
      <a href="dashboard.php?filter=phase2">Phase 2</a>
      <a href="dashboard.php?filter=phase3">Phase 3</a>
    </div>

    <div class="card">
      <h3>💻 Programas Técnicos</h3>
      <a class="secondary" href="dashboard.php?filter=sem1">Semestre 1</a>
      <a class="secondary" href="dashboard.php?filter=sem2">Semestre 2</a>
      <a class="secondary" href="dashboard.php?filter=sem3">Semestre 3</a>
      <a class="secondary" href="dashboard.php?filter=sem4">Semestre 4</a>
    </div>

  </div>
</div>

<!-- ======================
     CURSOS CREADOS
     ====================== -->
<h2 style="margin-top:60px">📘 Cursos</h2>

<?php
if ($filter) {
    $courses = array_filter($courses, function($c) use ($filter, $programs) {

        $programName = '';

        foreach ($programs as $p) {
            if (($p['id'] ?? null) === ($c['program_id'] ?? null)) {
                $programName = strtolower($p['name']);
                break;
            }
        }

        // Filtros Inglés
        if (in_array($filter, ['phase1','phase2','phase3'])) {
            return str_contains($programName, 'phase ' . substr($filter, -1));
        }

        // Filtros Técnicos
        if (in_array($filter, ['sem1','sem2','sem3','sem4'])) {
            return str_contains($programName, 'semestre ' . substr($filter, -1));
        }

        return true;
    });
}
?>

<?php if (empty($courses)): ?>
  <p>No hay cursos en esta categoría.</p>
<?php else: ?>

<div class="grid">
<?php foreach ($courses as $c): ?>

  <?php
    $courseAssignments = getCourseAssignments($c['id'], $assignments);
    $isActive = !empty($courseAssignments);
    $programName = getProgramName($c['program_id'], $programs);
    $periods = array_unique(array_column($courseAssignments, 'period'));
  ?>

  <div class="card">
    <h3><?= htmlspecialchars($c['name']) ?></h3>

    <p>Programa: <strong><?= htmlspecialchars($programName) ?></strong></p>

    <?php if ($isActive): ?>
      <p class="status-active">🟢 Activo</p>
      <p style="font-size:14px">
        Periodos: <?= htmlspecialchars(implode(", ", $periods)) ?>
      </p>
    <?php else: ?>
      <p class="status-inactive">🔴 Sin asignar</p>
    <?php endif; ?>

    <a href="../academic/modules_editor.php?course=<?= urlencode($c['id']) ?>">
      ✏️ Editar estructura
    </a>
    <br>
    <a class="secondary" href="../academic/assignments_editor.php">
      👥 Asignar curso
    </a>
  </div>

<?php endforeach; ?>
</div>

<?php endif; ?>

</body>
</html>

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
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{margin-bottom:30px}
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:30px;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
.card h2, .card h3{margin-top:0}
.card p{color:#555}
.card a{
  display:inline-block;
  margin-top:12px;
  padding:10px 18px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  border-radius:8px;
  font-size:14px;
}
.card a.secondary{background:#16a34a}
.card a.warning{background:#d97706}
.card a.gray{background:#6b7280}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:40px;
}
.topbar a{
  color:#dc2626;
  text-decoration:none;
  font-weight:bold;
}
.status-active{color:#16a34a;font-weight:700}
.status-inactive{color:#dc2626;font-weight:700}
</style>
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
    <p>
      Define la <strong>estructura académica</strong>:
      programas, cursos, niveles, unidades y actividades.
    </p>
    <a href="../academic/programs_editor.php">
      Gestionar estructura
    </a>
  </div>

  <div class="card">
    <h2>👥 Asignaciones</h2>
    <p>
      Ofrece los cursos por periodo (A / B) y asigna
      <strong>docentes y estudiantes</strong>.
    </p>
    <a class="warning" href="../academic/assignments_editor.php">
      Asignar cursos
    </a>
  </div>

</div>

<!-- ======================
     CURSOS CREADOS
     ====================== -->
<h2 style="margin-top:60px">📘 Cursos</h2>

<?php if (empty($courses)): ?>
  <p>No hay cursos creados aún.</p>
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

    <p>
      Programa: <strong><?= htmlspecialchars($programName) ?></strong>
    </p>

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

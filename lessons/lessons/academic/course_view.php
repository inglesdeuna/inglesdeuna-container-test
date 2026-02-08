<?php
session_start();

/**
 * COURSE VIEW
 * Vista principal de un curso
 */

// 🔐 ADMIN o DOCENTE
if (
  !isset($_SESSION["admin_logged"]) &&
  !isset($_SESSION["academic_logged"])
) {
  header("Location: login.php");
  exit;
}

/* ===============================
   VALIDAR CURSO
   =============================== */
$courseId = $_GET["course"] ?? null;
if (!$courseId) {
  die("Curso no especificado");
}

/* ===============================
   DATA
   =============================== */
$baseDir = __DIR__ . "/data";
$coursesFile = $baseDir . "/courses.json";
$unitsFile   = $baseDir . "/units.json";

if (!file_exists($coursesFile)) {
  die("Archivo de cursos no encontrado");
}

$courses = json_decode(file_get_contents($coursesFile), true);
$courses = is_array($courses) ? $courses : [];

/* ===============================
   BUSCAR CURSO
   =============================== */
$course = null;
foreach ($courses as $c) {
  if (($c["id"] ?? null) === $courseId) {
    $course = $c;
    break;
  }
}

if (!$course) {
  die("Curso no encontrado");
}

/* ===============================
   UNIDADES DEL CURSO
   =============================== */
$units = [];
if (file_exists($unitsFile)) {
  $allUnits = json_decode(file_get_contents($unitsFile), true) ?? [];
  foreach ($allUnits as $u) {
    if (($u["course_id"] ?? null) === $courseId) {
      $units[] = $u;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?></title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px}
.card{background:#fff;padding:25px;border-radius:14px;max-width:800px}
.unit{background:#fff;padding:15px;border-radius:10px;margin-top:10px;display:flex;justify-content:space-between;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{color:#2563eb;text-decoration:none;font-weight:bold}
.btn{display:inline-block;margin-top:20px;padding:12px 18px;background:#2563eb;color:#fff;border-radius:10px;text-decoration:none;font-weight:700}
</style>
</head>

<body>

<div class="top">
  <h1>📘 <?= htmlspecialchars($course["name"]) ?></h1>
  <a href="courses_manager.php?program=<?= urlencode($course["program_id"] ?? "") ?>">← Volver</a>
</div>

<div class="card">
  <h2>📚 Unidades</h2>

  <?php if (empty($units)): ?>
    <p>No hay unidades creadas.</p>
  <?php else: ?>
    <?php foreach ($units as $u): ?>
      <div class="unit">
        <strong><?= htmlspecialchars($u["name"]) ?></strong>
        <a href="unit_view.php?unit=<?= urlencode($u["id"]) ?>">Abrir →</a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <a class="btn" href="units_editor.php?course=<?= urlencode($courseId) ?>">
    ➕ Crear unidad
  </a>
</div>

</body>
</html>

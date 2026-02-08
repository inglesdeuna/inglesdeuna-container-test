<?php
/* ==========================
   CONTEXTO: CURSO
   ========================== */
$courseId = $_GET['course'] ?? null;
if (!$courseId) {
  die("Curso no especificado");
}

/* ==========================
   DATA
   ========================== */
$baseDir = dirname(__DIR__) . "/admin/data";
$coursesFile = $baseDir . "/courses.json";
$programsFile = $baseDir . "/programs.json";
$modulesFile  = $baseDir . "/modules.json";

foreach ([$modulesFile] as $f) {
  if (!file_exists($f)) file_put_contents($f, "[]");
}

$courses  = json_decode(file_get_contents($coursesFile), true) ?? [];
$programs = json_decode(file_get_contents($programsFile), true) ?? [];
$modules  = json_decode(file_get_contents($modulesFile), true) ?? [];

/* ==========================
   CURSO Y PROGRAMA
   ========================== */
$course = null;
foreach ($courses as $c) {
  if ($c['id'] === $courseId) {
    $course = $c;
    break;
  }
}
if (!$course) die("Curso no encontrado");

$program = null;
foreach ($programs as $p) {
  if ($p['id'] === $course['program_id']) {
    $program = $p;
    break;
  }
}
if (!$program) die("Programa no encontrado");

/* ==========================
   CATÁLOGOS
   ========================== */
$mcrLevels = ["A1", "A2", "B1", "B2", "C1"];

/* ==========================
   GUARDAR
   ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if ($program['type'] === 'technical') {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
      $modules[] = [
        "id"        => uniqid("module_"),
        "course_id"=> $courseId,
        "name"      => $name
      ];
    }
  } else {
    $selected = $_POST['levels'] ?? [];
    foreach ($selected as $lvl) {

      // evitar duplicados
      $exists = false;
      foreach ($modules as $m) {
        if (
          $m['course_id'] === $courseId &&
          $m['name'] === $lvl
        ) {
          $exists = true;
          break;
        }
      }

      if (!$exists) {
        $modules[] = [
          "id"        => uniqid("module_"),
          "course_id"=> $courseId,
          "name"      => $lvl
        ];
      }
    }
  }

  file_put_contents(
    $modulesFile,
    json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  header("Location: units_editor.php?course=" . urlencode($courseId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Módulos</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:14px;max-width:600px}
label{display:block;margin:8px 0}
button{margin-top:20px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
</style>
</head>
<body>

<h1>📦 Módulos</h1>
<p><strong>Curso:</strong> <?= htmlspecialchars($course['name']) ?></p>

<div class="card">
<form method="post">

<?php if ($program['type'] === 'technical'): ?>

  <input type="text" name="name"
    placeholder="Nombre del módulo (ej: Didáctica para preescolar)" required>

<?php else: ?>

  <?php foreach ($mcrLevels as $lvl): ?>
    <label>
      <input type="checkbox" name="levels[]" value="<?= $lvl ?>">
      Nivel <?= $lvl ?>
    </label>
  <?php endforeach; ?>

<?php endif; ?>

  <button>Guardar</button>
</form>
</div>

</body>
</html>

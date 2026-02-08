<?php
/* ==========================
   CARGAR PROGRAMA SELECCIONADO
   ========================== */
$programId = $_GET['program'] ?? null;

if (!$programId) {
  die("Programa no especificado");
}

/* ==========================
   DATA
   ========================== */
$baseDir = dirname(__DIR__) . "/admin/data";
$programsFile = $baseDir . "/programs.json";
$coursesFile  = $baseDir . "/courses.json";

if (!file_exists($coursesFile)) {
  file_put_contents($coursesFile, "[]");
}

$courses = json_decode(file_get_contents($coursesFile), true) ?? [];

/* ==========================
   CARGAR PROGRAMA
   ========================== */
$programs = json_decode(file_get_contents($programsFile), true) ?? [];
$program = null;

foreach ($programs as $p) {
  if (($p['id'] ?? null) === $programId) {
    $program = $p;
    break;
  }
}

if (!$program) {
  die("Programa no encontrado");
}

/* ==========================
   CATÁLOGOS
   ========================== */
$catalogInstitute = [
  "Basic 1", "Basic 2", "Basic 3",
  "Intermediate 1", "Intermediate 2",
  "Advanced 1", "Advanced 2"
];

$catalogTechnical = [
  "Semestre 1",
  "Semestre 2",
  "Semestre 3",
  "Semestre 4",
  "Práctica"
];

/* ==========================
   GUARDAR CURSOS
   ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $selected = $_POST['courses'] ?? [];

  foreach ($selected as $name) {

    // evitar duplicados
    $exists = false;
    foreach ($courses as $c) {
      if ($c['name'] === $name && $c['program_id'] === $programId) {
        $exists = true;
        break;
      }
    }

    if (!$exists) {
      $courses[] = [
        "id"         => uniqid("course_"),
        "program_id"=> $programId,
        "name"       => $name,
        "active"     => true
      ];
    }
  }

  file_put_contents(
    $coursesFile,
    json_encode($courses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  header("Location: semesters_editor.php?program=" . urlencode($programId));
  exit;
}

/* ==========================
   CATÁLOGO A USAR
   ========================== */
$catalog = ($program['type'] === 'technical')
  ? $catalogTechnical
  : $catalogInstitute;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Levels Manager</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:14px;max-width:600px}
label{display:block;margin:8px 0}
button{margin-top:20px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
</style>
</head>
<body>

<h1>📘 Levels Manager</h1>
<p><strong>Programa:</strong> <?= htmlspecialchars($program['name']) ?></p>

<div class="card">
<form method="post">

  <?php foreach ($catalog as $item): ?>
    <label>
      <input type="checkbox" name="courses[]" value="<?= htmlspecialchars($item) ?>">
      <?= htmlspecialchars($item) ?>
    </label>
  <?php endforeach; ?>

  <button>Guardar cursos</button>
</form>
</div>

</body>
</html>

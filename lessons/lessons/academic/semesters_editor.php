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
$coursesFile   = $baseDir . "/courses.json";
$periodsFile   = $baseDir . "/course_periods.json";

if (!file_exists($periodsFile)) {
  file_put_contents($periodsFile, "[]");
}

$courses = json_decode(file_get_contents($coursesFile), true) ?? [];
$periods = json_decode(file_get_contents($periodsFile), true) ?? [];

/* ==========================
   CURSO
   ========================== */
$course = null;
foreach ($courses as $c) {
  if (($c['id'] ?? null) === $courseId) {
    $course = $c;
    break;
  }
}
if (!$course) die("Curso no encontrado");

/* ==========================
   CATÁLOGO DE PERIODOS
   ========================== */
$catalog = [
  "A", "B"
];

/* ==========================
   GUARDAR
   ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $selected = $_POST['periods'] ?? [];

  foreach ($selected as $p) {

    // evitar duplicados
    $exists = false;
    foreach ($periods as $cp) {
      if (
        $cp['course_id'] === $courseId &&
        $cp['period'] === $p
      ) {
        $exists = true;
        break;
      }
    }

    if (!$exists) {
      $periods[] = [
        "id"        => uniqid("period_"),
        "course_id"=> $courseId,
        "period"   => $p
      ];
    }
  }

  file_put_contents(
    $periodsFile,
    json_encode($periods, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  header("Location: ../admin/dashboard.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignar Periodos</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:14px;max-width:500px}
label{display:block;margin:10px 0}
button{margin-top:20px;padding:12px 18px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-weight:700}
</style>
</head>
<body>

<h1>🗓️ Periodos del curso</h1>
<p><strong>Curso:</strong> <?= htmlspecialchars($course['name']) ?></p>

<div class="card">
<form method="post">

  <?php foreach ($catalog as $p): ?>
    <label>
      <input type="checkbox" name="periods[]" value="<?= $p ?>">
      Periodo <?= $p ?>
    </label>
  <?php endforeach; ?>

  <button>Guardar periodos</button>
</form>
</div>

</body>
</html>

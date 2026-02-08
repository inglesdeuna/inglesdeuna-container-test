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
$modulesFile = $baseDir . "/modules.json";
$unitsFile   = $baseDir . "/units.json";

foreach ([$unitsFile] as $f) {
  if (!file_exists($f)) file_put_contents($f, "[]");
}

$courses = json_decode(file_get_contents($coursesFile), true) ?? [];
$modules = json_decode(file_get_contents($modulesFile), true) ?? [];
$units   = json_decode(file_get_contents($unitsFile), true) ?? [];

/* ==========================
   CURSO
   ========================== */
$course = null;
foreach ($courses as $c) {
  if ($c['id'] === $courseId) {
    $course = $c;
    break;
  }
}
if (!$course) die("Curso no encontrado");

/* ==========================
   MÓDULOS DEL CURSO
   ========================== */
$courseModules = array_filter($modules, function ($m) use ($courseId) {
  return $m['course_id'] === $courseId;
});

/* ==========================
   GUARDAR UNIDAD
   ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $moduleId = $_POST['module_id'] ?? '';
  $name     = trim($_POST['name'] ?? '');

  if ($moduleId !== '' && $name !== '') {
    $units[] = [
      "id"        => uniqid("unit_"),
      "course_id"=> $courseId,
      "module_id"=> $moduleId,
      "name"      => $name,
      "activities"=> []
    ];

    file_put_contents(
      $unitsFile,
      json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: units_editor.php?course=" . urlencode($courseId));
  exit;
}

/* ==========================
   UNIDADES DEL CURSO
   ========================== */
$courseUnits = array_filter($units, function ($u) use ($courseId) {
  return $u['course_id'] === $courseId;
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unidades</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:14px;max-width:700px}
.item{background:#fff;padding:15px;border-radius:10px;margin-top:10px;box-shadow:0 4px 8px rgba(0,0,0,.08)}
button,a.btn{margin-top:15px;padding:10px 16px;border-radius:8px;font-weight:700;text-decoration:none}
button{background:#2563eb;color:#fff;border:none}
a.btn{background:#16a34a;color:#fff}
</style>
</head>
<body>

<h1>📚 Unidades</h1>
<p><strong>Curso:</strong> <?= htmlspecialchars($course['name']) ?></p>

<div class="card">
<form method="post">

  <select name="module_id" required>
    <option value="">Seleccionar módulo / nivel</option>
    <?php foreach ($courseModules as $m): ?>
      <option value="<?= $m['id'] ?>">
        <?= htmlspecialchars($m['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <input type="text" name="name"
    placeholder="Nombre de la unidad (ej: Unit 1 – Greetings)" required>

  <button>Crear unidad</button>
</form>
</div>

<h2>📋 Unidades creadas</h2>

<?php if (empty($courseUnits)): ?>
  <p>No hay unidades aún.</p>
<?php endif; ?>

<?php foreach ($courseUnits as $u): ?>
  <div class="item">
    <strong><?= htmlspecialchars($u['name']) ?></strong>
    <div style="margin-top:10px">
      <a class="btn"
         href="/lessons/lessons/activities/hub/index.php?unit=<?= urlencode($u['id']) ?>">
        📦 Actividades
      </a>
    </div>
  </div>
<?php endforeach; ?>

<br>
<a href="../admin/dashboard.php">⬅ Volver al panel</a>

</body>
</html>

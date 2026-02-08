<?php
session_start();

/**
 * UNITS EDITOR
 * Crear unidades asociadas a un curso
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ==========================
   VALIDAR CURSO
   ========================== */
$courseId = $_GET["course"] ?? null;
if (!$courseId) {
    die("Curso no especificado");
}

/* ==========================
   DATA
   ========================== */
$baseDir = __DIR__ . "/data";

$coursesFile = $baseDir . "/courses.json";
$unitsFile   = $baseDir . "/units.json";

if (!file_exists($unitsFile)) {
    file_put_contents($unitsFile, "[]");
}

$courses = json_decode(file_get_contents($coursesFile), true) ?? [];
$units   = json_decode(file_get_contents($unitsFile), true) ?? [];

$courses = is_array($courses) ? $courses : [];
$units   = is_array($units)   ? $units   : [];

/* ==========================
   BUSCAR CURSO
   ========================== */
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

/* ==========================
   GUARDAR UNIDAD
   ========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");

    if ($name !== "") {
        $units[] = [
            "id"        => uniqid("unit_"),
            "course_id"=> $courseId,
            "name"      => $name,
            "activities"=> []
        ];

        file_put_contents(
            $unitsFile,
            json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        header("Location: units_editor.php?course=" . urlencode($courseId));
        exit;
    }
}

/* ==========================
   UNIDADES DEL CURSO
   ========================== */
$courseUnits = array_filter($units, function ($u) use ($courseId) {
    return ($u["course_id"] ?? null) === $courseId;
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unidades</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{color:#2563eb;}
.card{
  background:#fff;
  padding:25px;
  border-radius:16px;
  max-width:600px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
input{
  width:100%;
  padding:12px;
  margin-top:10px;
  font-size:16px;
}
button{
  margin-top:15px;
  padding:12px 18px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:10px;
  font-weight:700;
}
.list{
  margin-top:30px;
  max-width:600px;
}
.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  margin-bottom:10px;
  box-shadow:0 4px 8px rgba(0,0,0,.08);
  display:flex;
  justify-content:space-between;
}
a{
  color:#2563eb;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>📚 Unidades — <?= htmlspecialchars($course["name"]) ?></h1>

<div class="card">
  <form method="post">
    <input type="text" name="name" placeholder="Nombre de la unidad (ej: Unit 1)" required>
    <button>➕ Crear Unidad</button>
  </form>
</div>

<div class="list">
  <h2>📋 Unidades creadas</h2>

  <?php if (empty($courseUnits)): ?>
    <p>No hay unidades creadas.</p>
  <?php else: ?>
    <?php foreach ($courseUnits as $u): ?>
      <div class="item">
        <strong><?= htmlspecialchars($u["name"]) ?></strong>
        <a href="unit_view.php?unit=<?= urlencode($u["id"]) ?>">Abrir →</a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</body>
</html>

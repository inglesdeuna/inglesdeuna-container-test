<?php
/* ===== RUTAS SEGURAS ===== */
$baseDir = "/var/www/html/lessons/data";
$unitsFile       = $baseDir . "/units.json";
$assignmentsFile = $baseDir . "/assignments.json";

/* ===== ASEGURAR DATA ===== */
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}
foreach ([$unitsFile, $assignmentsFile] as $f) {
  if (!file_exists($f)) file_put_contents($f, "[]");
}

/* ===== CARGAR DATOS ===== */
$units       = json_decode(file_get_contents($unitsFile), true) ?? [];
$assignments = json_decode(file_get_contents($assignmentsFile), true) ?? [];

/* ===== TIPOS DE ACTIVIDAD ===== */
$activityTypes = [
  "flashcards"     => "Flashcards",
  "pronunciation" => "Pronunciation",
  "unscramble"    => "Unscramble",
  "drag_drop"     => "Drag & Drop",
  "listen_order"  => "Listen & Order",
  "match"         => "Match"
];

/* ===== GUARDAR ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $unit_id = $_POST["unit_id"] ?? "";
  $type    = $_POST["type"] ?? "";
  $title   = trim($_POST["title"] ?? "");
  $url     = trim($_POST["url"] ?? "");

  if ($unit_id && $type && $title && $url) {
    $assignments[] = [
      "id"       => uniqid("asg_"),
      "unit_id"  => $unit_id,
      "type"     => $type,
      "title"    => $title,
      "url"      => $url
    ];

    file_put_contents(
      $assignmentsFile,
      json_encode($assignments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: assignments_editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignaciones</title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{color:#2563eb;}
.card{
  background:white;
  padding:20px;
  border-radius:14px;
  max-width:650px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
input, select{
  width:100%;
  padding:10px;
  margin-top:10px;
}
button{
  margin-top:15px;
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
}
.list{
  margin-top:30px;
  max-width:650px;
}
.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  margin-bottom:10px;
  box-shadow:0 4px 8px rgba(0,0,0,.08);
}
small{color:#555;}
</style>
</head>

<body>

<h1>📝 Asignaciones</h1>

<div class="card">
  <form method="post">

    <select name="unit_id" required>
      <option value="">Seleccionar unidad</option>
      <?php foreach ($units as $u): ?>
        <option value="<?= htmlspecialchars($u["id"]) ?>">
          <?= htmlspecialchars($u["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="type" required>
      <option value="">Tipo de actividad</option>
      <?php foreach ($activityTypes as $k => $v): ?>
        <option value="<?= $k ?>"><?= $v ?></option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="title"
      placeholder="Título de la actividad (ej: Basic Commands – Flashcards)" required>

    <input type="url" name="url"
      placeholder="URL del viewer de la actividad" required>

    <button>➕ Crear Asignación</button>

  </form>
</div>

<div class="list">
  <h2>📋 Asignaciones creadas</h2>

  <?php foreach ($assignments as $a): ?>
    <div class="item">
      <strong><?= htmlspecialchars($a["title"]) ?></strong><br>
      <small><?= htmlspecialchars($activityTypes[$a["type"]] ?? $a["type"]) ?></small>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>

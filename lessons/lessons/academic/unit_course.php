<?php
<?php
$baseDir = "/var/www/html/lessons/lessons/data";

$unitsFile       = $baseDir . "/units.json";
$assignmentsFile = $baseDir . "/assignments.json";

/* ===== ASEGURAR DATA ===== */
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}
if (!file_exists($unitsFile)) {
  file_put_contents($unitsFile, "[]");
}
if (!file_exists($assignmentsFile)) {
  file_put_contents($assignmentsFile, "[]");
}

/* ===== CARGAR DATA ===== */
$units       = json_decode(file_get_contents($unitsFile), true) ?? [];
$assignments = json_decode(file_get_contents($assignmentsFile), true) ?? [];

$unit_id = $_GET["unit"] ?? "";

/* ===== BUSCAR UNIDAD ===== */
$unit = null;
foreach ($units as $u) {
  if (($u["id"] ?? "") === $unit_id) {
    $unit = $u;
    break;
  }
}

if (!$unit) {
  die("Unidad no encontrada");
}

/* ===== MAPA DE ACTIVIDADES ===== */
$activityMap = [
  "flashcards"     => ["Flashcards",     "../activities/flashcards/"],
  "pronunciation" => ["Pronunciation",  "../activities/pronunciation/"],
  "unscramble"    => ["Unscramble",     "../activities/unscramble/"],
  "drag_drop"     => ["Drag & Drop",    "../activities/drag_drop/"],
  "listen_order"  => ["Listen & Order", "../activities/listen_order/"],
  "match"         => ["Match",          "../activities/match/"]
];

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($unit["name"]) ?></title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{color:#2563eb;}

.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
  gap:20px;
}

.card{
  background:#fff;
  padding:20px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.card h3{
  margin-top:0;
}

.card a{
  display:inline-block;
  margin-top:10px;
  padding:10px 16px;
  border-radius:10px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  font-weight:bold;
  font-size:14px;
}

.card a + a{
  margin-left:8px;
}
</style>
</head>

<body>

<h1>📘 <?= htmlspecialchars($unit["name"]) ?></h1>

<div class="grid">

<?php
$has = false;

foreach ($assignments as $a):
  if ($a["unit_id"] === $unit_id):
    $has = true;
    $type = $a["activity_type"];

    if (!isset($activityMap[$type])) continue;

    [$label, $path] = $activityMap[$type];
?>
  <div class="card">
    <h3><?= $label ?></h3>

    <a href="<?= $path ?>viewer.php" target="_blank">
      👀 Preview
    </a>

    <a href="<?= $path ?>editor.php" target="_blank">
      ✏️ Editar
    </a>
  </div>
<?php
  endif;
endforeach;

if (!$has):
?>
  <p>No hay actividades asignadas a esta unidad.</p>
<?php endif; ?>

</div>

</body>
</html>

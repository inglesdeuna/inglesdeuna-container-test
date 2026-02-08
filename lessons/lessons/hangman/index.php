<?php
session_start();

/* =====================
   VALIDAR PARÁMETRO
   ===================== */
$unitId = $_GET["unit"] ?? null;

if (!$unitId) {
  die("Unidad no especificada");
}

/* =====================
   RUTA REAL A DATA
   ===================== */
/*
 Estructura confirmada del proyecto:

 lessons/
   lessons/
     academic/
     hangman/
     data/
       units.json
       modules.json
*/

$baseDir   = dirname(__DIR__) . "/data";
$unitsFile = $baseDir . "/units.json";

/* =====================
   VALIDAR ARCHIVO
   ===================== */
if (!file_exists($unitsFile)) {
  die("Archivo de unidades no encontrado");
}

/* =====================
   CARGAR UNIDADES
   ===================== */
$units = json_decode(file_get_contents($unitsFile), true);

if (!is_array($units)) {
  $units = [];
}

/* =====================
   BUSCAR UNIDAD
   ===================== */
$unitIndex = null;

foreach ($units as $i => $u) {
  if (($u["id"] ?? null) === $unitId) {
    $unitIndex = $i;
    break;
  }
}

if ($unitIndex === null) {
  die("Unidad no encontrada");
}

/* =====================
   ASEGURAR ACTIVIDADES
   ===================== */
if (
  !isset($units[$unitIndex]["activities"]) ||
  !is_array($units[$unitIndex]["activities"])
) {
  $units[$unitIndex]["activities"] = [];
}

/* =====================
   GUARDAR ACTIVIDAD
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $word = trim($_POST["word"] ?? "");

  if ($word !== "") {
    $units[$unitIndex]["activities"][] = [
      "id"   => uniqid("act_"),
      "type" => "hangman",
      "data" => [
        "word" => $word
      ]
    ];

    file_put_contents(
      $unitsFile,
      json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: index.php?unit=" . urlencode($unitId));
  exit;
}

/* =====================
   ACTIVIDADES EXISTENTES
   ===================== */
$activities = $units[$unitIndex]["activities"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman – Editor</title>
<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
.box{
  background:#fff;
  padding:25px;
  border-radius:14px;
  max-width:500px;
  box-shadow:0 10px 25px rgba(0,0,0,.1);
}
.item{
  margin-top:8px;
  padding:8px;
  background:#eef2ff;
  border-radius:6px;
}
input, button{
  padding:10px;
  margin-top:8px;
}
button{
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:6px;
  cursor:pointer;
}
</style>
</head>
<body>

<div class="box">
  <h2>🎯 Hangman – Editor</h2>

  <form method="post">
    <input type="text" name="word" placeholder="Palabra" required>
    <br>
    <button type="submit">Guardar actividad</button>
  </form>

  <hr>

  <h3>Actividades guardadas</h3>

  <?php if (empty($activities)): ?>
    <p>No hay actividades aún.</p>
  <?php else: ?>
    <?php foreach ($activities as $a): ?>
      <?php if (($a["type"] ?? "") === "hangman"): ?>
        <div class="item">
          <?= htmlspecialchars($a["data"]["word"] ?? "") ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>

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
   ARCHIVO DE UNIDADES
   ===================== */
$baseDir = "/var/www/html/lessons/data";
$file = $baseDir . "/units.json";

if (!file_exists($file)) {
  die("Archivo de unidades no encontrado");
}

$units = json_decode(file_get_contents($file), true) ?? [];
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
      $file,
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
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:25px;border-radius:14px;max-width:500px}
.item{margin-top:8px;padding:8px;background:#eef2ff;border-radius:6px}
</style>
</head>
<body>

<div class="box">
  <h2>🎯 Hangman – Editor</h2>

  <form method="post">
    <input type="text" name="word" placeholder="Palabra" required>
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

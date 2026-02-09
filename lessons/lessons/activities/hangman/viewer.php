<?php
$unitId = $_GET['unit'] ?? null;
if (!$unitId) die("Unidad no especificada");

$unitsFile = dirname(__DIR__, 2) . "/admin/data/units.json";
$units = json_decode(file_get_contents($unitsFile), true) ?? [];

$words = [];

foreach ($units as $u) {
  if (($u['id'] ?? '') === $unitId) {
    foreach ($u['activities'] ?? [] as $a) {
      if (($a['type'] ?? '') === 'hangman') {
        $words[] = $a['data'];
      }
    }
  }
}

if (empty($words)) {
  die("No hangman activity available");
}

// palabra random
$entry = $words[array_rand($words)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman</title>
<style>
body{text-align:center;font-family:Arial;background:#f4f8ff;padding:30px}
img{max-width:200px}
.word{letter-spacing:10px;font-size:28px;margin:20px}
</style>
</head>
<body>

<h2>🎯 Hangman</h2>
<p>💡 <?= htmlspecialchars($entry['hint']) ?></p>

<img src="assets/hangman0.png" id="hangman">

<div class="word">
<?= implode(" ", array_fill(0, strlen($entry['word']), "_")) ?>
</div>

<div style="margin-top:30px">
  <a href="viewer.php?unit=<?= urlencode($unitId) ?>">➡️ Siguiente</a> |
  <a href="../hub/index.php?unit=<?= urlencode($unitId) ?>">⬅ Volver</a>
</div>

</body>
</html>


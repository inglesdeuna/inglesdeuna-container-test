<?php
/**
 * HANGMAN – VIEWER POR UNIDAD
 */

$unit = $_GET['unit'] ?? null;
if (!$unit) {
  die("Unidad no especificada");
}

$file = __DIR__ . "/hangman.json";

$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (
  !isset($data[$unit]) ||
  !is_array($data[$unit]) ||
  empty($data[$unit])
) {
  die("No hay actividades de Hangman para esta unidad");
}

$words = $data[$unit];

// palabra aleatoria
$index = rand(0, count($words) - 1);
$current = $words[$index];

$hidden = str_repeat("_ ", strlen($current["word"]));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>
body{
  font-family:Arial;
  background:#eef6ff;
  padding:40px;
  text-align:center;
}
.card{
  background:#fff;
  max-width:420px;
  margin:auto;
  padding:25px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.1);
}
.word{
  font-size:26px;
  letter-spacing:8px;
  margin:20px 0;
}
.hint{
  color:#555;
  margin-bottom:20px;
}
button,a{
  display:inline-block;
  margin:6px;
  padding:10px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  text-decoration:none;
  font-weight:bold;
  cursor:pointer;
}
.back{background:#16a34a;}
</style>
</head>

<body>

<div class="card">
  <h2>🎯 Hangman</h2>

  <div class="hint">💡 <?= htmlspecialchars($current["hint"]) ?></div>

  <div class="word"><?= $hidden ?></div>

  <a href="?unit=<?= urlencode($unit) ?>">➡️ Siguiente</a>
  <a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">⬅ Volver</a>
</div>

</body>
</html>


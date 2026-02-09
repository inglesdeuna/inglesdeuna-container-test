<?php
session_start();

$unitId = $_GET['unit'] ?? null;
if (!$unitId) {
  die("Unidad no especificada");
}

$unitsFile = dirname(__DIR__, 2) . "/admin/data/units.json";
if (!file_exists($unitsFile)) {
  die("Archivo de unidades no encontrado");
}

$units = json_decode(file_get_contents($unitsFile), true) ?? [];

// buscar unidad
$unitIndex = null;
foreach ($units as $i => $u) {
  if (($u['id'] ?? null) === $unitId) {
    $unitIndex = $i;
    break;
  }
}
if ($unitIndex === null) {
  die("Unidad no encontrada");
}

// asegurar actividades
if (!isset($units[$unitIndex]['activities'])) {
  $units[$unitIndex]['activities'] = [];
}

// guardar palabra
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $word = trim($_POST['word'] ?? '');
  $hint = trim($_POST['hint'] ?? '');

  if ($word !== '') {
    $units[$unitIndex]['activities'][] = [
      'type' => 'hangman',
      'data' => [
        'word' => strtoupper($word),
        'hint' => $hint
      ]
    ];

    file_put_contents(
      $unitsFile,
      json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: editor.php?unit=" . urlencode($unitId));
  exit;
}

// actividades existentes
$activities = array_filter(
  $units[$unitIndex]['activities'],
  fn($a) => ($a['type'] ?? '') === 'hangman'
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman – Editor</title>
<style>
body{font-family:Arial;background:#eef6ff;padding:40px}
.box{background:#fff;padding:25px;border-radius:14px;max-width:500px}
input,button{width:100%;padding:10px;margin-top:8px}
button{background:#2563eb;color:#fff;border:none;border-radius:8px}
.item{background:#eef2ff;padding:8px;margin-top:6px;border-radius:6px}
.actions{display:flex;gap:10px;margin-top:20px}
a{flex:1;text-align:center;padding:10px;border-radius:8px;text-decoration:none}
.back{background:#6b7280;color:#fff}
</style>
</head>
<body>

<div class="box">
<h2>🎯 Hangman – Editor</h2>

<form method="post">
  <input name="word" placeholder="WORD (CAT)" required>
  <input name="hint" placeholder="Hint (A pet 🐱)">
  <button type="submit">➕ Add word</button>
</form>

<hr>

<h3>📚 Words</h3>
<?php foreach ($activities as $a): ?>
  <div class="item">
    <strong><?= htmlspecialchars($a['data']['word']) ?></strong><br>
    <small><?= htmlspecialchars($a['data']['hint']) ?></small>
  </div>
<?php endforeach; ?>

<div class="actions">
  <a class="back" href="../hub/index.php?unit=<?= urlencode($unitId) ?>">⬅ Volver al hub</a>
</div>
</div>

</body>
</html>

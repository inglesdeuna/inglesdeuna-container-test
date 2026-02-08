<?php
echo "<!-- HUB VERSION DINÁMICA ACTIVITIES -->";

$unit = $_GET['unit'] ?? null;

/* ==========================
   ESCANEAR ACTIVIDADES
   ========================== */

$baseDir = dirname(__DIR__); // /activities
$dirs = scandir($baseDir);

$activities = [];

foreach ($dirs as $dir) {
  if ($dir === '.' || $dir === '..' || $dir === 'hub') continue;

  $path = $baseDir . '/' . $dir;
  if (!is_dir($path)) continue;

  // detectar entry point
  $entry = null;

  if (file_exists($path . '/editor.php')) {
    $entry = "../$dir/editor.php";
  } elseif (file_exists($path . '/create_editor.php')) {
    $entry = "../$dir/create_editor.php";
  } elseif (file_exists($path . '/viewer.php')) {
    $entry = "../$dir/viewer.php";
  }

  if ($entry) {
    if ($unit) {
      $entry .= "?unit=" . urlencode($unit);
    }

    $activities[] = [
      'title' => ucwords(str_replace('_', ' ', $dir)),
      'path'  => $entry,
      'icon'  => '🧩'
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Activities Hub</title>
<style>
body{font-family:Arial;background:#f5f7fb;margin:0}
.container{max-width:900px;margin:40px auto}
.card{
  background:#fff;
  padding:20px;
  border-radius:12px;
  margin-bottom:15px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.card a{
  text-decoration:none;
  color:#2563eb;
  font-weight:bold;
}
.icon{font-size:26px;margin-right:10px}
</style>
</head>
<body>

<div class="container">
<h1>🧩 Activities Hub</h1>

<?php if (empty($activities)): ?>
  <p>No hay actividades disponibles.</p>
<?php endif; ?>

<?php foreach ($activities as $a): ?>
  <div class="card">
    <div>
      <span class="icon"><?= $a["icon"] ?></span>
      <?= htmlspecialchars($a["title"]) ?>
    </div>
  <a href="<?= htmlspecialchars($a["path"]) ?>">Abrir</a>
  </div>
<?php endforeach; ?>

</div>
</body>
</html>

<?php
/* ==========================
   ACTIVITIES HUB – CENTRAL
   ========================== */

$unit = $_GET['unit'] ?? null;
if (!$unit) {
  die("Unidad no especificada");
}

/* Escanear carpeta de actividades */
$baseDir = dirname(__DIR__); // /activities
$dirs = scandir($baseDir);

$activities = [];

foreach ($dirs as $dir) {
  if ($dir === '.' || $dir === '..' || $dir === 'hub') continue;

  $path = $baseDir . '/' . $dir;
  if (!is_dir($path)) continue;

  $editor = null;
  $viewer = null;

  if (file_exists($path . '/editor.php')) {
    $editor = "../$dir/editor.php?unit=" . urlencode($unit);
  }

  if (file_exists($path . '/viewer.php')) {
    $viewer = "../$dir/viewer.php?unit=" . urlencode($unit);
  }

  if ($editor || $viewer) {
    $activities[] = [
      'name'   => ucwords(str_replace('_', ' ', $dir)),
      'editor' => $editor,
      'viewer' => $viewer
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
body{
  font-family:Arial;
  background:#f5f7fb;
  margin:0;
}
.container{
  max-width:900px;
  margin:40px auto;
}
.card{
  background:#fff;
  padding:20px;
  border-radius:12px;
  margin-bottom:15px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.title{
  font-size:18px;
  font-weight:bold;
}
.actions a{
  margin-left:10px;
  padding:8px 14px;
  border-radius:6px;
  text-decoration:none;
  font-weight:bold;
}
.edit{
  background:#2563eb;
  color:white;
}
.view{
  background:#16a34a;
  color:white;
}
.back{
  display:inline-block;
  margin-bottom:20px;
  text-decoration:none;
  color:#2563eb;
  font-weight:bold;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="../../academic/units_editor.php?unit=<?= urlencode($unit) ?>">
← Volver a la unidad
</a>

<h1>🧩 Activities Hub</h1>

<?php if (empty($activities)): ?>
  <p>No hay actividades disponibles.</p>
<?php endif; ?>

<?php foreach ($activities as $a): ?>
  <div class="card">
    <div class="title">
      <?= htmlspecialchars($a['name']) ?>
    </div>
    <div class="actions">
      <?php if ($a['editor']): ?>
        <a class="edit" href="<?= htmlspecialchars($a['editor']) ?>">Editar</a>
      <?php endif; ?>
      <?php if ($a['viewer']): ?>
        <a class="view" href="<?= htmlspecialchars($a['viewer']) ?>">Ver</a>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

</div>

</body>
</html>

<?php
$file = __DIR__ . "/external_links.json";

if (!file_exists($file)) {
  die("Actividad no configurada");
}

$links = json_decode(file_get_contents($file), true);

if (!$links || !is_array($links) || count($links) === 0) {
  die("Actividad no configurada");
}

/* Por ahora mostramos la ÚLTIMA actividad guardada */
$activity = $links[count($links) - 1];

$title = $activity['title'] ?? 'Actividad';
$url   = $activity['url']   ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:40px;
}
.container{
  max-width:900px;
  margin:auto;
  background:#fff;
  padding:25px;
  border-radius:12px;
  box-shadow:0 8px 25px rgba(0,0,0,.08);
}
h1{
  color:#2563eb;
}
iframe{
  width:100%;
  height:500px;
  border:none;
  border-radius:10px;
}
</style>
</head>

<body>
<div class="container">
  <h1><?php echo htmlspecialchars($title); ?></h1>

  <?php if ($url): ?>
  <?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$title = $data['title'] ?? '';
$url   = $data['url'] ?? '';
?>

    <iframe src="<?php echo htmlspecialchars($url); ?>"></iframe>
  <?php else: ?>
    <p>URL no configurada.</p>
  <?php endif; ?>
</div>
</body>
</html>

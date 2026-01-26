<?php
$file = __DIR__ . "/external_links.json";

if (!file_exists($file)) {
  die("Actividad no configurada");
}

$data = json_decode(file_get_contents($file), true);

$title = $data["title"] ?? "";
$url   = $data["url"] ?? "";

if (!$title || !$url) {
  die("Actividad no configurada");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
body{font-family:Arial;background:#f4f7fb;padding:40px}
.card{background:#fff;max-width:900px;margin:auto;padding:30px;border-radius:12px}
iframe{width:100%;height:500px;border-radius:10px;border:none}
</style>
</head>
<body>

<div class="card">
<h1><?= htmlspecialchars($title) ?></h1>
<iframe src="<?= htmlspecialchars($url) ?>"></iframe>
</div>

</body>
</html>

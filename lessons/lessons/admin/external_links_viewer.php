<?php
$file = __DIR__ . "/external_links.json";

if (!file_exists($file)) {
  die("Actividad no disponible");
}

$data = json_decode(file_get_contents($file), true);

if (!$data || empty($data["url"])) {
  die("Actividad no configurada");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($data["title"]) ?></title>
<style>
body{
  margin:0;
  font-family:Arial, sans-serif;
  background:#eef3ff;
}
.header{
  text-align:center;
  padding:15px;
  background:#2563eb;
  color:white;
  font-size:22px;
}
iframe{
  width:100%;
  height:calc(100vh - 60px);
  border:none;
}
</style>
</head>
<body>

<div class="header">
  <?= htmlspecialchars($data["title"]) ?>
</div>

<iframe src="<?= htmlspecialchars($data["url"]) ?>"></iframe>

</body>
</html>

<?php
$file = __DIR__ . "/external_links.json";

if (!file_exists($file)) {
  die("Actividad no disponible");
}

$data = json_decode(file_get_contents($file), true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($data["title"]) ?></title>
<style>
body{
  margin:0;
  font-family:Arial;
  background:#eef3ff;
}
h1{
  text-align:center;
  padding:15px;
}
iframe{
  width:100%;
  height:calc(100vh - 70px);
  border:none;
}
</style>
</head>
<body>

<h1><?= htmlspecialchars($data["title"]) ?></h1>

<iframe src="<?= htmlspecialchars($data["url"]) ?>"></iframe>

</body>
</html>

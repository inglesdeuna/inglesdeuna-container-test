<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividad Externa</title>
<style>
body{
  font-family: Arial, sans-serif;
  background:#f4f7fb;
}
.container{
  max-width:1000px;
  margin:40px auto;
}
.card{
  background:#fff;
  padding:20px;
  border-radius:12px;
  box-shadow:0 8px 20px rgba(0,0,0,.08);
  margin-bottom:30px;
}
iframe{
  width:100%;
  height:480px;
  border:none;
  border-radius:10px;
}
.open-link{
  display:inline-block;
  margin-top:10px;
  padding:10px 16px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  border-radius:8px;
}
</style>
</head>

<body>
<div class="container">

<?php if (!$data || count($data) === 0): ?>
  <div class="card">Actividad no configurada</div>
<?php else: ?>

<?php foreach ($data as $item): ?>
  <div class="card">
    <h2><?= htmlspecialchars($item["title"]) ?></h2>

    <?php if (preg_match("/youtube\.com|youtu\.be|codepen\.io/", $item["url"])): ?>
      <iframe src="<?= htmlspecialchars($item["url"]) ?>"></iframe>
    <?php else: ?>
      <p>Este recurso se abre en una nueva pestaña:</p>
      <a class="open-link" href="<?= htmlspecialchars($item["url"]) ?>" target="_blank">
        Abrir actividad
      </a>
    <?php endif; ?>

  </div>
<?php endforeach; ?>

<?php endif; ?>

</div>
</body>
</html>

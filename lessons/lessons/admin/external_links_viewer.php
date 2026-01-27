<?php
$file = __DIR__ . "/external_links.json";
$activities = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividad Externa</title>
<style>
body{font-family:Arial;background:#f4f6fb;padding:30px}
.container{max-width:900px;margin:auto}
.card{background:#fff;padding:20px;margin-bottom:30px;border-radius:12px}
iframe{width:100%;height:420px;border:none;border-radius:10px}
</style>
</head>

<body>
<div class="container">

<?php if (!$activities): ?>
  <p>Actividad no configurada</p>
<?php endif; ?>

<?php foreach ($activities as $a): ?>
  <div class="card">
    <h2><?= htmlspecialchars($a["title"]) ?></h2>
    <iframe src="<?= htmlspecialchars($a["url"]) ?>"></iframe>
  </div>
<?php endforeach; ?>

</div>
</body>
</html>

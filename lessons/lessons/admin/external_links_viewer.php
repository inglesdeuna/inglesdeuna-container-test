<?php
// === CARGAR DATOS GUARDADOS ===
$file = __DIR__ . "/external_links.json";
$data = file_exists($file)
    ? json_decode(file_get_contents($file), true)
    : [];

// Tomamos SOLO titulo y url
$title = $data['title'] ?? '';
$url   = $data['url'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividad Externa</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  margin:0;
  padding:40px;
}
.container{
  max-width:900px;
  margin:auto;
  background:#fff;
  padding:30px;
  border-radius:12px;
  box-shadow:0 10px 30px rgba(0,0,0,.08);
}
h1{
  color:#2563eb;
  margin-bottom:20px;
}
iframe{
  width:100%;
  height:500px;
  border:none;
  border-radius:10px;
  background:#e5e5e5;
}
.empty{
  color:#999;
  text-align:center;
  padding:80px 0;
}
</style>
</head>

<body>
<div class="container">

  <h1><?php echo htmlspecialchars($title); ?></h1>

  <?php if (!empty($url)): ?>
    <iframe
      src="<?php echo htmlspecialchars($url); ?>"
      allowfullscreen
      loading="lazy">
    </iframe>
  <?php else: ?>
    <div class="empty">
      Actividad no configurada
    </div>
  <?php endif; ?>

</div>
</body>
</html>

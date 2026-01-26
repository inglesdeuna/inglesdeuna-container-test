<?php
$file = __DIR__ . "/external_links.json";

$data = [
  "title" => "",
  "url"   => ""
];

if (file_exists($file)) {
  $json = json_decode(file_get_contents($file), true);
  if (is_array($json)) {
    $data = array_merge($data, $json);
  }
}

$title = trim($data["title"]);
$url   = trim($data["url"]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title ?: "Actividad externa"); ?></title>

<style>
body{
  margin:0;
  background:#f2f7ff;
  font-family:Arial, sans-serif;
}
.container{
  max-width:1000px;
  margin:40px auto;
  background:#fff;
  padding:30px;
  border-radius:16px;
  box-shadow:0 10px 30px rgba(0,0,0,.1);
}
h1{
  margin:0 0 20px;
  color:#1e40af;
}
.frame-wrap{
  position:relative;
  padding-top:56.25%;
  background:#ddd;
  border-radius:12px;
  overflow:hidden;
}
iframe{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  border:0;
}
.empty{
  text-align:center;
  color:#888;
  padding:60px 0;
}
</style>
</head>

<body>
<div class="container">

<h1><?php echo htmlspecialchars($title ?: "Actividad externa"); ?></h1>

<?php if ($url): ?>
  <div class="frame-wrap">
    <iframe
      src="<?php echo htmlspecialchars($url); ?>"
      allow="autoplay; fullscreen; picture-in-picture"
      allowfullscreen
      loading="lazy">
    </iframe>
  </div>
<?php else: ?>
  <div class="empty">Actividad no configurada</div>
<?php endif; ?>

</div>
</body>
</html>

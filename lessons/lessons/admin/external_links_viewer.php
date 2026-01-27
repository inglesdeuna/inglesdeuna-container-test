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

<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if (!$data || count($data) === 0) {
  echo "<p>Actividad no configurada</p>";
  exit;
}

function isEmbeddable($url) {
  return (
    str_contains($url, "youtube.com/embed") ||
    str_contains($url, "youtube-nocookie.com") ||
    str_contains($url, "vimeo.com") ||
    str_contains($url, "codepen.io") ||
    str_contains($url, "genial.ly")
  );
}

<?php
foreach ($data as $item) {

  $title = isset($item["title"]) ? htmlspecialchars($item["title"]) : "";
  $url   = isset($item["url"]) ? trim($item["url"]) : "";

  if ($title === "" || $url === "") continue;

  echo "<h2>{$title}</h2>";

  if (isEmbeddable($url)) {
    echo '<iframe src="' . htmlspecialchars($url) . '" allowfullscreen></iframe>';
  } else {
    echo '
      <p>Esta actividad se abre en una nueva pestaña.</p>
      <a href="' . htmlspecialchars($url) . '" target="_blank" class="btn">
        👉 Abrir actividad
      </a>
    ';
  }

  echo "<hr>";
}
?>


</div>
</body>

</html>

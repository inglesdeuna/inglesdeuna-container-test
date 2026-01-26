<?php
$file = __DIR__ . "/external_links.json";

$data = [
  "title" => "",
  "url" => ""
];

if (file_exists($file)) {
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

}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $data["title"] = trim($_POST["title"] ?? "");
  $data["url"]   = trim($_POST["url"] ?? "");


/* --- AUTO CONVERTIR YOUTUBE A EMBED --- */
if (strpos($url, 'youtube.com/watch') !== false) {
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    if (!empty($params['v'])) {
        $url = 'https://www.youtube.com/embed/' . $params['v'];
    }
}

if (strpos($url, 'youtu.be/') !== false) {
    $videoId = trim(parse_url($url, PHP_URL_PATH), '/');
    $url = 'https://www.youtube.com/embed/' . $videoId;
}

$data["url"] = $url;


  file_put_contents(
    $file,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
  );

  header("Location: external_links.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividad Externa (Docente)</title>
<style>
body{font-family:Arial;background:#f4f7fb;padding:40px}
.card{background:#fff;max-width:600px;margin:auto;padding:30px;border-radius:12px}
input,button{width:100%;padding:12px;margin-top:10px}
button{background:#2563eb;color:#fff;border:none;border-radius:8px}
</style>
</head>
<body>

<div class="card">
<h2>Actividad Externa (Docente)</h2>

<form method="post">
<label>Título</label>
<input type="text" name="title" value="<?= htmlspecialchars($data["title"]) ?>">

<label>URL (embed)</label>
<input type="text" name="url" value="<?= htmlspecialchars($data["url"]) ?>">

<button type="submit">Guardar actividad</button>
</form>
</div>

</body>
</html>

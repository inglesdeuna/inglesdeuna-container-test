<?php
$file = __DIR__ . "/unscramble.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $videoPath = "";
  $audioPath = "";

  // Video opcional
  if (!empty($_FILES["video"]["name"])) {
    $dir = __DIR__ . "/upload/videos/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES["video"]["name"], PATHINFO_EXTENSION);
    $name = uniqid("vid_") . "." . $ext;
    $target = $dir . $name;

    if (move_uploaded_file($_FILES["video"]["tmp_name"], $target)) {
      $videoPath = "upload/videos/" . $name;
    }
  }

  // Audio opcional
  if (!empty($_FILES["audio"]["name"])) {
    $dir = __DIR__ . "/upload/audio/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES["audio"]["name"], PATHINFO_EXTENSION);
    $name = uniqid("aud_") . "." . $ext;
    $target = $dir . $name;

    if (move_uploaded_file($_FILES["audio"]["tmp_name"], $target)) {
      $audioPath = "upload/audio/" . $name;
    }
  }

  $items = array_filter(array_map("trim", explode("\n", $_POST["items"])));

  $data[] = [
    "video" => $videoPath,
    "audio" => $audioPath,
    "items" => array_values($items)
  ];

  file_put_contents(
    $file,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unscramble – Editor</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f5f7fb;
  padding:30px;
}
h1{color:#2563eb;}

.form{
  background:#fff;
  padding:20px;
  border-radius:14px;
  max-width:600px;
}

textarea,input{
  width:100%;
  margin-top:10px;
  padding:10px;
}

button{
  margin-top:15px;
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🧩 Unscramble – Editor</h1>

<div class="form">
<form method="post" enctype="multipart/form-data">

  <textarea name="items" rows="6"
    placeholder="Una palabra u oración por línea (en orden correcto)" required></textarea>

  <input type="file" name="video" accept="video/mp4">
  <input type="file" name="audio" accept="audio/*">

  <button>➕ Agregar actividad</button>

</form>
</div>

</body>
</html>

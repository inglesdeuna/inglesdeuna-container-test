<?php
$file = __DIR__ . "/pronunciation.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

/* ===== SAVE ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $imagePath = "";

  // 1️⃣ Imagen subida
  if (!empty($_FILES["image_file"]["name"])) {
    $dir = __DIR__ . "/upload/images/";
    if (!is_dir($dir)) {
      mkdir($dir, 0777, true);
    }

    $ext = pathinfo($_FILES["image_file"]["name"], PATHINFO_EXTENSION);
    $name = uniqid("img_") . "." . $ext;
    $target = $dir . $name;

    if (move_uploaded_file($_FILES["image_file"]["tmp_name"], $target)) {
      $imagePath = "upload/images/" . $name;
    }
  }

  // 2️⃣ Si no hay archivo, usar URL
  if ($imagePath === "" && !empty($_POST["image_url"])) {
    $imagePath = trim($_POST["image_url"]);
  }

  // Guardar ítem de pronunciación
  $data[] = [
    "en"    => trim($_POST["en"] ?? ""),
    "ph"    => trim($_POST["ph"] ?? ""),
    "es"    => trim($_POST["es"] ?? ""),
    "image" => $imagePath
  ];

  file_put_contents(
    $file,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );

  exit; // permite agregar varios seguidos
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Pronunciation – Editor Docente</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f5f7fb;
  padding:30px;
}

h1{
  color:#2563eb;
  margin-bottom:20px;
}

.form{
  background:#fff;
  padding:20px;
  border-radius:14px;
  max-width:520px;
  box-shadow:0 8px 20px rgba(0,0,0,.08);
}

input{
  width:100%;
  padding:10px;
  margin-top:10px;
}

button{
  margin-top:15px;
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

.list{
  margin-top:30px;
  max-width:520px;
}

.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  margin-bottom:10px;
  box-shadow:0 4px 10px rgba(0,0,0,.06);
}
</style>
</head>

<body>

<h1>🎧 Pronunciation – Editor</h1>

<div class="form">
<form method="post" enctype="multipart/form-data">

  <input type="text" name="en" placeholder="Texto en inglés (ej: Stand up)" required>
  <input type="text" name="ph" placeholder="Fonética (ej: stánd ap)">
  <input type="text" name="es" placeholder="Traducción al español">

  <input type="file" name="image_file" accept="image/*">
  <input type="url" name="image_url" placeholder="O URL de imagen (opcional)">

  <button type="submit">➕ Agregar pronunciación</button>

</form>
</div>

<div class="list">
<?php foreach ($data as $item): ?>
  <div class="item">
    <strong><?= htmlspecialchars($item["en"]) ?></strong><br>
    <small><?= htmlspecialchars($item["es"]) ?></small>
  </div>
<?php endforeach; ?>
</div>

</body>
</html>

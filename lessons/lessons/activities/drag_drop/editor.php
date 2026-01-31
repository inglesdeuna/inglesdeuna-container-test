<?php
$file = __DIR__ . "/drag_drop.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $imagePath = "";

  if (!empty($_FILES["image"]["name"])) {
    $dir = __DIR__ . "/upload/images/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
    $name = uniqid("img_") . "." . $ext;
    $target = $dir . $name;

    if (move_uploaded_file($_FILES["image"]["tmp_name"], $target)) {
      $imagePath = "upload/images/" . $name;
    }
  }

  $sentence = trim($_POST["sentence"]);
  $answers  = array_map("trim", explode(",", $_POST["answers"]));
  $options  = array_map("trim", explode(",", $_POST["options"]));

  $data[] = [
    "sentence" => $sentence,
    "answers"  => $answers,
    "options"  => $options,
    "image"    => $imagePath
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
<title>Drag & Drop – Editor</title>

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

input{
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

<h1>🧩 Drag & Drop – Editor</h1>

<div class="form">
<form method="post" enctype="multipart/form-data">

  <input type="text" name="sentence"
    placeholder="Sentence (use ___ for blanks)" required>

  <input type="text" name="answers"
    placeholder="Correct words (comma separated)" required>

  <input type="text" name="options"
    placeholder="Draggable words (comma separated)" required>

  <input type="file" name="image" accept="image/*">

  <button>➕ Add Activity</button>

</form>
</div>

</body>
</html>

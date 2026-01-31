<?php
$file = __DIR__ . "/match.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $items = [];

  foreach ($_POST["id"] as $i => $id) {
    $items[] = [
      "id"   => trim($id),
      "text" => trim($_POST["text"][$i]),
      "img"  => trim($_POST["img"][$i])
    ];
  }

  $data[] = ["items" => $items];

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
<title>Match – Editor</title>

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
  max-width:700px;
}

input{
  width:100%;
  margin-top:8px;
  padding:8px;
}

.row{
  margin-bottom:12px;
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

<h1>🧩 Match – Editor</h1>

<div class="form">
<form method="post">

  <p><strong>Each row = one match</strong></p>

  <?php for($i=0;$i<6;$i++): ?>
  <div class="row">
    <input name="id[]" placeholder="ID (same for image & text)">
    <input name="text[]" placeholder="Text / word / sentence">
    <input name="img[]" placeholder="Image URL">
  </div>
  <?php endfor; ?>

  <button>➕ Save Activity</button>

</form>
</div>

</body>
</html>

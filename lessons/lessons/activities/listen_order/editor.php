<?php
$file = __DIR__ . "/listen_order.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $sentence = trim($_POST["sentence"]);

  $data[] = [
    "sentence" => $sentence
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
<title>Listen & Order – Editor</title>

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

<h1>🎧 Listen & Order – Editor</h1>

<div class="form">
<form method="post">

  <input type="text" name="sentence"
    placeholder="Correct sentence (students will order it)" required>

  <button>➕ Add Activity</button>

</form>
</div>

</body>
</html>

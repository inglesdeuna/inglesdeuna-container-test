<?php
$file = "/var/www/html/data/programs.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $name = trim($_POST["name"] ?? "");
  $type = trim($_POST["type"] ?? "");

  if ($name !== "") {
    $data[] = [
      "id"   => uniqid("prog_"),
      "name" => $name,
      "type" => $type
    ];

    file_put_contents(
      $file,
      json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: programs_editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programas Académicos</title>

<style>
body{
  font-family:Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  color:#2563eb;
}

.card{
  background:white;
  padding:20px;
  border-radius:14px;
  max-width:600px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

input, select{
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
  max-width:600px;
}

.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  margin-bottom:10px;
  box-shadow:0 4px 8px rgba(0,0,0,.08);
}
</style>
</head>

<body>

<h1>🎓 Programas Académicos</h1>

<div class="card">
  <form method="post">

    <input type="text" name="name"
      placeholder="Nombre del programa (ej: LET’S Institute)" required>

    <select name="type">
      <option value="">Tipo de programa</option>
      <option value="institute">Instituto de Idiomas</option>
      <option value="technical">Programa Técnico</option>
    </select>

    <button>➕ Crear Programa</button>

  </form>
</div>

<div class="list">
  <h2>📋 Programas creados</h2>

  <?php foreach ($data as $p): ?>
    <div class="item">
      <strong><?= htmlspecialchars($p["name"]) ?></strong>
      <?php if (!empty($p["type"])): ?>
        <div style="font-size:13px;color:#555;">
          Tipo: <?= htmlspecialchars($p["type"]) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>

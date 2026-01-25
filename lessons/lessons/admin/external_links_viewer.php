<?php
$file = __DIR__ . "/external_links.json";

/* ---------- GUARDAR ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $title = trim($_POST["title"] ?? "");
  $url   = trim($_POST["url"] ?? "");

  if ($title !== "" && $url !== "") {
    $data = [
      "title" => $title,
      "url"   => $url
    ];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
  }

  header("Location: external_links.php?saved=1");
  exit;
}

/* ---------- LEER ---------- */
$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : ["title"=>"", "url"=>""];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividad Externa</title>
<style>
body{
  font-family:Arial;
  background:#f4f7ff;
  padding:30px;
}
.container{
  max-width:600px;
  margin:auto;
  background:white;
  padding:25px;
  border-radius:10px;
}
label{font-weight:bold}
input{
  width:100%;
  padding:10px;
  margin:8px 0 15px;
}
button{
  background:#2563eb;
  color:white;
  border:none;
  padding:12px 20px;
  border-radius:8px;
  cursor:pointer;
}
.success{
  background:#d1fae5;
  padding:10px;
  border-radius:6px;
  margin-bottom:15px;
}
</style>
</head>
<body>

<div class="container">
  <h2>🌐 Actividad Externa</h2>

  <?php if (isset($_GET["saved"])): ?>
    <div class="success">✅ Actividad guardada correctamente</div>
  <?php endif; ?>

  <form method="post">
    <label>Título</label>
    <input type="text" name="title" required
      value="<?= htmlspecialchars($data["title"]) ?>">

    <label>URL</label>
    <input type="url" name="url" required
      value="<?= htmlspecialchars($data["url"]) ?>">

    <button type="submit">💾 Guardar</button>
  </form>
</div>

</body>
</html>

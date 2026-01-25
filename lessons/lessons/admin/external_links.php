<?php
$file = __DIR__ . "/external_links.json";
$links = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $links[] = [
    "title" => $_POST["title"],
    "url"   => $_POST["url"]
  ];
  file_put_contents($file, json_encode($links, JSON_PRETTY_PRINT));
  header("Location: external_links.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades Externas</title>
<style>
body{font-family:Arial;background:#f2f7ff;padding:30px}
.container{background:#fff;padding:25px;border-radius:14px;max-width:900px;margin:auto}
input{width:100%;padding:10px;margin:8px 0}
button{padding:10px 16px;background:#2a6edb;color:#fff;border:none;border-radius:8px}
.card{margin:10px 0;padding:12px;background:#eef3ff;border-radius:10px}
</style>
</head>
<body>

<div class="container">
<h2>🌐 Actividades Externas (Docente)</h2>

<form method="post">
  <label>Nombre de la actividad</label>
  <input type="text" name="title" required>

  <label>URL (Wordwall, Liveworksheets, etc.)</label>
  <input type="url" name="url" required>

  <button>Guardar actividad</button>
</form>

<hr>

<h3>📋 Actividades guardadas</h3>

<?php foreach ($links as $l): ?>
  <div class="card">
    <strong><?= htmlspecialchars($l["title"]) ?></strong><br>
    <?= htmlspecialchars($l["url"]) ?>
  </div>
<?php endforeach; ?>

</div>
</body>
</html>

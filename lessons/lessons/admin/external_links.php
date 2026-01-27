<?php
$file = __DIR__ . "/external_links.json";
$activities = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if (!is_array($activities)) {
  $activities = [];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $title = trim($_POST["title"] ?? "");
  $url   = trim($_POST["url"] ?? "");

  if ($title && $url) {
    $activities[] = [
      "title" => $title,
      "url"   => $url
    ];
    file_put_contents($file, json_encode($activities, JSON_PRETTY_PRINT));
  }

  header("Location: external_links.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades Externas (Docente)</title>
<style>
body{font-family:Arial;background:#f4f6fb;padding:30px}
.container{max-width:700px;margin:auto;background:#fff;padding:25px;border-radius:12px}
input,button{width:100%;padding:10px;margin-top:10px}
button{background:#2563eb;color:#fff;border:none;border-radius:6px}
.item{background:#eef2ff;padding:10px;margin-top:10px;border-radius:6px}
</style>
</head>

<body>
<div class="container">
<h2>Actividad Externa (Docente)</h2>

<form method="POST">
  <label>Título</label>
  <input name="title" required>

  <label>URL (embed)</label>
  <input name="url" required>

  <button>Guardar actividad</button>
</form>

<hr>

<h3>Actividades guardadas</h3>

<?php foreach ($activities as $a): ?>
  <div class="item">
    <strong><?= htmlspecialchars($a["title"]) ?></strong><br>
    <?= htmlspecialchars($a["url"]) ?>
  </div>
<?php endforeach; ?>

</div>
</body>
</html>


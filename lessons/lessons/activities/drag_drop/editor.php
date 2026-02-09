<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$file = __DIR__ . "/drag_drop.json";
$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!isset($data[$unit])) {
  $data[$unit] = [];
}

/* GUARDAR */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["sentence"])) {
  $data[$unit][] = [
    "id" => uniqid("dd_"),
    "sentence" => trim($_POST["sentence"])
  ];

  file_put_contents(
    $file,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
  );
}

$sentences = $data[$unit];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Drag & Drop – Editor</title>
<style>
body{font-family:Arial;background:#eef6ff;padding:40px}
.panel{max-width:600px;background:#fff;padding:25px;border-radius:14px}
input,button{width:100%;padding:10px;margin:8px 0}
button.save{background:#2563eb;color:#fff;border:none;border-radius:10px}
a.back{
  display:block;text-align:center;margin-top:10px;
  background:#16a34a;color:#fff;padding:10px;
  border-radius:10px;text-decoration:none;font-weight:bold
}
.item{background:#f1f5ff;padding:10px;border-radius:8px;margin-top:6px}
</style>
</head>
<body>

<div class="panel">
<h2>🧩 Drag & Drop – Editor</h2>

<form method="post">
  <input name="sentence" placeholder="Write the sentence" required>
  <button class="save">💾 Guardar</button>
</form>

<h3>📚 Sentences</h3>

<?php foreach ($sentences as $s): ?>
  <div class="item"><?= htmlspecialchars($s["sentence"]) ?></div>
<?php endforeach; ?>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
  ↩ Volver al Hub
</a>

</div>
</body>
</html>


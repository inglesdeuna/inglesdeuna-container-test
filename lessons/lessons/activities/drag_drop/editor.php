<?php
/* ==========================
   DRAG & DROP – EDITOR
   ========================== */

/* Validar unidad */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
  die("Unidad no especificada");
}

/* Archivo de datos */
$dataFile = __DIR__ . "/drag_drop.json";

/* Carpeta para imágenes */
$uploadDir = __DIR__ . "/uploads";
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

/* Asegurar archivo */
if (!file_exists($dataFile)) {
  file_put_contents($dataFile, json_encode([]));
}

/* Cargar data */
$data = json_decode(file_get_contents($dataFile), true);
$data = is_array($data) ? $data : [];

/* Asegurar unidad */
if (!isset($data[$unit])) {
  $data[$unit] = [];
}

/* Guardar par */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $left  = trim($_POST["left"] ?? "");
  $right = trim($_POST["right"] ?? "");
  $imagePath = null;

  /* Imagen opcional */
  if (!empty($_FILES["image"]["name"])) {

    /* Validar tamaño (máx 1MB) */
    if ($_FILES["image"]["size"] > 1024 * 1024) {
      die("La imagen no puede superar 1MB");
    }

    /* Validar tipo */
    $allowed = ["image/jpeg", "image/png", "image/webp"];
    if (!in_array($_FILES["image"]["type"], $allowed)) {
      die("Formato de imagen no permitido");
    }

    $ext = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
    $fileName = uniqid("img_") . "." . $ext;
    $dest = $uploadDir . "/" . $fileName;

    move_uploaded_file($_FILES["image"]["tmp_name"], $dest);
    $imagePath = "uploads/" . $fileName;
  }

  if ($left !== "" && $right !== "") {
    $data[$unit][] = [
      "left"  => $left,
      "right" => $right,
      "image" => $imagePath
    ];

    file_put_contents(
      $dataFile,
      json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Drag & Drop – Editor</title>
<style>
body{
  font-family:Arial;
  background:#eef6ff;
  padding:40px;
}
.panel{
  max-width:520px;
  background:#fff;
  padding:20px;
  border-radius:12px;
}
input, button{
  width:100%;
  padding:10px;
  margin:8px 0;
}
button.save{
  background:#16a34a;
  color:white;
  border:none;
  border-radius:8px;
  font-weight:bold;
}
a.back{
  display:block;
  text-align:center;
  margin-top:15px;
  padding:10px;
  background:#2563eb;
  color:white;
  text-decoration:none;
  border-radius:8px;
  font-weight:bold;
}
ul{padding-left:18px}
li{margin-bottom:12px}
img{max-width:120px;display:block;margin-top:6px}
.note{
  font-size:13px;
  color:#555;
}
</style>
</head>

<body>

<div class="panel">
<h2>🧩 Drag & Drop – Editor</h2>

<form method="post" enctype="multipart/form-data">
  <input name="left" placeholder="Elemento izquierdo (ej: cat)" required>
  <input name="right" placeholder="Elemento derecho (ej: animal)" required>

  <label class="note">Imagen opcional (jpg, png, webp – máx 1MB)</label>
  <input type="file" name="image" accept="image/*">

  <button type="submit" class="save">💾 Guardar</button>
</form>

<hr>

<h3>📚 Pares en esta unidad</h3>

<?php if (empty($data[$unit])): ?>
  <p>No hay pares aún.</p>
<?php else: ?>
<ul>
<?php foreach ($data[$unit] as $p): ?>
  <li>
    <strong><?= htmlspecialchars($p["left"]) ?></strong>
    →
    <?= htmlspecialchars($p["right"]) ?>

    <?php if (!empty($p["image"])): ?>
      <img src="<?= htmlspecialchars($p["image"]) ?>">
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
  ← Volver al Hub
</a>

</div>

</body>
</html>

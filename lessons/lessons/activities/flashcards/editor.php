<?php
$file = __DIR__ . "/flashcards.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$imagePath = "";

/* ===== SAVE ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $data[] = [
    "front_text"  => trim($_POST["front_text"] ?? ""),
   "front_image" => $imagePath,
    "back_text"   => trim($_POST["back_text"] ?? ""),
    "audio"       => trim($_POST["audio"] ?? "")
  ];
$imagePath = "";

if (!empty($_FILES["front_image_file"]["name"])) {
  $dir = __DIR__ . "/upload/images/";
  if (!is_dir($dir)) mkdir($dir, 0777, true);

  $ext = pathinfo($_FILES["front_image_file"]["name"], PATHINFO_EXTENSION);
  $name = uniqid("img_") . "." . $ext;
  $target = $dir . $name;

  if (move_uploaded_file($_FILES["front_image_file"]["tmp_name"], $target)) {
    $imagePath = "upload/images/" . $name;
  }
}

  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  header("Location: editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Flashcards – Editor Docente</title>
<style>
body{font-family:Arial;background:#f5f7fb}
.card{background:#fff;padding:20px;border-radius:12px;max-width:600px;margin:40px auto}
input,button{width:100%;padding:10px;margin-top:10px}
button{background:#2563eb;color:#fff;border:none;border-radius:8px}
.item{background:#fff;padding:15px;border-radius:10px;margin:10px auto;max-width:600px}
</style>
</head>
<body>

<div class="card">
  <h2>➕ Nueva Flashcard</h2>
  <form method="post" action="editor.php" enctype="multipart/form-data">

    <input type="text" name="front_text" placeholder="Texto frontal (obligatorio)" required>
  <input type="file" name="front_image_file" accept="image/*">
    <input type="url" name="front_image" placeholder="URL de imagen (opcional)">
    <input type="text" name="back_text" placeholder="Texto reverso (opcional)">
    <input type="url" name="audio" placeholder="URL de audio en inglés (opcional)">
    <button type="submit">Guardar flashcard</button>
  </form>
</div>

<?php foreach ($data as $c): ?>
  <div class="item">
    <strong><?= htmlspecialchars($c["front_text"]) ?></strong>
  </div>
<?php endforeach; ?>

</body>
</html>

<?php
$file = __DIR__ . "/flashcards.json";
$cards = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $front = [
        "text" => trim($_POST["front_text"]),
        "image" => "",
        "audio_lang" => $_POST["front_audio_lang"] ?? ""
    ];

    $back = [
        "text" => trim($_POST["back_text"]),
        "image" => "",
        "audio_lang" => $_POST["back_audio_lang"] ?? ""
    ];

    // IMAGEN FRENTE
    if (!empty($_FILES["front_image"]["name"])) {
        $name = time() . "_front_" . basename($_FILES["front_image"]["name"]);
        $path = "uploads/images/" . $name;
        move_uploaded_file($_FILES["front_image"]["tmp_name"], __DIR__ . "/" . $path);
        $front["image"] = $path;
    }

    // IMAGEN REVERSO
    if (!empty($_FILES["back_image"]["name"])) {
        $name = time() . "_back_" . basename($_FILES["back_image"]["name"]);
        $path = "uploads/images/" . $name;
        move_uploaded_file($_FILES["back_image"]["tmp_name"], __DIR__ . "/" . $path);
        $back["image"] = $path;
    }

    $cards[] = [
        "front" => $front,
        "back" => $back
    ];

    file_put_contents($file, json_encode($cards, JSON_PRETTY_PRINT));
    header("Location: editor.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Editor de Flashcards</title>

<style>
body{
  font-family:Arial;
  background:#f5f7fb;
  padding:40px;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:14px;
  max-width:800px;
  margin:auto;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
h2{margin-top:0}
.section{
  margin-bottom:20px;
}
input, select, textarea, button{
  width:100%;
  padding:10px;
  margin-top:8px;
}
button{
  background:#2563eb;
  color:white;
  border:none;
  border-radius:8px;
  font-weight:bold;
}
hr{margin:30px 0}
.list{
  margin-top:30px;
}
.item{
  background:#eef2ff;
  padding:15px;
  border-radius:10px;
  margin-bottom:10px;
}
</style>
</head>

<body>

<div class="card">
<h2>🃏 Nueva Flashcard</h2>

<form method="post" enctype="multipart/form-data">

<div class="section">
  <h3>Frente</h3>
  <textarea name="front_text" placeholder="Texto frente" required></textarea>
  <input type="file" name="front_image">
  <select name="front_audio_lang">
    <option value="">Sin audio</option>
    <option value="en">Audio en inglés</option>
    <option value="es">Audio en español</option>
  </select>
</div>

<hr>

<div class="section">
  <h3>Reverso</h3>
  <textarea name="back_text" placeholder="Texto reverso" required></textarea>
  <input type="file" name="back_image">
  <select name="back_audio_lang">
    <option value="">Sin audio</option>
    <option value="en">Audio en inglés</option>
    <option value="es">Audio en español</option>
  </select>
</div>

<button>Guardar flashcard</button>
</form>

<div class="list">
<h3>📚 Flashcards guardadas</h3>

<?php if (empty($cards)): ?>
<p>No hay flashcards aún.</p>
<?php endif; ?>

<?php foreach ($cards as $i => $c): ?>
<div class="item">
<strong>#<?= $i + 1 ?></strong><br>
Frente: <?= htmlspecialchars($c["front"]["text"]) ?><br>
Reverso: <?= htmlspecialchars($c["back"]["text"]) ?>
</div>
<?php endforeach; ?>
</div>

</div>

</body>
</html>


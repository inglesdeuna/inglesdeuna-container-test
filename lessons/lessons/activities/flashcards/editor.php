<?php
$file = __DIR__ . "/../../admin/flashcards.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

/* ===== SAVE ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $data[] = [
    "front_text"  => trim($_POST["front_text"]),
    "front_image" => trim($_POST["front_image"]),
    "back_text"   => trim($_POST["back_text"]),
    "audio"       => trim($_POST["audio"])
  ];

  file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
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

<form method="post">
<input type="text" name="front_text" placeholder="Texto frontal (obligatorio)" required>
<input type="url" name="front_image" placeholder="URL de imagen (opcional)">
<input type="text" name="back_text" placeholder="Texto reverso (opcional)">
<input type="url" name="audio" placeholder="URL de audio en inglés (opcional)">
<button>Guardar flashcard</button>
</form>
</div>

<?php foreach ($data as $c): ?>
<div class="item">
<strong><?= htmlspecialchars($c["front_text"]) ?></strong>
</div>
<?php endforeach; ?>

</body>
</html>

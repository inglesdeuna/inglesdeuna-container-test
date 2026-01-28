<?php
$file = __DIR__ . "/questions.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $data[] = [
        "question" => trim($_POST["question"]),
        "image"    => trim($_POST["image"]),
        "audio"    => trim($_POST["audio"]),
        "options"  => $_POST["options"],
        "answer"   => (int)$_POST["answer"]
    ];

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Multiple Choice – Docente</title>
<style>
body{font-family:Arial;background:#f5f7fb;padding:20px}
.card{background:#fff;padding:25px;border-radius:12px;max-width:800px;margin:auto}
input,button{width:100%;padding:10px;margin:8px 0}
.option{display:flex;gap:10px}
.option input{flex:1}
button{background:#2563eb;color:#fff;border:none;border-radius:6px}
.list{margin-top:30px}
.item{background:#f9fafb;padding:15px;border-radius:8px;margin-bottom:10px}
</style>
</head>

<body>

<div class="card">
<h2>➕ Nueva pregunta</h2>

<form method="post">
<input name="question" placeholder="Pregunta" required>

<input name="image" placeholder="URL de imagen (opcional)">
<input name="audio" placeholder="URL de audio (opcional)">

<h4>Opciones</h4>
<?php for($i=0;$i<4;$i++): ?>
<div class="option">
  <input name="options[]" placeholder="Opción <?= $i+1 ?>" required>
  <input type="radio" name="answer" value="<?= $i ?>" required> ✔
</div>
<?php endfor; ?>

<button>Guardar pregunta</button>
</form>
</div>

<div class="card list">
<h3>📚 Preguntas guardadas</h3>
<?php foreach ($data as $q): ?>
<div class="item">
<strong><?= htmlspecialchars($q["question"]) ?></strong>
</div>
<?php endforeach; ?>
</div>

</body>
</html>

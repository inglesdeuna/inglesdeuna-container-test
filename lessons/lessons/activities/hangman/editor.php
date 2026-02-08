<?php
/* ==========================
   HANGMAN – EDITOR DOCENTE
   Guarda directamente en hangman.json
   ========================== */

$file = __DIR__ . "/hangman.json";

/* Asegurar archivo */
if (!file_exists($file)) {
  file_put_contents($file, json_encode([]));
}

/* Cargar datos */
$data = json_decode(file_get_contents($file), true);
if (!is_array($data)) {
  $data = [];
}

/* Guardar palabra */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $word = trim($_POST["word"] ?? "");
  $hint = trim($_POST["hint"] ?? "");

  if ($word !== "" && $hint !== "") {
    $data[] = [
      "word" => strtoupper($word),
      "hint" => $hint
    ];

    file_put_contents(
      $file,
      json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  // Evitar reenvío del form
  header("Location: editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – Teacher Panel</title>
<style>
body{
  font-family: Arial;
  background:#eef6ff;
  padding:40px;
}
.panel{
  max-width:420px;
  background:white;
  padding:20px;
  border-radius:12px;
}
input, button{
  width:100%;
  padding:10px;
  margin:8px 0;
}
ul{padding-left:18px}
li{margin-bottom:10px}
</style>
</head>

<body>

<div class="panel">
<h2>🎓 Teacher Panel – Hangman</h2>

<!-- 🔴 FORM SIN ACTION -->
<form method="post">
  <input name="word" placeholder="WORD (example: CAT)" required>
  <input name="hint" placeholder="Hint (example: A pet 🐱)" required>
  <button type="submit">➕ Add word</button>
</form>

<hr>

<h3>📚 Current words</h3>

<?php if (empty($data)): ?>
  <p>No words yet.</p>
<?php else: ?>
<ul>
<?php foreach ($data as $w): ?>
  <li>
    <strong><?= htmlspecialchars($w["word"]) ?></strong><br>
    <small><?= htmlspecialchars($w["hint"]) ?></small><br>
    <span style="letter-spacing:6px;font-size:18px;">
      <?= str_repeat("_ ", strlen($w["word"])) ?>
    </span>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

</div>

</body>
</html>


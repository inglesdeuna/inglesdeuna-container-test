<?php
/* ==========================
   HANGMAN – EDITOR (DOCENTE)
   Guarda palabras por UNIDAD
   ========================== */

$unit = $_GET['unit'] ?? null;
if (!$unit) {
  die("Unidad no especificada");
}

$file = __DIR__ . "/hangman.json";

/* Asegurar archivo */
if (!file_exists($file)) {
  file_put_contents($file, json_encode([]));
}

/* Cargar data */
$data = json_decode(file_get_contents($file), true);
$data = is_array($data) ? $data : [];

/* Asegurar unidad */
if (!isset($data[$unit])) {
  $data[$unit] = [];
}

/* Guardar palabra */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $word = trim($_POST["word"] ?? "");
  $hint = trim($_POST["hint"] ?? "");

  if ($word !== "" && $hint !== "") {
    $data[$unit][] = [
      "word" => strtoupper($word),
      "hint" => $hint
    ];

    file_put_contents(
      $file,
      json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    // volver al HUB
    header("Location: ../hub/index.php?unit=" . urlencode($unit));
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – Editor</title>
<style>
body{font-family:Arial;background:#eef6ff;padding:40px}
.panel{max-width:420px;background:#fff;padding:20px;border-radius:12px}
input,button{width:100%;padding:10px;margin:8px 0}
ul{padding-left:18px}
</style>
</head>
<body>

<div class="panel">
<h2>🎓 Hangman – Editor</h2>

<form method="post">
  <input name="word" placeholder="WORD (CAT)" required>
  <input name="hint" placeholder="Hint (A pet 🐱)" required>
  <button>➕ Add word</button>
</form>

<hr>

<h3>📚 Words in this unit</h3>

<?php if (empty($data[$unit])): ?>
  <p>No words yet.</p>
<?php else: ?>
<ul>
<?php foreach ($data[$unit] as $w): ?>
  <li>
    <strong><?= htmlspecialchars($w["word"]) ?></strong><br>
    <small><?= htmlspecialchars($w["hint"]) ?></small>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

</div>

</body>
</html>

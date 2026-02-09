<?php
/**
 * HANGMAN – EDITOR POR UNIDAD
 * Guarda palabras + hint en hangman.json
 * NO maneja sesiones ni permisos (eso ya se valida antes)
 */

$unit = $_GET['unit'] ?? null;
if (!$unit) {
  die("Unidad no especificada");
}

/* ==========================
   ARCHIVO JSON
   ========================== */
$file = __DIR__ . "/hangman.json";

$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!is_array($data)) {
  $data = [];
}

if (!isset($data[$unit])) {
  $data[$unit] = [];
}

/* ==========================
   GUARDAR PALABRA
   ========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save"])) {

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
  }

  // ❌ NO redirige automáticamente
  // Se queda en el editor
}

$words = $data[$unit];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman – Editor</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#eef6ff;
  padding:40px;
}
.panel{
  max-width:480px;
  background:#fff;
  padding:25px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
input, button{
  width:100%;
  padding:10px;
  margin:8px 0;
}
button{
  border:none;
  border-radius:10px;
  font-weight:bold;
  cursor:pointer;
}
.save{background:#2563eb;color:#fff;}
.back{background:#16a34a;color:#fff;text-align:center;text-decoration:none;display:block;margin-top:10px;padding:10px;border-radius:10px;}
.word{
  background:#f1f5ff;
  padding:10px;
  border-radius:8px;
  margin-bottom:8px;
}
</style>
</head>

<body>

<div class="panel">

<h2>🎓 Hangman – Editor</h2>

<form method="post">
  <input type="text" name="word" placeholder="WORD (example: CAT)" required>
  <input type="text" name="hint" placeholder="Hint (example: A pet 🐱)" required>

  <button class="save" type="submit" name="save">
    💾 Guardar palabra
  </button>
</form>

<hr>

<h3>📚 Palabras guardadas</h3>

<?php if (empty($words)): ?>
  <p>No hay palabras aún.</p>
<?php else: ?>
  <?php foreach ($words as $w): ?>
    <div class="word">
      <strong><?= htmlspecialchars($w["word"]) ?></strong><br>
      <small><?= htmlspecialchars($w["hint"]) ?></small>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
  ⬅ Volver al Hub
</a>

</div>

</body>
</html>


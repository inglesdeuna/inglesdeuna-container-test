<?php
/* ==========================
   HANGMAN – EDITOR
   Admin crea | Docente edita / ve
   ========================== */

session_start();

/* Validar unidad */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
  die("Unidad no especificada");
}

/* Validar rol */
$isAdmin   = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$isTeacher = isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true;

if (!$isAdmin && !$isTeacher) {
  die("Acceso no autorizado");
}

/* Archivo de datos */
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

/* Guardar palabra (SOLO ADMIN) */
if ($isAdmin && $_SERVER["REQUEST_METHOD"] === "POST") {
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
button.save{
  background:#16a34a;
  color:white;
  border:none;
  font-weight:bold;
  border-radius:8px;
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
li{margin-bottom:10px}
.note{
  background:#fef3c7;
  padding:10px;
  border-radius:8px;
  font-size:14px;
}
</style>
</head>

<body>

<div class="panel">
<h2>🎓 Hangman</h2>

<?php if ($isAdmin): ?>
  <!-- FORM SOLO ADMIN -->
  <form method="post">
    <input name="word" placeholder="WORD (example: CAT)" required>
    <input name="hint" placeholder="Hint (example: A pet 🐱)" required>
    <button type="submit" class="save">💾 Guardar palabra</button>
  </form>
  <hr>
<?php else: ?>
  <div class="note">
    👩‍🏫 El docente puede ver y usar esta actividad, pero no crear nuevas palabras.
  </div>
  <hr>
<?php endif; ?>

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

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
  ← Volver al Hub
</a>

</div>

</body>
</html>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =========================
   VALIDAR UNIT
========================= */

$unit = $_GET["unit"] ?? null;

if (!$unit) {
    die("Unit no especificada");
}

/* =========================
   RUTA JSON
========================= */

$jsonFile = __DIR__ . "/hangman.json";

/* =========================
   LEER JSON
========================= */

$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];

/* =========================
   ASEGURAR UNIT
========================= */

if (!isset($data[$unit])) {
    $data[$unit] = [];
}

/* =========================
   GUARDAR PALABRA
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $word = trim($_POST["word"] ?? "");

    if ($word !== "") {

        $data[$unit][] = [
            "word" => strtoupper($word)
        ];

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    header("Location: editor.php?unit=" . urlencode($unit));
    exit;
}

$words = $data[$unit];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>

<style>
body{
    font-family: Arial;
    background:#eef4ff;
    padding:40px;
}

.box{
    background:white;
    padding:25px;
    border-radius:12px;
    max-width:500px;
}

input, button{
    padding:10px;
    margin-top:8px;
    width:100%;
}

button{
    background:#2563eb;
    color:white;
    border:none;
    border-radius:6px;
    cursor:pointer;
}

.word{
    padding:8px;
    background:#eef2ff;
    margin-top:6px;
    border-radius:6px;
}
</style>
</head>

<body>

<div class="box">

<h2>🎯 Hangman Editor</h2>

<form method="post">
<input name="word" placeholder="Palabra o frase" required>
<button>Guardar</button>
</form>

<hr>

<h3>Palabras guardadas</h3>

<?php if(empty($words)): ?>
<p>No hay palabras aún</p>
<?php else: ?>
<?php foreach($words as $w): ?>
<div class="word">
<?= htmlspecialchars($w["word"]) ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<hr>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button>⬅ Volver al Hub</button>
</a>

</div>

</body>
</html>


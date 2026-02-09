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
   LEER JSON
========================= */

$jsonFile = __DIR__ . "/hangman.json";

$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];

$words = $data[$unit] ?? [];

if (empty($words)) {
    die("No hay palabras para esta unidad");
}

/* =========================
   ELEGIR PALABRA RANDOM
========================= */

$randomWord = $words[array_rand($words)]["word"];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman Game</title>

<style>
body{
    font-family: Arial;
    background:#eef4ff;
    padding:40px;
    text-align:center;
}

.word{
    font-size:30px;
    letter-spacing:10px;
    margin:20px;
}

.keyboard button{
    padding:10px;
    margin:4px;
    font-size:16px;
}
</style>
</head>

<body>

<h2>🎯 Hangman</h2>

<div id="word" class="word"></div>

<div class="keyboard" id="keyboard"></div>

<script>

let word = "<?= $randomWord ?>";
let guessed = [];
let mistakes = 0;

function renderWord(){
    let display = "";
    for(let letter of word){
        display += guessed.includes(letter) ? letter + " " : "_ ";
    }
    document.getElementById("word").innerText = display;
}

function guess(letter){
    if(guessed.includes(letter)) return;

    guessed.push(letter);

    if(!word.includes(letter)){
        mistakes++;
        console.log("Mistake:", mistakes);
    }

    renderWord();
}

function buildKeyboard(){
    let letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    let html = "";
    for(let l of letters){
        html += `<button onclick="guess('${l}')">${l}</button>`;
    }

    document.getElementById("keyboard").innerHTML = html;
}

renderWord();
buildKeyboard();

</script>

<hr>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button>⬅ Volver al Hub</button>
</a>

</body>
</html>



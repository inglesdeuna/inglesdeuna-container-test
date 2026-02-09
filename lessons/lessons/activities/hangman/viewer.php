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
   LEER JSON SEGURO
========================= */

$data = [];

if (file_exists($jsonFile)) {
    $decoded = json_decode(file_get_contents($jsonFile), true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

/* =========================
   OBTENER PALABRA SEGURA
========================= */

if (!isset($data[$unit]) || empty($data[$unit])) {
    $randomWord = "TEST";
} else {
    $words = $data[$unit];
    $randomWord = $words[array_rand($words)]["word"] ?? "TEST";
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>
body{
    font-family: Arial;
    background:#eef4ff;
    padding:40px;
    text-align:center;
}

.word{
    font-size:32px;
    letter-spacing:10px;
    margin:20px;
}

.keyboard button{
    padding:10px;
    margin:4px;
    font-size:16px;
    cursor:pointer;
}
</style>
</head>

<body>

<h2>🎯 Hangman</h2>

<div id="word" class="word"></div>

<div id="keyboard" class="keyboard"></div>

<script>

/* =========================
   PALABRA DESDE PHP (SEGURO)
========================= */

let word = <?= json_encode($randomWord) ?>;

/* =========================
   ESTADO JUEGO
========================= */

let guessed = [];
let mistakes = 0;

/* =========================
   RENDER PALABRA
========================= */

function renderWord(){
    let display = "";

    for(let letter of word){
        if(guessed.includes(letter)){
            display += letter + " ";
        } else {
            display += "_ ";
        }
    }

    document.getElementById("word").innerText = display;
}

/* =========================
   ADIVINAR LETRA
========================= */

function guess(letter){

    if(guessed.includes(letter)) return;

    guessed.push(letter);

    if(!word.includes(letter)){
        mistakes++;
        console.log("Mistakes:", mistakes);
    }

    renderWord();
}

/* =========================
   TECLADO
========================= */

function buildKeyboard(){

    let letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    let html = "";

    for(let l of letters){
        html += `<button onclick="guess('${l}')">${l}</button>`;
    }

    document.getElementById("keyboard").innerHTML = html;
}

/* =========================
   INIT
========================= */

renderWord();
buildKeyboard();

</script>

<hr>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button>⬅ Volver al Hub</button>
</a>

</body>
</html>


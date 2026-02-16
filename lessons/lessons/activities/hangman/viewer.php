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
$jsonFile = __DIR__ . "/../../hangman/hangman.json";

/* =========================
   LEER JSON
========================= */
$data = [];
if (file_exists($jsonFile)) {
    $decoded = json_decode(file_get_contents($jsonFile), true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

/* =========================
   OBTENER PALABRA
========================= */
if (!isset($data[$unit]) || empty($data[$unit])) {
    $randomWord = "TEST";
} else {
    $words = $data[$unit];
    $randomWord = strtoupper($words[array_rand($words)]["word"] ?? "TEST");
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
    text-align:center;
    padding:40px;
}
.word{
    font-size:34px;
    letter-spacing:12px;
    margin:25px;
}
.keyboard button{
    padding:10px;
    margin:4px;
    font-size:16px;
    cursor:pointer;
}
img{
    margin-top:20px;
}
.hidden{
    display:none;
}
</style>

</head>
<body>

<h2>🎯 Hangman</h2>

<!-- IMAGEN CORREGIDA -->
<img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="220">

<div id="word" class="word"></div>

<div id="keyboard" class="keyboard"></div>

<br>

<button id="nextBtn" class="hidden"
onclick="window.location.reload()">➡ Siguiente</button>

<button id="retryBtn" class="hidden"
onclick="window.location.reload()">🔁 Try Again</button>

<br><br>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button>⬅ Volver al Hub</button>
</a>

<!-- SONIDOS CORREGIDOS -->
<audio id="correctSound" src="../../hangman/assets/correct.wav"></audio>
<audio id="wrongSound" src="../../hangman/assets/wrong.wav"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3"></audio>

<script>

let word = <?= json_encode($randomWord) ?>;
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;

/* =========================
   RENDER PALABRA
========================= */
function renderWord(){

    let display = "";

    for(let letter of word){
        if(guessed.includes(letter)){
            display += letter + " ";
        }else{
            display += "_ ";
        }
    }

    document.getElementById("word").innerText = display;
    checkWin();
}

/* =========================
   GUESS
========================= */
function guess(letter){

    if(guessed.includes(letter)) return;

    guessed.push(letter);

    if(word.includes(letter)){
        play("correctSound");
    }else{
        mistakes++;
        play("wrongSound");
        updateHangman();
    }

    renderWord();
    checkLose();
}

/* =========================
   CAMBIAR IMAGEN
========================= */
function updateHangman(){
    document.getElementById("hangmanImg").src =
        "../../hangman/assets/hangman" + mistakes + ".png";
}

/* =========================
   WIN
========================= */
function checkWin(){

    let win = true;

    for(let l of word){
        if(!guessed.includes(l)){
            win = false;
        }
    }

    if(win){
        play("winSound");
        disableKeyboard();
        document.getElementById("nextBtn").classList.remove("hidden");
    }
}

/* =========================
   LOSE
========================= */
function checkLose(){

    if(mistakes >= maxMistakes){
        play("loseSound");
        disableKeyboard();
        document.getElementById("retryBtn").classList.remove("hidden");
    }
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
   DISABLE KEYBOARD
========================= */
function disableKeyboard(){
    document.querySelectorAll(".keyboard button")
    .forEach(btn => btn.disabled = true);
}

/* =========================
   PLAY SOUND
========================= */
function play(id){
    const audio = document.getElementById(id);
    audio.currentTime = 0;
    audio.play().catch(e=>console.log("Audio blocked by browser"));
}

/* INIT */
renderWord();
buildKeyboard();

</script>

</body>
</html>

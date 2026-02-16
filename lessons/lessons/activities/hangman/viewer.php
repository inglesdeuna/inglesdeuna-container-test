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
   RUTA JSON CORRECTA
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
    font-family: Arial, sans-serif;
    background:#eef6ff;
    text-align:center;
    padding:40px;
}

h2{
    color:#0b5ed7;
}

.game-box{
    background:white;
    padding:30px;
    border-radius:15px;
    max-width:750px;
    margin:30px auto;
    box-shadow:0 5px 20px rgba(0,0,0,0.08);
}

.word{
    font-size:34px;
    letter-spacing:12px;
    margin:25px 0;
}

.keyboard button{
    padding:10px 14px;
    margin:5px;
    font-size:15px;
    border:none;
    border-radius:10px;
    background:#2563eb;
    color:white;
    font-weight:bold;
    cursor:pointer;
    transition:0.2s;
}

.keyboard button:hover{
    background:#1e40af;
}

.keyboard button:disabled{
    background:#9ca3af;
    cursor:not-allowed;
}

img{
    margin-top:15px;
}

button{
    padding:10px 18px;
    border:none;
    border-radius:12px;
    background:#0b5ed7;
    color:white;
    cursor:pointer;
    margin:6px;
    font-weight:bold;
}

button:hover{
    background:#084298;
}

.hidden{
    display:none;
}

.back-btn{
    display:inline-block;
    margin-top:20px;
    background:#16a34a;
    color:#fff;
    padding:10px 18px;
    border-radius:12px;
    text-decoration:none;
    font-weight:bold;
}
</style>

</head>
<body>

<h2>🎯 Hangman</h2>

<div class="game-box">

    <!-- IMAGEN CORREGIDA -->
    <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="220">

    <div id="word" class="word"></div>

    <div id="keyboard" class="keyboard"></div>

    <br>

    <button id="nextBtn" class="hidden"
    onclick="window.location.reload()">➡ Siguiente</button>

    <button id="retryBtn" class="hidden"
    onclick="window.location.reload()">🔁 Try Again</button>

</div>

<a class="back-btn" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
⬅ Volver al Hub
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

/* RENDER PALABRA */
function renderWord(){
    let display = "";
    for(let letter of word){
        display += guessed.includes(letter) ? letter + " " : "_ ";
    }
    document.getElementById("word").innerText = display;
    checkWin();
}

/* GUESS */
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

/* CAMBIAR IMAGEN */
function updateHangman(){
    document.getElementById("hangmanImg").src =
        "../../hangman/assets/hangman" + mistakes + ".png";
}

/* WIN */
function checkWin(){
    let win = word.split("").every(l => guessed.includes(l));
    if(win){
        play("winSound");
        disableKeyboard();
        document.getElementById("nextBtn").classList.remove("hidden");
    }
}

/* LOSE */
function checkLose(){
    if(mistakes >= maxMistakes){
        play("loseSound");
        disableKeyboard();
        document.getElementById("retryBtn").classList.remove("hidden");
    }
}

/* TECLADO */
function buildKeyboard(){
    let letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    let html = "";
    for(let l of letters){
        html += `<button onclick="guess('${l}')">${l}</button>`;
    }
    document.getElementById("keyboard").innerHTML = html;
}

/* DESHABILITAR */
function disableKeyboard(){
    document.querySelectorAll(".keyboard button")
    .forEach(btn => btn.disabled = true);
}

/* SONIDO */
function play(id){
    const audio = document.getElementById(id);
    audio.currentTime = 0;
    audio.play().catch(()=>{});
}

/* INIT */
renderWord();
buildKeyboard();

</script>

</body>
</html>

<?php
require_once __DIR__ . "/../../config/db.php";

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =========================
   VALIDAR UNIT
========================= */
$unit = $_GET["unit"] ?? null;
if (!$unit) {
    die("Unidad no especificada");
}

/* =========================
   OBTENER DATOS DESDE BD
========================= */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'hangman'
");

$stmt->execute(["unit" => $unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

/* =========================
   EXTRAER PALABRAS
========================= */
$words = [];

if (!empty($data)) {
    foreach ($data as $item) {
        if (!empty($item["word"])) {
            $words[] = strtoupper($item["word"]);
        }
    }
}

if (empty($words)) {
    $words = ["TEST"];
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
    margin-bottom:5px;
}

.subtitle{
    color:#6b7280;
    font-size:14px;
    margin-bottom:25px;
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

.back-top{
    position:absolute;
    top:25px;
    left:25px;
    text-decoration:none;
    font-weight:bold;
    color:#2563eb;
}

.back-top:hover{
    text-decoration:underline;
}
</style>

</head>
<body>

<a class="back-top" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
← Volver al Hub
</a>

<h2>🎯 Hangman</h2>
<div class="subtitle">Listen and guess the correct word.</div>

<div class="game-box">

    <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="220">

    <div id="word" class="word"></div>

    <div id="keyboard" class="keyboard"></div>

    <br>

    <button id="nextBtn" class="hidden"
    onclick="nextWord()">➡ Next</button>

    <button id="retryBtn" class="hidden"
    onclick="resetGame()">🔁 Try Again</button>

</div>

<!-- SONIDOS -->
<audio id="correctSound" src="../../hangman/assets/correct.wav"></audio>
<audio id="wrongSound" src="../../hangman/assets/wrong.wav"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3"></audio>

<script>

const words = <?= json_encode($words) ?>;

let currentIndex = 0;
let word = words[currentIndex];
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;

/* =========================
   INICIAR PALABRA
========================= */
function startWord(){
    guessed = [];
    mistakes = 0;
    word = words[currentIndex];

    document.getElementById("hangmanImg").src =
        "../../hangman/assets/hangman0.png";

    document.getElementById("nextBtn").classList.add("hidden");
    document.getElementById("retryBtn").classList.add("hidden");

    buildKeyboard();
    renderWord();
}

/* =========================
   RENDER
========================= */
function renderWord(){
    let display = "";
    for(let letter of word){
        display += guessed.includes(letter) ? letter + " " : "_ ";
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
   IMAGEN
========================= */
function updateHangman(){
    document.getElementById("hangmanImg").src =
        "../../hangman/assets/hangman" + mistakes + ".png";
}

/* =========================
   WIN
========================= */
function checkWin(){
    let win = word.split("").every(l => guessed.includes(l));
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
   NEXT SIN RECARGAR
========================= */
function nextWord(){
    currentIndex++;
    if(currentIndex >= words.length){
        currentIndex = 0;
    }
    startWord();
}

/* =========================
   RESTART
========================= */
function resetGame(){
    startWord();
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

function disableKeyboard(){
    document.querySelectorAll(".keyboard button")
    .forEach(btn => btn.disabled = true);
}

function play(id){
    const audio = document.getElementById(id);
    audio.currentTime = 0;
    audio.play().catch(()=>{});
}

/* INIT */
startWord();

</script>

</body>
</html>

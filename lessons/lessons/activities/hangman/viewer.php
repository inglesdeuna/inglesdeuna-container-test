<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'hangman'
");

$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

$words = [];

foreach ($data as $item) {
    if (!empty($item["word"])) {
        $words[] = strtoupper($item["word"]);
    }
}

if (empty($words)) {
    $words = ["TEST"];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}

h1{
  color:#0b5ed7;
  font-size:26px;
  margin-bottom:5px;
}

.subtitle{
  color:#444;
  margin-bottom:20px;
}

.game-box{
  background:white;
  border-radius:15px;
  padding:25px;
  max-width:750px;
  margin:20px auto;
}

.word{
  font-size:32px;
  letter-spacing:10px;
  margin:20px 0;
}

.keyboard{
  margin-top:15px;
}

.keyboard button{
  padding:8px 14px;
  margin:4px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

.keyboard button:disabled{
  background:#9ca3af;
}

.controls{
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
}

#feedback{
  font-size:18px;
  font-weight:bold;
  margin-top:10px;
}

.good{ color:green; }
.bad{ color:crimson; }

a.back{
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

<h1>🎯 Hangman</h1>
<p class="subtitle">Listen and guess the correct word.</p>

<div class="game-box">

  <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="200">

  <div id="word" class="word"></div>

  <div id="keyboard" class="keyboard"></div>

  <div class="controls">
    <button onclick="checkGame()">✅ Check</button>
    <button onclick="nextWord()">➡️</button>
  </div>

  <div id="feedback"></div>

</div>

<a class="back" href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>">
  ↩ Back
</a>

<!-- SONIDOS -->
<audio id="correctSound" src="../../hangman/assets/correct.wav"></audio>
<audio id="wrongSound" src="../../hangman/assets/wrong.wav"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3"></audio>

<script>

const words = <?= json_encode($words) ?>;

let index = 0;
let word = words[index];
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;

const feedback = document.getElementById("feedback");

/* LOAD WORD */
function loadWord(){
  guessed = [];
  mistakes = 0;
  word = words[index];
  feedback.textContent="";
  feedback.className="";

  document.getElementById("hangmanImg").src =
    "../../hangman/assets/hangman0.png";

  buildKeyboard();
  renderWord();
}

/* RENDER */
function renderWord(){
  let display = "";
  for(let l of word){
    display += guessed.includes(l) ? l+" " : "_ ";
  }
  document.getElementById("word").innerText = display;
}

/* GUESS */
function guess(letter){
  if(guessed.includes(letter)) return;

  guessed.push(letter);

  if(word.includes(letter)){
    document.getElementById("correctSound").play();
  }else{
    mistakes++;
    document.getElementById("wrongSound").play();
    document.getElementById("hangmanImg").src =
      "../../hangman/assets/hangman"+mistakes+".png";
  }

  renderWord();
}

/* CHECK */
function checkGame(){

  let current = word.split("").every(l => guessed.includes(l));

  if(current){
    feedback.textContent="🌟 Excellent!";
    feedback.className="good";
    document.getElementById("winSound").play();
  }else if(mistakes >= maxMistakes){
    feedback.textContent="❌ You lost!";
    feedback.className="bad";
    document.getElementById("loseSound").play();
  }else{
    feedback.textContent="🔁 Try again!";
    feedback.className="bad";
  }
}

/* NEXT */
function nextWord(){
  index++;
  if(index >= words.length){
    feedback.textContent="🏆 You finished all words!";
    feedback.className="good";
    return;
  }
  loadWord();
}

/* KEYBOARD */
function buildKeyboard(){
  let letters="ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  let html="";
  for(let l of letters){
    html += `<button onclick="guess('${l}')">${l}</button>`;
  }
  document.getElementById("keyboard").innerHTML=html;
}

/* START */
loadWord();

</script>

</body>
</html>

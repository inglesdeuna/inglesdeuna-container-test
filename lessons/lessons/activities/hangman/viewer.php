<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unit not specified");

$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'hangman'
");

$stmt->execute(["unit" => $unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$raw = json_decode($row["data"] ?? "[]", true);
if (!is_array($raw)) {
    $raw = [];
}

$title = "Hangman";
$items = [];

if (isset($raw["title"]) || isset($raw["items"])) {
    $title = trim((string) ($raw["title"] ?? "Hangman"));
    $items = is_array($raw["items"] ?? null) ? $raw["items"] : [];
} else {
    $items = $raw;
}

$normalizedItems = [];

foreach ($items as $item) {
    if (is_string($item)) {
        $word = strtoupper(trim($item));
        if ($word !== "") {
            $normalizedItems[] = [
                "word" => $word,
                "hint" => "",
                "image" => ""
            ];
        }
        continue;
    }

    if (!is_array($item)) {
        continue;
    }

    $word = strtoupper(trim((string) ($item["word"] ?? "")));
    $hint = trim((string) ($item["hint"] ?? ""));
    $image = trim((string) ($item["image"] ?? ""));

    if ($word !== "") {
        $normalizedItems[] = [
            "word" => $word,
            "hint" => $hint,
            "image" => $image
        ];
    }
}

if (empty($normalizedItems)) {
    $normalizedItems = [[
        "word" => "TEST",
        "hint" => "",
        "image" => ""
    ]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

*{ box-sizing:border-box; }

body{
  margin:0;
  font-family:'Nunito', 'Segoe UI', sans-serif;
  background:linear-gradient(135deg, #fff8db 0%, #fff0de 50%, #f2f7e9 100%);
  text-align:center;
  padding:8px;
  color:#3f3a2b;
}

h1{
  color:#9a3412;
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:26px;
  margin:0 0 2px;
}

.subtitle{
  color:#6b5b41;
  margin:0;
  font-size:13px;
}

.hangman-intro,
.game-box{
  background:rgba(255,255,255,.86);
  border-radius:24px;
  border:1px solid rgba(255,255,255,.8);
  box-shadow:0 16px 34px rgba(15, 23, 42, .1);
}

.hangman-intro{
  max-width:1020px;
  margin:0 auto 6px;
  padding:8px 12px;
}

.game-box{
  padding:10px;
  max-width:1020px;
  margin:0 auto 6px;
}

.game-layout{
  display:grid;
  grid-template-columns:minmax(0, 1fr) 140px;
  gap:8px;
  align-items:start;
}

.left-panel{
  min-width:0;
}

.right-panel{
  display:flex;
  justify-content:flex-end;
}

.hangman-wrap{
  display:flex;
  justify-content:center;
}

#hangmanImg{
  width:150px;
  max-width:100%;
}

.word{
  font-size:22px;
  margin:4px 0 6px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:6px 8px;
}

.word-char{
  min-width:22px;
  display:inline-flex;
  justify-content:center;
  align-items:center;
  border-bottom:2px solid #111827;
  line-height:1;
  padding-bottom:4px;
  font-weight:bold;
  color:#111827;
}

.word-space{
  min-width:18px;
  border-bottom:none;
}

.keyboard{
  margin-top:4px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:5px;
  max-width:430px;
  margin-left:auto;
  margin-right:auto;
}

.keyboard button{
  width:32px;
  height:32px;
  border:none;
  border-radius:10px;
  background:linear-gradient(180deg, #fde68a 0%, #fbbf24 100%);
  color:#7c2d12;
  font-weight:800;
  font-size:11px;
  box-shadow:0 8px 18px rgba(251, 191, 36, .18);
  cursor:pointer;
}

.keyboard button:disabled{
  background:#d6d3d1;
  color:#78716c;
  box-shadow:none;
}

.controls{
  margin-top:6px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px;
}

.action-btn{
  padding:7px 10px;
  border:none;
  border-radius:999px;
  color:white;
  cursor:pointer;
  min-width:98px;
  font-weight:800;
  font-size:12px;
  font-family:'Nunito', 'Segoe UI', sans-serif;
  box-shadow:0 10px 22px rgba(15, 23, 42, .12);
  transition:transform .15s ease, filter .15s ease;
}

.action-btn:hover{
  filter:brightness(1.04);
  transform:translateY(-1px);
}

.action-check{background:linear-gradient(180deg, #f59e0b 0%, #ea580c 100%)}
.action-hint{background:linear-gradient(180deg, #2dd4bf 0%, #0f766e 100%)}
.action-answer{background:linear-gradient(180deg, #f9a8d4 0%, #ec4899 100%)}
.action-next{background:linear-gradient(180deg, #84cc16 0%, #4d7c0f 100%)}

#feedback{
  font-size:14px;
  font-weight:800;
  margin-top:5px;
  min-height:16px;
}

.good{ color:#15803d; }
.bad{ color:#dc2626; }

.hint{
  font-size:12px;
  font-weight:800;
  color:#0f766e;
  margin:4px 0;
  min-height:14px;
}

.hint-image{
  width:120px;
  max-width:120px;
  max-height:88px;
  object-fit:contain;
  border-radius:10px;
  border:1px solid #e2e8f0;
  background:#fff;
  margin:0;
  display:none;
}

a.back{
  display:inline-block;
  margin-top:4px;
  background:linear-gradient(180deg, #34d399 0%, #10b981 100%);
  color:#fff;
  padding:8px 14px;
  border-radius:999px;
  text-decoration:none;
  font-weight:800;
  box-shadow:0 10px 22px rgba(16, 185, 129, .24);
}

@media (max-width:760px){
  body{padding:8px}
  h1{font-size:21px}
  .subtitle{font-size:12px}
  .hangman-intro,
  .game-box{padding:8px}
  .game-layout{grid-template-columns:1fr}
  .right-panel{justify-content:center}
  .hint-image{max-width:108px;max-height:80px}
  #hangmanImg{width:136px}
  .action-btn{width:calc(50% - 8px);min-width:0}
}
</style>
</head>

<body>

<section class="hangman-intro">
  <h1>🎯 <?= htmlspecialchars($title) ?></h1>
  <p class="subtitle">Guess the correct word, use Hint if needed, and reveal the answer only when you want full support.</p>
</section>

<div class="game-box">
  <div class="game-layout">
    <div class="left-panel">
      <div class="hangman-wrap">
        <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="200" alt="hangman">
      </div>

      <div id="hint" class="hint"></div>

      <div id="word" class="word"></div>

      <div id="keyboard" class="keyboard"></div>

      <div class="controls">
        <button class="action-btn action-check" type="button" onclick="checkGame()">Check Answer</button>
        <button class="action-btn action-hint" type="button" onclick="showHint()">Hint</button>
        <button class="action-btn action-answer" type="button" onclick="showAnswer()">Show Answer</button>
        <button class="action-btn action-next" type="button" onclick="nextWord()">Next</button>
      </div>

      <div id="feedback"></div>
    </div>

    <aside class="right-panel">
      <img id="hintImage" class="hint-image" alt="hint image">
    </aside>
  </div>

</div>

<a class="back" href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>&source=<?= urlencode($_GET['source'] ?? '') ?>">
  ↩ Back
</a>

<audio id="correctSound" src="../../hangman/assets/realcorrect.mp3" preload="auto"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/losefun.mp3" preload="auto"></audio>
<audio id="wrongSound" src="assets/wrong.wav" preload="auto"></audio>

<script>
const items = <?= json_encode($normalizedItems, JSON_UNESCAPED_UNICODE) ?>;

// PRELOAD imágenes del ahorcado para evitar delay
const preloadedHangmanImages = [];
for (let i = 0; i <= 7; i++) {
  const img = new Image();
  img.src = `../../hangman/assets/hangman${i}.png`;
  preloadedHangmanImages.push(img);
}

const feedback = document.getElementById("feedback");
const hintEl = document.getElementById("hint");
const hintImageEl = document.getElementById("hintImage");
const hangmanImg = document.getElementById("hangmanImg");
const correctSound = document.getElementById("correctSound");
const winSound = document.getElementById("winSound");
const loseSound = document.getElementById("loseSound");
const wrongSound = document.getElementById("wrongSound");

let index = 0;
let word = "";
let hint = "";
let hintImage = "";
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;
let gameFinished = false;
let hintVisible = false;

function setKeyboardDisabled(disabledState) {
  const keys = document.querySelectorAll('#keyboard button');
  keys.forEach((keyButton) => {
    keyButton.disabled = !!disabledState;
  });
}

function playSound(audio) {
  try {
    audio.pause();
    audio.currentTime = 0;
    audio.play();
  } catch (e) {}
}

function loadWord(){
  guessed = [];
  mistakes = 0;
  gameFinished = false;
  hintVisible = false;

  const current = items[index] || { word: "TEST", hint: "", image: "" };
  word = String(current.word || "TEST").toUpperCase();
  hint = String(current.hint || "");
  hintImage = String(current.image || "");

  feedback.textContent = "";
  feedback.className = "";

  hintEl.textContent = "";
  hintImageEl.style.display = "none";
  hintImageEl.removeAttribute("src");

  hangmanImg.src = "../../hangman/assets/hangman0.png";

  buildKeyboard();
  renderWord();
}

function renderWord(){
  let html = "";

  for (let l of word) {
    if (l === " ") {
      html += `<span class="word-char word-space">&nbsp;</span>`;
    } else if (guessed.includes(l)) {
      html += `<span class="word-char">${l}</span>`;
    } else {
      html += `<span class="word-char">&nbsp;</span>`;
    }
  }

  document.getElementById("word").innerHTML = html;
}

function showHint(){
  hintVisible = true;
  hintEl.textContent = hint ? `💡 Hint: ${hint}` : "💡 No hint available.";

  if (hintImage) {
    hintImageEl.src = hintImage;
    hintImageEl.style.display = "block";
  }
}

function guess(letter){
  if (gameFinished) return;
  if (guessed.includes(letter)) return;

  guessed.push(letter);

  const btn = document.querySelector(`button[data-letter="${letter}"]`);
  if (btn) btn.disabled = true;

  if (!word.includes(letter)) {
    mistakes++;
    // ya está preloaded
    hangmanImg.src = `../../hangman/assets/hangman${mistakes}.png`;
    if (mistakes >= maxMistakes) {
      feedback.textContent = "❌ Try Again!";
      feedback.className = "bad";
      playSound(loseSound);
      gameFinished = true;
      setKeyboardDisabled(true);
    } else {
      playSound(wrongSound);
    }
  } else {
    playSound(correctSound);

    if (isSolved()) {
      if (index === items.length - 1) {
        feedback.textContent = "Completed!";
        feedback.className = "good";
        playSound(winSound);
      } else {
        feedback.textContent = "Correct!";
        feedback.className = "good";
      }
      gameFinished = true;
      setKeyboardDisabled(true);
    }
  }

  renderWord();
}

function isSolved(){
  for (let l of word) {
    if (l === " ") continue;
    if (!guessed.includes(l)) return false;
  }
  return true;
}

function checkGame(){
  if (gameFinished) return;

  if (isSolved()) {
    if (index === items.length - 1) {
      feedback.textContent = "Completed!";
      feedback.className = "good";
      playSound(winSound);
    } else {
      feedback.textContent = "Correct!";
      feedback.className = "good";
      playSound(correctSound);
    }
    gameFinished = true;
    setKeyboardDisabled(true);
    return;
  }

  if (mistakes >= maxMistakes) {
    feedback.textContent = "❌ Try Again!";
    feedback.className = "bad";
    playSound(loseSound);
    gameFinished = true;
    setKeyboardDisabled(true);
    return;
  }

  feedback.textContent = "Try Again";
  feedback.className = "bad";
}

function showAnswer(){
  if (gameFinished) return;

  guessed = [];
  for (let l of word) {
    if (l !== " ") {
      guessed.push(l);
    }
  }

  renderWord();
  feedback.textContent = "Show The Answer";
  feedback.className = "good";
  gameFinished = true;
  setKeyboardDisabled(true);
}

function nextWord(){
  if (index >= items.length - 1) {
    feedback.textContent = "Completed!";
    feedback.className = "good";
    playSound(winSound);
    gameFinished = true;
    setKeyboardDisabled(true);
    return;
  }

  index++;
  loadWord();
}

function buildKeyboard(){
  const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  let html = "";
  for (let l of letters){
    html += `<button type="button" data-letter="${l}" onclick="guess('${l}')">${l}</button>`;
  }
  document.getElementById("keyboard").innerHTML = html;
}

loadWord();
</script>

</body>
</html>

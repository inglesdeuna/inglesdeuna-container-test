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
  margin:20px 0;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px 10px;
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
  min-height:24px;
}

.good{ color:green; }
.bad{ color:crimson; }

.hint{
  font-size:16px;
  font-weight:bold;
  color:#1d4ed8;
  margin:12px 0;
  min-height:22px;
}

.hint-image{
  max-width:220px;
  max-height:170px;
  object-fit:contain;
  border-radius:12px;
  margin:10px auto 14px;
  display:none;
}

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

<h1>🎯 <?= htmlspecialchars($title) ?></h1>
<p class="subtitle">Guess the correct word.</p>

<div class="game-box">

  <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="200" alt="hangman">

  <img id="hintImage" class="hint-image" alt="hint image">

  <div id="hint" class="hint"></div>

  <div id="word" class="word"></div>

  <div id="keyboard" class="keyboard"></div>

  <div class="controls">
    <button type="button" onclick="checkGame()">✅ Check</button>
    <button type="button" onclick="showHint()">💡 Hint</button>
    <button type="button" onclick="nextWord()">➡️</button>
  </div>

  <div id="feedback"></div>

</div>

<a class="back" href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>&source=<?= urlencode($_GET['source'] ?? '') ?>">
  ↩ Back
</a>

<audio id="correctSound" src="../../hangman/assets/realcorrect.mp3" preload="auto"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/losefun.mp3" preload="auto"></audio>

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

let index = 0;
let word = "";
let hint = "";
let hintImage = "";
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;
let gameFinished = false;
let hintVisible = false;

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
      feedback.textContent = "🏆 Completed!";
      feedback.className = "good";
      playSound(winSound);
    } else {
      feedback.textContent = "🌟 Excellent!";
      feedback.className = "good";
      playSound(correctSound);
    }
    gameFinished = true;
    return;
  }

  if (mistakes >= maxMistakes) {
    feedback.textContent = "❌ You lost!";
    feedback.className = "bad";
    playSound(loseSound);
    gameFinished = true;
    return;
  }

  feedback.textContent = "🔁 Try again!";
  feedback.className = "bad";
}

function nextWord(){
  if (index >= items.length - 1) {
    feedback.textContent = "🏆 Completed!";
    feedback.className = "good";
    playSound(winSound);
    gameFinished = true;
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

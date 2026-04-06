<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET['unit'] ?? null;
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
if (!$unit && $activityId === '') die("Unit not specified");

$stmt = null;
if ($activityId !== '') {
  $stmt = $pdo->prepare("
    SELECT id, unit_id, data
    FROM activities
    WHERE id = :id
    AND type = 'hangman'
    LIMIT 1
  ");
  $stmt->execute(["id" => $activityId]);
} else {
  $stmt = $pdo->prepare("
    SELECT id, unit_id, data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'hangman'
    ORDER BY id ASC
    LIMIT 1
  ");
  $stmt->execute(["unit" => $unit]);
}

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) die("Activity not found");

if (!$unit && isset($row['unit_id'])) {
  $unit = (string) $row['unit_id'];
}

$resolvedActivityId = isset($row['id']) ? (string) $row['id'] : '';

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
  min-height:100vh;
  font-family:'Nunito', 'Segoe UI', sans-serif;
  background:linear-gradient(135deg, #fff8db 0%, #fff0de 50%, #f2f7e9 100%);
  text-align:center;
  padding:18px 22px 24px;
  color:#3f3a2b;
}

h1{
  color:#9a3412;
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:clamp(26px, 2.2vw, 30px);
  margin:0 0 4px;
  text-align:center;
}

.subtitle{
  color:#6b5b41;
  margin:0;
  font-size:15px;
  text-align:center;
}

.hangman-intro,
.game-box{
  background:rgba(255,255,255,.86);
  border-radius:24px;
  border:1px solid rgba(255,255,255,.8);
  box-shadow:0 16px 34px rgba(15, 23, 42, .1);
}

.hangman-intro{
  max-width:980px;
  margin:0 auto 14px;
  padding:16px 18px;
}

.game-box{
  padding:16px;
  max-width:980px;
  margin:0 auto 14px;
  position:relative;
}

.game-layout{
  display:flex;
  flex-direction:column;
  align-items:center;
  position:relative;
}

.left-panel{
  width:100%;
  display:flex;
  flex-direction:column;
  align-items:center;
}

.right-panel{
  position:absolute;
  top:0;
  right:0;
}

.hangman-wrap{
  display:flex;
  justify-content:center;
}

#hangmanImg{
  width:200px;
  max-width:100%;
}

.word{
  font-size:clamp(22px, 2.4vw, 30px);
  margin:8px 0 12px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px 10px;
}

.word-char{
  min-width:28px;
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
  min-width:22px;
  border-bottom:none;
}

.keyboard{
  margin-top:12px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px;
  max-width:560px;
  margin-left:auto;
  margin-right:auto;
}

.keyboard button{
  width:40px;
  height:40px;
  border:none;
  border-radius:10px;
  background:linear-gradient(180deg, #fde68a 0%, #fbbf24 100%);
  color:#7c2d12;
  font-weight:800;
  font-size:13px;
  box-shadow:0 8px 18px rgba(251, 191, 36, .18);
  cursor:pointer;
}

.keyboard button:disabled{
  background:#d6d3d1;
  color:#78716c;
  box-shadow:none;
}

.controls{
  margin-top:10px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
}

.action-btn{
  padding:11px 18px;
  border:none;
  border-radius:999px;
  color:white;
  cursor:pointer;
  min-width:142px;
  font-weight:800;
  font-size:14px;
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
  font-size:18px;
  font-weight:800;
  margin-top:10px;
  min-height:22px;
}

.good{ color:#15803d; }
.bad{ color:#dc2626; }
.word-char.revealed{ color:#dc2626; font-style:italic; }

.hint{
  font-size:14px;
  font-weight:800;
  color:#0f766e;
  margin:8px 0;
  min-height:18px;
}

.hint-image{
  width:140px;
  max-width:140px;
  max-height:110px;
  object-fit:contain;
  border-radius:10px;
  border:1px solid #e2e8f0;
  background:#fff;
  margin:0;
  display:none;
}

.hg-completed-screen{
  display:none;
  text-align:center;
  max-width:600px;
  margin:0 auto;
  padding:40px 20px;
}

.hg-completed-screen.active{
  display:block;
}

.hg-completed-icon{
  font-size:80px;
  margin-bottom:20px;
}

.hg-completed-title{
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:36px;
  font-weight:700;
  color:#9a3412;
  margin:0 0 16px;
  line-height:1.2;
}

.hg-completed-text{
  font-size:16px;
  color:#6b5b41;
  line-height:1.6;
  margin:0 0 32px;
}

.hg-completed-button{
  display:inline-block;
  padding:12px 24px;
  border:none;
  border-radius:999px;
  background:linear-gradient(180deg, #f59e0b 0%, #ea580c 100%);
  color:#fff;
  font-weight:700;
  font-size:16px;
  cursor:pointer;
  box-shadow:0 10px 24px rgba(0,0,0,.14);
  transition:transform .18s ease, filter .18s ease;
}

.hg-completed-button:hover{
  transform:scale(1.05);
  filter:brightness(1.07);
}

a.back{
  display:inline-block;
  margin-top:10px;
  background:linear-gradient(180deg, #34d399 0%, #10b981 100%);
  color:#fff;
  padding:10px 16px;
  border-radius:999px;
  text-decoration:none;
  font-weight:800;
  box-shadow:0 10px 22px rgba(16, 185, 129, .24);
}

@media (max-width:760px){
  body{padding:12px}
  h1{font-size:24px}
  .subtitle{font-size:13px}
  .hangman-intro,
  .game-box{padding:16px}
  .hint-image{max-width:120px;max-height:96px}
  #hangmanImg{width:160px}
  .keyboard button{width:36px;height:36px}
  .action-btn{width:calc(50% - 8px);min-width:0}
}

@media (max-height:900px) and (min-width:761px){
  .hangman-intro{padding:14px 16px;margin-bottom:10px}
  .game-box{padding:14px}
  .action-btn{padding:10px 16px;min-width:132px;font-size:13px}
}
</style>
</head>

<body>

<div class="game-box">
  <div class="game-layout" id="gameLayout">
    <div class="left-panel">
      <div class="hangman-wrap">
        <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="200" alt="hangman">
      </div>

      <div id="hint" class="hint"></div>

      <div id="word" class="word"></div>

      <div id="keyboard" class="keyboard"></div>

      <div class="controls">
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

  <div id="hg-completed" class="hg-completed-screen">
    <div class="hg-completed-icon">✅</div>
    <h2 class="hg-completed-title" id="hg-completed-title"></h2>
    <p class="hg-completed-text" id="hg-completed-text"></p>
    <p class="hg-completed-text" id="hg-score-text" style="font-weight:700;font-size:18px;color:#9a3412;"></p>
    <button type="button" class="hg-completed-button" id="hg-restart" onclick="restartActivity()">Restart</button>
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
const activityTitle = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;
const HG_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const HG_ACTIVITY_ID = <?= json_encode($resolvedActivityId, JSON_UNESCAPED_UNICODE) ?>;

// Preload hangman images to avoid delay
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
const gameLayout = document.getElementById("gameLayout");
const completedEl = document.getElementById("hg-completed");
const completedTitleEl = document.getElementById("hg-completed-title");
const completedTextEl = document.getElementById("hg-completed-text");
const scoreTextEl = document.getElementById("hg-score-text");

if (completedTitleEl) {
  completedTitleEl.textContent = activityTitle || 'Hangman';
}

if (completedTextEl) {
  completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
}

let index = 0;
let word = "";
let hint = "";
let hintImage = "";
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;
let gameFinished = false;
let hintVisible = false;
let correctCount = 0;
let totalCount = items.length;
let scoredWordsByIndex = {};

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

function persistScoreSilently(targetUrl) {
  if (!targetUrl) {
    return Promise.resolve(false);
  }

  return fetch(targetUrl, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
  }).then(function (response) {
    return !!(response && response.ok);
  }).catch(function () {
    return false;
  });
}

function navigateToReturn(targetUrl) {
  if (!targetUrl) {
    return;
  }

  try {
    if (window.top && window.top !== window.self) {
      window.top.location.href = targetUrl;
      return;
    }
  } catch (e) {}

  window.location.href = targetUrl;
}

function registerSolvedWord() {
  if (scoredWordsByIndex[index]) {
    return;
  }

  scoredWordsByIndex[index] = true;
  correctCount += 1;
}

function loadWord(){
  guessed = [];
  mistakes = 0;
  gameFinished = false;
  hintVisible = false;

  if (completedEl) {
    completedEl.classList.remove('active');
  }

  if (gameLayout) {
    gameLayout.style.display = 'grid';
  }

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

async function showCompleted() {
  gameFinished = true;
  feedback.textContent = '';
  feedback.className = '';
  setKeyboardDisabled(true);

  const pct = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
  const errors = Math.max(0, totalCount - correctCount);

  if (gameLayout) {
    gameLayout.style.display = 'none';
  }

  if (completedEl) {
    completedEl.classList.add('active');
  }

  if (scoreTextEl) {
    scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
  }

  playSound(winSound);

  if (HG_ACTIVITY_ID && HG_RETURN_TO) {
    const joiner = HG_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    const saveUrl = HG_RETURN_TO
      + joiner + 'activity_percent=' + pct
      + '&activity_errors=' + errors
      + '&activity_total=' + totalCount
      + '&activity_id=' + encodeURIComponent(HG_ACTIVITY_ID)
      + '&activity_type=hangman';

    const ok = await persistScoreSilently(saveUrl);
    if (!ok) {
      navigateToReturn(saveUrl);
    }
  }
}

function restartActivity() {
  index = 0;
  correctCount = 0;
  totalCount = items.length;
  scoredWordsByIndex = {};
  loadWord();
}

function renderWord(revealMissing = false){
  let html = "";

  for (let l of word) {
    if (l === " ") {
      html += `<span class="word-char word-space">&nbsp;</span>`;
    } else if (guessed.includes(l)) {
      html += `<span class="word-char">${l}</span>`;
    } else if (revealMissing) {
      html += `<span class="word-char revealed">${l}</span>`;
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
    // already preloaded
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
      registerSolvedWord();
      if (index === items.length - 1) {
        showCompleted();
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
    registerSolvedWord();
    if (index === items.length - 1) {
      showCompleted();
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
  renderWord(true);
  feedback.textContent = "Show The Answer";
  feedback.className = "good";
  gameFinished = true;
  setKeyboardDisabled(true);
}

function nextWord(){
  if (index >= items.length - 1) {
    showCompleted();
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

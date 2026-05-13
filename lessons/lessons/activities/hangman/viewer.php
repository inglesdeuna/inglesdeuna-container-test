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
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap');

:root{
  --hg-orange:#F97316;
  --hg-orange-dark:#C2580A;
  --hg-orange-soft:#FFF0E6;
  --hg-purple:#7F77DD;
  --hg-purple-dark:#534AB7;
  --hg-purple-soft:#EEEDFE;
  --hg-muted:#9B94BE;
  --hg-border:#F0EEF8;
  --hg-track:#F4F2FD;
  --hg-white:#FFFFFF;
}

*{box-sizing:border-box}

html,body{width:100%;min-height:100%}

body{
  margin:0!important;
  padding:0!important;
  min-height:0;
  font-family:'Nunito','Segoe UI',sans-serif;
  background:#ffffff;
  color:#534AB7;
  text-align:center;
}

.hg-shell{
  width:100%;
  flex:1;
  min-height:0;
  overflow-y:auto;
  padding:clamp(14px,2.5vw,34px);
  display:flex;
  justify-content:center;
  align-items:flex-start;
  background:#ffffff;
}

.hg-app{
  width:min(900px,100%);
  display:grid;
  gap:clamp(12px,2vw,18px);
}

.hg-header{text-align:center}

.hg-kicker{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  margin-bottom:8px;
  padding:7px 14px;
  border-radius:999px;
  background:var(--hg-orange-soft);
  border:1px solid #FCDDBF;
  color:var(--hg-orange-dark);
  font-size:12px;
  font-weight:900;
  letter-spacing:.08em;
  text-transform:uppercase;
}

.hg-title{
  margin:0;
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:clamp(30px,5.5vw,58px);
  line-height:1.03;
  color:var(--hg-orange);
  font-weight:700;
}

.hg-subtitle{
  margin:8px 0 0;
  color:var(--hg-muted);
  font-size:clamp(13px,1.8vw,17px);
  font-weight:800;
}

.game-box{
  width:100%;
  margin:0 auto;
  padding:clamp(16px,2.6vw,26px);
  border-radius:34px;
  background:var(--hg-white);
  border:1px solid var(--hg-border);
  box-shadow:0 8px 40px rgba(127,119,221,.13);
  position:relative;
}

.game-layout{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(150px,210px);
  gap:clamp(14px,2.4vw,24px);
  align-items:start;
}

.left-panel{
  min-width:0;
  display:flex;
  flex-direction:column;
  align-items:center;
}

.right-panel{
  width:100%;
  display:flex;
  align-items:flex-start;
  justify-content:center;
}

.hg-visual-card{
  width:100%;
  max-width:620px;
  border:1px solid #EDE9FA;
  border-radius:30px;
  background:#ffffff;
  box-shadow:0 8px 24px rgba(127,119,221,.09);
  padding:clamp(16px,2.4vw,22px);
  display:flex;
  flex-direction:column;
  align-items:center;
}

.hg-status-row{
  width:100%;
  display:grid;
  grid-template-columns:1fr auto;
  gap:10px;
  align-items:center;
  margin-bottom:14px;
}

.hg-track{
  height:12px;
  background:var(--hg-track);
  border:1px solid #E4E1F8;
  border-radius:999px;
  overflow:hidden;
}

.hg-fill{
  height:100%;
  width:0%;
  border-radius:999px;
  background:linear-gradient(90deg,var(--hg-orange),var(--hg-purple));
  transition:width .35s ease;
}

.hg-count{
  min-width:74px;
  text-align:center;
  padding:7px 11px;
  border-radius:999px;
  background:var(--hg-purple);
  color:#fff;
  font-size:12px;
  font-weight:900;
}

.hangman-wrap{
  display:flex;
  justify-content:center;
  align-items:center;
  width:min(210px,58vw);
  height:min(210px,58vw);
  max-height:220px;
  margin:0 auto 10px;
  border-radius:26px;
  background:#ffffff;
  border:1px solid #EDE9FA;
  box-shadow:0 10px 28px rgba(127,119,221,.10);
  overflow:hidden;
}

#hangmanImg{
  width:78%;
  max-width:170px;
  object-fit:contain;
  display:block;
}

.hint{
  width:100%;
  min-height:20px;
  margin:4px 0 10px;
  color:var(--hg-muted);
  font-size:13px;
  font-weight:800;
  line-height:1.45;
}

.word{
  width:100%;
  margin:8px 0 14px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px 10px;
}

.word-char{
  min-width:28px;
  height:38px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-bottom:2px solid #D7D3F3;
  color:var(--hg-purple);
  font-size:clamp(20px,3vw,30px);
  font-weight:900;
  line-height:1;
}

.word-space{min-width:18px;border-bottom:none}
.word-char.revealed{color:var(--hg-orange);font-style:normal}

.keyboard{
  width:100%;
  max-width:590px;
  margin:10px auto 0;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px;
}

.keyboard button{
  width:40px;
  height:40px;
  border:1px solid #EDE9FA;
  border-radius:14px;
  background:#ffffff;
  color:var(--hg-purple-dark);
  font-family:'Nunito','Segoe UI',sans-serif;
  font-weight:900;
  font-size:13px;
  box-shadow:0 5px 14px rgba(127,119,221,.12);
  cursor:pointer;
  transition:transform .16s ease, background .16s ease, color .16s ease, box-shadow .16s ease;
}

.keyboard button:hover:not(:disabled){
  transform:translateY(-1px);
  background:var(--hg-purple-soft);
  box-shadow:0 9px 18px rgba(127,119,221,.18);
}

.keyboard button:disabled{
  background:#F4F2FD;
  color:#B8B2D5;
  box-shadow:none;
  cursor:not-allowed;
}

.controls{
  margin-top:16px;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
}

.action-btn,
.hg-completed-button,
a.back{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:clamp(104px,16vw,146px);
  padding:13px 20px;
  border:0;
  border-radius:999px;
  color:#ffffff;
  font-family:'Nunito','Segoe UI',sans-serif;
  font-size:13px;
  font-weight:900;
  line-height:1;
  text-decoration:none;
  cursor:pointer;
  transition:filter .15s ease, transform .15s ease;
}

.action-btn:hover,
.hg-completed-button:hover,
a.back:hover{
  filter:brightness(1.06);
  transform:translateY(-1px);
}

.action-hint,.action-answer{background:var(--hg-purple);box-shadow:0 6px 18px rgba(127,119,221,.18)}
.action-next,.hg-completed-button{background:var(--hg-orange);box-shadow:0 6px 18px rgba(249,115,22,.22)}
a.back{background:var(--hg-purple);box-shadow:0 6px 18px rgba(127,119,221,.18);justify-self:center;margin:2px auto 0}

#feedback{
  min-height:24px;
  margin-top:12px;
  color:var(--hg-muted);
  font-size:15px;
  font-weight:900;
}

.good{color:var(--hg-purple-dark)!important}
.bad{color:var(--hg-orange-dark)!important}

.hint-image-card{
  width:100%;
  min-height:190px;
  border-radius:28px;
  border:1px solid #EDE9FA;
  background:#ffffff;
  box-shadow:0 8px 24px rgba(127,119,221,.09);
  padding:14px;
  display:flex;
  align-items:center;
  justify-content:center;
}

.hint-image{
  width:100%;
  max-width:180px;
  max-height:170px;
  object-fit:contain;
  border-radius:20px;
  display:none;
}

.hg-placeholder{
  width:100%;
  min-height:150px;
  border-radius:22px;
  background:#FAFAFD;
  color:#D5D0F0;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:42px;
  font-weight:900;
}

.hg-completed-screen{
  display:none;
  width:min(680px,100%);
  margin:0 auto;
  text-align:center;
  padding:clamp(34px,5vw,54px) clamp(18px,4vw,34px);
  border-radius:34px;
  background:#ffffff;
}

.hg-completed-screen.active{display:block;animation:hgPop .35s ease}

.hg-completed-icon{
  width:72px;
  height:72px;
  margin:0 auto 16px;
  border-radius:999px;
  background:var(--hg-purple-soft);
  color:var(--hg-purple);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:34px;
  font-weight:900;
}

.hg-completed-title{
  margin:0 0 10px;
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:clamp(30px,5.5vw,58px);
  color:var(--hg-orange);
  line-height:1.03;
  font-weight:700;
}

.hg-completed-text,
#hg-score-text{
  margin:0 auto 14px!important;
  max-width:520px;
  color:var(--hg-muted)!important;
  font-size:clamp(13px,1.8vw,17px)!important;
  font-weight:800!important;
  line-height:1.5;
}

@keyframes hgPop{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:none}}

@media(max-width:760px){
  .hg-shell{padding:12px}
  .game-box{border-radius:26px;padding:14px}
  .game-layout{grid-template-columns:1fr;gap:14px}
  .right-panel{order:-1}
  .hint-image-card{min-height:128px;padding:10px;border-radius:24px}
  .hint-image{max-height:112px;max-width:150px}
  .hg-placeholder{min-height:104px;font-size:34px}
  .hangman-wrap{width:150px;height:150px;border-radius:22px}
  #hangmanImg{max-width:122px}
  .word-char{min-width:24px;height:34px;font-size:20px}
  .keyboard{gap:7px}
  .keyboard button{width:34px;height:34px;border-radius:12px;font-size:12px}
  .controls{display:grid;grid-template-columns:1fr;gap:9px;width:100%}
  .action-btn{width:100%}
}
</style>
</head>

<body>
<div class="hg-shell">
  <main class="hg-app">
    <header class="hg-header">
      <div class="hg-kicker">Activity <span id="hg-kicker-count">1 / <?= count($normalizedItems) ?></span></div>
      <h1 class="hg-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="hg-subtitle">Guess the word with clean, focused practice.</p>
    </header>

    <section class="game-box">
      <div class="game-layout" id="gameLayout">
        <div class="left-panel">
          <div class="hg-visual-card">
            <div class="hg-status-row">
              <div class="hg-track"><div class="hg-fill" id="hg-progress-fill"></div></div>
              <div class="hg-count" id="hg-progress-count">1 / <?= count($normalizedItems) ?></div>
            </div>

            <div class="hangman-wrap">
              <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="200" alt="hangman">
            </div>

            <div id="hint" class="hint"></div>
            <div id="word" class="word"></div>
            <div id="keyboard" class="keyboard"></div>

            <div class="controls">
              <button class="action-btn action-hint" type="button" onclick="showHint()">Hint</button>
              <button class="action-btn action-answer" type="button" onclick="showAnswer()">Show Text</button>
              <button class="action-btn action-next" type="button" onclick="nextWord()">Next</button>
            </div>

            <div id="feedback"></div>
          </div>
        </div>

        <aside class="right-panel">
          <div class="hint-image-card">
            <img id="hintImage" class="hint-image" alt="hint image">
            <div class="hg-placeholder" id="hintPlaceholder">?</div>
          </div>
        </aside>
      </div>

      <div id="hg-completed" class="hg-completed-screen">
        <div class="hg-completed-icon">✓</div>
        <h2 class="hg-completed-title" id="hg-completed-title"></h2>
        <p class="hg-completed-text" id="hg-completed-text"></p>
        <p class="hg-completed-text" id="hg-score-text"></p>
        <button type="button" class="hg-completed-button" id="hg-restart" onclick="restartActivity()">Restart</button>
      </div>
    </section>

  </main>
</div>

<audio id="correctSound" src="../../hangman/assets/realcorrect.mp3" preload="auto"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/losefun.mp3" preload="auto"></audio>
<audio id="wrongSound" src="assets/wrong.wav" preload="auto"></audio>

<script>
const items = <?= json_encode($normalizedItems, JSON_UNESCAPED_UNICODE) ?>;
const activityTitle = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;
const HG_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const HG_ACTIVITY_ID = <?= json_encode($resolvedActivityId, JSON_UNESCAPED_UNICODE) ?>;

const preloadedHangmanImages = [];
for (let i = 0; i <= 7; i++) {
  const img = new Image();
  img.src = `../../hangman/assets/hangman${i}.png`;
  preloadedHangmanImages.push(img);
}

const feedback = document.getElementById("feedback");
const hintEl = document.getElementById("hint");
const hintImageEl = document.getElementById("hintImage");
const hintPlaceholderEl = document.getElementById("hintPlaceholder");
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
const progressFillEl = document.getElementById("hg-progress-fill");
const progressCountEl = document.getElementById("hg-progress-count");
const kickerCountEl = document.getElementById("hg-kicker-count");

if (completedTitleEl) completedTitleEl.textContent = activityTitle || 'Hangman';
if (completedTextEl) completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';

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

function updateProgress(){
  const countText = (index + 1) + ' / ' + totalCount;
  const pct = totalCount > 0 ? Math.max(1, Math.round(((index + 1) / totalCount) * 100)) : 0;
  if (progressFillEl) progressFillEl.style.width = pct + '%';
  if (progressCountEl) progressCountEl.textContent = countText;
  if (kickerCountEl) kickerCountEl.textContent = countText;
}

function setKeyboardDisabled(disabledState) {
  document.querySelectorAll('#keyboard button').forEach((keyButton) => {
    keyButton.disabled = !!disabledState;
  });
}

function playSound(audio) {
  try { audio.pause(); audio.currentTime = 0; audio.play(); } catch (e) {}
}

function persistScoreSilently(targetUrl) {
  if (!targetUrl) return Promise.resolve(false);
  return fetch(targetUrl, { method:'GET', credentials:'same-origin', cache:'no-store' })
    .then(function (response) { return !!(response && response.ok); })
    .catch(function () { return false; });
}

function navigateToReturn(targetUrl) {
  if (!targetUrl) return;
  try {
    if (window.top && window.top !== window.self) {
      window.top.location.href = targetUrl;
      return;
    }
  } catch (e) {}
  window.location.href = targetUrl;
}

function registerSolvedWord() {
  if (scoredWordsByIndex[index]) return;
  scoredWordsByIndex[index] = true;
  correctCount += 1;
}

function loadWord(){
  guessed = [];
  mistakes = 0;
  gameFinished = false;
  hintVisible = false;

  if (completedEl) completedEl.classList.remove('active');
  if (gameLayout) gameLayout.style.display = 'grid';

  const current = items[index] || { word:"TEST", hint:"", image:"" };
  word = String(current.word || "TEST").toUpperCase();
  hint = String(current.hint || "");
  hintImage = String(current.image || "");

  feedback.textContent = "";
  feedback.className = "";
  hintEl.textContent = "";
  hintImageEl.style.display = "none";
  hintImageEl.removeAttribute("src");
  if (hintPlaceholderEl) hintPlaceholderEl.style.display = "flex";
  hangmanImg.src = "../../hangman/assets/hangman0.png";

  updateProgress();
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

  if (gameLayout) gameLayout.style.display = 'none';
  if (completedEl) completedEl.classList.add('active');
  if (scoreTextEl) scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
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
    if (!ok) navigateToReturn(saveUrl);
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
    if (l === " ") html += `<span class="word-char word-space">&nbsp;</span>`;
    else if (guessed.includes(l)) html += `<span class="word-char">${l}</span>`;
    else if (revealMissing) html += `<span class="word-char revealed">${l}</span>`;
    else html += `<span class="word-char">&nbsp;</span>`;
  }
  document.getElementById("word").innerHTML = html;
}

function showHint(){
  hintVisible = true;
  hintEl.textContent = hint ? `Hint: ${hint}` : "No hint available.";
  if (hintImage) {
    hintImageEl.src = hintImage;
    hintImageEl.style.display = "block";
    if (hintPlaceholderEl) hintPlaceholderEl.style.display = "none";
  }
}

function guess(letter){
  if (gameFinished || guessed.includes(letter)) return;
  guessed.push(letter);

  const btn = document.querySelector(`button[data-letter="${letter}"]`);
  if (btn) btn.disabled = true;

  if (!word.includes(letter)) {
    mistakes++;
    hangmanImg.src = `../../hangman/assets/hangman${mistakes}.png`;
    if (mistakes >= maxMistakes) {
      feedback.textContent = "Try Again";
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
      if (index === items.length - 1) showCompleted();
      else {
        feedback.textContent = "Correct";
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

function showAnswer(){
  renderWord(true);
  feedback.textContent = "Text shown";
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

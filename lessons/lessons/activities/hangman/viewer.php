<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

$activityId = isset($_GET["id"]) ? trim((string) $_GET["id"]) : "";
$unit = isset($_GET["unit"]) ? trim((string) $_GET["unit"]) : "";

if ($activityId === "" && $unit === "") {
    die("Actividad no especificada");
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === "") {
        return "";
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(["id" => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row["unit_id"]) ? (string) $row["unit_id"] : "";
}

function default_hangman_title(): string
{
    return "Hangman";
}

function normalize_hangman_payload($rawData): array
{
    $default = [
        "title" => default_hangman_title(),
        "items" => [],
    ];

    if ($rawData === null || $rawData === "") {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded["title"] ?? ""));
    $itemsSource = $decoded;

    if (isset($decoded["items"]) && is_array($decoded["items"])) {
        $itemsSource = $decoded["items"];
    } elseif (isset($decoded["words"]) && is_array($decoded["words"])) {
        $itemsSource = $decoded["words"];
    }

    $items = [];
    foreach ($itemsSource as $item) {
        if (is_string($item)) {
            $word = strtoupper(trim($item));
            if ($word !== "") {
                $items[] = ["word" => $word, "hint" => "", "image" => ""];
            }
            continue;
        }

        if (!is_array($item)) {
            continue;
        }

        $word = strtoupper(trim((string) ($item["word"] ?? "")));
        $hint = trim((string) ($item["hint"] ?? ""));
        $image = trim((string) ($item["image"] ?? ""));

        if ($word === "") {
            continue;
        }

        $items[] = [
            "word" => $word,
            "hint" => $hint,
            "image" => $image,
        ];
    }

    return [
        "title" => $title !== "" ? $title : default_hangman_title(),
        "items" => $items,
    ];
}

function load_hangman_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        "title" => default_hangman_title(),
        "items" => [],
    ];

    $row = null;

    if ($activityId !== "") {
        $stmt = $pdo->prepare("
            SELECT data
            FROM activities
            WHERE id = :id
              AND type = 'hangman'
            LIMIT 1
        ");
        $stmt->execute(["id" => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== "") {
        $stmt = $pdo->prepare("
            SELECT data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'hangman'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(["unit" => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    return normalize_hangman_payload($row["data"] ?? null);
}

if ($unit === "" && $activityId !== "") {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_hangman_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity["title"] ?? default_hangman_title());
$items = is_array($activity["items"] ?? null) ? $activity["items"] : [];

if (empty($items)) {
    $items = [["word" => "TEST", "hint" => "", "image" => ""]];
}

ob_start();
?>

<style>
.hg-viewer{
  max-width:860px;
  margin:0 auto;
  text-align:center;
}
.hg-subtitle{
  color:#4b5563;
  margin:0 0 16px;
  font-size:16px;
}
.hg-game-box{
  background:#fff;
  border-radius:18px;
  padding:24px;
  box-shadow:0 8px 24px rgba(0,0,0,.08);
}
.hg-visual{
  min-height:240px;
  display:flex;
  align-items:center;
  justify-content:center;
  margin-bottom:10px;
}
.hg-hangman-img{
  width:220px;
  max-width:100%;
  display:block;
  margin:0 auto;
}
.hg-hint-image{
  max-width:220px;
  max-height:180px;
  object-fit:contain;
  border-radius:14px;
  display:none;
  margin:0 auto 12px;
}
.hg-hint{
  margin:0 0 16px;
  font-size:16px;
  color:#1f3b8a;
  font-weight:700;
  min-height:24px;
}
.hg-word{
  font-size:34px;
  margin:20px 0;
  font-weight:800;
  color:#1f2937;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px 10px;
}
.hg-char{
  min-width:24px;
  display:inline-flex;
  justify-content:center;
  align-items:center;
  border-bottom:2px solid #1f2937;
  line-height:1;
  padding-bottom:4px;
}
.hg-char.space{
  min-width:18px;
  border-bottom:none;
}
.hg-keyboard{
  margin-top:15px;
}
.hg-keyboard button{
  padding:8px 14px;
  margin:4px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}
.hg-keyboard button:disabled{
  background:#9ca3af;
  cursor:default;
}
.hg-controls{
  margin-top:15px;
}
.hg-controls button{
  padding:10px 18px;
  border:none;
  border-radius:12px;
  background:#0b5ed7;
  color:white;
  cursor:pointer;
  margin:6px;
  font-weight:700;
}
.hg-controls button:disabled{
  opacity:.55;
  cursor:default;
}
#feedback{
  font-size:20px;
  font-weight:800;
  margin-top:14px;
  min-height:28px;
}
.good{ color:green; }
.bad{ color:crimson; }
</style>

<div class="hg-viewer">
  <p class="hg-subtitle">Guess the correct word.</p>

  <div class="hg-game-box">
    <div class="hg-visual">
      <img id="hangmanImg" class="hg-hangman-img" src="../../hangman/assets/hangman0.png" alt="hangman">
    </div>

    <img id="hintImage" class="hg-hint-image" alt="hint image">

    <div id="hint" class="hg-hint"></div>

    <div id="word" class="hg-word"></div>

    <div id="keyboard" class="hg-keyboard"></div>

    <div class="hg-controls">
      <button type="button" id="checkBtn" onclick="checkGame()">✅ Check</button>
      <button type="button" id="hintBtn" onclick="showHint()">💡 Hint</button>
      <button type="button" id="nextBtn" onclick="nextWord()">➡️ Next</button>
    </div>

    <div id="feedback"></div>
  </div>
</div>

<audio id="correctSound" src="../../hangman/assets/realcorrect.mp3" preload="auto"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/losefun.mp3" preload="auto"></audio>

<script>
const items = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
const hangmanImages = [];

for (let i = 0; i <= 7; i++) {
  const img = new Image();
  img.src = `../../hangman/assets/hangman${i}.png`;
  hangmanImages.push(img);
}

let index = 0;
let current = items[index];
let word = (current.word || 'TEST').toUpperCase();
let hint = current.hint || '';
let hintImage = current.image || '';
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;
let hintVisible = false;
let roundSolved = false;
let roundLost = false;
let finished = false;

const feedback = document.getElementById("feedback");
const hangmanImg = document.getElementById("hangmanImg");
const hintEl = document.getElementById("hint");
const hintImageEl = document.getElementById("hintImage");
const keyboardEl = document.getElementById("keyboard");
const checkBtn = document.getElementById("checkBtn");
const hintBtn = document.getElementById("hintBtn");
const nextBtn = document.getElementById("nextBtn");
const correctSound = document.getElementById("correctSound");
const winSound = document.getElementById("winSound");
const loseSound = document.getElementById("loseSound");

function playSound(audioEl) {
  try {
    audioEl.pause();
    audioEl.currentTime = 0;
    audioEl.play();
  } catch (e) {}
}

function sanitizeWord(text) {
  return String(text || "").toUpperCase();
}

function setRoundButtons() {
  checkBtn.disabled = finished;
  hintBtn.disabled = finished;
  nextBtn.disabled = finished;
}

function updateHintVisual() {
  if (hintVisible && hintImage) {
    hintImageEl.src = hintImage;
    hintImageEl.style.display = 'block';
  } else {
    hintImageEl.removeAttribute('src');
    hintImageEl.style.display = 'none';
  }

  if (hintVisible) {
    hintEl.textContent = hint ? "💡 Hint: " + hint : "💡 No hint available.";
  } else {
    hintEl.textContent = "";
  }
}

function updateHangmanImage() {
  hangmanImg.src = `../../hangman/assets/hangman${mistakes}.png`;
}

function isSolved() {
  return word.split("").every(letter => {
    if (letter === " ") return true;
    return guessed.includes(letter);
  });
}

function renderWord() {
  const html = word.split("").map(letter => {
    if (letter === " ") {
      return '<span class="hg-char space">&nbsp;</span>';
    }
    if (guessed.includes(letter)) {
      return `<span class="hg-char">${letter}</span>`;
    }
    return '<span class="hg-char">&nbsp;</span>';
  }).join("");

  document.getElementById("word").innerHTML = html;
}

function showHint() {
  hintVisible = true;
  updateHintVisual();
}

function guess(letter) {
  if (finished || roundSolved || roundLost) return;
  if (guessed.includes(letter)) return;

  guessed.push(letter);

  const btn = document.querySelector(`button[data-letter="${letter}"]`);
  if (btn) btn.disabled = true;

  if (!word.includes(letter)) {
    mistakes = Math.min(maxMistakes, mistakes + 1);
    updateHangmanImage();
  }

  renderWord();
}

function checkGame() {
  if (finished) return;

  if (isSolved()) {
    if (index === items.length - 1) {
      feedback.textContent = "🏆 Completed!";
      feedback.className = "good";
      finished = true;
      playSound(winSound);
    } else {
      feedback.textContent = "🌟 Excellent!";
      feedback.className = "good";
      roundSolved = true;
      playSound(correctSound);
    }
    setRoundButtons();
    return;
  }

  if (mistakes >= maxMistakes) {
    feedback.textContent = "❌ You lost!";
    feedback.className = "bad";
    roundLost = true;
    playSound(loseSound);
    setRoundButtons();
    return;
  }

  feedback.textContent = "🔁 Try again!";
  feedback.className = "bad";
}

function nextWord() {
  if (finished) return;

  if (index >= items.length - 1) {
    feedback.textContent = "🏆 Completed!";
    feedback.className = "good";
    finished = true;
    playSound(winSound);
    setRoundButtons();
    return;
  }

  index++;
  loadWord();
}

function buildKeyboard() {
  const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  let html = "";
  for (const l of letters) {
    html += `<button type="button" data-letter="${l}" onclick="guess('${l}')">${l}</button>`;
  }
  keyboardEl.innerHTML = html;
}

function loadWord() {
  guessed = [];
  mistakes = 0;
  hintVisible = false;
  roundSolved = false;
  roundLost = false;

  current = items[index] || { word: "TEST", hint: "", image: "" };
  word = sanitizeWord(current.word || "TEST");
  hint = current.hint || "";
  hintImage = current.image || "";

  feedback.textContent = "";
  feedback.className = "";

  updateHangmanImage();
  updateHintVisual();
  buildKeyboard();
  renderWord();
  setRoundButtons();
}

loadWord();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, "🎯", $content);

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
                $items[] = ["word" => $word, "hint" => ""];
            }
            continue;
        }

        if (!is_array($item)) {
            continue;
        }

        $word = strtoupper(trim((string) ($item["word"] ?? "")));
        $hint = trim((string) ($item["hint"] ?? ""));

        if ($word === "") {
            continue;
        }

        $items[] = [
            "word" => $word,
            "hint" => $hint,
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
    $items = [["word" => "TEST", "hint" => ""]];
}

ob_start();
?>

<style>
.hg-viewer{
  max-width:820px;
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
.hg-hint{
  margin:0 0 16px;
  font-size:16px;
  color:#1f3b8a;
  font-weight:700;
  min-height:24px;
}
.hg-word{
  font-size:34px;
  letter-spacing:10px;
  margin:20px 0;
  font-weight:800;
  color:#1f2937;
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
    <img id="hangmanImg" src="../../hangman/assets/hangman0.png" width="220" alt="hangman">

    <div id="hint" class="hg-hint"></div>

    <div id="word" class="hg-word"></div>

    <div id="keyboard" class="hg-keyboard"></div>

    <div class="hg-controls">
      <button type="button" onclick="checkGame()">✅ Check</button>
      <button type="button" onclick="showHint()">💡 Hint</button>
      <button type="button" onclick="nextWord()">➡️ Next</button>
    </div>

    <div id="feedback"></div>
  </div>
</div>

<audio id="correctSound" src="../../hangman/assets/realcorrect.mp3"></audio>
<audio id="winSound" src="../../hangman/assets/win.mp3"></audio>
<audio id="loseSound" src="../../hangman/assets/losefun.mp3"></audio>

<script>
const items = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let current = items[index];
let word = current.word;
let hint = current.hint || '';
let guessed = [];
let mistakes = 0;
let maxMistakes = 7;
let hintVisible = false;

const feedback = document.getElementById("feedback");

function loadWord(){
  guessed = [];
  mistakes = 0;
  hintVisible = false;
  current = items[index];
  word = (current.word || 'TEST').toUpperCase();
  hint = current.hint || '';

  feedback.textContent = "";
  feedback.className = "";

  document.getElementById("hangmanImg").src = "../../hangman/assets/hangman0.png";
  document.getElementById("hint").textContent = "";

  buildKeyboard();
  renderWord();
}

function renderWord(){
  let display = "";
  for (let l of word){
    display += guessed.includes(l) ? l + " " : "_ ";
  }
  document.getElementById("word").innerText = display;
}

function showHint(){
  hintVisible = true;
  document.getElementById("hint").textContent = hint ? "💡 Hint: " + hint : "💡 No hint available.";
}

function guess(letter){
  if (guessed.includes(letter)) return;

  guessed.push(letter);

  const btn = document.querySelector(`button[data-letter="${letter}"]`);
  if (btn) btn.disabled = true;

  if (!word.includes(letter)){
    mistakes++;
    document.getElementById("hangmanImg").src = "../../hangman/assets/hangman" + mistakes + ".png";
  }

  renderWord();
}

function checkGame(){
  const completed = word.split("").every(l => guessed.includes(l));

  if (completed){
    feedback.textContent = "🌟 Excellent!";
    feedback.className = "good";
    document.getElementById("correctSound").currentTime = 0;
    document.getElementById("correctSound").play();
    return;
  }

  if (mistakes >= maxMistakes){
    feedback.textContent = "❌ You lost!";
    feedback.className = "bad";
    document.getElementById("loseSound").currentTime = 0;
    document.getElementById("loseSound").play();
    return;
  }

  feedback.textContent = "🔁 Try again!";
  feedback.className = "bad";
}

function nextWord(){
  index++;

  if (index >= items.length){
    feedback.textContent = "🏆 Completed!";
    feedback.className = "good";
    document.getElementById("winSound").currentTime = 0;
    document.getElementById("winSound").play();
    return;
  }

  loadWord();
}

function buildKeyboard(){
  let letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  let html = "";
  for (let l of letters){
    html += `<button type="button" data-letter="${l}" onclick="guess('${l}')">${l}</button>`;
  }
  document.getElementById("keyboard").innerHTML = html;
}

loadWord();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, "🎯", $content);

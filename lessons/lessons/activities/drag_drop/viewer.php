<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
  die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_drag_drop_title(): string
{
    return 'Build the Sentence';
}

function parse_listen_value($raw): bool
{
    if (is_bool($raw)) return $raw;
    if (is_numeric($raw)) return (int) $raw === 1;
    if (is_string($raw)) {
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) return false;
    }
    return true;
}

function normalize_drag_drop_payload($rawData): array
{
    $default = [
        'title' => default_drag_drop_title(),
        'blocks' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    $blocksSource = $decoded;

    if (isset($decoded['blocks']) && is_array($decoded['blocks'])) {
        $blocksSource = $decoded['blocks'];
    }

    $blocks = [];

    foreach ($blocksSource as $block) {
        if (!is_array($block)) {
            continue;
        }

        $text = '';
        if (isset($block['text']) && is_string($block['text'])) {
            $text = trim($block['text']);
        } elseif (isset($block['sentence']) && is_string($block['sentence'])) {
            $text = trim($block['sentence']);
        }

        $missingWords = [];
        if (isset($block['missing_words']) && is_array($block['missing_words'])) {
            foreach ($block['missing_words'] as $word) {
                $w = trim((string) $word);
                if ($w !== '') {
                    $missingWords[] = $w;
                }
            }
        }

        if ($text === '') {
            continue;
        }

        $listenEnabled = array_key_exists('listen_enabled', $block)
            ? parse_listen_value($block['listen_enabled'])
            : (array_key_exists('listen', $block) ? parse_listen_value($block['listen']) : true);

        $blocks[] = [
            'text' => $text,
            'missing_words' => $missingWords,
            'listen_enabled' => $listenEnabled,
        ];
    }

    return [
        'title' => $title !== '' ? $title : default_drag_drop_title(),
        'blocks' => $blocks,
    ];
}

function load_drag_drop_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'title' => default_drag_drop_title(),
        'blocks' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT data
            FROM activities
            WHERE id = :id
              AND type = 'drag_drop'
            LIMIT 1
        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'drag_drop'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    return normalize_drag_drop_payload($row['data'] ?? null);
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_drag_drop_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? default_drag_drop_title());
$blocks = is_array($activity['blocks'] ?? null) ? $activity['blocks'] : [];

if (count($blocks) === 0) {
  die('No sentences found for this unit');
}

ob_start();
?>
<style>
.dd-stage{
  max-width:980px;
  margin:0 auto;
}

.dd-intro{
  margin-bottom:18px;
  padding:24px 26px;
  border-radius:26px;
  border:1px solid #ffd8b8;
  background:linear-gradient(135deg, #fff2e2 0%, #fff8e7 52%, #f4eadc 100%);
  box-shadow:0 16px 34px rgba(15, 23, 42, .09);
}

.dd-intro h2{
  margin:0 0 8px;
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:30px;
  line-height:1.1;
  color:#9a3412;
}

.dd-intro p,
.instructions{
  margin:0;
  text-align:center;
  color:#6b4f3a;
  font-size:16px;
  line-height:1.6;
}

#sentenceBox{
  margin:20px auto 0;
  padding:22px;
  background:linear-gradient(180deg, #fffdf9 0%, #fff5eb 100%);
  border:1px solid #f3dcc8;
  border-radius:24px;
  max-width:920px;
  box-shadow:0 14px 28px rgba(15, 23, 42, .08);
}

#promptText{
  line-height:2;
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:clamp(20px, 2.4vw, 32px);
  color:#4b2e1c;
}

.blank{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:110px;
  min-height:48px;
  padding:6px 12px;
  margin:4px 6px;
  border:2px dashed #f59e0b;
  border-radius:16px;
  background:#fff7ed;
  vertical-align:middle;
  color:#9a3412;
  font-weight:800;
}

.blank.filled{
  border-style:solid;
  background:#ffedd5;
}

#wordBank{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:12px;
  margin:18px 0;
}

.word{
  padding:10px 16px;
  border-radius:999px;
  color:#7c2d12;
  font-weight:800;
  cursor:grab;
  background:linear-gradient(180deg, #fed7aa 0%, #fdba74 100%);
  box-shadow:0 10px 20px rgba(251, 146, 60, .18);
  user-select:none;
}

.dd-btn{
  padding:11px 18px;
  border:none;
  border-radius:999px;
  color:white;
  cursor:pointer;
  margin:6px;
  min-width:148px;
  font-weight:800;
  font-family:'Nunito', 'Segoe UI', sans-serif;
  font-size:14px;
  box-shadow:0 10px 22px rgba(15, 23, 42, .12);
  transition:transform .15s ease, filter .15s ease;
}

.dd-btn:hover{
  filter:brightness(1.04);
  transform:translateY(-1px);
}

.dd-btn-listen{background:linear-gradient(180deg, #14b8a6 0%, #0f766e 100%)}
.dd-btn-check{background:linear-gradient(180deg, #fb923c 0%, #f97316 100%)}
.dd-btn-show{background:linear-gradient(180deg, #d8b4fe 0%, #a855f7 100%)}
.dd-btn-next{background:linear-gradient(180deg, #5eead4 0%, #14b8a6 100%)}

#listenBtn.hidden{ display:none; }

#feedback{
  text-align:center;
  font-size:20px;
  font-weight:800;
  min-height:32px;
  margin-top:8px;
}

.good{ color:#15803d; }
.bad{ color:#dc2626; }

.controls{
  margin-top:15px;
  text-align:center;
}

@media (max-width:760px){
  .dd-intro{padding:20px 18px}
  .dd-intro h2{font-size:26px}
  #sentenceBox{padding:18px}
  .controls{display:flex;flex-direction:column;align-items:center}
  .dd-btn{width:100%;max-width:320px}
}
</style>

<div class="dd-stage">
  <section class="dd-intro">
    <h2>Build The Sentence</h2>
    <p class="instructions">Complete the blanks by dragging the correct words into place. Use Show Answer when you need to reveal the full sentence.</p>
  </section>

  <div id="sentenceBox">
    <button id="listenBtn" class="dd-btn dd-btn-listen" type="button" onclick="speak()">Listen</button>
    <div id="promptText"></div>
  </div>

  <div id="wordBank"></div>

  <div class="controls">
    <button class="dd-btn dd-btn-check" type="button" onclick="checkSentence()">Check Answer</button>
    <button class="dd-btn dd-btn-show" type="button" onclick="showAnswer()">Show Answer</button>
    <button class="dd-btn dd-btn-next" type="button" onclick="nextSentence()">Next</button>
  </div>

  <div id="feedback"></div>
</div>

<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let dragged = null;
let currentText = '';
let currentAnswers = [];
let listenEnabled = true;
let finished = false;

const promptText = document.getElementById('promptText');
const wordBank = document.getElementById('wordBank');
const feedback = document.getElementById('feedback');
const listenBtn = document.getElementById('listenBtn');
const winSound = document.getElementById('winSound');

function playSound(audio) {
  try {
    audio.pause();
    audio.currentTime = 0;
    audio.play();
  } catch (e) {}
}

function setListenVisible(visible) {
  if (visible) {
    listenBtn.classList.remove('hidden');
  } else {
    listenBtn.classList.add('hidden');
    speechSynthesis.cancel();
  }
}

function shuffle(list) {
  return list.slice().sort(function () {
    return Math.random() - 0.5;
  });
}

function createWordChip(word) {
  const chip = document.createElement('span');
  chip.textContent = word;
  chip.className = 'word';
  chip.draggable = true;
  chip.dataset.word = word;

  chip.addEventListener('dragstart', function () {
    dragged = chip;
  });

  return chip;
}

function createBlank(indexBlank) {
  const blank = document.createElement('span');
  blank.className = 'blank';
  blank.dataset.index = String(indexBlank);
  blank.textContent = '____';

  blank.addEventListener('dragover', function (event) {
    event.preventDefault();
  });

  blank.addEventListener('drop', function (event) {
    event.preventDefault();
    if (!dragged || finished) return;

    const draggedWord = dragged.dataset.word || '';
    const oldWord = blank.dataset.word || '';

    if (oldWord) {
      wordBank.appendChild(createWordChip(oldWord));
    }

    blank.dataset.word = draggedWord;
    blank.textContent = draggedWord;
    blank.classList.add('filled');

    dragged.remove();
    dragged = null;
  });

  blank.addEventListener('click', function () {
    if (finished) return;

    const existing = blank.dataset.word || '';
    if (!existing) return;

    wordBank.appendChild(createWordChip(existing));
    blank.dataset.word = '';
    blank.textContent = '____';
    blank.classList.remove('filled');
  });

  return blank;
}

function loadSentence() {
  dragged = null;
  finished = false;
  feedback.textContent = '';
  feedback.className = '';

  promptText.innerHTML = '';
  wordBank.innerHTML = '';

  const block = blocks[index] || {};
  currentText = typeof block.text === 'string' ? block.text.trim() : '';
  currentAnswers = Array.isArray(block.missing_words) ? block.missing_words.slice() : [];
  listenEnabled = !!block.listen_enabled;

  setListenVisible(listenEnabled);

  if (!currentText) {
    feedback.textContent = '⚠ Empty block';
    feedback.className = 'bad';
    return;
  }

  if (currentAnswers.length === 0) {
    currentAnswers = currentText.split(/\s+/).filter(function (w) { return w.length > 0; });
  }

  let renderedText = currentText;
  currentAnswers.forEach(function (word) {
    const escaped = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp('\\b' + escaped + '\\b', 'i');
    renderedText = renderedText.replace(regex, '[[BLANK]]');
  });

  const parts = renderedText.split('[[BLANK]]');

  parts.forEach(function (part, i) {
    promptText.appendChild(document.createTextNode(part));
    if (i < currentAnswers.length) {
      promptText.appendChild(createBlank(i));
    }
  });

  shuffle(currentAnswers).forEach(function (word) {
    wordBank.appendChild(createWordChip(word));
  });
}

function getBuiltAnswers() {
  const blanks = Array.prototype.slice.call(promptText.querySelectorAll('.blank'));
  return blanks.map(function (blank) {
    return blank.dataset.word || '';
  });
}

function checkSentence() {
  if (finished) return;

  const built = getBuiltAnswers();

  if (built.includes('')) {
    feedback.textContent = 'Complete all blanks first.';
    feedback.className = 'bad';
    return;
  }

  if (JSON.stringify(built) === JSON.stringify(currentAnswers)) {
    if (index === blocks.length - 1) {
      feedback.textContent = 'Completed!';
      feedback.className = 'good';
      playSound(winSound);
      finished = true;
      return;
    }

    feedback.textContent = 'Correct!';
    feedback.className = 'good';
    finished = true;
  } else {
    feedback.textContent = 'Try Again';
    feedback.className = 'bad';
  }
}

function showAnswer() {
  const blanks = Array.prototype.slice.call(promptText.querySelectorAll('.blank'));

  blanks.forEach(function (blank, blankIndex) {
    const answer = currentAnswers[blankIndex] || '';
    blank.dataset.word = answer;
    blank.textContent = answer;
    blank.classList.add('filled');
  });

  wordBank.innerHTML = '';
  feedback.textContent = 'Show The Answer';
  feedback.className = 'good';
  finished = true;
}

function nextSentence() {
  if (index >= blocks.length - 1) {
    feedback.textContent = 'Completed!';
    feedback.className = 'good';
    playSound(winSound);
    finished = true;
    return;
  }

  index += 1;
  loadSentence();
}

function speak() {
  if (!listenEnabled) return;

  speechSynthesis.cancel();
  const msg = new SpeechSynthesisUtterance(currentText || '');
  msg.lang = 'en-US';
  msg.rate = 0.9;
  speechSynthesis.speak(msg);
}

loadSentence();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎯', $content);

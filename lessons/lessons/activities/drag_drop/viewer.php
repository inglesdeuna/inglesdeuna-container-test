<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
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
    die('No hay oraciones para esta unidad');
}

ob_start();
?>
<style>
.instructions{
  margin:0 0 16px 0;
  text-align:center;
  color:#334155;
}

#sentenceBox{
  margin:20px auto;
  padding:20px;
  background:white;
  border-radius:18px;
  max-width:920px;
  box-shadow:0 8px 24px rgba(0,0,0,.08);
}

#promptText{
  line-height:2;
  font-size:22px;
}

.blank{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:100px;
  min-height:42px;
  padding:4px 8px;
  margin:0 5px;
  border:2px dashed #0b5ed7;
  border-radius:10px;
  background:#f8fbff;
  vertical-align:middle;
}

.blank.filled{
  border-style:solid;
  background:#e8f1ff;
}

#wordBank{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
  margin:15px 0;
}

.word{
  padding:8px 14px;
  border-radius:10px;
  color:white;
  font-weight:bold;
  cursor:grab;
  background:#2563eb;
  user-select:none;
}

button{
  padding:10px 18px;
  border:none;
  border-radius:12px;
  background:#0b5ed7;
  color:white;
  cursor:pointer;
  margin:6px;
  font-weight:700;
}

#listenBtn.hidden{ display:none; }

#feedback{
  text-align:center;
  font-size:20px;
  font-weight:bold;
  min-height:28px;
}

.good{ color:green; }
.bad{ color:crimson; }

.controls{
  margin-top:15px;
  text-align:center;
}
</style>

<p class="instructions">Complete the blanks by dragging the correct words.</p>

<div id="sentenceBox">
  <button id="listenBtn" type="button" onclick="speak()">🔊 Listen</button>
  <div id="promptText"></div>
</div>

<div id="wordBank"></div>

<div class="controls">
  <button type="button" onclick="checkSentence()">✅ Check</button>
  <button type="button" onclick="nextSentence()">➡️ Next</button>
</div>

<div id="feedback"></div>

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
    feedback.textContent = '⚠ Complete all blanks.';
    feedback.className = 'bad';
    return;
  }

  if (JSON.stringify(built) === JSON.stringify(currentAnswers)) {
    if (index === blocks.length - 1) {
      feedback.textContent = '🏆 Completed!';
      feedback.className = 'good';
      playSound(winSound);
      finished = true;
      return;
    }

    feedback.textContent = '🌟 Excellent!';
    feedback.className = 'good';
    finished = true;
  } else {
    feedback.textContent = '🔁 Try again!';
    feedback.className = 'bad';
  }
}

function nextSentence() {
  if (index >= blocks.length - 1) {
    feedback.textContent = '🏆 Completed!';
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

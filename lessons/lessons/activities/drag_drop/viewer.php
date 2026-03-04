<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? $_GET['id'] : null;
$unit = isset($_GET['unit']) ? $_GET['unit'] : null;

if ($activityId && !$unit) {
    $stmt = $pdo->prepare(
        "SELECT unit_id
         FROM activities
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die('Actividad no encontrada');
    }

    $unit = $row['unit_id'];
}

if (!$unit) {
    die('Unidad no especificada');
}

$stmt = $pdo->prepare(
    "SELECT data
     FROM activities
     WHERE unit_id = :unit
       AND type = 'drag_drop'
     LIMIT 1"
);
$stmt->execute(array('unit' => $unit));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$raw = isset($row['data']) ? $row['data'] : '[]';
$decoded = json_decode($raw, true);
$blocks = is_array($decoded) ? $decoded : array();

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
  padding:15px;
  background:white;
  border-radius:15px;
  max-width:900px;
}

#promptText{
  line-height:2;
  font-size:20px;
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
}

#feedback{
  text-align:center;
  font-size:18px;
  font-weight:bold;
  min-height:28px;
}

.good{ color:green; }
.bad{ color:crimson; }

.controls{ margin-top:15px; text-align:center; }
</style>

<p class="instructions">Completa los espacios arrastrando las palabras correctas.</p>

<div id="sentenceBox">
  <button onclick="speak()">🔊 Listen</button>
  <div id="promptText"></div>
</div>

<div id="wordBank"></div>

<div class="controls">
  <button onclick="checkSentence()">✅ Check</button>
  <button onclick="nextSentence()">➡️ Next</button>
</div>

<div id="feedback"></div>

<script>
const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let dragged = null;
let currentText = '';
let currentAnswers = [];

const promptText = document.getElementById('promptText');
const wordBank = document.getElementById('wordBank');
const feedback = document.getElementById('feedback');

function normalizeBlock(block) {
  const text = (block && typeof block.text === 'string' && block.text.trim() !== '')
    ? block.text.trim()
    : ((block && typeof block.sentence === 'string') ? block.sentence.trim() : '');

  let missing = [];
  if (block && Array.isArray(block.missing_words)) {
    missing = block.missing_words
      .map(function (w) { return String(w).trim(); })
      .filter(function (w) { return w.length > 0; });
  }

  return { text: text, missing_words: missing };
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
    if (!dragged) {
      return;
    }

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
    const existing = blank.dataset.word || '';
    if (!existing) {
      return;
    }

    wordBank.appendChild(createWordChip(existing));
    blank.dataset.word = '';
    blank.textContent = '____';
    blank.classList.remove('filled');
  });

  return blank;
}

function loadSentence() {
  dragged = null;
  feedback.textContent = '';
  feedback.className = '';

  promptText.innerHTML = '';
  wordBank.innerHTML = '';

  const block = normalizeBlock(blocks[index] || {});
  currentText = block.text;
  currentAnswers = block.missing_words.slice();

  if (!currentText) {
    feedback.textContent = '⚠ Bloque vacío';
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
  const built = getBuiltAnswers();

  if (built.includes('')) {
    feedback.textContent = '⚠ Complete all blanks.';
    feedback.className = 'bad';
    return;
  }

  if (JSON.stringify(built) === JSON.stringify(currentAnswers)) {
    feedback.textContent = '🌟 Excellent!';
    feedback.className = 'good';
  } else {
    feedback.textContent = '🔁 Try again!';
    feedback.className = 'bad';
  }
}

function nextSentence() {
  index += 1;

  if (index >= blocks.length) {
    feedback.textContent = '🏆 You finished all sentences!';
    feedback.className = 'good';
    index = blocks.length - 1;
    return;
  }

  loadSentence();
}

function speak() {
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
render_activity_viewer('Build the Sentence', '🎯', $content);

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unidad no especificada');
}

$stmt = $pdo->prepare(
    "SELECT data
     FROM activities
     WHERE unit_id = :unit
       AND type = 'listen_order'
     LIMIT 1"
);
$stmt->execute(array('unit' => $unit));

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$raw = isset($row['data']) ? $row['data'] : '[]';
$decoded = json_decode($raw, true);
$blocks = is_array($decoded) ? $decoded : array();

if (count($blocks) === 0) {
    die('No activities for this unit');
}

ob_start();
?>
<style>
#sentenceBox{
  margin:20px auto;
  padding:15px;
  background:white;
  border-radius:15px;
  max-width:760px;
}

#controls{
  display:flex;
  gap:12px;
  justify-content:center;
  align-items:center;
  flex-wrap:wrap;
  margin-bottom:8px;
}

#controls label{
  font-size:14px;
  color:#334155;
  font-weight:bold;
}

#controls select, #controls input[type="checkbox"]{
  margin-left:6px;
}

#words, #answer{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
  margin:15px 0;
}

.word{
  padding:6px;
  border-radius:12px;
  background:white;
  cursor:grab;
  box-shadow:0 2px 6px rgba(0,0,0,.15);
}

.word img{
  height:80px;
  width:auto;
  display:block;
  object-fit:contain;
}

.drop-zone{
  background:#fff;
  border:2px dashed #0b5ed7;
  border-radius:12px;
  padding:15px;
  min-height:100px;
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

#listenBtn.hidden{ display:none; }

#feedback{
  font-size:18px;
  font-weight:bold;
}

.good{color:green;}
.bad{color:crimson;}

.actions{ text-align:center; }
</style>

<div id="sentenceBox">
  <div id="controls">
    <label>
      Language:
      <select id="langSelect">
        <option value="en">English</option>
        <option value="es">Español</option>
      </select>
    </label>

    <label>
      <input type="checkbox" id="listenToggle" checked>
      Listen enabled
    </label>
  </div>

  <button id="listenBtn" onclick="playAudio()">🔊 Listen</button>
</div>

<div id="words"></div>
<div id="answer" class="drop-zone"></div>

<div class="actions">
  <button onclick="checkOrder()">✅ Check</button>
  <button onclick="nextBlock()">➡️ Next</button>
</div>

<div id="feedback"></div>

<script>
const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let correct = [];
let dragged = null;

let utter = null;
let isPaused = false;
let isSpeaking = false;

const wordsDiv = document.getElementById('words');
const answerDiv = document.getElementById('answer');
const feedback = document.getElementById('feedback');
const langSelect = document.getElementById('langSelect');
const listenToggle = document.getElementById('listenToggle');
const listenBtn = document.getElementById('listenBtn');

function getCurrentBlock() {
  return blocks[index] || {};
}

function getSentenceForLanguage(block, lang) {
  const en = typeof block.sentence_en === 'string' ? block.sentence_en.trim() : '';
  const es = typeof block.sentence_es === 'string' ? block.sentence_es.trim() : '';
  const generic = typeof block.sentence === 'string' ? block.sentence.trim() : '';

  if (lang === 'es') {
    return es || generic || en;
  }

  return en || generic || es;
}

function shouldAllowListen(block) {
  if (typeof block.listen_enabled === 'boolean') {
    return block.listen_enabled;
  }

  if (typeof block.listen === 'boolean') {
    return block.listen;
  }

  return true;
}

function updateListenAvailability() {
  const block = getCurrentBlock();
  const enabledByBlock = shouldAllowListen(block);
  const enabledByUser = listenToggle.checked;
  const enabled = enabledByBlock && enabledByUser;

  if (enabled) {
    listenBtn.classList.remove('hidden');
  } else {
    listenBtn.classList.add('hidden');
    speechSynthesis.cancel();
    isSpeaking = false;
    isPaused = false;
  }
}

function getSpeechLang() {
  return langSelect.value === 'es' ? 'es-ES' : 'en-US';
}

function playAudio() {
  if (listenBtn.classList.contains('hidden')) {
    return;
  }

  if (isSpeaking && !isPaused) {
    speechSynthesis.pause();
    isPaused = true;
    return;
  }

  if (isPaused) {
    speechSynthesis.resume();
    isPaused = false;
    return;
  }

  const block = getCurrentBlock();
  const sentence = getSentenceForLanguage(block, langSelect.value);

  speechSynthesis.cancel();
  utter = new SpeechSynthesisUtterance(sentence || '');
  utter.lang = getSpeechLang();
  utter.rate = 0.8;
  utter.pitch = 1;
  utter.volume = 1;

  utter.onstart = function () {
    isSpeaking = true;
    isPaused = false;
  };

  utter.onend = function () {
    isSpeaking = false;
    isPaused = false;
  };

  speechSynthesis.speak(utter);
}

function loadBlock() {
  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;

  dragged = null;
  feedback.textContent = '';
  feedback.className = '';

  wordsDiv.innerHTML = '';
  answerDiv.innerHTML = '';

  const block = getCurrentBlock();
  correct = Array.isArray(block.images) ? block.images.slice() : [];

  const shuffled = correct.slice().sort(function () {
    return Math.random() - 0.5;
  });

  shuffled.forEach(function (src) {
    const div = document.createElement('div');
    div.className = 'word';
    div.draggable = true;
    div.dataset.src = src;

    const img = document.createElement('img');
    img.src = src;
    img.alt = 'word-image';

    div.appendChild(img);
    div.addEventListener('dragstart', function () {
      dragged = div;
    });

    wordsDiv.appendChild(div);
  });

  updateListenAvailability();
}

answerDiv.addEventListener('dragover', function (event) {
  event.preventDefault();
});

answerDiv.addEventListener('drop', function () {
  if (dragged) {
    answerDiv.appendChild(dragged);
  }
});

langSelect.addEventListener('change', function () {
  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;
});

listenToggle.addEventListener('change', function () {
  updateListenAvailability();
});

function checkOrder() {
  const built = Array.prototype.slice.call(answerDiv.children).map(function (node) {
    return node.dataset.src;
  });

  if (JSON.stringify(built) === JSON.stringify(correct)) {
    feedback.textContent = '🌟 Excellent!';
    feedback.className = 'good';
  } else {
    feedback.textContent = '🔁 Try again!';
    feedback.className = 'bad';
  }
}

function nextBlock() {
  index += 1;

  if (index >= blocks.length) {
    feedback.textContent = '🏆 Completed!';
    feedback.className = 'good';
    index = blocks.length - 1;
    updateListenAvailability();
    return;
  }

  loadBlock();
}

loadBlock();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Listen & Order', '🎧', $content);

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

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
  return 'Unscramble';
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
        'id' => '',
        'title' => default_drag_drop_title(),
        'blocks' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("\n            SELECT id, data\n            FROM activities\n            WHERE id = :id\n              AND type = 'drag_drop'\n            LIMIT 1\n        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("\n            SELECT id, data\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'drag_drop'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_drag_drop_payload($row['data'] ?? null);

    return [
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => (string) ($payload['title'] ?? default_drag_drop_title()),
        'blocks' => is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [],
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_drag_drop_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? default_drag_drop_title());
$blocks = is_array($activity['blocks'] ?? null) ? $activity['blocks'] : [];

if ($activityId === '' && !empty($activity['id'])) {
  $activityId = (string) $activity['id'];
}

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

.blank.incorrect{
  border-color:#dc2626;
  background:#fee2e2;
  color:#991b1b;
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

.dd-completed-screen{
  display:none;
  text-align:center;
  max-width:600px;
  margin:0 auto;
  padding:40px 20px;
}

.dd-completed-screen.active{
  display:block;
}

.dd-completed-icon{
  font-size:80px;
  margin-bottom:20px;
}

.dd-completed-title{
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:36px;
  font-weight:700;
  color:#9a3412;
  margin:0 0 16px;
  line-height:1.2;
}

.dd-completed-text{
  font-size:16px;
  color:#6b4f3a;
  line-height:1.6;
  margin:0 0 32px;
}

.dd-completed-button{
  display:inline-block;
  padding:12px 24px;
  border:none;
  border-radius:999px;
  background:linear-gradient(180deg, #fb923c 0%, #f97316 100%);
  color:#fff;
  font-weight:700;
  font-size:16px;
  cursor:pointer;
  box-shadow:0 10px 24px rgba(0,0,0,.14);
  transition:transform .18s ease, filter .18s ease;
}

.dd-completed-button:hover{
  transform:scale(1.05);
  filter:brightness(1.07);
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
  <div id="sentenceBox">
    <button id="listenBtn" class="dd-btn dd-btn-listen" type="button" onclick="speak()">Listen</button>
    <div id="promptText"></div>
  </div>

  <div id="wordBank"></div>

  <div class="controls">
    <button class="dd-btn dd-btn-show" type="button" onclick="showAnswer()">Show Answer</button>
    <button class="dd-btn dd-btn-next" type="button" onclick="nextSentence()">Next</button>
  </div>

  <div id="feedback"></div>

  <div id="dd-completed" class="dd-completed-screen">
    <div class="dd-completed-icon">✅</div>
    <h2 class="dd-completed-title" id="dd-completed-title"></h2>
    <p class="dd-completed-text" id="dd-completed-text"></p>
    <p class="dd-completed-text" id="dd-score-text" style="font-weight:700;font-size:18px;color:#9a3412;"></p>
    <button type="button" class="dd-completed-button" id="dd-restart" onclick="restartActivity()">Restart</button>
  </div>
</div>

<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<audio id="doneSound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
const sourceBlocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;
const blocks = sourceBlocks.length > 1
  ? sourceBlocks.slice().sort(function () { return Math.random() - 0.5; }).slice(0, Math.max(1, Math.ceil(sourceBlocks.length * 0.75)))
  : sourceBlocks.slice();
const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const DD_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
const DD_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let dragged = null;
let currentText = '';
let currentAnswers = [];
let listenEnabled = true;
let isSpeaking = false;
let isPaused = false;
let utter = null;
let speechOffset = 0;
let speechSourceText = '';
let speechSegmentStart = 0;
let finished = false;
let blockFinished = false;
let correctCount = 0;
let totalCount = 0;
let checkedBlocks = {};
let attemptsByBlock = {};
let scoredWordsByBlock = {};

const promptText = document.getElementById('promptText');
const wordBank = document.getElementById('wordBank');
const feedback = document.getElementById('feedback');
const listenBtn = document.getElementById('listenBtn');
const winSound = document.getElementById('winSound');
const loseSound = document.getElementById('loseSound');
const doneSound = document.getElementById('doneSound');
const sentenceBox = document.getElementById('sentenceBox');
const controls = document.querySelector('.controls');
const completedEl = document.getElementById('dd-completed');
const completedTitleEl = document.getElementById('dd-completed-title');
const completedTextEl = document.getElementById('dd-completed-text');
const scoreTextEl = document.getElementById('dd-score-text');

if (completedTitleEl) {
  completedTitleEl.textContent = 'Completed';
}

if (completedTextEl) {
  completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
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

function setListenVisible(visible) {
  if (visible) {
    listenBtn.classList.remove('hidden');
  } else {
    listenBtn.classList.add('hidden');
    speechSynthesis.cancel();
    isSpeaking = false;
    isPaused = false;
    speechOffset = 0;
    speechSourceText = '';
    speechSegmentStart = 0;
  }
}

function shuffle(list) {
  return list.slice().sort(function () {
    return Math.random() - 0.5;
  });
}

function getAnswersForBlock(block) {
  const text = typeof block.text === 'string' ? block.text.trim() : '';
  const missing = Array.isArray(block.missing_words) ? block.missing_words : [];
  const cleanedMissing = missing.map(function (word) {
    return String(word || '').trim();
  }).filter(function (word) {
    return word.length > 0;
  });

  if (cleanedMissing.length > 0) {
    return cleanedMissing;
  }

  if (!text) {
    return [];
  }

  return text.split(/\s+/).filter(function (word) {
    return word.length > 0;
  });
}

function countCorrectWords(built, answers) {
  let correct = 0;
  for (let i = 0; i < answers.length; i += 1) {
    if ((built[i] || '') === (answers[i] || '')) {
      correct += 1;
    }
  }
  return correct;
}

function registerBlockWordScore(built) {
  if (Object.prototype.hasOwnProperty.call(scoredWordsByBlock, index)) {
    return;
  }

  const builtAnswers = Array.isArray(built) ? built : getBuiltAnswers();
  const score = countCorrectWords(builtAnswers, currentAnswers);
  scoredWordsByBlock[index] = score;
  correctCount += score;
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
    if (!dragged || finished || blockFinished) return;

    const draggedWord = dragged.dataset.word || '';
    const oldWord = blank.dataset.word || '';

    if (oldWord) {
      wordBank.appendChild(createWordChip(oldWord));
    }

    blank.dataset.word = draggedWord;
    blank.textContent = draggedWord;
    blank.classList.add('filled');
    blank.classList.remove('incorrect');

    dragged.remove();
    dragged = null;

    setTimeout(autoCheckIfNeeded, 40);
  });

  blank.addEventListener('click', function () {
    if (finished || blockFinished) return;

    const existing = blank.dataset.word || '';
    if (!existing) return;

    wordBank.appendChild(createWordChip(existing));
    blank.dataset.word = '';
    blank.textContent = '____';
    blank.classList.remove('filled');
    blank.classList.remove('incorrect');
  });

  return blank;
}

function loadSentence() {
  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;
  speechOffset = 0;
  speechSourceText = '';
  speechSegmentStart = 0;
  dragged = null;
  finished = false;
  blockFinished = false;

  if (completedEl) {
    completedEl.classList.remove('active');
  }

  if (sentenceBox) {
    sentenceBox.style.display = 'block';
  }

  if (wordBank) {
    wordBank.style.display = 'flex';
  }

  if (promptText) {
    promptText.style.display = 'block';
  }

  if (controls) {
    controls.style.display = 'block';
  }

  feedback.textContent = '';
  feedback.className = '';

  promptText.innerHTML = '';
  wordBank.innerHTML = '';

  const block = blocks[index] || {};
  currentText = typeof block.text === 'string' ? block.text.trim() : '';
  speechSourceText = currentText;
  currentAnswers = getAnswersForBlock(block);
  listenEnabled = !!block.listen_enabled;

  setListenVisible(listenEnabled);

  if (!currentText) {
    feedback.textContent = 'Empty block';
    feedback.className = 'bad';
    return;
  }

  let renderedText = currentText;

  // Find each answer's first position in the ORIGINAL text so blank order
  // matches the left-to-right visual order in the sentence, regardless of
  // the order the words were saved in missing_words.
  var wordMatches = [];
  currentAnswers.forEach(function (word) {
    var escaped = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var regex = new RegExp('\\b' + escaped + '\\b', 'i');
    var match = regex.exec(currentText);
    if (match) {
      wordMatches.push({ word: word, pos: match.index, len: match[0].length });
    }
  });

  // Sort ascending so blank 0 = leftmost occurrence, blank 1 = next, etc.
  wordMatches.sort(function (a, b) { return a.pos - b.pos; });
  currentAnswers = wordMatches.map(function (wm) { return wm.word; });

  // Replace from rightmost to leftmost so earlier offsets stay valid.
  wordMatches.slice().sort(function (a, b) { return b.pos - a.pos; }).forEach(function (wm) {
    renderedText = renderedText.slice(0, wm.pos) + '[[BLANK]]' + renderedText.slice(wm.pos + wm.len);
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

async function showCompleted() {
  finished = true;
  blockFinished = true;
  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;
  speechOffset = 0;
  speechSourceText = '';
  speechSegmentStart = 0;
  feedback.textContent = '';
  feedback.className = '';

  if (sentenceBox) {
    sentenceBox.style.display = 'none';
  }

  if (wordBank) {
    wordBank.style.display = 'none';
  }

  if (controls) {
    controls.style.display = 'none';
  }

  if (completedEl) {
    completedEl.classList.add('active');
  }

  playSound(doneSound);

  const pct = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
  const errors = Math.max(0, totalCount - correctCount);

  if (scoreTextEl) {
    scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
  }

  if (DD_ACTIVITY_ID && DD_RETURN_TO) {
    const joiner = DD_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    const saveUrl = DD_RETURN_TO +
      joiner + 'activity_percent=' + pct +
      '&activity_errors=' + errors +
      '&activity_total=' + totalCount +
      '&activity_id=' + encodeURIComponent(DD_ACTIVITY_ID) +
      '&activity_type=drag_drop';

    const ok = await persistScoreSilently(saveUrl);
    if (!ok) {
      navigateToReturn(saveUrl);
    }
  }
}

function getBuiltAnswers() {
  const blanks = Array.prototype.slice.call(promptText.querySelectorAll('.blank'));
  return blanks.map(function (blank) {
    return blank.dataset.word || '';
  });
}

function checkSentence() {
  if (finished || blockFinished || checkedBlocks[index]) return;

  const built = getBuiltAnswers();

  if (built.includes('')) {
    feedback.textContent = 'Complete all blanks first.';
    feedback.className = 'bad';
    return;
  }

  const currentAttempts = (attemptsByBlock[index] || 0) + 1;
  attemptsByBlock[index] = currentAttempts;

  if (JSON.stringify(built) === JSON.stringify(currentAnswers)) {
    feedback.textContent = '\u2714 Right';
    feedback.className = 'good';
    playSound(winSound);
    checkedBlocks[index] = true;
    registerBlockWordScore(built);
    blockFinished = true;
  } else {
    if (currentAttempts >= 2) {
      feedback.textContent = '\u2718 Wrong (2/2)';
      feedback.className = 'bad';
      playSound(loseSound);
      checkedBlocks[index] = true;
      registerBlockWordScore(built);
      blockFinished = true;

      const blanks = Array.prototype.slice.call(promptText.querySelectorAll('.blank'));
      blanks.forEach(function (blank, blankIndex) {
        const builtWord = built[blankIndex] || '';
        const answerWord = currentAnswers[blankIndex] || '';
        if (builtWord !== answerWord) {
          blank.classList.add('incorrect');
        }
      });
    } else {
      feedback.textContent = '\u2718 Wrong (1/2) - try again';
      feedback.className = 'bad';
      playSound(loseSound);
    }
  }
}

function autoCheckIfNeeded() {
  if (finished || blockFinished || checkedBlocks[index]) {
    return;
  }

  const built = getBuiltAnswers();
  if (built.includes('')) {
    return;
  }

  checkSentence();
}

function showAnswer() {
  const built = getBuiltAnswers();
  const blanks = Array.prototype.slice.call(promptText.querySelectorAll('.blank'));

  registerBlockWordScore(built);
  checkedBlocks[index] = true;

  blanks.forEach(function (blank, blankIndex) {
    const answer = currentAnswers[blankIndex] || '';
    const builtWord = built[blankIndex] || '';
    if (builtWord !== '' && builtWord !== answer) {
      blank.classList.add('incorrect');
    }
    blank.dataset.word = answer;
    blank.textContent = answer;
    blank.classList.add('filled');
  });

  wordBank.innerHTML = '';
  feedback.textContent = 'Show Answer';
  feedback.className = 'good';
  blockFinished = true;
}

function nextSentence() {
  if (finished) {
    return;
  }

  autoCheckIfNeeded();

  if (!blockFinished && !checkedBlocks[index]) {
    return;
  }

  registerBlockWordScore();

  if (index >= blocks.length - 1) {
    showCompleted();
    return;
  }

  index += 1;
  loadSentence();
}

function restartActivity() {
  index = 0;
  correctCount = 0;
  totalCount = blocks.reduce(function (sum, block) {
    return sum + getAnswersForBlock(block).length;
  }, 0);
  checkedBlocks = {};
  attemptsByBlock = {};
  scoredWordsByBlock = {};
  loadSentence();
}

function speak() {
  if (!listenEnabled) return;

  if (!currentText || String(currentText).trim() === '') {
    return;
  }

  if (speechSynthesis.paused || isPaused) {
    speechSynthesis.resume();
    isSpeaking = true;
    isPaused = false;

    setTimeout(function () {
      if (!speechSynthesis.speaking && speechOffset < speechSourceText.length) {
        startSpeechFromOffset();
      }
    }, 80);
    return;
  }

  if (speechSynthesis.speaking && !speechSynthesis.paused) {
    speechSynthesis.pause();
    isSpeaking = true;
    isPaused = true;
    return;
  }

  speechSynthesis.cancel();
  speechSourceText = currentText || '';
  speechOffset = 0;
  startSpeechFromOffset();
}

function startSpeechFromOffset() {
  const source = speechSourceText || currentText || '';
  if (!source) {
    return;
  }

  const safeOffset = Math.max(0, Math.min(speechOffset, source.length));
  const remaining = source.slice(safeOffset);

  if (!remaining.trim()) {
    isSpeaking = false;
    isPaused = false;
    speechOffset = 0;
    return;
  }

  speechSynthesis.cancel();

  speechSegmentStart = safeOffset;
  utter = new SpeechSynthesisUtterance(remaining);
  utter.lang = 'en-US';
  utter.rate = 0.9;
  utter.pitch = 1;
  utter.volume = 1;

  utter.onstart = function () {
    isSpeaking = true;
    isPaused = false;
  };

  utter.onpause = function () {
    isPaused = true;
    isSpeaking = true;
  };

  utter.onresume = function () {
    isPaused = false;
    isSpeaking = true;
  };

  utter.onboundary = function (event) {
    if (typeof event.charIndex === 'number') {
      speechOffset = Math.max(speechSegmentStart, Math.min(source.length, speechSegmentStart + event.charIndex));
    }
  };

  utter.onend = function () {
    if (isPaused) {
      return;
    }
    isSpeaking = false;
    isPaused = false;
    speechOffset = 0;
  };

  speechSynthesis.speak(utter);
}

totalCount = blocks.reduce(function (sum, block) {
  return sum + getAnswersForBlock(block).length;
}, 0);

loadSentence();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎯', $content);

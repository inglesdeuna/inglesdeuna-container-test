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

function default_listen_order_title(): string
{
    return 'Listen & Order';
}

function normalize_listen_order_payload($rawData): array
{
    $default = [
        'title' => default_listen_order_title(),
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

        $sentence = trim((string) ($block['sentence'] ?? ''));
        $images = [];

        if (isset($block['images']) && is_array($block['images'])) {
            foreach ($block['images'] as $img) {
                $url = trim((string) $img);
                if ($url !== '') {
                    $images[] = $url;
                }
            }
        }

        $dropZoneImages = [];
        if (isset($block['dropZoneImages']) && is_array($block['dropZoneImages'])) {
            foreach ($block['dropZoneImages'] as $dzi) {
                if (!is_array($dzi)) {
                    continue;
                }
                $dzSrc = trim((string) ($dzi['src'] ?? ''));
                if ($dzSrc === '') {
                    continue;
                }
                $dropZoneImages[] = [
                    'id'    => trim((string) ($dzi['id'] ?? uniqid('dzi_'))),
                    'src'   => $dzSrc,
                    'left'  => (int) ($dzi['left'] ?? 0),
                    'top'   => (int) ($dzi['top'] ?? 0),
                    'width' => max(60, min(800, (int) ($dzi['width'] ?? 180))),
                ];
            }
        }

        if ($sentence === '') {
            continue;
        }

        $blocks[] = [
            'sentence'       => $sentence,
            'images'         => $images,
            'dropZoneImages' => $dropZoneImages,
        ];
    }

    return [
        'title' => $title !== '' ? $title : default_listen_order_title(),
        'blocks' => $blocks,
    ];
}

function load_listen_order_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'title' => default_listen_order_title(),
        'blocks' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT data
            FROM activities
            WHERE id = :id
              AND type = 'listen_order'
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
              AND type = 'listen_order'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    return normalize_listen_order_payload($row['data'] ?? null);
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_listen_order_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? default_listen_order_title());
$blocks = is_array($activity['blocks'] ?? null) ? $activity['blocks'] : [];
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if (count($blocks) === 0) {
    die('No activities for this unit');
}

ob_start();
?>
<style>
/* ── Stage: bust out of the 980px template constraint ── */
.lo-stage{
  max-width:100% !important;
  margin:0 auto;
  min-height:calc(100vh - 120px);
  display:flex;
  flex-direction:column;
  justify-content:center;
}

.lo-intro{
  margin-bottom:18px;
  padding:24px 26px;
  border-radius:26px;
  border:1px solid #d9cff6;
  background:linear-gradient(135deg, #eef4ff 0%, #f8ebff 48%, #e8fff7 100%);
  box-shadow:0 16px 34px rgba(15, 23, 42, .09);
}

.lo-intro h2{
  margin:0 0 8px;
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:35px;
  line-height:1.1;
  color:#4c1d95;
}

.lo-intro p,
.instructions{
  margin:0;
  text-align:center;
  color:#5b516f;
  font-size:21px;
  line-height:1.6;
}

#sentenceBox{
  margin:20px auto 0;
  padding:20px;
  background:linear-gradient(180deg, #fdfcff 0%, #f2fbff 100%);
  border:1px solid #d7e6fb;
  border-radius:24px;
  max-width:760px;
  box-shadow:0 14px 28px rgba(15, 23, 42, .08);
  text-align:center;
}

#words, #answer{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:12px;
  margin:18px 0;
}

.word{
  padding:8px;
  border-radius:18px;
  background:linear-gradient(180deg, #ffffff 0%, #f5f3ff 100%);
  cursor:grab;
  border:1px solid #ddd6fe;
  box-shadow:0 10px 20px rgba(124, 58, 237, .1);
  transition: border-color .15s ease, background-color .15s ease;
  position:relative;
  z-index:2;
}

.word.incorrect{
  background:linear-gradient(180deg, #fee2e2 0%, #fecdd3 100%);
  border-color:#fca5a5;
}

.word img{
  height:90px;
  width:auto;
  display:block;
  object-fit:contain;
  border-radius:12px;
}

/* ── Drop zone: 3× wider ── */
.drop-zone{
  background:#fff;
  border:2px dashed #7c3aed;
  border-radius:20px;
  padding:18px;
  min-height:200px;
  position:relative;
  width:min(2280px, 100%) !important;
  max-width:2280px !important;
  box-sizing:border-box;
}

/* ── Uploaded background image (viewer: non-interactive) ── */
#answer .uploaded-drop-image,
.drop-zone .uploaded-drop-image{
  position:absolute;
  pointer-events:none;
  user-select:none;
  z-index:1;
  height:auto;
}

/* ── Free-placement mode: background image fills and sizes the zone ── */
#answer.lo-free-mode{
  display:block !important;
  padding:0 !important;
  background:#f0eeff !important;
  border:3px solid #7c3aed !important;
  border-style:solid !important;
  /* cap width for readability; JS sets height via aspect ratio */
  width:min(860px, 100%) !important;
  max-width:860px !important;
  margin:0 auto !important;
  min-height:300px;
  overflow:hidden;
  cursor:default;
}
/* Image fills the zone completely */
#answer.lo-free-mode .uploaded-drop-image{
  position:absolute !important;
  inset:0 !important;
  width:100% !important;
  height:100% !important;
  max-width:none !important;
  object-fit:contain;
  object-position:center;
  pointer-events:none;
  z-index:1;
}
/* Chips float freely on top of the image */
#answer.lo-free-mode .word{
  position:absolute;
  z-index:3;
  cursor:grab;
  margin:0;
}
#answer.lo-free-mode .word:active{ cursor:grabbing; }

.lo-controls{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
}

.lo-completed-screen{
  display:none;
  text-align:center;
  max-width:600px;
  margin:0 auto;
  padding:40px 20px;
}

.lo-completed-screen.active{
  display:block;
}

.lo-completed-icon{
  font-size:80px;
  margin-bottom:20px;
}

.lo-completed-title{
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:36px;
  font-weight:700;
  color:#4c1d95;
  margin:0 0 16px;
  line-height:1.2;
}

.lo-completed-text{
  font-size:16px;
  color:#5b516f;
  line-height:1.6;
  margin:0 0 32px;
}

.lo-completed-button{
  display:inline-block;
  padding:12px 24px;
  border:none;
  border-radius:999px;
  background:linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
  color:#fff;
  font-weight:700;
  font-size:16px;
  cursor:pointer;
  box-shadow:0 10px 24px rgba(0,0,0,.14);
  transition:transform .18s ease, filter .18s ease;
}

.lo-completed-button:hover{
  transform:scale(1.05);
  filter:brightness(1.07);
}

.lo-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:11px 18px;
  border:none;
  border-radius:999px;
  color:white;
  cursor:pointer;
  min-width:142px;
  font-weight:800;
  font-family:'Nunito', 'Segoe UI', sans-serif;
  font-size:14px;
  line-height:1;
  box-shadow:0 10px 22px rgba(15, 23, 42, .12);
  transition:transform .15s ease, filter .15s ease;
}

.lo-btn:hover{
  filter:brightness(1.04);
  transform:translateY(-1px);
}

.lo-btn-listen{background:linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%)}
.lo-btn-check{background:linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%)}
.lo-btn-show{background:linear-gradient(180deg, #f9a8d4 0%, #ec4899 100%)}
.lo-btn-next{background:linear-gradient(180deg, #2dd4bf 0%, #0f766e 100%)}

#feedback{
  font-size:20px;
  font-weight:800;
  min-height:28px;
  text-align:center;
}

#lo-status{
  text-align:center;
  margin-bottom:12px;
  font-size:14px;
  color:#5b516f;
  font-weight:700;
}

#lo-status.completed{
  font-family:'Fredoka', 'Trebuchet MS', sans-serif;
  font-size:36px;
  font-weight:700;
  color:#4c1d95;
  line-height:1.2;
  margin-bottom:18px;
}

.good{color:#15803d;}
.bad{color:#dc2626;}

/* ── Title +5px override ── */
.act-header h2{
  font-size:clamp(31px, 4vw, 41px) !important;
}
/* ── Instructions +5px override ── */
.act-header p{
  font-size:23px !important;
}

@media (max-width:760px){
  .lo-intro{padding:20px 18px}
  .lo-intro h2{font-size:31px}
  .lo-controls{flex-direction:column;align-items:center}
  .lo-btn{width:100%;max-width:320px}
  .act-header h2{font-size:31px !important;}
  .act-header p{font-size:21px !important;}
  /* Drop zone: full width on mobile, no horizontal overflow */
  .drop-zone{
    width:100% !important;
    max-width:100% !important;
  }
}
</style>

<?= render_activity_header($viewerTitle) ?>
<div class="lo-stage">
  <div id="sentenceBox">
    <button class="lo-btn lo-btn-listen" type="button" onclick="playAudio()">🔊 Listen</button>
  </div>

  <div id="words"></div>
  <div id="answer" class="drop-zone"></div>

  <div class="lo-controls">
    <button class="lo-btn lo-btn-show" type="button" onclick="showAnswer()">Show Answer</button>
    <button class="lo-btn lo-btn-next" type="button" onclick="nextBlock()">Next</button>
  </div>

  <div id="feedback"></div>

  <div id="lo-status"></div>

  <div id="lo-completed" class="lo-completed-screen">
    <div class="lo-completed-icon">✅</div>
    <h2 class="lo-completed-title" id="lo-completed-title"></h2>
    <p class="lo-completed-text" id="lo-completed-text"></p>
    <p class="lo-completed-text" id="lo-score-text" style="font-weight:700;font-size:18px;color:#4c1d95;"></p>
    <button type="button" class="lo-completed-button" id="lo-restart" onclick="restartActivity()">Restart</button>
  </div>
</div>

<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<audio id="doneSound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
const sourceBlocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;
const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const LO_ACTIVITY_ID = <?= json_encode($activityId ?? '', JSON_UNESCAPED_UNICODE) ?>;
const LO_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const loParams = new URLSearchParams(window.location.search || '');
const loRequestedPick = parseInt(loParams.get('lo_pick') || '', 10);
const loRequestedRatio = Number(loParams.get('lo_ratio') || '0.75');
const loRatio = Number.isFinite(loRequestedRatio) ? Math.max(0.1, Math.min(1, loRequestedRatio)) : 0.75;
const loComputedPick = Number.isFinite(loRequestedPick) && loRequestedPick > 0
  ? Math.min(loRequestedPick, sourceBlocks.length)
  : Math.max(1, Math.ceil(sourceBlocks.length * loRatio));
const blocks = sourceBlocks.length > 1
  ? shuffle(sourceBlocks).slice(0, loComputedPick)
  : sourceBlocks.slice();

/* ── Voice cache: Chrome loads voices asynchronously; keep a live copy ── */
var _loVoices = [];
function _loLoadVoices() {
  if ('speechSynthesis' in window) {
    _loVoices = window.speechSynthesis.getVoices() || [];
  }
}
if ('speechSynthesis' in window) {
  _loLoadVoices();
  window.speechSynthesis.addEventListener('voiceschanged', _loLoadVoices);
}

/* Return the best available English voice.
   Priority: known high-quality names → non-local (neural/online) → first English voice. */
function getPreferredVoice() {
  if (!_loVoices.length) _loLoadVoices();
  var voices = _loVoices;
  if (!voices.length) return null;

  var enVoices = voices.filter(function (v) {
    var l = String(v.lang || '').toLowerCase();
    return l === 'en-us' || l.startsWith('en-') || l.startsWith('en_');
  });
  if (!enVoices.length) enVoices = voices;

  /* Well-known high-quality English voices across platforms */
  var preferred = [
    'samantha', 'daniel',   'karen',  'moira',  'fiona',
    'alex',     'aria',     'jenny',  'guy',     'davis',
    'tony',     'emma',     'olivia', 'ava',     'allison',
    'victoria', 'kate',     'zira',   'hazel',   'mark',
  ];
  for (var p = 0; p < preferred.length; p++) {
    var hint = preferred[p];
    var match = null;
    for (var v = 0; v < enVoices.length; v++) {
      var label = (String(enVoices[v].name || '') + ' ' + String(enVoices[v].voiceURI || '')).toLowerCase();
      if (label.indexOf(hint) !== -1) { match = enVoices[v]; break; }
    }
    if (match) return match;
  }

  /* Prefer online/neural voices (non-local) as they tend to sound more natural */
  for (var v2 = 0; v2 < enVoices.length; v2++) {
    if (!enVoices[v2].localService) return enVoices[v2];
  }

  return enVoices[0];
}

/* ── Button visual state ── */
function setListenBtnState(state) {
  if (!listenBtn) return;
  if (state === 'speaking') {
    listenBtn.textContent = '⏸ Pause';
  } else if (state === 'paused') {
    listenBtn.textContent = '▶ Resume';
  } else {
    listenBtn.textContent = '🔊 Listen';
  }
}

let index = 0;
let correct = [];
let dragged = null;
var dragOffsetX = 0, dragOffsetY = 0;
let currentSentence = '';
let isSpeaking = false;
let isPaused = false;
let utter = null;
let speechOffset = 0;
let speechSourceText = '';
let speechSegmentStart = 0;
let finished = false;
let blockFinished = false;
let correctCount = 0;
let totalCount = blocks.length;
let attemptsByBlock = {};
let checkedBlocks = {};

const wordsDiv = document.getElementById('words');
const answerDiv = document.getElementById('answer');
const feedback = document.getElementById('feedback');
const statusEl = document.getElementById('lo-status');
const winSound = document.getElementById('winSound');
const loseSound = document.getElementById('loseSound');
const doneSound = document.getElementById('doneSound');
const sentenceBox = document.getElementById('sentenceBox');
const listenBtn = sentenceBox ? sentenceBox.querySelector('button.lo-btn-listen') : null;
const controls = document.querySelector('.lo-controls');
const completedEl = document.getElementById('lo-completed');
const completedTitleEl = document.getElementById('lo-completed-title');
const completedTextEl = document.getElementById('lo-completed-text');
const scoreTextEl = document.getElementById('lo-score-text');

if (completedTitleEl) {
  completedTitleEl.textContent = activityTitle || 'Listen & Order';
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

function playAudio() {
  if (finished) return;

  if (!currentSentence || String(currentSentence).trim() === '') {
    return;
  }

  if (listenBtn) {
    listenBtn.disabled = false;
  }

  if (speechSynthesis.paused || isPaused) {
    speechSynthesis.resume();
    isSpeaking = true;
    isPaused = false;
    setListenBtnState('speaking');

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
    setListenBtnState('paused');
    return;
  }

  speechSynthesis.cancel();
  speechSourceText = currentSentence || '';
  speechOffset = 0;
  startSpeechFromOffset();
}

function startSpeechFromOffset() {
  const source = speechSourceText || currentSentence || '';
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
  utter.lang   = 'en-US';
  utter.rate   = 0.9;   /* 0.9 = natural but still clear for learners (was 0.7) */
  utter.pitch  = 1;
  utter.volume = 1;

  /* Assign best available English voice */
  var bestVoice = getPreferredVoice();
  if (bestVoice) utter.voice = bestVoice;

  utter.onstart = function () {
    isSpeaking = true;
    isPaused = false;
    setListenBtnState('speaking');
  };

  utter.onpause = function () {
    isPaused = true;
    isSpeaking = true;
    setListenBtnState('paused');
  };

  utter.onresume = function () {
    isPaused = false;
    isSpeaking = true;
    setListenBtnState('speaking');
  };

  utter.onboundary = function (event) {
    if (typeof event.charIndex === 'number') {
      speechOffset = Math.max(speechSegmentStart, Math.min(source.length, speechSegmentStart + event.charIndex));
    }
  };

  utter.onend = function () {
    if (isPaused) return;
    isSpeaking = false;
    isPaused = false;
    speechOffset = 0;
    setListenBtnState('idle');
  };

  utter.onerror = function () {
    isSpeaking = false;
    isPaused = false;
    speechOffset = 0;
    setListenBtnState('idle');
  };

  speechSynthesis.speak(utter);
}

function shuffle(list) {
  return list.slice().sort(function () {
    return Math.random() - 0.5;
  });
}

function createImageChip(src) {
  const div = document.createElement('div');
  div.className = 'word';
  div.draggable = true;
  div.dataset.src = src;

  const img = document.createElement('img');
  img.src = src;

  div.appendChild(img);

  div.addEventListener('dragstart', function (e) {
    dragged = div;
    /* Record cursor offset inside the chip so drop position is exact */
    var rect = div.getBoundingClientRect();
    dragOffsetX = (e.clientX || 0) - rect.left;
    dragOffsetY = (e.clientY || 0) - rect.top;
  });

  div.addEventListener('click', function () {
    if (div.parentElement === answerDiv && !finished && !blockFinished) {
      /* Clear free-placement position before returning to the word bank */
      div.style.position = '';
      div.style.left = '';
      div.style.top  = '';
      wordsDiv.appendChild(div);
    }
  });

  return div;
}

/* ── Render drop-zone background images for the current block ── */
function renderDropZoneImages(block) {
  var images = (block && Array.isArray(block.dropZoneImages)) ? block.dropZoneImages : [];

  if (images.length > 0) {
    /* Switch to free-placement mode */
    answerDiv.classList.add('lo-free-mode');
  }

  images.forEach(function (dzi) {
    if (!dzi || !dzi.src) return;
    var img = document.createElement('img');
    img.className = 'uploaded-drop-image';
    img.src = dzi.src;
    img.draggable = false;
    img.alt = '';

    img.onload = function () {
      /* Size the drop zone to the image's natural aspect ratio.
         Zone width is already set by CSS (min(860px, 100%));
         compute height from that width to preserve the image proportions. */
      var nw = img.naturalWidth  || 400;
      var nh = img.naturalHeight || 300;
      var zoneW = answerDiv.offsetWidth || 600;
      var zoneH = Math.round(zoneW * (nh / nw));
      /* Cap at 78 % of viewport so it stays fully visible */
      var maxH = Math.round(window.innerHeight * 0.78);
      answerDiv.style.height = Math.min(zoneH, maxH) + 'px';
    };

    /* If image is already cached it won't fire onload — call manually */
    if (img.complete && img.naturalWidth) img.onload();

    answerDiv.appendChild(img);
  });
}

function updateStatus() {
  statusEl.classList.remove('completed');
  statusEl.textContent = (index + 1) + ' / ' + totalCount;
}

function checkAnswerAuto() {
  if (finished || blockFinished || checkedBlocks[index]) return;

  /* Chips only (.word) — uploaded-drop-image is excluded */
  var chips = Array.prototype.slice.call(answerDiv.querySelectorAll('.word'));

  /* ── Free-placement mode: just check that all chips are placed ── */
  if (answerDiv.classList.contains('lo-free-mode')) {
    if (chips.length < correct.length) return;
    feedback.textContent = '✔ All placed!';
    feedback.className = 'good';
    playSound(winSound);
    checkedBlocks[index] = true;
    correctCount++;
    blockFinished = true;
    return;
  }

  /* ── Ordered mode: original sequence check ── */
  var built = chips.map(function (node) { return node.dataset.src; });

  if (built.length !== correct.length) return;

  if (JSON.stringify(built) === JSON.stringify(correct)) {
    feedback.textContent = '✔ Right';
    feedback.className = 'good';
    playSound(winSound);
    checkedBlocks[index] = true;
    correctCount++;
    blockFinished = true;
  } else {
    var currentAttempts = (attemptsByBlock[index] || 0) + 1;
    attemptsByBlock[index] = currentAttempts;

    if (currentAttempts >= 2) {
      feedback.textContent = '✘ Wrong (2/2)';
      feedback.className = 'bad';
      playSound(loseSound);
      checkedBlocks[index] = true;
      blockFinished = true;
      chips.forEach(function (child, i) {
        if ((built[i] || '') !== (correct[i] || '')) child.classList.add('incorrect');
      });
    } else {
      feedback.textContent = '✘ Wrong (1/2) - try again';
      feedback.className = 'bad';
      playSound(loseSound);
    }
  }
}

function loadBlock() {
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

  if (wordsDiv) {
    wordsDiv.style.display = 'flex';
  }

  if (answerDiv) {
    /* display is managed by CSS (.lo-free-mode overrides to block) */
    answerDiv.style.display = '';
  }

  if (controls) {
    controls.style.display = 'flex';
  }

  if (listenBtn) {
    listenBtn.disabled = false;
  }
  setListenBtnState('idle');

  feedback.textContent = '';
  feedback.className = '';

  wordsDiv.innerHTML = '';
  answerDiv.innerHTML = '';
  /* Reset free-placement state between blocks */
  answerDiv.classList.remove('lo-free-mode');
  answerDiv.style.height = '';

  /* Render background image (adds lo-free-mode if present, sets height) */
  renderDropZoneImages(blocks[index] || {});

  const block = blocks[index] || {};
  currentSentence = typeof block.sentence === 'string' ? block.sentence : '';
  speechSourceText = currentSentence;
  correct = Array.isArray(block.images) ? block.images.slice() : [];

  updateStatus();

  shuffle(correct).forEach(function (src) {
    wordsDiv.appendChild(createImageChip(src));
  });
}

async function showCompleted() {
  finished = true;
  blockFinished = true;
  feedback.textContent = '';
  feedback.className = '';

  if (sentenceBox) {
    sentenceBox.style.display = 'none';
  }

  if (wordsDiv) {
    wordsDiv.style.display = 'none';
  }

  if (answerDiv) {
    answerDiv.style.display = 'none';
  }

  if (controls) {
    controls.style.display = 'none';
  }

  statusEl.classList.add('completed');
  statusEl.textContent = 'Completed';

  if (completedEl) {
    completedEl.classList.add('active');
  }

  playSound(doneSound);

  var pct = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
  var errors = Math.max(0, totalCount - correctCount);

  if (scoreTextEl) {
    scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
  }

  if (LO_ACTIVITY_ID && LO_RETURN_TO) {
    var joiner = LO_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    var saveUrl = LO_RETURN_TO +
        joiner + 'activity_percent=' + pct +
        '&activity_errors=' + errors +
        '&activity_total=' + totalCount +
        '&activity_id=' + encodeURIComponent(LO_ACTIVITY_ID) +
        '&activity_type=listen_order';

    var ok = await persistScoreSilently(saveUrl);
    if (!ok) {
        navigateToReturn(saveUrl);
    }
  }
}

answerDiv.addEventListener('dragover', function (event) {
  event.preventDefault();
});

answerDiv.addEventListener('drop', function (e) {
  e.preventDefault();
  if (!dragged || !dragged.classList.contains('word') || finished || blockFinished) return;

  if (answerDiv.classList.contains('lo-free-mode')) {
    /* ── Free-placement: drop chip at exact cursor position ── */
    var rect    = answerDiv.getBoundingClientRect();
    var rawLeft = (e.clientX || 0) - rect.left - dragOffsetX;
    var rawTop  = (e.clientY || 0) - rect.top  - dragOffsetY;

    if (dragged.parentElement !== answerDiv) {
      answerDiv.appendChild(dragged);
    }
    dragged.style.position = 'absolute';
    dragged.style.left = rawLeft + 'px';
    dragged.style.top  = rawTop  + 'px';

    /* Clamp to zone bounds after layout — offsetWidth/Height available now */
    requestAnimationFrame(function () {
      var zw = answerDiv.offsetWidth;
      var zh = answerDiv.offsetHeight;
      var cw = dragged.offsetWidth  || 0;
      var ch = dragged.offsetHeight || 0;
      dragged.style.left = Math.max(0, Math.min(zw - cw, parseFloat(dragged.style.left) || 0)) + 'px';
      dragged.style.top  = Math.max(0, Math.min(zh - ch, parseFloat(dragged.style.top)  || 0)) + 'px';
      setTimeout(checkAnswerAuto, 50);
    });
  } else {
    /* ── Ordered mode: append and sequence-check ── */
    answerDiv.appendChild(dragged);
    setTimeout(checkAnswerAuto, 100);
  }
});

function showAnswer() {
  var built = Array.prototype.slice.call(answerDiv.querySelectorAll('.word')).map(function (node) {
    return node.dataset.src;
  });

  var wasFreeMode = answerDiv.classList.contains('lo-free-mode');

  /* Clear zone then re-render background image */
  answerDiv.innerHTML = '';
  answerDiv.classList.remove('lo-free-mode');
  answerDiv.style.height = '';
  renderDropZoneImages(blocks[index] || {});
  wordsDiv.innerHTML = '';

  if (wasFreeMode) {
    /* Free-placement: spread chips evenly across the centre of the zone.
       Wait for the background image to set the zone height before positioning. */
    var count = correct.length;

    function placeChips() {
      var zw = answerDiv.offsetWidth  || 600;
      var zh = answerDiv.offsetHeight || 300;
      correct.forEach(function (src, i) {
        var chip = createImageChip(src);
        chip.style.zIndex = '3';
        answerDiv.appendChild(chip);
        /* Distribute horizontally, centre vertically */
        var gap = zw / (count + 1);
        var cw  = chip.offsetWidth  || 98;
        var ch  = chip.offsetHeight || 98;
        chip.style.left = Math.max(0, gap * (i + 1) - cw / 2) + 'px';
        chip.style.top  = Math.max(0, (zh - ch) / 2) + 'px';
      });
    }

    var bgImg = answerDiv.querySelector('.uploaded-drop-image');
    if (bgImg && !bgImg.complete) {
      bgImg.addEventListener('load', placeChips, { once: true });
    } else {
      requestAnimationFrame(placeChips);
    }
  } else {
    correct.forEach(function (src, position) {
      var chip = createImageChip(src);
      if ((built[position] || '') !== src) chip.classList.add('incorrect');
      answerDiv.appendChild(chip);
    });
  }

  feedback.textContent = wasFreeMode ? '👁 Correct order shown' : 'Show Answer';
  feedback.className = 'good';

  if (listenBtn) listenBtn.disabled = false;
  setListenBtnState('idle');
  blockFinished = true;
}

function nextBlock() {
  if (blockFinished || checkedBlocks[index]) {
    if (index >= blocks.length - 1) {
      showCompleted();
      return;
    }

    index += 1;
    loadBlock();
  } else {
    feedback.textContent = 'Check your answer first.';
    feedback.className = 'bad';
  }
}

function restartActivity() {
  index = 0;
  correctCount = 0;
  totalCount = blocks.length;
  attemptsByBlock = {};
  checkedBlocks = {};
  loadBlock();
}

loadBlock();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎧', $content);

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
    'voice_id' => 'nzFihrBIvB34imQBuxub',
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

        $image = isset($block['image']) && is_string($block['image']) ? trim($block['image']) : '';

        $blocks[] = [
            'text' => $text,
            'missing_words' => $missingWords,
            'listen_enabled' => $listenEnabled,
            'image' => $image,
        ];
    }

    return [
        'title' => $title !== '' ? $title : default_drag_drop_title(),
      'voice_id' => trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'blocks' => $blocks,
    ];
}

function load_drag_drop_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => default_drag_drop_title(),
    'voice_id' => 'nzFihrBIvB34imQBuxub',
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
      'voice_id' => (string) ($payload['voice_id'] ?? 'nzFihrBIvB34imQBuxub'),
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
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{--dd-orange:#F97316;--dd-purple:#7F77DD;--dd-purple-dark:#534AB7;--dd-lila:#EDE9FA;--dd-muted:#9B94BE;--dd-green:#16a34a;--dd-red:#dc2626}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:0;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;min-height:0!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}
.dd-page{width:100%;flex:1;min-height:0;overflow-y:auto;padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#fff;box-sizing:border-box}
.dd-app{width:min(860px,100%);margin:0 auto}
.dd-topbar{height:36px;display:flex;align-items:center;justify-content:center;margin-bottom:8px}
.dd-topbar-title{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}
.dd-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}
.dd-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.dd-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,58px);font-weight:700;color:#F97316;margin:0;line-height:1.03}
.dd-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.8vw,17px);font-weight:800;color:#9B94BE;margin:8px 0 0}
.dd-stage{background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13);width:min(760px,100%);margin:0 auto;box-sizing:border-box;position:relative}
.dd-intro{display:none}
#sentenceBox{margin:0 auto;padding:clamp(18px,3vw,28px);background:#fff;border:1px solid #EDE9FA;border-radius:28px;max-width:100%;min-height:clamp(240px,34vh,380px);box-shadow:0 12px 36px rgba(127,119,221,.13);box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
#blockImage{display:block;max-width:min(320px,100%);max-height:220px;margin:0 auto 18px;border-radius:22px;object-fit:contain;background:#fff;border:1px solid #EDE9FA;box-shadow:0 8px 24px rgba(127,119,221,.10)}
#promptText{width:100%;line-height:2;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:clamp(22px,3.2vw,34px);font-weight:600;color:#534AB7;text-align:center}
.blank{display:inline-flex;align-items:center;justify-content:center;min-width:110px;min-height:48px;padding:6px 12px;margin:4px 6px;border:2px dashed #EDE9FA;border-radius:16px;background:#fff;vertical-align:middle;color:#9B94BE;font-weight:900;box-shadow:0 4px 14px rgba(127,119,221,.10);transition:border-color .15s,background .15s,box-shadow .15s}
.blank.filled{border-style:solid;border-color:#7F77DD;background:#EEEDFE;color:#534AB7}
.blank.incorrect{border-color:#dc2626;background:#fff;color:#dc2626;box-shadow:0 0 0 2px rgba(220,38,38,.18)}
#wordBank{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin:18px 0 0}
.word{padding:11px 16px;border-radius:999px;color:#534AB7;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;cursor:grab;background:#fff;border:1px solid #EDE9FA;box-shadow:0 6px 18px rgba(127,119,221,.13);user-select:none;touch-action:manipulation;transition:transform .12s,filter .12s,box-shadow .12s,border-color .12s}
.word:hover{transform:translateY(-1px);border-color:#7F77DD;box-shadow:0 12px 24px rgba(127,119,221,.16)}
.word.selected-touch{outline:3px solid rgba(127,119,221,.30);outline-offset:2px;background:#EEEDFE;color:#534AB7}
.dd-touch-hint{text-align:center;color:#9B94BE;font-size:13px;font-weight:900;margin:10px 0 0}.dd-touch-hint.hidden{display:none}
.dd-btn,.dd-completed-button{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;border:none;border-radius:999px;color:#fff;cursor:pointer;min-width:clamp(104px,16vw,146px);font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:13px;line-height:1;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s,box-shadow .12s}
.dd-btn:hover,.dd-completed-button:hover{filter:brightness(1.07);transform:translateY(-1px)}
.dd-btn:active,.dd-completed-button:active{transform:scale(.98)}
.dd-btn-listen{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);margin-bottom:16px}
.dd-btn-show{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.dd-btn-next{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22)}
#listenBtn.hidden{display:none}
#feedback{text-align:center;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;min-height:18px;margin-top:10px;color:#534AB7}.good{color:#16a34a!important}.bad{color:#dc2626!important}
.controls{border-top:1px solid #F0EEF8;margin-top:16px;padding-top:16px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;background:#fff}
.dd-completed-screen{display:none;background:#fff;border:1px solid #EDE9FA;border-radius:28px;box-shadow:0 12px 36px rgba(127,119,221,.13);min-height:clamp(300px,42vh,430px);flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:clamp(28px,5vw,48px) 24px;gap:12px;box-sizing:border-box}
.dd-completed-screen.active{display:flex}.dd-completed-icon{font-size:64px;line-height:1;margin-bottom:4px}
.dd-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:clamp(30px,5.5vw,58px);font-weight:700;color:#F97316;margin:0;line-height:1.03}
.dd-completed-text{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.8vw,17px);font-weight:800;color:#9B94BE;line-height:1.5;margin:0}
#dd-score-text{color:#534AB7!important;font-family:'Nunito',sans-serif!important;font-size:15px!important;font-weight:900!important}
.dd-completed-button{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);margin-top:4px}
@media(max-width:760px){.dd-page{padding:12px}.dd-topbar{height:30px;margin-bottom:4px}.dd-kicker{padding:5px 11px;font-size:11px;margin-bottom:6px}.dd-hero h1{font-size:clamp(26px,8vw,38px)}.dd-stage{border-radius:26px;padding:14px;width:100%}#sentenceBox{border-radius:22px;padding:18px;min-height:260px}#promptText{line-height:1.9;font-size:clamp(20px,6.2vw,28px)}.blank{min-width:86px;min-height:42px;margin:3px 4px;padding:5px 9px}.word{padding:10px 14px;font-size:14px}#wordBank{gap:9px}.controls{display:grid;grid-template-columns:1fr;gap:9px}.dd-btn,.dd-completed-button{width:100%}.dd-completed-screen{border-radius:26px}}
</style>

<div class="dd-page">
  <div class="dd-app">
    <div class="dd-topbar">
      <span class="dd-topbar-title">Drag and Drop</span>
    </div>

    <div class="dd-hero">
      <div class="dd-kicker">Activity</div>
      <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
      <p>Drag the words into the correct blanks.</p>
    </div>

    <div class="dd-stage">
      <div id="sentenceBox">
        <img id="blockImage" src="" alt="" style="display:none;">
        <button id="listenBtn" class="dd-btn dd-btn-listen" type="button" onclick="speak()">Listen</button>
        <div id="promptText"></div>
      </div>

      <div id="wordBank"></div>
      <div id="ddTouchHint" class="dd-touch-hint hidden">Tap a word, then tap a blank space to place it.</div>

      <div class="controls">
        <button class="dd-btn dd-btn-show" type="button" onclick="showAnswer()">Show Answer</button>
        <button class="dd-btn dd-btn-next" type="button" onclick="nextSentence()">Next</button>
      </div>

      <div id="feedback"></div>

      <div id="dd-completed" class="dd-completed-screen">
        <div class="dd-completed-icon">✅</div>
        <h2 class="dd-completed-title" id="dd-completed-title"></h2>
        <p class="dd-completed-text" id="dd-completed-text"></p>
        <p class="dd-completed-text" id="dd-score-text" style="font-weight:900;font-size:15px;color:#534AB7;"></p>
        <button type="button" class="dd-completed-button" id="dd-restart" onclick="restartActivity()">Restart</button>
      </div>
    </div>
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
const DD_VOICE_ID = <?= json_encode((string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub'), JSON_UNESCAPED_UNICODE) ?>;
const DD_TTS_URL = 'tts.php';

let index = 0;
let dragged = null;
let currentText = '';
let currentAnswers = [];
let listenEnabled = true;
let isSpeaking = false;
let isPaused = false;
let currentAudio = null;
let currentAudioUrl = '';
let finished = false;
let blockFinished = false;
let correctCount = 0;
let totalCount = 0;
let checkedBlocks = {};
let attemptsByBlock = {};
let scoredWordsByBlock = {};

const promptText = document.getElementById('promptText');
const blockImage = document.getElementById('blockImage');
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
const ddTouchHint = document.getElementById('ddTouchHint');

const isTouchLike = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches)
  || ('ontouchstart' in window)
  || (navigator.maxTouchPoints > 0);

let selectedWordChip = null;

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
    if (currentAudio) {
      currentAudio.pause();
      currentAudio.currentTime = 0;
      currentAudio = null;
    }
    if (currentAudioUrl) {
      try { URL.revokeObjectURL(currentAudioUrl); } catch (e) {}
      currentAudioUrl = '';
    }
    isSpeaking = false;
    isPaused = false;
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
  chip.setAttribute('role', 'button');
  chip.setAttribute('tabindex', '0');

  chip.addEventListener('dragstart', function () {
    dragged = chip;
  });

  chip.addEventListener('click', function () {
    if (!isTouchLike || finished || blockFinished) return;
    toggleSelectedChip(chip);
  });

  chip.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (finished || blockFinished) return;
    event.preventDefault();
    if (isTouchLike) {
      toggleSelectedChip(chip);
    }
  });

  return chip;
}

function clearSelectedChip() {
  if (!selectedWordChip) return;
  selectedWordChip.classList.remove('selected-touch');
  selectedWordChip = null;
}

function toggleSelectedChip(chip) {
  if (selectedWordChip === chip) {
    clearSelectedChip();
    return;
  }

  if (selectedWordChip) {
    selectedWordChip.classList.remove('selected-touch');
  }

  selectedWordChip = chip;
  selectedWordChip.classList.add('selected-touch');
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

    if (isTouchLike && selectedWordChip) {
      const selectedWord = selectedWordChip.dataset.word || '';
      const existingWord = blank.dataset.word || '';
      if (!selectedWord) {
        clearSelectedChip();
        return;
      }

      if (existingWord) {
        wordBank.appendChild(createWordChip(existingWord));
      }

      blank.dataset.word = selectedWord;
      blank.textContent = selectedWord;
      blank.classList.add('filled');
      blank.classList.remove('incorrect');

      selectedWordChip.remove();
      clearSelectedChip();

      setTimeout(autoCheckIfNeeded, 40);
      return;
    }

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
  if (currentAudio) {
    currentAudio.pause();
    currentAudio.currentTime = 0;
    currentAudio = null;
  }
  if (currentAudioUrl) {
    try { URL.revokeObjectURL(currentAudioUrl); } catch (e) {}
    currentAudioUrl = '';
  }
  isSpeaking = false;
  isPaused = false;
  dragged = null;
  clearSelectedChip();
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

  if (ddTouchHint) {
    ddTouchHint.classList.toggle('hidden', !isTouchLike);
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
  currentAnswers = getAnswersForBlock(block);
  listenEnabled = !!block.listen_enabled;

  const imgSrc = typeof block.image === 'string' ? block.image.trim() : '';
  if (blockImage) {
    if (imgSrc) {
      blockImage.src = imgSrc;
      blockImage.style.display = 'block';
    } else {
      blockImage.src = '';
      blockImage.style.display = 'none';
    }
  }

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
  if (currentAudio) {
    currentAudio.pause();
    currentAudio.currentTime = 0;
    currentAudio = null;
  }
  if (currentAudioUrl) {
    try { URL.revokeObjectURL(currentAudioUrl); } catch (e) {}
    currentAudioUrl = '';
  }
  isSpeaking = false;
  isPaused = false;
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
  clearSelectedChip();
  loadSentence();
}

function speak() {
  if (!listenEnabled) return;

  if (!currentText || String(currentText).trim() === '') {
    return;
  }

  if (currentAudio) {
    if (!currentAudio.paused) {
      currentAudio.pause();
      isSpeaking = true;
      isPaused = true;
    } else {
      currentAudio.play().then(function () {
        isSpeaking = true;
        isPaused = false;
      }).catch(function () {});
    }
    return;
  }

  listenBtn.disabled = true;
  listenBtn.textContent = '...';

  const fd = new FormData();
  fd.append('text', currentText);
  fd.append('voice_id', DD_VOICE_ID || 'nzFihrBIvB34imQBuxub');

  fetch(DD_TTS_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (res) {
      if (!res.ok) throw new Error('TTS error ' + res.status);
      return res.blob();
    })
    .then(function (blob) {
      currentAudioUrl = URL.createObjectURL(blob);
      currentAudio = new Audio(currentAudioUrl);

      currentAudio.onended = function () {
        isSpeaking = false;
        isPaused = false;
        if (currentAudioUrl) {
          try { URL.revokeObjectURL(currentAudioUrl); } catch (e) {}
          currentAudioUrl = '';
        }
        currentAudio = null;
      };

      currentAudio.onpause = function () {
        if (currentAudio && currentAudio.currentTime < (currentAudio.duration || Infinity)) {
          isSpeaking = true;
          isPaused = true;
        }
      };

      return currentAudio.play().then(function () {
        isSpeaking = true;
        isPaused = false;
      });
    })
    .catch(function () {
      isSpeaking = false;
      isPaused = false;
    })
    .finally(function () {
      listenBtn.disabled = false;
      listenBtn.textContent = 'Listen';
    });
}

totalCount = blocks.reduce(function (sum, block) {
  return sum + getAnswersForBlock(block).length;
}, 0);

loadSentence();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎯', $content);

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

        if ($sentence === '') {
            continue;
        }

        $blocks[] = [
            'sentence' => $sentence,
            'images' => $images,
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

if (count($blocks) === 0) {
    die('No activities for this unit');
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
  max-width:760px;
  box-shadow:0 8px 24px rgba(0,0,0,.08);
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
  height:90px;
  width:auto;
  display:block;
  object-fit:contain;
}

.drop-zone{
  background:#fff;
  border:2px dashed #0b5ed7;
  border-radius:12px;
  padding:15px;
  min-height:110px;
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

#feedback{
  font-size:20px;
  font-weight:bold;
  min-height:28px;
  text-align:center;
}

.good{color:green;}
.bad{color:crimson;}
</style>

<p class="instructions">Listen and drag the images into the correct order.</p>

<div id="sentenceBox">
  <button type="button" onclick="playAudio()">🔊 Listen</button>
</div>

<div id="words"></div>
<div id="answer" class="drop-zone"></div>

<div>
  <button type="button" onclick="checkOrder()">✅ Check</button>
  <button type="button" onclick="nextBlock()">➡️ Next</button>
</div>

<div id="feedback"></div>

<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let correct = [];
let dragged = null;
let currentSentence = '';
let isSpeaking = false;
let isPaused = false;
let utter = null;
let finished = false;

const wordsDiv = document.getElementById('words');
const answerDiv = document.getElementById('answer');
const feedback = document.getElementById('feedback');
const winSound = document.getElementById('winSound');

function playSound(audio) {
  try {
    audio.pause();
    audio.currentTime = 0;
    audio.play();
  } catch (e) {}
}

function playAudio() {
  if (finished) return;

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

  speechSynthesis.cancel();

  utter = new SpeechSynthesisUtterance(currentSentence || '');
  utter.lang = 'en-US';
  utter.rate = 0.7;
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
  div.addEventListener('dragstart', function () {
    dragged = div;
  });

  div.addEventListener('click', function () {
    if (div.parentElement === answerDiv && !finished) {
      wordsDiv.appendChild(div);
    }
  });

  return div;
}

function loadBlock() {
  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;
  dragged = null;
  finished = false;

  feedback.textContent = '';
  feedback.className = '';

  wordsDiv.innerHTML = '';
  answerDiv.innerHTML = '';

  const block = blocks[index] || {};
  currentSentence = typeof block.sentence === 'string' ? block.sentence : '';
  correct = Array.isArray(block.images) ? block.images.slice() : [];

  shuffle(correct).forEach(function (src) {
    wordsDiv.appendChild(createImageChip(src));
  });
}

answerDiv.addEventListener('dragover', function (event) {
  event.preventDefault();
});

answerDiv.addEventListener('drop', function () {
  if (dragged && !finished) {
    answerDiv.appendChild(dragged);
  }
});

function checkOrder() {
  if (finished) return;

  const built = Array.prototype.slice.call(answerDiv.children).map(function (node) {
    return node.dataset.src;
  });

  if (built.length !== correct.length) {
    feedback.textContent = '⚠ Complete all images first.';
    feedback.className = 'bad';
    return;
  }

  if (JSON.stringify(built) === JSON.stringify(correct)) {
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

function nextBlock() {
  if (index >= blocks.length - 1) {
    feedback.textContent = '🏆 Completed!';
    feedback.className = 'good';
    playSound(winSound);
    finished = true;
    return;
  }

  index += 1;
  loadBlock();
}

loadBlock();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎧', $content);

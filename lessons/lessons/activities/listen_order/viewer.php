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
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen &amp; Order</title>
<style>
body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}

h1{color:#0b5ed7;}

#sentenceBox{
  margin:20px auto;
  padding:15px;
  background:white;
  border-radius:15px;
  max-width:700px;
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

#feedback{
  font-size:18px;
  font-weight:bold;
}

.good{color:green;}
.bad{color:crimson;}

.back{
  display:inline-block;
  margin-top:20px;
  background:#16a34a;
  color:white;
  padding:10px 18px;
  border-radius:12px;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>
<body>

<div id="sentenceBox">
  <button onclick="playAudio()">🔊 Listen</button>
</div>

<div id="words"></div>
<div id="answer" class="drop-zone"></div>

<div>
  <button onclick="checkOrder()">✅ Check</button>
  <button onclick="nextBlock()">➡️ Next</button>
</div>

<div id="feedback"></div>

<a class="back" href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>">↩ Back</a>

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

function playAudio() {
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

  utter = new SpeechSynthesisUtterance(blocks[index].sentence || '');
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

function loadBlock() {
  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;

  dragged = null;
  feedback.textContent = '';
  feedback.className = '';

  wordsDiv.innerHTML = '';
  answerDiv.innerHTML = '';

  const block = blocks[index] || {};
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

    div.appendChild(img);
    div.addEventListener('dragstart', function () {
      dragged = div;
    });

    wordsDiv.appendChild(div);
  });
}

answerDiv.addEventListener('dragover', function (event) {
  event.preventDefault();
});

answerDiv.addEventListener('drop', function () {
  if (dragged) {
    answerDiv.appendChild(dragged);
  }
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
    return;
  }

  loadBlock();
}

loadBlock();
</script>

</body>
</html>
<?php
$content = ob_get_clean();
render_activity_viewer('Listen & Order', '🎧', $content);

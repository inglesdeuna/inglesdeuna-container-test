<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ========= LOAD FROM DB ========= */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
    die("Unidad no especificada");
}

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'listen_order'
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'listen_order'
    LIMIT 1
");
$stmt->execute(["u"=>$unit]);
$stmt->execute(["unit" => $unit]);
$stmt = $pdo->prepare("\n    SELECT data\n    FROM activities\n    WHERE unit_id = :unit\n    AND type = 'listen_order'\n    LIMIT 1\n");
$stmt->execute(array("unit" => $unit));

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$blocks = json_decode($row["data"] ?? "[]", true);
$blocks = json_decode($row['data'] ?? '[]', true);
$blocks = is_array($blocks) ? $blocks : [];
$blocks = is_array($blocks) ? $blocks : array();

if(!$blocks || count($blocks) == 0){
    die("No activities for this unit");
}
ob_start();
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{
  font-family: Arial;
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

<h1>🎧 Listen & Order</h1>

<div id="sentenceBox">
  <button onclick="playAudio()">🔊 Listen</button>
</div>

<div id="words"></div>
<div id="answer" class="drop-zone"></div>

<div>
  <button onclick="checkOrder()">✅ Check</button>
  <button onclick="nextBlock()">➡️</button>
</div>

<div id="feedback"></div>

<a class="back" href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>">
↩ Back
</a>

<script>

const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let correct = [];
let dragged = null;

/* ===== TTS CONTROL ===== */

let utter = null;
let isPaused = false;
let isSpeaking = false;

function playAudio(){

  if (isSpeaking && !isPaused) {
    speechSynthesis.pause();
    isPaused = true;
    return;
  }
<?php if (empty($blocks)): ?>
<?php if (empty($blocks)) { ?>
    <p style="text-align:center;color:#dc2626;font-weight:bold;">No activities for this unit.</p>
<?php else: ?>
<?php } else { ?>
    <style>
        #sentenceBox{
            margin:20px auto;
            padding:15px;
            background:#fff;
            border-radius:15px;
            max-width:760px;
            text-align:center;
        }

        #words, #answer{
            display:flex;
            flex-wrap:wrap;
            justify-content:center;
            gap:10px;
            margin:15px auto;
            max-width:900px;
        }

        .card{
            padding:6px;
            border-radius:12px;
            background:white;
            cursor:grab;
            box-shadow:0 2px 6px rgba(0,0,0,.15);
@@ -214,125 +67,125 @@ function playAudio(){
            text-align:center;
            margin-top:10px;
        }

        .btn{
            padding:10px 18px;
            border:none;
            border-radius:10px;
            background:#0b5ed7;
            color:white;
            cursor:pointer;
            margin:0 6px;
        }

        #feedback{
            text-align:center;
            font-size:18px;
            font-weight:bold;
            margin-top:12px;
        }

        .good{ color:green; }
        .bad{ color:#dc2626; }
    </style>

    <h2 style="text-align:center;color:#0b5ed7;margin:0 0 10px 0;">🎧 Listen & Order</h2>

    <div id="sentenceBox">
        <button class="btn" onclick="playAudio()">🔊 Listen</button>
    </div>

    <div id="words"></div>
    <div id="answer" class="drop-zone"></div>

    <div class="actions">
        <button class="btn" onclick="checkOrder()">✅ Check</button>
        <button class="btn" onclick="nextBlock()">➡️ Next</button>
    </div>

    <div id="feedback"></div>

    <script>
        const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

        let index = 0;
        let correct = [];
        let dragged = null;

        let isPaused = false;
        let isSpeaking = false;

        const wordsDiv = document.getElementById("words");
        const answerDiv = document.getElementById("answer");
        const feedback = document.getElementById("feedback");

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

            const utter = new SpeechSynthesisUtterance(blocks[index].sentence || "");
            const sentence = (blocks[index] && blocks[index].sentence) ? blocks[index].sentence : "";
            const utter = new SpeechSynthesisUtterance(sentence);
            utter.lang = "en-US";
            utter.rate = 0.7;

            utter.onstart = function () {
                isSpeaking = true;
                isPaused = false;
            };

            utter.onend = function () {
                isSpeaking = false;
                isPaused = false;
            };

            speechSynthesis.cancel();
            speechSynthesis.speak(utter);
        }

        function loadBlock() {
            speechSynthesis.cancel();
            isSpeaking = false;
            isPaused = false;
            dragged = null;

            feedback.textContent = "";
            feedback.className = "";
            wordsDiv.innerHTML = "";
            answerDiv.innerHTML = "";

            const block = blocks[index];
            correct = Array.isArray(block.images) ? [...block.images] : [];
            const shuffled = [...correct].sort(() => Math.random() - 0.5);
            const block = blocks[index] || {};
            correct = Array.isArray(block.images) ? block.images.slice() : [];
            const shuffled = correct.slice().sort(function () {
                return Math.random() - 0.5;
            });

            shuffled.forEach(function (src) {
                const div = document.createElement("div");
                div.className = "card";
                div.draggable = true;
                div.dataset.src = src;

                const img = document.createElement("img");
                img.src = src;

                div.appendChild(img);
                div.addEventListener("dragstart", function () {
                    dragged = div;
                });

                wordsDiv.appendChild(div);
            });
        }

        answerDiv.addEventListener("dragover", function (e) {
            e.preventDefault();
        });

        answerDiv.addEventListener("drop", function () {
            if (dragged) {
@@ -347,134 +200,30 @@ function playAudio(){

            if (JSON.stringify(built) === JSON.stringify(correct)) {
                feedback.textContent = "🌟 Excellent!";
                feedback.className = "good";
            } else {
                feedback.textContent = "🔁 Try again!";
                feedback.className = "bad";
            }
        }

        function nextBlock() {
            index++;

            if (index >= blocks.length) {
                feedback.textContent = "🏆 Completed!";
                feedback.className = "good";
                index = blocks.length - 1;
                return;
            }

            loadBlock();
        }

        loadBlock();
    </script>
<?php endif; ?>

  if (isPaused) {
    speechSynthesis.resume();
    isPaused = false;
    return;
  }

  utter = new SpeechSynthesisUtterance(blocks[index].sentence);

  utter.lang = "en-US";
  utter.rate = 0.7;
  utter.pitch = 1;
  utter.volume = 1;

  utter.onstart = () => {
    isSpeaking = true;
    isPaused = false;
  };

  utter.onend = () => {
    isSpeaking = false;
    isPaused = false;
  };

  speechSynthesis.speak(utter);
}

/* ===== GAME LOGIC ===== */

const wordsDiv = document.getElementById("words");
const answerDiv = document.getElementById("answer");
const feedback = document.getElementById("feedback");

function loadBlock(){

  speechSynthesis.cancel();
  isSpeaking = false;
  isPaused = false;

  dragged = null;
  feedback.textContent="";
  feedback.className="";

  wordsDiv.innerHTML="";
  answerDiv.innerHTML="";

  const block = blocks[index];
  correct = [...block.images];

  let shuffled = [...correct].sort(()=>Math.random()-0.5);

  shuffled.forEach(src=>{
    const div=document.createElement("div");
    div.className="word";
    div.draggable=true;
    div.dataset.src=src;

    const img=document.createElement("img");
    img.src=src; // 🔥 Cloudinary URL directa

    div.appendChild(img);

    div.addEventListener("dragstart",()=>dragged=div);
    wordsDiv.appendChild(div);
  });
}

answerDiv.addEventListener("dragover", e=>e.preventDefault());

answerDiv.addEventListener("drop", ()=>{
  if(dragged) answerDiv.appendChild(dragged);
});

function checkOrder(){

  const built=[...answerDiv.children].map(x=>x.dataset.src);

  if(JSON.stringify(built)===JSON.stringify(correct)){
    feedback.textContent="🌟 Excellent!";
    feedback.className="good";
  }else{
    feedback.textContent="🔁 Try again!";
    feedback.className="bad";
  }
}

function nextBlock(){

  index++;

  if(index>=blocks.length){
    feedback.textContent="🏆 Completed!";
    feedback.className="good";
    return;
  }

  loadBlock();
}

loadBlock();

</script>
<?php } ?>

</body>
</html>
<?php
$content = ob_get_clean();
render_activity_viewer("Listen & Order", "🎧", $content);

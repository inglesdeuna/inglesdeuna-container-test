<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$file = __DIR__ . "/drag_drop.json";

$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!isset($data[$unit]) || empty($data[$unit])) {
  die("No hay oraciones para esta unidad");
}

/* EXTRAER SOLO LAS ORACIONES */
$sentences = [];

foreach ($data[$unit] as $item) {
  if (isset($item["sentence"])) {
    $sentences[] = $item["sentence"];
  }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<title>Build the Sentence</title>

<style>

body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}

h1{
  color:#0b5ed7;
}

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
  padding:8px 14px;
  border-radius:10px;
  color:white;
  font-weight:bold;
  cursor:grab;
  background:#2563eb;
}

.drop-zone{
  background:#fff;
  border:2px dashed #0b5ed7;
  border-radius:12px;
  padding:15px;
  min-height:60px;
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

.good{
  color:green;
}

.bad{
  color:crimson;
}

.controls{
  margin-top:15px;
}

a.back{
  display:inline-block;
  margin-top:20px;
  background:#16a34a;
  color:#fff;
  padding:10px 18px;
  border-radius:12px;
  text-decoration:none;
  font-weight:bold;
}

</style>
</head>

<body>

<h1>🎯 Build the Sentence</h1>
<p>Drag the words to build the sentence.</p>

<div id="sentenceBox">
  <button onclick="speak()">🔊 Listen</button>
</div>

<h3>Words</h3>
<div id="words"></div>

<h3>Your sentence</h3>
<div id="answer" class="drop-zone"></div>

<div class="controls">
  <button onclick="checkSentence()">✅ Check</button>
  <button onclick="nextSentence()">➡️</button>
</div>

<div id="feedback"></div>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
  ↩ Volver al Hub
</a>

<script>

const sentences = <?= json_encode($sentences) ?>;

let index = 0;
let correct = "";
let dragged = null;

const wordsDiv = document.getElementById("words");
const answerDiv = document.getElementById("answer");
const feedback = document.getElementById("feedback");

/* ================= LOAD ================= */

function loadSentence(){

  dragged = null;
  feedback.textContent = "";
  feedback.className = "";

  wordsDiv.innerHTML = "";
  answerDiv.innerHTML = "";

  correct = sentences[index];

  let words = correct.split(" ").sort(()=>Math.random()-0.5);

  words.forEach(w=>{

    const span = document.createElement("span");
    span.textContent = w;
    span.className="word";
    span.draggable=true;

    span.addEventListener("dragstart",()=>dragged=span);

    wordsDiv.appendChild(span);

  });

}

/* ================= DRAG ================= */

answerDiv.addEventListener("dragover", e=>e.preventDefault());

answerDiv.addEventListener("drop", ()=>{
  if(dragged) answerDiv.appendChild(dragged);
});

/* ================= CHECK ================= */

function checkSentence(){

  const built = [...answerDiv.children]
  .map(w=>w.textContent)
  .join(" ");

  if(built === correct){
    feedback.textContent="🌟 Excellent!";
    feedback.className="good";
  }else{
    feedback.textContent="🔁 Try again!";
    feedback.className="bad";
  }

}

/* ================= NEXT ================= */

function nextSentence(){

  index++;

  if(index >= sentences.length){
    feedback.textContent="🏆 You finished all sentences!";
    feedback.className="good";
    return;
  }

  loadSentence();

}

/* ================= TTS ================= */

function speak(){

  const msg = new SpeechSynthesisUtterance(correct);
  msg.lang="en-US";

  speechSynthesis.speak(msg);

}

/* ================= START ================= */

loadSentence();

</script>

</body>
</html>

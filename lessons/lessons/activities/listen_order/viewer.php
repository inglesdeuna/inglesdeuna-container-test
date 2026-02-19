<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$file = __DIR__ . "/listen_order.json";

$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!isset($data[$unit]) || empty($data[$unit])) {
  die("No hay actividades para esta unidad");
}

$blocks = $data[$unit];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

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
  color:#fff;
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

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
↩ Back
</a>

<script>

const blocks = <?= json_encode($blocks, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;
let correct = [];
let dragged = null;

const wordsDiv = document.getElementById("words");
const answerDiv = document.getElementById("answer");
const feedback = document.getElementById("feedback");

/* 🔥 BASE PATH PARA PRODUCCIÓN */
const BASE_PATH = "/lessons/lessons/";

function loadBlock(){

  dragged=null;
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
    img.src = BASE_PATH + src;

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

function playAudio(){

  speechSynthesis.cancel(); // limpiar cualquier audio anterior

  const msg = new SpeechSynthesisUtterance(blocks[index].sentence);
  msg.lang = "en-US";
  msg.rate = 0.7; // más lento

  speechSynthesis.speak(msg);

}


loadBlock();

</script>

</body>
</html>

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
  font-family: Arial;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}

h1{ color:#0b5ed7; }

#sentenceBox{
  margin:20px auto;
  padding:15px;
  background:white;
  border-radius:15px;
  max-width:700px;
}

#images, #answer{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:12px;
  margin:15px 0;
}

.imgCard{
  background:white;
  padding:8px;
  border-radius:12px;
  cursor:grab;
  box-shadow:0 2px 6px rgba(0,0,0,0.1);
}

.imgCard img{
  height:90px;
}

.drop-zone{
  background:white;
  border:2px dashed #0b5ed7;
  border-radius:14px;
  padding:18px;
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
}

#feedback{
  font-size:18px;
  font-weight:bold;
}

.good{ color:green; }
.bad{ color:crimson; }

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

<div id="images"></div>

<div id="answer" class="drop-zone"></div>

<div>
  <button onclick="check()">✅ Check</button>
  <button onclick="next()">➡️</button>
</div>

<div id="feedback"></div>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
↩ Volver al Hub
</a>

<script>

const blocks = <?= json_encode($blocks) ?>;

let index = 0;
let correctOrder = [];
let dragged = null;

const imagesDiv = document.getElementById("images");
const answerDiv = document.getElementById("answer");
const feedback = document.getElementById("feedback");

function loadBlock(){

  feedback.textContent="";
  feedback.className="";

  imagesDiv.innerHTML="";
  answerDiv.innerHTML="";

  const block = blocks[index];

  correctOrder = [...block.images];

  let shuffled = [...correctOrder].sort(()=>Math.random()-0.5);

  shuffled.forEach(src=>{
    const card = document.createElement("div");
    card.className="imgCard";
    card.draggable=true;
    card.dataset.src=src;

    const img=document.createElement("img");
    img.src="../../"+src;

    card.appendChild(img);

    card.addEventListener("dragstart",()=>dragged=card);

    imagesDiv.appendChild(card);
  });
}

answerDiv.addEventListener("dragover", e=>e.preventDefault());

answerDiv.addEventListener("drop", ()=>{
  if(dragged) answerDiv.appendChild(dragged);
});

function check(){

  const built = [...answerDiv.children].map(x=>x.dataset.src);

  if(JSON.stringify(built) === JSON.stringify(correctOrder)){
    feedback.textContent="🌟 Excellent!";
    feedback.className="good";
  }else{
    feedback.textContent="🔁 Try again!";
    feedback.className="bad";
  }
}

function next(){

  index++;

  if(index >= blocks.length){
    feedback.textContent="🏆 Completado!";
    feedback.className="good";
    return;
  }

  loadBlock();
}

function playAudio(){
  const audio = new Audio("../../"+blocks[index].audio);
  audio.play();
}

loadBlock();

</script>

</body>
</html>

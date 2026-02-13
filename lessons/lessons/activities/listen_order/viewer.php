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
<html lang="en">
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

.images, .drop{
display:flex;
flex-wrap:wrap;
justify-content:center;
gap:12px;
margin-top:20px;
}

.images img, .drop img{
width:110px;
height:110px;
object-fit:contain;
background:white;
padding:8px;
border-radius:14px;
box-shadow:0 2px 6px rgba(0,0,0,.1);
cursor:grab;
}

.drop{
border:2px dashed #0b5ed7;
min-height:130px;
padding:20px;
border-radius:14px;
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
margin-top:10px;
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

<button onclick="speak()">🔊 Listen</button>

<div id="images" class="images"></div>

<div id="drop" class="drop"></div>

<br>

<button onclick="check()">✅ Check</button>
<button onclick="next()">➡ Next</button>

<div id="feedback"></div>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
↩ Volver al Hub
</a>

<script>

const blocks = <?= json_encode($blocks) ?>;

let index = 0;
let dragged = null;
let correctOrder = [];
let correctText = "";

const imagesDiv = document.getElementById("images");
const dropDiv = document.getElementById("drop");
const feedback = document.getElementById("feedback");

/* ================= LOAD ================= */

function load(){

feedback.textContent="";
feedback.className="";

imagesDiv.innerHTML="";
dropDiv.innerHTML="";

let block = blocks[index];

/* TEXTO UNIVERSAL */
correctText =
block.sentence ||
block.text ||
block.tts ||
"";

correctOrder = block.images;

/* shuffle images */
let shuffled = [...correctOrder].sort(()=>Math.random()-0.5);

shuffled.forEach(src=>{
let img = document.createElement("img");
img.src="../../"+src;
img.draggable=true;
img.addEventListener("dragstart",()=> dragged = img);
imagesDiv.appendChild(img);
});

}

/* ================= DRAG ================= */

dropDiv.addEventListener("dragover", e=>e.preventDefault());

dropDiv.addEventListener("drop", ()=>{
if(dragged){
dropDiv.appendChild(dragged);
dragged=null;
}
});

/* ================= CHECK ================= */

function check(){

let built = [...dropDiv.children].map(img =>
img.src.split("/activities/")[1]
);

let ok = true;

for(let i=0;i<correctOrder.length;i++){
if(!built[i] || !built[i].includes(correctOrder[i])){
ok=false;
break;
}
}

if(ok){
feedback.textContent="🌟 Excellent!";
feedback.className="good";
}else{
feedback.textContent="🔁 Try again!";
feedback.className="bad";
}

}

/* ================= NEXT ================= */

function next(){

index++;

if(index >= blocks.length){
feedback.textContent="🏆 Finished!";
feedback.className="good";
return;
}

load();

}

/* ================= TTS UNIVERSAL ================= */

function speak(){

if(!correctText) return;

const msg = new SpeechSynthesisUtterance(correctText);
msg.lang="en-US";
msg.rate=0.9;
msg.pitch=1;

speechSynthesis.speak(msg);

}

/* START */
load();

</script>

</body>
</html>

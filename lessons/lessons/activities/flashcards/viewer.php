<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__."/flashcards.json";
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

if(!isset($data[$unit]) || empty($data[$unit])){
 die("No hay tarjetas");
}

$cards = $data[$unit];
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>
body{
font-family:Arial;
background:#eef6ff;
text-align:center;
padding:40px;
}

.card{
width:260px;
height:320px;
margin:auto;
perspective:1000px;
}

.inner{
position:relative;
width:100%;
height:100%;
transition:transform .6s;
transform-style:preserve-3d;
cursor:pointer;
}

.card.flip .inner{
transform:rotateY(180deg);
}

.front,.back{
position:absolute;
width:100%;
height:100%;
border-radius:18px;
box-shadow:0 6px 18px rgba(0,0,0,.2);
display:flex;
align-items:center;
justify-content:center;
backface-visibility:hidden;
}

.front{
background:white;
border:4px solid #60a5fa;
}

.front img{
max-width:80%;
max-height:80%;
}

.back{
background:#fef3c7;
transform:rotateY(180deg);
font-size:32px;
font-family:'Comic Sans MS';
color:#d97706;
}

button{
margin-top:20px;
padding:12px 22px;
border:none;
border-radius:14px;
background:#2563eb;
color:white;
font-weight:bold;
cursor:pointer;
}

.next{
background:#16a34a;
}
</style>
</head>

<body>

<h2>🧠 Flashcards</h2>

<div class="card" id="card">
<div class="inner">

<div class="front">
<img id="img">
</div>

<div class="back" id="text"></div>

</div>
</div>

<br>

<button onclick="speak()">🔊 Escuchar</button>
<button class="next" onclick="next()">➡ Siguiente</button>

<script>

const cards = <?= json_encode($cards) ?>;
let index = 0;

const card = document.getElementById("card");
const img = document.getElementById("img");
const text = document.getElementById("text");

function load(){
 img.src = cards[index].image;
 text.textContent = cards[index].text;
 card.classList.remove("flip");
}

card.onclick = ()=> card.classList.toggle("flip");

function speak(){
 let msg = new SpeechSynthesisUtterance(cards[index].text);
 msg.lang="en-US";
 msg.rate=.9;
 speechSynthesis.speak(msg);
}

function next(){
 index++;
 if(index>=cards.length) index=0;
 load();
}

load();

</script>

</body>
</html>

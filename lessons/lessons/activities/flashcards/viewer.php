<?php
$unit = $_GET['unit'] ?? null;
if(!$unit) die("Unidad no especificada");

$file = __DIR__."/flashcards.json";

$data = file_exists($file)
 ? json_decode(file_get_contents($file), true)
 : [];

if(!isset($data[$unit]) || empty($data[$unit])){
 die("No hay flashcards para esta unidad");
}

$cards = $data[$unit];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>
body{
 font-family:Arial;
 background:#eef6ff;
 text-align:center;
 padding:30px;
}

h1{
 color:#0b5ed7;
}

.cardWrap{
 perspective:1000px;
 width:240px;
 height:320px;
 margin:40px auto;
}

.card{
 width:100%;
 height:100%;
 position:relative;
 transform-style:preserve-3d;
 transition:transform .6s;
 cursor:pointer;
}

.card.flip{
 transform:rotateY(180deg);
}

.side{
 position:absolute;
 width:100%;
 height:100%;
 border-radius:20px;
 box-shadow:0 6px 14px rgba(0,0,0,.2);
 display:flex;
 align-items:center;
 justify-content:center;
 backface-visibility:hidden;
}

.front{
 background:white;
 border:4px solid #4f46e5;
}

.front img{
 max-width:85%;
 max-height:85%;
}

.back{
 background:linear-gradient(135deg,#4f46e5,#6366f1);
 color:white;
 font-size:28px;
 font-weight:bold;
 transform:rotateY(180deg);
 padding:20px;
}

button{
 background:#0b5ed7;
 color:white;
 border:none;
 padding:10px 18px;
 border-radius:12px;
 margin:6px;
 cursor:pointer;
}

.nextBtn{
 background:#2563eb;
}

.backHub{
 display:inline-block;
 margin-top:25px;
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

<h1>🃏 Flashcards</h1>

<button onclick="speak()">🔊 Listen</button>

<div class="cardWrap">
 <div id="card" class="card" onclick="flip()">
  
  <div class="side front">
   <img id="img">
  </div>

  <div class="side back">
   <div id="text"></div>
  </div>

 </div>
</div>

<button class="nextBtn" onclick="nextCard()">➡ Siguiente</button>

<br>

<a class="backHub" href="../hub/index.php?unit=<?=urlencode($unit)?>">
↩ Volver al Hub
</a>

<script>
const cards = <?=json_encode($cards)?>;

let index = 0;
let flipped = false;

const card = document.getElementById("card");
const img = document.getElementById("img");
const text = document.getElementById("text");

function loadCard(){
 flipped = false;
 card.classList.remove("flip");

 const c = cards[index];

 img.src = "../../" + c.image;
 text.textContent = c.text;
}

function flip(){
 flipped = !flipped;
 card.classList.toggle("flip");
}

function nextCard(){
 index++;

 if(index >= cards.length){
  alert("🎉 Terminaste!");
  return;
 }

 loadCard();
}

function speak(){
 const msg = new SpeechSynthesisUtterance(cards[index].text);
 msg.lang = "en-US";
 speechSynthesis.speak(msg);
}

loadCard();
</script>

</body>
</html>

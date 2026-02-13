<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unidad no especificada");

/* ================= DB ================= */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$blocks = json_decode($row["data"] ?? "[]", true);

if(!$blocks || count($blocks)==0){
    die("No hay actividades");
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{
font-family:Arial;
background:#eef6ff;
text-align:center;
padding:30px;
}

.box{
background:white;
max-width:900px;
margin:auto;
padding:30px;
border-radius:18px;
box-shadow:0 4px 14px rgba(0,0,0,.1);
}

h2{color:#0b5ed7;}

.images{
display:flex;
justify-content:center;
flex-wrap:wrap;
gap:12px;
margin-top:20px;
}

.images img{
width:110px;
height:110px;
object-fit:contain;
background:#fff;
border-radius:14px;
padding:10px;
box-shadow:0 2px 6px rgba(0,0,0,.1);
cursor:grab;
}

.drop{
margin-top:25px;
border:2px dashed #0b5ed7;
padding:20px;
border-radius:14px;
min-height:130px;
display:flex;
gap:12px;
justify-content:center;
flex-wrap:wrap;
}

button{
background:#0b5ed7;
color:white;
border:none;
padding:10px 18px;
border-radius:12px;
cursor:pointer;
margin:8px;
}

.green{background:#16a34a;}

.msg{
font-weight:bold;
margin-top:15px;
font-size:18px;
}

.good{color:green;}
.bad{color:red;}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="speak()">🔊 Listen</button>

<div id="images" class="images"></div>

<div id="drop" class="drop"></div>

<br>

<button onclick="check()">✅ Check</button>
<button onclick="next()">➡️ Next</button>

<div id="msg" class="msg"></div>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">↩ Volver al Hub</button>
</a>

</div>

<script>

const blocks = <?= json_encode($blocks) ?>;

let index = 0;
let correctSentence = "";
let correctOrder = [];
let dragged = null;

const imagesDiv = document.getElementById("images");
const dropDiv = document.getElementById("drop");
const msg = document.getElementById("msg");

/* ================= LOAD ================= */

function load(){

msg.innerHTML="";
msg.className="msg";

imagesDiv.innerHTML="";
dropDiv.innerHTML="";

const block = blocks[index];

correctSentence = block.text;
correctOrder = block.images;

/* shuffle images */
let shuffled = [...correctOrder].sort(()=>Math.random()-0.5);

shuffled.forEach(src=>{
const img = document.createElement("img");
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

const built = [...dropDiv.children].map(i=> i.src.split("/activities/")[1]);

let ok = true;

for(let i=0;i<correctOrder.length;i++){
if(!built[i] || !built[i].includes(correctOrder[i])){
ok=false;
break;
}
}

if(ok){
msg.innerHTML="🌟 Excelente!";
msg.className="msg good";
}else{
msg.innerHTML="🔁 Try again";
msg.className="msg bad";
}

}

/* ================= NEXT ================= */

function next(){

index++;

if(index >= blocks.length){
msg.innerHTML="🎉 Completado";
msg.className="msg good";
return;
}

load();
}

/* ================= TTS (MISMO DRAG DROP) ================= */

function speak(){

if(!correctSentence) return;

const msgVoice = new SpeechSynthesisUtterance(correctSentence);
msgVoice.lang = "en-US";
msgVoice.rate = 0.9;
msgVoice.pitch = 1;
msgVoice.volume = 1;

speechSynthesis.speak(msg);

}

/* START */
load();

</script>

</body>
</html>

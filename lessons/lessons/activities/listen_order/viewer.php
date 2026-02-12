<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ======================
LOAD DATA
====================== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]", true);
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
padding:30px;
}

.box{
background:white;
padding:25px;
border-radius:16px;
max-width:900px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
text-align:center;
}

.listenBtn{
background:#0b5ed7;
color:white;
padding:10px 20px;
border-radius:10px;
border:none;
cursor:pointer;
}

.dragArea{
margin-top:20px;
display:flex;
justify-content:center;
flex-wrap:wrap;
gap:10px;
}

.dragImg{
width:120px;
height:120px;
object-fit:contain;
background:#f8f9fa;
border-radius:12px;
padding:6px;
cursor:grab;
}

.dropZone{
margin-top:30px;
min-height:140px;
border:2px dashed #0b5ed7;
border-radius:12px;
padding:20px;
display:flex;
flex-wrap:wrap;
gap:10px;
justify-content:center;
}

.btn{
background:#0b5ed7;
color:white;
padding:10px 16px;
border:none;
border-radius:10px;
margin:10px;
cursor:pointer;
}

.green{
background:#28a745;
}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button class="listenBtn" onclick="speak()">🔊 Listen</button>

<div class="dragArea" id="dragArea"></div>

<div class="dropZone" id="dropZone"></div>

<button class="btn" onclick="check()">✔ Check</button>
<button class="btn" onclick="next()">➡ Next</button>

<br><br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="btn green">← Volver al Hub</button>
</a>

</div>

<script>

let blocks = <?=json_encode($data)?>;
let index = 0;
let correct = [];

function shuffle(arr){
return arr.sort(()=>Math.random()-0.5);
}

function load(){

if(index>=blocks.length){
document.querySelector(".box").innerHTML="<h2>🎉 Completado</h2>";
return;
}

let b = blocks[index];

correct = b.images;

let shuffled = shuffle([...b.images]);

let dragArea = document.getElementById("dragArea");
let dropZone = document.getElementById("dropZone");

dragArea.innerHTML="";
dropZone.innerHTML="";

shuffled.forEach((img,i)=>{

let el=document.createElement("img");
el.src="../../"+img;
el.className="dragImg";
el.draggable=true;

el.ondragstart=e=>{
e.dataTransfer.setData("text",img);
};

dragArea.appendChild(el);

});

dropZone.ondragover=e=>e.preventDefault();

dropZone.ondrop=e=>{
e.preventDefault();

let img=e.dataTransfer.getData("text");

let el=document.createElement("img");
el.src="../../"+img;
el.className="dragImg";

dropZone.appendChild(el);
};

}

function speak(){

let txt = blocks[index].text;

let msg = new SpeechSynthesisUtterance(txt);

msg.lang="en-US";      // ✅ INGLES
msg.rate=0.9;          // ✅ velocidad natural
msg.pitch=1.1;         // ✅ voz un poco más natural

let voices = speechSynthesis.getVoices();
let enVoice = voices.find(v=>v.lang.includes("en"));

if(enVoice) msg.voice=enVoice;

speechSynthesis.speak(msg);

}

function check(){

let imgs = [...document.querySelectorAll("#dropZone img")].map(i=>i.src.split("../../")[1]);

if(JSON.stringify(imgs)===JSON.stringify(correct)){
alert("Excellent!");
}else{
alert("Try again");
}

}

function next(){
index++;
load();
}

load();

</script>

</body>
</html>

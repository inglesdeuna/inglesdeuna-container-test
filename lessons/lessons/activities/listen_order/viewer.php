<?php
require_once __DIR__ . "/../../config/db.php";

$unit=$_GET["unit"]??null;
if(!$unit) die("Unit missing");

/* =====================
LOAD DATA
===================== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$blocks=json_decode($row["data"]??"[]",true);

if(!$blocks || count($blocks)==0){
echo "<h2 style='text-align:center;margin-top:40px'>No activity yet</h2>";
exit;
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
padding:30px;
}

.box{
background:white;
padding:30px;
border-radius:18px;
max-width:900px;
margin:auto;
box-shadow:0 4px 12px rgba(0,0,0,.12);
text-align:center;
}

.imgRow{
display:flex;
gap:20px;
flex-wrap:wrap;
justify-content:center;
margin:25px 0;
}

.imgCard{
background:#f1f4f8;
padding:10px;
border-radius:14px;
cursor:grab;
}

.imgCard img{
height:90px;
}

.drop{
border:2px dashed #2c6bed;
padding:20px;
border-radius:14px;
min-height:120px;
display:flex;
gap:20px;
flex-wrap:wrap;
justify-content:center;
margin-top:20px;
}

button{
background:#2c6bed;
color:white;
border:none;
padding:10px 18px;
border-radius:10px;
cursor:pointer;
margin:6px;
font-size:16px;
}

.green{ background:#2e9d4d; }

.msg{
margin-top:15px;
font-weight:bold;
}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="playTTS()">🔊 Listen</button>

<div id="imgPool" class="imgRow"></div>

<div class="drop" id="dropZone"></div>

<br>

<button onclick="check()">✔ Check</button>
<button onclick="next()">➡ Next</button>

<div id="msg" class="msg"></div>

<br>
<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver al Hub</button>
</a>

</div>

<script>

let blocks = <?=json_encode($blocks)?>;
let index=0;

/* =====================
TTS — MISMA VOZ DRAG DROP
===================== */
function playTTS(){

let txt = blocks[index].sentence || blocks[index].text;

let speech=new SpeechSynthesisUtterance(txt);
speech.lang="en-US";
speech.rate=0.85;
speech.pitch=1;

let voices=speechSynthesis.getVoices();

let enVoice=voices.find(v=>v.lang.includes("en"));

if(enVoice) speech.voice=enVoice;

speechSynthesis.cancel();
speechSynthesis.speak(speech);

}

/* =====================
RENDER
===================== */
function render(){

document.getElementById("msg").innerHTML="";

let pool=document.getElementById("imgPool");
let drop=document.getElementById("dropZone");

pool.innerHTML="";
drop.innerHTML="";

let imgs=[...blocks[index].images];

imgs.sort(()=>Math.random()-0.5);

imgs.forEach((src,i)=>{

let div=document.createElement("div");
div.className="imgCard";
div.draggable=true;
div.dataset.index=i;

div.innerHTML=`<img src="../../${src}">`;

div.ondragstart=e=>{
e.dataTransfer.setData("src",src);
};

pool.appendChild(div);

});

}

/* =====================
DROP
===================== */
let dropZone=document.getElementById("dropZone");

dropZone.ondragover=e=>e.preventDefault();

dropZone.ondrop=e=>{
e.preventDefault();

let src=e.dataTransfer.getData("src");

let div=document.createElement("div");
div.className="imgCard";

div.innerHTML=`<img src="../../${src}">`;

dropZone.appendChild(div);
};

/* =====================
CHECK CORRECT ORDER
===================== */
function check(){

let dropImgs=[...dropZone.querySelectorAll("img")].map(i=>{
return i.src.split("activities/")[1];
});

let correct = blocks[index].images.map(i=>i);

if(JSON.stringify(dropImgs)==JSON.stringify(correct)){

document.getElementById("msg").innerHTML="✨ Excellent!";
document.getElementById("msg").style.color="green";

}else{

document.getElementById("msg").innerHTML="Try again";
document.getElementById("msg").style.color="red";

}

}

/* =====================
NEXT
===================== */
function next(){

index++;

if(index>=blocks.length){
document.querySelector(".box").innerHTML="<h2>🎉 Completado</h2>";
return;
}

render();

}

render();

</script>

</body>
</html>

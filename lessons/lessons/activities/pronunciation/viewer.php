<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ========================
LOAD DATA
======================== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='pronunciation'
");
$stmt->execute(["u"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pronunciation Activity</title>

<style>

body{
font-family:Arial;
background:#eef6ff;
padding:25px;
}

h1{
text-align:center;
color:#0b5ed7;
}

.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
gap:18px;
max-width:1200px;
margin:auto;
}

.card{
background:white;
border-radius:18px;
padding:14px;
text-align:center;
box-shadow:0 6px 14px rgba(0,0,0,0.1);
}

.image{
width:100%;
height:140px;
object-fit:contain;
margin-bottom:8px;
}

.en{
font-size:20px;
font-weight:bold;
}

.ph{
font-size:14px;
color:#666;
}

.es{
font-size:14px;
margin-bottom:8px;
}

button{
margin:4px;
padding:7px 14px;
border:none;
border-radius:10px;
background:#0b5ed7;
color:white;
cursor:pointer;
font-weight:bold;
}

.feedback{
font-weight:bold;
margin-top:6px;
}

.good{ color:green; }
.try{ color:orange; }

.hub{
position:fixed;
top:20px;
right:20px;
background:#28a745;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
font-weight:bold;
}

</style>
</head>

<body>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">
⬅ Volver Hub
</a>

<h1>📘 Pronunciation – Listen & Speak</h1>

<div class="grid" id="cards"></div>

<script>

const data = <?=json_encode($data,JSON_UNESCAPED_UNICODE)?>;

const container=document.getElementById("cards");

/* ========================
RENDER CARDS
======================== */
data.forEach((item,i)=>{

container.innerHTML+=`
<div class="card">

${item.img ? `<img class="image" src="/lessons/lessons/${item.img}">` : ""}

<div class="en">${item.en}</div>
<div class="ph">${item.ph ?? ""}</div>
<div class="es">${item.es ?? ""}</div>

<button onclick="speak('${item.en}')">🔊 Listen</button>
<button onclick="record(${i})">🎤 Speak</button>

<div id="f${i}" class="feedback"></div>

</div>
`;

});

/* ========================
TTS LISTEN
======================== */
function speak(text){
const u=new SpeechSynthesisUtterance(text);
u.lang="en-US";
speechSynthesis.speak(u);
}

/* ========================
SPEECH RECOGNITION
======================== */
let recognition;

if('webkitSpeechRecognition' in window){
recognition=new webkitSpeechRecognition();
recognition.lang="en-US";
recognition.continuous=false;
recognition.interimResults=false;
}

function record(i){

if(!recognition){
alert("Speech recognition not supported");
return;
}

recognition.start();

recognition.onresult=e=>{

const said=e.results[0][0].transcript.toLowerCase();
const correct=data[i].en.toLowerCase();

const fb=document.getElementById("f"+i);

if(said.includes(correct.split(" ")[0])){
fb.innerHTML="🌟 Good job!";
fb.className="feedback good";
}else{
fb.innerHTML="🔁 Try again!";
fb.className="feedback try";
}

};

}

</script>

</body>
</html>

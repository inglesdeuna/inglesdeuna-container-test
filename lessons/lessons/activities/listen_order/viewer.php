<?php
require_once __DIR__."/../../config/db.php";

$type="listen_order";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type=:t
");
$stmt->execute([
"u"=>$unit,
"t"=>$type
]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);
if(!is_array($data)) $data=[];
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
max-width:950px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
text-align:center;
}

.bank, .slots{
display:flex;
flex-wrap:wrap;
justify-content:center;
gap:15px;
margin-top:20px;
min-height:120px;
}

.img{
width:120px;
height:110px;
object-fit:contain;
background:#f8f9fa;
padding:10px;
border-radius:12px;
cursor:grab;
border:3px solid transparent;
}

.slot{
width:120px;
height:110px;
border-radius:12px;
border:3px dashed #0b5ed7;
display:flex;
align-items:center;
justify-content:center;
background:#f1f6ff;
}

.slot img{
width:100%;
height:100%;
object-fit:contain;
}

button{
background:#0b5ed7;
color:white;
border:none;
padding:10px 16px;
border-radius:10px;
cursor:pointer;
margin:6px;
}

.green{background:#28a745;}

.feedback{
font-weight:bold;
margin-top:15px;
}

.good{color:green;}
.bad{color:red;}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="speak()">🔊 Listen</button>

<h3>🖼 Drag Images</h3>
<div id="bank" class="bank"></div>

<h3>📥 Order Here</h3>
<div id="slots" class="slots"></div>

<div>
<button onclick="check()">✅ Check</button>
<button onclick="next()">➡ Next</button>
</div>

<div id="fb" class="feedback"></div>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

<script>

const blocks = <?=json_encode($data)?>;

let current = 0;
let correct = [];
let mix = [];
let slots = [];

function shuffle(arr){
return [...arr].sort(()=>Math.random()-0.5);
}

function load(){

if(!blocks[current]) return;

const b = blocks[current;

correct = [...b.images];
mix = shuffle(b.images);
slots = new Array(correct.length).fill(null);

draw();
document.getElementById("fb").innerHTML="";
}

function draw(){

const bankDiv=document.getElementById("bank");
const slotDiv=document.getElementById("slots");

bankDiv.innerHTML="";
slotDiv.innerHTML="";

mix.forEach(img=>{

const el=document.createElement("img");
el.src="../../"+img;
el.className="img";
el.draggable=true;

el.ondragstart=e=>{
e.dataTransfer.setData("img",img);
};

bankDiv.appendChild(el);

});

slots.forEach((val,i)=>{

const s=document.createElement("div");
s.className="slot";

s.ondragover=e=>e.preventDefault();

s.ondrop=e=>{
e.preventDefault();
const img=e.dataTransfer.getData("img");
slots[i]=img;
renderSlots();
};

slotDiv.appendChild(s);

});

renderSlots();
}

function renderSlots(){

const slotDiv=document.getElementById("slots");

[...slotDiv.children].forEach((slot,i)=>{

slot.innerHTML="";

if(slots[i]){

const img=document.createElement("img");
img.src="../../"+slots[i];
slot.appendChild(img);

}

});

}

function speak(){
const t=blocks[current].text;
const u=new SpeechSynthesisUtterance(t);
u.lang="en-US";
speechSynthesis.speak(u);
}

function check(){

const fb=document.getElementById("fb");

for(let i=0;i<correct.length;i++){
if(slots[i]!==correct[i]){
fb.innerHTML="❌ Try again";
fb.className="feedback bad";
return;
}
}

fb.innerHTML="✅ Correct!";
fb.className="feedback good";
}

function next(){
current++;
load();
}

load();

</script>

</body>
</html>

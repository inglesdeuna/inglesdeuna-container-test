<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:30px;}

.box{
background:white;
padding:25px;
border-radius:16px;
max-width:900px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
text-align:center;
}

.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(120px,1fr));
gap:15px;
margin-top:20px;
}

.img{
width:100%;
height:110px;
object-fit:contain;
background:#f8f9fa;
padding:10px;
border-radius:12px;
cursor:pointer;
border:3px solid transparent;
}

.selected{
border:3px solid #0b5ed7;
background:#dbe9ff;
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
.feedback{font-weight:bold;margin-top:15px;}
.good{color:green;}
.bad{color:red;}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="speak()">🔊 Listen</button>

<div id="grid" class="grid"></div>

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

let current=0;
let correct=[];
let mix=[];
let chosen=[];

function load(){

if(!blocks[current]) return;

correct=[...blocks[current].images];
mix=[...blocks[current].images].sort(()=>Math.random()-0.5);

chosen=[];

draw();
document.getElementById("fb").innerHTML="";
}

function draw(){

const g=document.getElementById("grid");
g.innerHTML="";

mix.forEach(img=>{

const el=document.createElement("img");
el.src="../../"+img;
el.className="img";

el.onclick=()=>{
if(chosen.includes(img)) return;
chosen.push(img);
el.classList.add("selected");
};

g.appendChild(el);

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

if(chosen.length!==correct.length){
fb.innerHTML="Try again";
fb.className="feedback bad";
return;
}

let ok=true;

for(let i=0;i<correct.length;i++){
if(chosen[i]!==correct[i]){
ok=false;
break;
}
}

if(ok){
fb.innerHTML="Correct!";
fb.className="feedback good";
}else{
fb.innerHTML="Try again";
fb.className="feedback bad";
}
}

function next(){
current++;
load();
}

load();

</script>

</body>
</html>

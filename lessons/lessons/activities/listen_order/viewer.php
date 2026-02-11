<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("SELECT data FROM activities WHERE unit_id=? AND type='listen_order'");
$stmt->execute([$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:20px;}
h1{text-align:center;color:#0b5ed7;}

.grid{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:16px;
margin-top:20px;
}

.card{
background:white;
padding:10px;
border-radius:14px;
text-align:center;
cursor:grab;
box-shadow:0 4px 8px rgba(0,0,0,.1);
}

.img{
width:100%;
height:100px;
object-fit:contain;
}

.drop{
margin-top:25px;
padding:20px;
border:2px dashed #0b5ed7;
border-radius:12px;
text-align:center;
background:white;
}

.hub{
position:fixed;
top:20px;
right:20px;
background:#28a745;
color:white;
padding:10px 16px;
border-radius:10px;
text-decoration:none;
}
</style>

</head>

<body>

<h1>🎧 Listen & Order</h1>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">← Hub</a>

<div id="game"></div>

<script>

const activities=<?=json_encode($data)?>;
let current=0;

function speak(text){
const u=new SpeechSynthesisUtterance(text);
u.lang="en-US";
speechSynthesis.speak(u);
}

function loadGame(){

if(!activities[current]){
document.getElementById("game").innerHTML="<h2>🏆 Completed!</h2>";
return;
}

const a=activities[current];

speak(a.sentence);

let shuffled=[...a.parts].sort(()=>Math.random()-0.5);

let html="<div class='grid'>";

shuffled.forEach((p,i)=>{

html+=`
<div class="card"
draggable="true"
ondragstart="drag(event)"
id="drag_${i}"
data-word="${p.text}">
${p.img ? `<img src="../../${p.img}" class="img">` : ""}
<div>${p.text}</div>
</div>
`;

});

html+="</div>";

html+=`
<div class="drop"
ondrop="drop(event)"
ondragover="allowDrop(event)">
Drop words in order here
</div>
`;

document.getElementById("game").innerHTML=html;

}

function allowDrop(e){e.preventDefault();}
function drag(e){e.dataTransfer.setData("text",e.target.id);}

let result=[];

function drop(e){

e.preventDefault();

const id=e.dataTransfer.getData("text");
const el=document.getElementById(id);

result.push(el.dataset.word);

el.style.opacity=.3;

check();

}

function check(){

const correct=activities[current].parts.map(p=>p.text);

if(result.length===correct.length){

if(JSON.stringify(result)===JSON.stringify(correct)){

setTimeout(()=>{
current++;
result=[];
loadGame();
},800);

}else{
alert("Try again!");
result=[];
loadGame();
}

}

}

loadGame();

</script>

</body>
</html>


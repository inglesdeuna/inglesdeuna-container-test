<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit no especificada");

$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data=[];
if($row && $row["data"]){
$data=json_decode($row["data"],true)??[];
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Match Activity</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:20px;}
.container{display:grid;grid-template-columns:1fr 1fr;gap:24px;}
.images, .words{
display:grid;
grid-template-columns: repeat(6, 1fr);
gap:16px;
}
.card{background:white;border-radius:16px;padding:10px;text-align:center;box-shadow:0 4px 8px rgba(0,0,0,0.1);}
.image{width:100%;height:80px;object-fit:contain;cursor:grab;}
.word{
padding:18px;
background:#fff;
border:2px dashed #0b5ed7;
border-radius:14px;
display:flex;
align-items:center;
justify-content:center;
min-height:80px;
font-weight:bold;
}
.correct{background:#d4edda;border-color:green;}
.wrong{background:#f8d7da;border-color:red;}

.top-bar{
display:flex;
justify-content:space-between;
margin-bottom:20px;
}

.hub-btn{
background:#28a745;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
}
</style>
</head>

<body>

<div class="top-bar">
<h2>🧩 Match Activity</h2>
<a class="hub-btn" href="/lessons/lessons/activities/hub/index.php?unit=<?=$unit?>">
⬅ Volver al Hub
</a>
</div>

<div class="container">
<div class="images" id="images"></div>
<div class="words" id="words"></div>
</div>

<script>

const data = <?= json_encode($data) ?>;
const unitId="<?= $unit ?>";

const loseSound=new Audio('/lessons/lessons/activities/hangman/assets/lose.mp3');
const winSound=new Audio('/lessons/lessons/activities/hangman/assets/win.mp3');

let attempts=data.length+4;
let correctCount=0;

const shuffle=arr=>arr.sort(()=>Math.random()-0.5);

const imagesDiv=document.getElementById("images");
const wordsDiv=document.getElementById("words");

shuffle([...data]).forEach(item=>{
imagesDiv.innerHTML+=`
<div class="card">
<img src="/lessons/lessons/${item.image}"
class="image"
draggable="true"
ondragstart="drag(event)"
id="${item.id}">
</div>`;
});

shuffle([...data]).forEach(item=>{
wordsDiv.innerHTML+=`
<div class="word"
data-id="${item.id}"
ondragover="allowDrop(event)"
ondrop="drop(event)">
${item.text}
</div>`;
});

function allowDrop(ev){ev.preventDefault();}
function drag(ev){ev.dataTransfer.setData("text",ev.target.id);}

function drop(ev){
ev.preventDefault();

const draggedId=ev.dataTransfer.getData("text");
const targetId=ev.target.dataset.id;

if(draggedId===targetId){

ev.target.classList.add("correct");
document.getElementById(draggedId).style.opacity="0.3";

correctCount++;

if(correctCount===data.length){
winSound.play();
setTimeout(()=>alert("Great Job!"),500);
}

}else{

attempts--;
loseSound.play();

ev.target.classList.add("wrong");
setTimeout(()=>ev.target.classList.remove("wrong"),800);

if(attempts<=0){
alert("No more attempts");
location.reload();
}
}
}
</script>

</body>
</html>


<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("No unit");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]", true);

if(!$data) die("No data");
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
padding:20px;
text-align:center;
}

.images{
display:flex;
gap:15px;
justify-content:center;
flex-wrap:wrap;
margin:25px 0;
}

.img{
width:140px;
height:140px;
background:white;
border-radius:15px;
box-shadow:0 4px 8px rgba(0,0,0,0.1);
cursor:grab;
display:flex;
align-items:center;
justify-content:center;
}

.img img{
max-width:100%;
max-height:100%;
}

.drop{
border:2px dashed #0b5ed7;
padding:20px;
min-height:160px;
border-radius:15px;
display:flex;
gap:15px;
justify-content:center;
flex-wrap:wrap;
}

button{
padding:10px 18px;
border:none;
border-radius:10px;
background:#0b5ed7;
color:white;
margin:10px;
cursor:pointer;
}

.hub{
background:#28a745;
}
</style>
</head>

<body>

<h1>🎧 Listen & Order</h1>

<button onclick="play()">🔊 Listen</button>

<div class="images" id="pool"></div>

<h3>Your Order</h3>
<div class="drop" id="drop"
ondragover="allow(event)"
ondrop="drop(event)">
</div>

<br>

<button onclick="check()">✔ Check</button>
<button onclick="reset()">🔄 Reset</button>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="hub">← Volver Hub</button>
</a>

<audio id="audio" src="<?= $data["audio"] ?>"></audio>

<script>
const data = <?= json_encode($data) ?>;
const correct = data.order;
const images = data.images;

let placed=[];

function play(){
document.getElementById("audio").play();
}

function allow(e){
e.preventDefault();
}

function drop(e){
e.preventDefault();
let id=e.dataTransfer.getData("id");

if(!placed.includes(id)){
placed.push(id);
renderDrop();
}
}

function drag(e,id){
e.dataTransfer.setData("id",id);
}

function renderPool(){
let html="";
images.forEach(img=>{
if(!placed.includes(img.id)){
html+=`
<div class="img" draggable="true"
ondragstart="drag(event,'${img.id}')">
<img src="${img.src}">
</div>`;
}
});
document.getElementById("pool").innerHTML=html;
}

function renderDrop(){
let html="";
placed.forEach(id=>{
let img=images.find(i=>i.id==id);
html+=`<div class="img"><img src="${img.src}"></div>`;
});
document.getElementById("drop").innerHTML=html;
renderPool();
}

function check(){
if(JSON.stringify(placed)==JSON.stringify(correct)){
alert("⭐ Correct!");
location.reload();
}else{
alert("Try again");
}
}

function reset(){
placed=[];
renderDrop();
}

renderPool();
</script>

</body>
</html>

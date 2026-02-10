<?php
require_once __DIR__ . "/../../config/db.php";

/* =========================
VALIDAR UNIT
========================= */
$unit = $_GET["unit"] ?? null;
if(!$unit){
    die("Unit no especificada");
}

/* =========================
CARGAR DATA DESDE DB
========================= */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
LIMIT 1
");

$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Match Game</title>

<style>
body{
font-family:Arial;
background:#eef6ff;
padding:20px;
}

.container{
display:grid;
grid-template-columns:1fr 1fr;
gap:20px;
}

.images, .words{
display:grid;
grid-template-columns: repeat(auto-fit,minmax(120px,1fr));
gap:16px;
}

.card{
background:white;
padding:10px;
border-radius:12px;
text-align:center;
box-shadow:0 4px 8px rgba(0,0,0,.1);
}

.image{
width:100%;
height:100px;
object-fit:contain;
cursor:grab;
}

.word{
padding:16px;
border:2px dashed #2563eb;
border-radius:12px;
text-align:center;
font-weight:bold;
}

.correct{ background:#d4edda; }
.wrong{ background:#f8d7da; }
</style>
</head>

<body>

<h2>🧩 Match Activity</h2>

<div class="container">
<div id="images" class="images"></div>
<div id="words" class="words"></div>
</div>

<script>

const data = <?= json_encode($data) ?>;

const shuffle = arr => arr.sort(()=>Math.random()-0.5);

const imagesDiv = document.getElementById("images");
const wordsDiv = document.getElementById("words");

shuffle([...data]).forEach(item=>{
imagesDiv.innerHTML += `
<div class="card">
<img src="/lessons/lessons/${item.image}"
class="image"
draggable="true"
ondragstart="drag(event)"
id="${item.id}">
</div>`;
});

shuffle([...data]).forEach(item=>{
wordsDiv.innerHTML += `
<div class="word"
data-id="${item.id}"
ondragover="allowDrop(event)"
ondrop="drop(event)">
${item.text}
</div>`;
});

function allowDrop(e){
e.preventDefault();
}

function drag(e){
e.dataTransfer.setData("text", e.target.id);
}

function drop(e){
e.preventDefault();

const dragId = e.dataTransfer.getData("text");
const targetId = e.target.dataset.id;

if(dragId === targetId){
e.target.classList.add("correct");
e.target.innerHTML += " ✅";
document.getElementById(dragId).style.opacity="0.3";
}else{
e.target.classList.add("wrong");
setTimeout(()=>e.target.classList.remove("wrong"),700);
}
}

</script>

</body>
</html>

<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* =====================
CARGAR DATA
===================== */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

if(!$data) echo "<p>No match data yet</p>";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Match Activity</title>

<style>

body{
font-family: Arial;
background:#eef6ff;
padding:20px;
}

h1{
text-align:center;
color:#0b5ed7;
}

.container{
display:grid;
grid-template-columns:1fr 1fr;
gap:25px;
}

/* GRID */
.images, .words{
display:grid;
grid-template-columns:repeat(6,1fr);
gap:16px;
}

@media(max-width:1200px){
.images,.words{ grid-template-columns:repeat(4,1fr); }
}

@media(max-width:700px){
.images,.words{ grid-template-columns:repeat(2,1fr); }
}

.card{
background:white;
padding:10px;
border-radius:14px;
box-shadow:0 4px 8px rgba(0,0,0,0.1);
}

.image{
width:100%;
height:110px;
object-fit:contain;
cursor:grab;
}

.word{
height:110px;
display:flex;
align-items:center;
justify-content:center;
background:white;
border:2px dashed #0b5ed7;
border-radius:14px;
font-weight:bold;
}

.correct{
background:#d4edda;
border-color:green;
}

.wrong{
background:#f8d7da;
border-color:red;
}

.hub{
position:fixed;
right:20px;
top:20px;
background:#28a745;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
}

</style>
</head>

<body>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">
← Volver al Hub
</a>
  <a class="hub" href="../hub/index.php?unit=<?=$unit?>">
← Volver al Hub
</a>

<h1>🧩 Match Activity</h1>

<div class="container">
<div class="images" id="images"></div>
<div class="words" id="words"></div>
</div>

<script>

const data = <?= json_encode($data) ?>;

const shuffle = arr => arr.sort(()=>Math.random()-0.5);

const imagesDiv = document.getElementById("images");
const wordsDiv = document.getElementById("words");

/* IMAGENES */
shuffle([...data]).forEach(item=>{
imagesDiv.innerHTML += `
<div class="card">
<img src="../../${item.image}" class="image"
draggable="true"
ondragstart="drag(event)"
id="${item.id}">
</div>`;
});

/* TEXTOS */
shuffle([...data]).forEach(item=>{
wordsDiv.innerHTML += `
<div class="word"
data-id="${item.id}"
ondragover="allowDrop(event)"
ondrop="drop(event)">
${item.text}
</div>`;
});

function allowDrop(e){ e.preventDefault(); }

function drag(e){
e.dataTransfer.setData("text", e.target.id);
}

let correct = 0;

function drop(e){
e.preventDefault();

let dragId = e.dataTransfer.getData("text");
let targetId = e.target.dataset.id;

if(dragId === targetId){
e.target.classList.add("correct");
document.getElementById(dragId).style.opacity="0.3";
correct++;
}else{
e.target.classList.add("wrong");
setTimeout(()=>e.target.classList.remove("wrong"),700);
}
}

</script>

</body>
</html>

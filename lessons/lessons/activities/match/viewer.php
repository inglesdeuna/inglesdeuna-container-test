<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit no especificada");

/* =========================
CARGAR DATA
========================= */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = [];
if($row && $row["data"]){
    $data = json_decode($row["data"], true) ?? [];
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Match Activity</title>

<style>

body{
font-family: Arial, sans-serif;
background:#eef6ff;
padding:20px;
}

h1{
text-align:center;
color:#0b5ed7;
margin-bottom:20px;
}

.container{
display:grid;
grid-template-columns:1fr 1fr;
gap:24px;
}

.images, .words{
display:grid;
grid-template-columns: repeat(auto-fit, minmax(120px,1fr));
gap:16px;
}

.card{
background:white;
border-radius:16px;
padding:10px;
text-align:center;
box-shadow:0 4px 8px rgba(0,0,0,0.1);
}

.image{
width:100%;
height:80px;
object-fit:contain;
cursor:grab;
}

.word{
padding:18px;
background:#ffffff;
border:2px dashed #0b5ed7;
border-radius:14px;
font-size:17px;
font-weight:bold;
text-align:center;
display:flex;
align-items:center;
justify-content:center;
min-height:80px;
}

.correct{
background:#d4edda;
border-color:green;
}

.wrong{
background:#f8d7da;
border-color:red;
}

.win-screen{
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,0.7);
display:flex;
flex-direction:column;
align-items:center;
justify-content:center;
color:white;
font-size:28px;
}

.win-screen button{
margin-top:20px;
padding:14px 28px;
background:#28a745;
border:none;
border-radius:12px;
color:white;
font-size:18px;
cursor:pointer;
}

</style>
</head>

<body>

<h1>🧩 Match Activity</h1>

<div class="container">
  <div class="images" id="images"></div>
  <div class="words" id="words"></div>
</div>

<script>

/* =========================
DATA FROM PHP
========================= */
const data = <?= json_encode($data) ?>;
const unitId = "<?= $unit ?>";

/* =========================
SOUNDS (FROM HANGMAN)
========================= */
const loseSound = new Audio('/lessons/lessons/activities/hangman/assets/lose.mp3');
const winSound = new Audio('/lessons/lessons/activities/hangman/assets/win.mp3');

/* =========================
GAME STATE
========================= */
let attempts = data.length + 4;
let correctCount = 0;

/* =========================
HELPERS
========================= */
const shuffle = arr => arr.sort(() => Math.random() - 0.5);

const imagesDiv = document.getElementById("images");
const wordsDiv = document.getElementById("words");

/* =========================
RENDER IMAGES
========================= */
shuffle([...data]).forEach(item=>{
  imagesDiv.innerHTML += `
    <div class="card">
      <img src="/lessons/lessons/${item.image}"
           class="image"
           draggable="true"
           ondragstart="drag(event)"
           id="${item.id}">
    </div>
  `;
});

/* =========================
RENDER WORDS
========================= */
shuffle([...data]).forEach(item=>{
  wordsDiv.innerHTML += `
    <div class="word"
         data-id="${item.id}"
         ondragover="allowDrop(event)"
         ondrop="drop(event)">
      ${item.text}
    </div>
  `;
});

/* =========================
DRAG DROP
========================= */
function allowDrop(ev){
  ev.preventDefault();
}

function drag(ev){
  ev.dataTransfer.setData("text", ev.target.id);
}

function drop(ev){
  ev.preventDefault();

  const draggedId = ev.dataTransfer.getData("text");
  const targetId = ev.target.dataset.id;

  if(draggedId === targetId){

    ev.target.classList.add("correct");
    ev.target.innerHTML += " ✅";

    document.getElementById(draggedId).style.opacity="0.3";

    correctCount++;

    if(correctCount === data.length){
        winSound.play();
        showWin();
    }

  }else{

    attempts--;
    loseSound.play();

    ev.target.classList.add("wrong");
    setTimeout(()=>ev.target.classList.remove("wrong"),800);

    if(attempts <= 0){
        alert("No more attempts");
        location.reload();
    }
  }
}

/* =========================
WIN SCREEN
========================= */
function showWin(){
  document.body.innerHTML += `
  <div class="win-screen">
    <h2>🎉 Great Job!</h2>
    <button onclick="goHub()">Volver al Hub</button>
  </div>`;
}

function goHub(){
  window.location.href = "/lessons/lessons/activities/hub/index.php?unit="+unitId;
}

</script>

</body>
</html>


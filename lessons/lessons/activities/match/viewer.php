<?php
require_once __DIR__ . "/../../config/db.php";

/* =========================
   VALIDAR UNIT
========================= */
$unit = $_GET["unit"] ?? null;

if (!$unit) {
    die("Unit no especificada");
}

/* =========================
   OBTENER DATA MATCH
========================= */

$stmt = $pdo->prepare("
    SELECT content_json
    FROM activities
    WHERE unit_id = :unit
    AND type = 'match'
    LIMIT 1
");

$stmt->execute([
    "unit" => $unit
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = [];

if ($row && !empty($row["content_json"])) {
    $data = json_decode($row["content_json"], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Match</title>

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
  height:100px;
  object-fit:contain;
  cursor:grab;
}

.word{
  padding:18px;
  background:#fff;
  border:2px dashed #0b5ed7;
  border-radius:14px;
  font-size:17px;
  font-weight:bold;
  text-align:center;
}

.correct{
  background:#d4edda;
  border-color:green;
}
.wrong{
  background:#f8d7da;
  border-color:red;
}
</style>
</head>

<body>

<h1>🧩 Match</h1>

<div class="container">
  <div class="images" id="images"></div>
  <div class="words" id="words"></div>
</div>

<script>
const data = <?= json_encode($data ?: []) ?>;

const shuffle = arr => arr.sort(() => Math.random() - 0.5);

const imagesDiv = document.getElementById("images");
const wordsDiv = document.getElementById("words");

shuffle([...data]).forEach(item=>{
  imagesDiv.innerHTML += `
    <div class="card">
      <img src="${item.img}" class="image"
        draggable="true"
        ondragstart="drag(event)"
        id="${item.id}">
    </div>
  `;
});

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
  }else{
    ev.target.classList.add("wrong");
    setTimeout(()=>ev.target.classList.remove("wrong"),800);
  }
}
</script>

</body>
</html>

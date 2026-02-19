<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ================= LOAD ================= */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit AND type = 'match'
");
$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

if(empty($data)){
    die("No pairs for this unit");
}
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
  text-align:center;
  padding:20px;
}

h1{
  color:#0b5ed7;
}

.grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(140px,1fr));
  gap:15px;
  max-width:800px;
  margin:20px auto;
}

.card{
  background:white;
  padding:15px;
  border-radius:12px;
  cursor:pointer;
  box-shadow:0 4px 10px rgba(0,0,0,.1);
}

.card img{
  max-width:100%;
  height:100px;
  object-fit:contain;
}

.card.selected{
  border:3px solid #0b5ed7;
}

#feedback{
  font-weight:bold;
  margin-top:15px;
}

.good{ color:green; }
.bad{ color:crimson; }

.back{
  display:inline-block;
  margin-top:20px;
  background:#16a34a;
  color:white;
  padding:10px 18px;
  border-radius:12px;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🧩 Match</h1>

<div class="grid" id="grid"></div>

<div id="feedback"></div>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
↩ Back
</a>

<script>

const pairs = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;

let selected = null;
let matches = 0;

const grid = document.getElementById("grid");
const feedback = document.getElementById("feedback");

/* ================= BUILD CARDS ================= */

let cards = [];

pairs.forEach(p=>{
  cards.push({type:"text", value:p.text, id:p.id});
  cards.push({type:"image", value:p.image, id:p.id});
});

cards.sort(()=>Math.random()-0.5);

cards.forEach(c=>{
  const div = document.createElement("div");
  div.className="card";
  div.dataset.id=c.id;

  if(c.type==="text"){
    div.textContent=c.value;
  }else{
    const img=document.createElement("img");
    img.src=c.value; // 🔥 Cloudinary URL directa
    div.appendChild(img);
  }

  div.addEventListener("click",()=>selectCard(div,c));
  grid.appendChild(div);
});

/* ================= SELECT ================= */

function selectCard(div,card){

  if(div.classList.contains("matched")) return;

  if(!selected){
    selected = {div,card};
    div.classList.add("selected");
    return;
  }

  if(selected.card.id === card.id &&
     selected.card.type !== card.type){

      selected.div.classList.remove("selected");
      selected.div.classList.add("matched");
      div.classList.add("matched");

      matches++;

      if(matches === pairs.length){
        feedback.textContent="🏆 Completed!";
        feedback.className="good";
      }

  }else{
      feedback.textContent="❌ Try again!";
      feedback.className="bad";
  }

  selected.div.classList.remove("selected");
  selected=null;
}

/* ================= TTS ================= */

function speak(text){

  speechSynthesis.cancel();

  const msg = new SpeechSynthesisUtterance(text);
  msg.lang="en-US";
  msg.rate=0.7;

  speechSynthesis.speak(msg);
}

</script>

</body>
</html>

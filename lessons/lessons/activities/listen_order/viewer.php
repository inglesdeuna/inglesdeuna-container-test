<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* LOAD DATA */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='listen_order'
LIMIT 1
");

$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$items = json_decode($row["data"] ?? "[]", true);
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
}

h1{
text-align:center;
color:#0b5ed7;
}

.wrap{
max-width:900px;
margin:auto;
}

.card{
background:white;
padding:20px;
border-radius:16px;
box-shadow:0 4px 8px rgba(0,0,0,0.1);
margin-bottom:20px;
}

.image{
width:100%;
max-height:200px;
object-fit:contain;
margin-bottom:10px;
}

.words{
display:flex;
flex-wrap:wrap;
gap:10px;
margin-top:10px;
}

.word{
background:white;
border:2px dashed #0b5ed7;
padding:10px 16px;
border-radius:12px;
cursor:pointer;
font-weight:bold;
}

.selected{
background:#d4edda;
border-color:green;
}

.btn{
background:#0b5ed7;
color:white;
border:none;
padding:12px 20px;
border-radius:10px;
cursor:pointer;
}

.next{
background:#28a745;
}

.hub{
position:fixed;
top:20px;
right:20px;
background:#28a745;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
}
</style>
</head>

<body>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">← Hub</a>

<h1>🎧 Listen & Order</h1>

<div class="wrap" id="game"></div>

<script>

const data = <?=json_encode($items)?>;

let index = 0;
let selected = [];

function shuffle(a){
 return a.sort(()=>Math.random()-0.5);
}

function render(){

 selected=[];

 const item = data[index];
 if(!item) return;

 let words = shuffle(item.text.split(" "));

 document.getElementById("game").innerHTML = `
 <div class="card">

 ${item.image ? `<img class="image" src="/${item.image}">` : ""}

 <button class="btn" onclick="playAudio('${item.audio}')">🔊 Listen</button>

 <div class="words" id="words">
 ${words.map(w=>`<div class="word" onclick="pick(this,'${w}')">${w}</div>`).join("")}
 </div>

 <br>
 <button class="btn" onclick="check()">Check</button>

 <div id="msg"></div>

 </div>
 `;
}

function pick(el,w){
 if(el.classList.contains("selected")) return;
 el.classList.add("selected");
 selected.push(w);
}

function check(){

 const correct = data[index].text.trim();
 const user = selected.join(" ").trim();

 if(user === correct){

 document.getElementById("msg").innerHTML =
 "<br><b style='color:green'>Good!</b>";

 setTimeout(()=>{
 index++;
 if(index < data.length){
 render();
 }else{
 document.getElementById("game").innerHTML =
 "<h2>🌟 Finished!</h2>";
 }
 },1000);

 }else{
 document.getElementById("msg").innerHTML =
 "<br><b style='color:red'>Try again</b>";
 selected=[];
 document.querySelectorAll(".word").forEach(w=>w.classList.remove("selected"));
 }
}

function playAudio(src){
 let a = new Audio("/"+src);
 a.play();
}

render();

</script>

</body>
</html>

<?php
require_once __DIR__ . "/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]", true);

if(!$data || !is_array($data)) $data=[];
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
padding:30px;
text-align:center;
}
.box{
background:white;
padding:25px;
border-radius:16px;
max-width:900px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
}
.images{
display:flex;
flex-wrap:wrap;
justify-content:center;
margin-top:20px;
}
.images img{
height:120px;
margin:8px;
cursor:pointer;
border-radius:12px;
}
button{
background:#0b5ed7;
color:white;
border:none;
padding:10px 16px;
border-radius:10px;
cursor:pointer;
margin:5px;
}
.green{ background:#28a745; }
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="listen()">🔊 Escuchar</button>

<div id="imgs" class="images"></div>

<br>

<button onclick="check()">✅ Check</button>
<button onclick="next()">➡ Next</button>

<br><br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

<script>
let blocks = <?=json_encode($data)?>;
let index = 0;
let order = [];

function shuffle(a){
return a.sort(()=>Math.random()-0.5);
}

function render(){

if(index>=blocks.length){
document.querySelector(".box").innerHTML="<h2>🎉 Completado</h2>";
return;
}

let imgs = [...blocks[index].images];
shuffle(imgs);

order=[];

let html="";
imgs.forEach((src,i)=>{
html+=`<img src="../../${src}" onclick="select('${src}',this)">`;
});

document.getElementById("imgs").innerHTML=html;
}

function select(src,el){
order.push(src);
el.style.opacity=0.3;
}

function listen(){
let msg=new SpeechSynthesisUtterance(blocks[index].tts);
speechSynthesis.speak(msg);
}

function check(){

let correct = blocks[index].images.join("|");
let user = order.join("|");

if(correct===user){
alert("✔ Correcto");
}else{
alert("❌ Try again");
}
}

function next(){
index++;
render();
}

render();
</script>

</body>
</html>

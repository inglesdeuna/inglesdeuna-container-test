<?php
$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$file=__DIR__."/listen_order.json";
$data=file_exists($file)
 ? json_decode(file_get_contents($file),true)
 : [];

$list=$data[$unit] ?? [];
if(!$list) die("No hay actividades");
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{font-family:Arial;background:#eef6ff;text-align:center;padding:30px}
.box{background:white;padding:25px;border-radius:15px;max-width:800px;margin:auto}
.images{display:flex;flex-wrap:wrap;gap:15px;justify-content:center}
.images img{height:100px;border-radius:12px;background:white;padding:10px;cursor:grab}
.drop{border:2px dashed #0b5ed7;border-radius:15px;padding:20px;margin-top:20px;min-height:120px;display:flex;gap:15px;justify-content:center;flex-wrap:wrap}
button{padding:10px 20px;border:none;border-radius:12px;background:#0b5ed7;color:white;margin:5px;cursor:pointer}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="play()">🔊 Listen</button>

<div class="images" id="imgs"></div>

<div class="drop" id="drop"></div>

<br>
<button onclick="check()">Check</button>
<button onclick="next()">Next</button>

<p id="msg"></p>

</div>

<script>

const data = <?= json_encode($list) ?>;
let index=0;
let correct=[];
let dragged=null;

const imgs=document.getElementById("imgs");
const drop=document.getElementById("drop");
const msg=document.getElementById("msg");

function load(){

 imgs.innerHTML="";
 drop.innerHTML="";
 msg.textContent="";

 const block=data[index];
 correct=[...block.images];

 let shuffled=[...correct].sort(()=>Math.random()-0.5);

 shuffled.forEach(src=>{
  let img=document.createElement("img");
  img.src="../../"+src;
  img.draggable=true;
  img.dataset.src=src;
  img.ondragstart=()=>dragged=img;
  imgs.appendChild(img);
 });
}

drop.ondragover=e=>e.preventDefault();
drop.ondrop=()=>{ if(dragged) drop.appendChild(dragged); };

function check(){
 let user=[...drop.children].map(i=>i.dataset.src);
 msg.textContent = JSON.stringify(user)==JSON.stringify(correct)
  ? "✔ Correct"
  : "Try again";
}

function next(){
 index++;
 if(index>=data.length){
  msg.textContent="Completed!";
  return;
 }
 load();
}

function play(){
 let a=new Audio("../../"+data[index].audio);
 a.play();
}

load();

</script>

</body>
</html>

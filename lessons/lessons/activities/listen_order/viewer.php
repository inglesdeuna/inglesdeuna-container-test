<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ======================
LOAD DATA
====================== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]", true);
if(!is_array($data)) $data=[];

if(count($data)==0){
    die("No activities created");
}
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
}

.box{
background:white;
padding:25px;
border-radius:16px;
max-width:1100px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
text-align:center;
}

.images{
display:flex;
flex-wrap:wrap;
justify-content:center;
gap:20px;
margin-top:25px;
}

.images img{
height:140px;
border-radius:14px;
cursor:pointer;
border:3px solid transparent;
background:white;
padding:6px;
box-shadow:0 2px 6px rgba(0,0,0,.1);
}

.images img.selected{
border-color:#0b5ed7;
}

.images img.correct{
border-color:#28a745;
}

.images img.wrong{
border-color:#dc3545;
}

button{
background:#0b5ed7;
color:white;
border:none;
padding:12px 20px;
border-radius:12px;
cursor:pointer;
margin:10px;
font-size:15px;
}

.green{ background:#28a745; }

.feedback{
font-size:18px;
font-weight:bold;
margin-top:15px;
}
.good{ color:green; }
.bad{ color:red; }

</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="speak()">🔊 Listen</button>

<div id="images" class="images"></div>

<br>

<button onclick="check()">✅ Check</button>
<button onclick="next()">➡ Next</button>

<div id="fb" class="feedback"></div>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

<script>

const data = <?=json_encode($data)?>;

let index=0;
let selected=[];
let shuffled=[];

function shuffle(a){
  let arr=[...a];
  for(let i=arr.length-1;i>0;i--){
    const j=Math.floor(Math.random()*(i+1));
    [arr[i],arr[j]]=[arr[j],arr[i]];
  }
  return arr;
}

function load(){

  selected=[];
  document.getElementById("fb").innerHTML="";

  const block=data[index];

  shuffled=shuffle(block.images);

  const div=document.getElementById("images");
  div.innerHTML="";

  shuffled.forEach((img,i)=>{
    div.innerHTML+=`
      <img src="../../${img}" onclick="select(this,'${img}')">
    `;
  });

}

function select(el,img){

  if(selected.includes(img)){
    selected=selected.filter(x=>x!=img);
    el.classList.remove("selected");
  }else{
    selected.push(img);
    el.classList.add("selected");
  }

}

function speak(){

  const block=data[index];

  const u=new SpeechSynthesisUtterance(block.text);
  u.lang="en-US";
  speechSynthesis.speak(u);

}

function check(){

  const correct=data[index].images;

  const imgs=document.querySelectorAll(".images img");

  let ok=true;

  imgs.forEach(el=>{
    el.classList.remove("correct","wrong");

    const src=el.getAttribute("src").replace("../../","");

    if(selected.includes(src)){
      if(correct[selected.indexOf(src)]===src){
        el.classList.add("correct");
      }else{
        el.classList.add("wrong");
        ok=false;
      }
    }
  });

  if(selected.length!==correct.length) ok=false;

  const fb=document.getElementById("fb");

  if(ok){
    fb.innerHTML="🌟 Correct!";
    fb.className="feedback good";
  }else{
    fb.innerHTML="Try again";
    fb.className="feedback bad";
  }

}

function next(){

  index++;

  if(index>=data.length){
    alert("Finished!");
    index=0;
  }

  load();
}

load();

</script>

</body>
</html>

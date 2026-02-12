<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"]??die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"]??"[]",true);
if(!is_array($data)) $data=[];
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:30px;text-align:center;}
.box{
background:white;padding:25px;border-radius:16px;
max-width:900px;margin:auto;box-shadow:0 4px 10px rgba(0,0,0,.1);
}
button{
background:#0b5ed7;color:white;border:none;
padding:10px 18px;border-radius:10px;margin:6px;cursor:pointer;
}
.green{background:#28a745;}
.images{display:flex;flex-wrap:wrap;justify-content:center;margin-top:20px;}
.images img{
width:120px;margin:8px;border-radius:12px;cursor:pointer;
}
.order{
border:2px dashed #ccc;
min-height:140px;
margin-top:25px;
padding:15px;
border-radius:12px;
confirm
}
.order img{width:120px;margin:6px;border-radius:10px;}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order</h2>

<button onclick="speak()">🔊 Escuchar</button>

<div class="images" id="pool"></div>

<h3>📥 Orden aquí</h3>
<div class="order" id="order"></div>

<br>

<button onclick="check()">✅ Revisar</button>
<button onclick="next()">➡ Siguiente</button>

<br><br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

<script>

let blocks = <?=json_encode($data,JSON_UNESCAPED_UNICODE)?>;
let index=0;
let correct=[];

function shuffle(a){
    return a.sort(()=>Math.random()-0.5);
}

function load(){

    if(index>=blocks.length){
        document.querySelector(".box").innerHTML="<h2>🎉 Completado</h2>";
        return;
    }

    let b=blocks[index];

    correct=[...b.images];

    let mixed=shuffle([...b.images]);

    let pool=document.getElementById("pool");
    let order=document.getElementById("order");

    pool.innerHTML="";
    order.innerHTML="";

    mixed.forEach(src=>{
        let img=document.createElement("img");
        img.src="../../"+src;
        img.onclick=()=>order.appendChild(img);
        pool.appendChild(img);
    });
}

function speak(){
    let t=blocks[index].text;
    let u=new SpeechSynthesisUtterance(t);
    u.lang="en-US";
    speechSynthesis.speak(u);
}

function check(){

    let user=[...document.querySelectorAll("#order img")].map(i=>i.src.split("activities")[1]);

    let ok=JSON.stringify(user)==JSON.stringify(correct);

    if(ok) alert("✅ Correcto");
    else alert("❌ Intenta otra vez");
}

function next(){
    index++;
    load();
}

load();

</script>

</body>
</html>

<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='pronunciation'
");

$stmt->execute(["unit"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pronunciation</title>

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

.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
gap:18px;
}

.card{
background:white;
padding:14px;
border-radius:18px;
text-align:center;
box-shadow:0 4px 10px rgba(0,0,0,.1);
}

.image{
width:100%;
height:130px;
object-fit:contain;
}

.command{
font-size:18px;
font-weight:bold;
}

.phonetic{
font-size:13px;
color:#666;
}

.spanish{
font-size:15px;
font-weight:600;
}

button{
padding:8px 14px;
border:none;
border-radius:10px;
background:#2f6fed;
color:white;
cursor:pointer;
margin:4px;
}

.hub{
position:fixed;
left:20px;
top:20px;
background:#28a745;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
font-weight:bold;
}
</style>
</head>

<body>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">← Volver Hub</a>

<h1>🎧 Pronunciation</h1>

<div class="grid">

<?php foreach($data as $i=>$item): ?>

<div class="card">

<img class="image" src="/lessons/lessons/<?=$item["img"]?>">

<div class="command"><?=$item["en"]?></div>
<div class="phonetic"><?=$item["ph"]?></div>
<div class="spanish"><?=$item["es"]?></div>

<button onclick="speak('<?=$item["en"]?>')">🔊 Listen</button>
<button onclick="record(<?=$i?>)">🎤 Speak</button>

<div id="f<?=$i?>"></div>

</div>

<?php endforeach; ?>

</div>

<script>
function speak(text){
let u=new SpeechSynthesisUtterance(text);
u.lang="en-US";
speechSynthesis.speak(u);
}

let recognition;
if('webkitSpeechRecognition' in window){
recognition=new webkitSpeechRecognition();
recognition.lang="en-US";
}

function record(i){
recognition.start();
recognition.onresult=e=>{
let said=e.results[0][0].transcript.toLowerCase();
let correct=document.querySelectorAll(".command")[i].innerText.toLowerCase();
let fb=document.getElementById("f"+i);

if(said.includes(correct.split(" ")[0])){
fb.innerHTML="🌟 Good!";
fb.style.color="green";
}else{
fb.innerHTML="🔁 Try again";
fb.style.color="orange";
}
};
}
</script>

</body>
</html>

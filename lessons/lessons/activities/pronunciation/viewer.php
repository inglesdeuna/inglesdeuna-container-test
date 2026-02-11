<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"]??null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='pronunciation'
");
$stmt->execute(["unit"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"]??"[]",true);
?>

<style>
body{
font-family:Arial;
background:#eef6ff;
padding:20px;
}

h1{text-align:center;color:#0b5ed7;}

.grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
gap:16px;
}

.card{
background:white;
padding:14px;
border-radius:18px;
box-shadow:0 4px 8px rgba(0,0,0,.1);
text-align:center;
}

.image{
width:100%;
height:130px;
object-fit:contain;
margin-bottom:6px;
}

button{
margin:4px;
padding:7px 12px;
border:none;
border-radius:10px;
background:#0b5ed7;
color:white;
cursor:pointer;
}
</style>

<h1>🎧 Pronunciation</h1>

<a href="../hub/index.php?unit=<?=$unit?>">⬅ Volver Hub</a>

<div class="grid">

<?php foreach($data as $i=>$d): ?>

<div class="card">

<?php if(!empty($d["image"])): ?>
<img class="image" src="/lessons/lessons/<?=$d["image"]?>">
<?php endif; ?>

<div><b><?=$d["en"]?></b></div>
<div><?=$d["ph"]?></div>
<div><?=$d["es"]?></div>

<button onclick="speak('<?=$d["en"]?>')">🔊 Listen</button>
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
document.getElementById("f"+i).innerHTML=
said;
};
}

</script>

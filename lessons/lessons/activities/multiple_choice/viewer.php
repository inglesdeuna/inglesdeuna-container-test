<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='multiple_choice'
");

$stmt->execute(["unit"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Multiple Choice</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:20px;}
h1{text-align:center;color:#0b5ed7;}

.card{
background:white;
padding:20px;
border-radius:16px;
box-shadow:0 4px 10px rgba(0,0,0,.1);
margin-bottom:20px;
}

img{max-width:200px;margin-bottom:10px;}

button{
padding:8px 16px;
border:none;
border-radius:10px;
background:#2f6fed;
color:white;
margin:5px;
cursor:pointer;
}

.hub{
position:fixed;
top:20px;
left:20px;
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

<h1>🧠 Multiple Choice</h1>

<?php foreach($data as $i=>$q): ?>

<div class="card">

<?php if(!empty($q["img"])): ?>
<img src="/lessons/lessons/<?=$q["img"]?>">
<?php endif; ?>

<h3><?=$q["question"]?></h3>

<?php foreach($q["options"] as $k=>$opt): ?>
<button onclick="check(<?=$i?>,<?=$k?>)"><?=$opt?></button>
<?php endforeach; ?>

<div id="f<?=$i?>"></div>

</div>

<?php endforeach; ?>

<script>

const data=<?=json_encode($data)?>;

function speak(t){
let u=new SpeechSynthesisUtterance(t);
u.lang="en-US";
speechSynthesis.speak(u);
}

function check(i,k){
let fb=document.getElementById("f"+i);

if(k===data[i].correct){
fb.innerHTML="✅ Correct";
fb.style.color="green";
speak("Correct");
}else{
fb.innerHTML="❌ Try again";
fb.style.color="red";
speak("Try again");
}
}
</script>

</body>
</html>

<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* =========================
UPLOAD DIR
========================= */
$uploadDir = __DIR__ . "/uploads/".$unit;
if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* =========================
GET EXISTING DATA
========================= */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='pronunciation'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$items = json_decode($row["data"] ?? "[]", true);

/* =========================
SAVE
========================= */
if($_SERVER["REQUEST_METHOD"]==="POST"){

$newItems = json_decode($_POST["items"] ?? "[]", true);

if(!is_array($newItems)) $newItems=[];

/* PROCESS UPLOADS */
if(!empty($_FILES["image"]["name"][0])){

foreach($_FILES["image"]["name"] as $i=>$name){

if(empty($name)) continue;

$tmp = $_FILES["image"]["tmp_name"][$i];
$file = uniqid()."_".basename($name);

move_uploaded_file($tmp,$uploadDir."/".$file);

$newItems[$i]["image"] =
"activities/pronunciation/uploads/".$unit."/".$file;

}

}

$json = json_encode($newItems,JSON_UNESCAPED_UNICODE);

$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(:id,:u,'pronunciation',:d)
ON CONFLICT(unit_id,type)
DO UPDATE SET data=EXCLUDED.data
])->execute([
"id"=>"act_".uniqid(),
"u"=>$unit,
"d"=>$json
]);

header("Location: editor.php?unit=".$unit."&saved=1");
exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pronunciation Editor</title>

<style>

body{
font-family:Arial;
background:#eef6ff;
padding:20px;
}

h2{
text-align:center;
color:#0b5ed7;
}

.card{
background:white;
padding:25px;
border-radius:18px;
box-shadow:0 6px 14px rgba(0,0,0,.1);
max-width:900px;
margin:auto;
}

.rows{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:12px;
margin-bottom:20px;
}

.rows input{
padding:10px;
border-radius:10px;
border:1px solid #ddd;
}

.btn{
padding:10px 18px;
border:none;
border-radius:12px;
background:#0b5ed7;
color:white;
cursor:pointer;
}

.btn-green{
background:#28a745;
}

.saved{
background:#f8fbff;
border-radius:14px;
padding:10px;
margin-bottom:10px;
display:flex;
align-items:center;
gap:14px;
}

.saved img{
width:60px;
height:60px;
object-fit:contain;
background:white;
border-radius:10px;
padding:4px;
}

</style>
</head>

<body>

<h2>🎧 Pronunciation Editor</h2>

<div class="card">

<?php if(isset($_GET["saved"])): ?>
<p style="color:green">✔ Guardado</p>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" onsubmit="prepareSave()">

<div id="rows" class="rows"></div>

<input type="hidden" name="items" id="itemsInput">

<button type="button" class="btn" onclick="addRow()">+ Add</button>
<button class="btn">💾 Guardar Todo</button>

</form>

<br>

<a href="../hub/index.php?unit=<?=$unit?>" class="btn btn-green">⬅ Volver Hub</a>

<hr>

<h3>📦 Guardados</h3>

<?php foreach($items as $it): ?>
<div class="saved">

<?php if(!empty($it["image"])): ?>
<img src="/lessons/lessons/<?=$it["image"]?>">
<?php endif; ?>

<div>
<b><?=$it["en"]?></b><br>
<?=$it["ph"]?><br>
<?=$it["es"]?>
</div>

</div>
<?php endforeach; ?>

</div>

<script>

let rows=[];

function addRow(){
rows.push({en:"",ph:"",es:""});
render();
}

function render(){
const div=document.getElementById("rows");
div.innerHTML="";

rows.forEach((r,i)=>{
div.innerHTML+=`
<input placeholder="English"
onchange="rows[${i}].en=this.value">

<input placeholder="Phonetic"
onchange="rows[${i}].ph=this.value">

<input placeholder="Spanish"
onchange="rows[${i}].es=this.value">

<input type="file" name="image[]">
`;
});
}

function prepareSave(){
document.getElementById("itemsInput").value=
JSON.stringify(rows);
}

</script>

</body>
</html>


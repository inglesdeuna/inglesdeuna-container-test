<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ========================
UPLOAD DIR
======================== */
$uploadDir=__DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

/* ========================
CARGAR EXISTENTE
======================== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='pronunciation'
");
$stmt->execute(["u"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$existing=json_decode($row["data"] ?? "[]",true);

/* ========================
GUARDAR
======================== */
if($_SERVER["REQUEST_METHOD"]==="POST"){

$newItems=[];

if(isset($_POST["en"])){

foreach($_POST["en"] as $i=>$en){

$en=trim($en);
$ph=trim($_POST["ph"][$i] ?? "");
$es=trim($_POST["es"][$i] ?? "");

if($en==="") continue;

$imgPath="";

/* IMAGE UPLOAD */
if(!empty($_FILES["img"]["name"][$i])){

$tmp=$_FILES["img"]["tmp_name"][$i];
$name=uniqid()."_".basename($_FILES["img"]["name"][$i]);

move_uploaded_file($tmp,$uploadDir."/".$name);

$imgPath="activities/pronunciation/uploads/".$unit."/".$name;
}

$newItems[]=[
"id"=>uniqid(),
"en"=>$en,
"ph"=>$ph,
"es"=>$es,
"img"=>$imgPath
];

}

}

/* MERGE CON EXISTENTES */
$final=array_merge($existing,$newItems);

$json=json_encode($final,JSON_UNESCAPED_UNICODE);

/* UPSERT */
$stmt=$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(:id,:u,'pronunciation',:d)
ON CONFLICT (unit_id,type)
DO UPDATE SET data=EXCLUDED.data
");

$stmt->execute([
"id"=>uniqid(),
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
padding:30px;
}

.box{
background:white;
max-width:950px;
margin:auto;
padding:25px;
border-radius:18px;
box-shadow:0 6px 16px rgba(0,0,0,0.1);
}

.row{
display:grid;
grid-template-columns:2fr 1.5fr 2fr 2fr;
gap:10px;
margin-bottom:12px;
}

input{
padding:10px;
border-radius:10px;
border:1px solid #ccc;
}

button{
background:#0b5ed7;
color:white;
border:none;
padding:10px 16px;
border-radius:10px;
cursor:pointer;
font-weight:bold;
}

.hub{
display:inline-block;
background:#28a745;
color:white;
padding:10px 16px;
border-radius:10px;
text-decoration:none;
margin-top:15px;
}

.saved{
background:#f8fbff;
padding:12px;
border-radius:12px;
margin-bottom:10px;
display:flex;
justify-content:space-between;
align-items:center;
}

.delete{
color:red;
font-size:22px;
text-decoration:none;
}

</style>
</head>

<body>

<h2 style="text-align:center;">🎧 Pronunciation Editor</h2>

<div class="box">

<?php if(isset($_GET["saved"])): ?>
<div style="color:green;font-weight:bold;">✔ Guardado</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">

<div id="rows">

<div class="row">
<input name="en[]" placeholder="English">
<input name="ph[]" placeholder="Phonetic">
<input name="es[]" placeholder="Spanish">
<input type="file" name="img[]">
</div>

</div>

<br>

<button type="button" onclick="addRow()">＋ Add</button>
<button>💾 Guardar Todo</button>

</form>

<br>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">
⬅ Volver Hub
</a>

<hr>

<h3>📋 Guardados</h3>

<?php foreach($existing as $i=>$e): ?>
<div class="saved">

<div style="display:flex;gap:10px;align-items:center;">

<?php if(!empty($e["img"])): ?>
<img src="/lessons/lessons/<?=$e["img"]?>" width="60">
<?php endif; ?>

<div>
<b><?=$e["en"]?></b><br>
<?=$e["ph"]?><br>
<?=$e["es"]?>
</div>

</div>

<a class="delete"
href="delete.php?unit=<?=$unit?>&i=<?=$i?>">✖</a>

</div>
<?php endforeach; ?>

</div>

<script>
function addRow(){
document.getElementById("rows").innerHTML+=`
<div class="row">
<input name="en[]" placeholder="English">
<input name="ph[]" placeholder="Phonetic">
<input name="es[]" placeholder="Spanish">
<input type="file" name="img[]">
</div>
`;
}
</script>

</body>
</html>

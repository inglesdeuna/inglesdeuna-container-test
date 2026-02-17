<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ========= UPLOAD DIR ========= */

$uploadDir = __DIR__."/uploads/".$unit;

if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

/* ========= LOAD EXISTING ========= */

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='pronunciation'
");

$stmt->execute(["unit"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);

/* ========= DELETE ========= */

if(isset($_GET["delete"])){

$index=intval($_GET["delete"]);

if(isset($data[$index])){
array_splice($data,$index,1);
}

$json=json_encode($data,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
UPDATE activities
SET data=:json
WHERE unit_id=:unit AND type='pronunciation'
");

$stmt->execute([
"json"=>$json,
"unit"=>$unit
]);

header("Location: editor.php?unit=".$unit);
exit;
}

/* ========= SAVE ========= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

$newData=$data;

if(isset($_POST["en"])){

foreach($_POST["en"] as $i=>$en){

$en=trim($en);
$ph=trim($_POST["ph"][$i] ?? "");
$es=trim($_POST["es"][$i] ?? "");

if($en=="") continue;

$imgPath="";

if(!empty($_FILES["img"]["name"][$i])){

$tmp=$_FILES["img"]["tmp_name"][$i];
$name=uniqid()."_".basename($_FILES["img"]["name"][$i]);

move_uploaded_file($tmp,$uploadDir."/".$name);

$imgPath="activities/pronunciation/uploads/".$unit."/".$name;
}

$newData[]=[
"en"=>$en,
"ph"=>$ph,
"es"=>$es,
"img"=>$imgPath
];

}
}

$json=json_encode($newData,JSON_UNESCAPED_UNICODE);

/* UPSERT */

$stmt=$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(:id,:unit,'pronunciation',:json)
ON CONFLICT (unit_id,type)
DO UPDATE SET data=EXCLUDED.data
");

$stmt->execute([
"id"=>uniqid("act_"),
"unit"=>$unit,
"json"=>$json
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
  .back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    color:white;
    padding:10px 18px;
    border-radius:12px;
    text-decoration:none;
    font-weight:bold;
    display:inline-block;
}

body{
  
font-family:Arial;
background:#eef6ff;
padding:20px;
}

.box{
max-width:900px;
margin:auto;
background:white;
padding:20px;
border-radius:16px;
box-shadow:0 4px 10px rgba(0,0,0,.1);
}

input{
padding:10px;
border-radius:8px;
border:1px solid #ccc;
}

.row{
display:grid;
grid-template-columns:2fr 1fr 2fr 2fr;
gap:10px;
margin-bottom:10px;
}

.btn{
padding:10px 18px;
border:none;
border-radius:10px;
cursor:pointer;
font-weight:bold;
}

.add{ background:#2f6fed; color:white;}
.save{ background:#2f6fed; color:white;}
.hub{ background:#28a745; color:white;}

.savedRow{
display:flex;
align-items:center;
gap:15px;
background:#f7f7f7;
padding:10px;
border-radius:12px;
margin-bottom:8px;
}

.mini{
width:60px;
height:60px;
object-fit:contain;
}

.ph{
font-size:12px;
color:#666;
}

.del{
margin-left:auto;
color:red;
font-size:22px;
text-decoration:none;
}
</style>
</head>

<body>
  <a class="back-btn" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
    ← Volver al Hub
</a>


<div class="box">

<h2>🎧 Pronunciation Editor</h2>

<form method="post" enctype="multipart/form-data">

<div id="rows"></div>

<button type="button" class="btn add" onclick="addRow()">+ Add</button>
<button class="btn save">💾 Guardar Todo</button>

<a href="../hub/index.php?unit=<?=$unit?>" class="btn hub">← Volver Hub</a>

</form>

<hr>

<h3>📦 Guardados</h3>

<?php foreach($data as $i=>$item): ?>

<div class="savedRow">

<img src="/lessons/lessons/<?=$item["img"]?>" class="mini">

<div>
<b><?=$item["en"]?></b><br>
<span class="ph"><?=$item["ph"]?></span><br>
<?=$item["es"]?>
</div>

<a class="del" href="?unit=<?=$unit?>&delete=<?=$i?>">❌</a>

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

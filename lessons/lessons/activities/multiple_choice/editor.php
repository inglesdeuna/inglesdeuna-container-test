<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* UPLOAD DIR */

$uploadDir=__DIR__."/uploads/".$unit;

if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

/* LOAD EXISTING */

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='multiple_choice'
");

$stmt->execute(["unit"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);

/* DELETE */

if(isset($_GET["delete"])){

$i=intval($_GET["delete"]);

if(isset($data[$i])){
array_splice($data,$i,1);
}

$json=json_encode($data,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
UPDATE activities
SET data=:json
WHERE unit_id=:unit AND type='multiple_choice'
");

$stmt->execute([
"json"=>$json,
"unit"=>$unit
]);

header("Location: editor.php?unit=".$unit);
exit;
}

/* SAVE */

if($_SERVER["REQUEST_METHOD"]==="POST"){

$newData=$data;

$q=trim($_POST["question"]);
$o1=trim($_POST["opt1"]);
$o2=trim($_POST["opt2"]);
$o3=trim($_POST["opt3"]);
$c=intval($_POST["correct"]);

if($q!=""){

$img="";

if(!empty($_FILES["img"]["name"])){

$tmp=$_FILES["img"]["tmp_name"];
$name=uniqid()."_".basename($_FILES["img"]["name"]);

move_uploaded_file($tmp,$uploadDir."/".$name);

$img="activities/multiple_choice/uploads/".$unit."/".$name;
}

$newData[]=[
"question"=>$q,
"img"=>$img,
"options"=>[$o1,$o2,$o3],
"correct"=>$c
];
}

$json=json_encode($newData,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(:id,:unit,'multiple_choice',:json)
ON CONFLICT(unit_id,type)
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
<title>Multiple Choice Editor</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:20px;}

.box{
max-width:900px;
margin:auto;
background:white;
padding:20px;
border-radius:16px;
box-shadow:0 4px 10px rgba(0,0,0,.1);
}

input{padding:10px;border-radius:8px;border:1px solid #ccc;width:100%;}

.btn{
padding:10px 18px;
border:none;
border-radius:10px;
cursor:pointer;
font-weight:bold;
margin-top:10px;
}

.save{background:#2f6fed;color:white;}
.hub{background:#28a745;color:white;}

.saved{
background:#f7f7f7;
padding:10px;
border-radius:12px;
margin-top:10px;
display:flex;
align-items:center;
gap:10px;
}

.del{margin-left:auto;color:red;text-decoration:none;font-size:20px;}
.mini{width:60px;height:60px;object-fit:contain;}
</style>
</head>

<body>

<div class="box">

<h2>🧠 Multiple Choice Editor</h2>

<form method="post" enctype="multipart/form-data">

<input name="question" placeholder="Pregunta"><br><br>
<input name="opt1" placeholder="Opción 1"><br><br>
<input name="opt2" placeholder="Opción 2"><br><br>
<input name="opt3" placeholder="Opción 3"><br><br>

Correcta:
<select name="correct">
<option value="0">Opción 1</option>
<option value="1">Opción 2</option>
<option value="2">Opción 3</option>
</select><br><br>

Imagen opcional:
<input type="file" name="img"><br><br>

<button class="btn save">💾 Guardar</button>

<a href="../hub/index.php?unit=<?=$unit?>" class="btn hub">← Volver Hub</a>

</form>

<hr>

<h3>📦 Guardadas</h3>

<?php foreach($data as $i=>$q): ?>
<div class="saved">

<?php if(!empty($q["img"])): ?>
<img src="/lessons/lessons/<?=$q["img"]?>" class="mini">
<?php endif; ?>

<div>
<b><?=$q["question"]?></b><br>
<?=$q["options"][$q["correct"]]?>
</div>

<a class="del" href="?unit=<?=$unit?>&delete=<?=$i?>">❌</a>

</div>
<?php endforeach; ?>

</div>

</body>
</html>

<?php
require_once __DIR__."/../../config/db.php";

$type="listen_order";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ===== UPLOAD DIR ===== */
$uploadDir=__DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

/* ===== LOAD FROM DB ===== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type=:t
");
$stmt->execute([
"u"=>$unit,
"t"=>$type
]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);
if(!is_array($data)) $data=[];

/* ===== ADD BLOCK (ONLY WHEN CLICK ADD) ===== */
if(isset($_POST["add"])){

$text=trim($_POST["text"] ?? "");

if($text!=""){

$imgs=[];

if(isset($_FILES["images"]["name"])){

foreach($_FILES["images"]["name"] as $i=>$name){

if(!$name) continue;

$tmp=$_FILES["images"]["tmp_name"][$i];
$new=uniqid()."_".basename($name);

if(move_uploaded_file($tmp,$uploadDir."/".$new)){

$imgs[]="activities/listen_order/uploads/".$unit."/".$new;

}

}
}

if(count($imgs)>0){

$data[]=[
"text"=>$text,
"images"=>$imgs
];

/* ===== SAVE DB HERE ONLY ===== */
$json=json_encode($data,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(gen_random_uuid(),:u,:t,:d)
ON CONFLICT (unit_id,type)
DO UPDATE SET data=EXCLUDED.data
");

$stmt->execute([
"u"=>$unit,
"t"=>$type,
"d"=>$json
]);

}
}
}

/* ===== DELETE ===== */
if(isset($_GET["delete"])){

$i=(int)$_GET["delete"];

if(isset($data[$i])){

array_splice($data,$i,1);

$json=json_encode($data,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(gen_random_uuid(),:u,:t,:d)
ON CONFLICT (unit_id,type)
DO UPDATE SET data=EXCLUDED.data
");

$stmt->execute([
"u"=>$unit,
"t"=>$type,
"d"=>$json
]);

}
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order Editor</title>

<style>
body{font-family:Arial;background:#eef6ff;padding:30px;}
.box{background:white;padding:25px;border-radius:16px;max-width:900px;margin:auto;box-shadow:0 4px 10px rgba(0,0,0,.1);}
input[type=text]{width:100%;padding:10px;border-radius:8px;border:1px solid #ccc;margin-bottom:10px;}
button{background:#0b5ed7;color:white;border:none;padding:10px 16px;border-radius:10px;cursor:pointer;margin:5px;}
.green{background:#28a745;}
.item{display:flex;justify-content:space-between;align-items:center;background:#f8f9fa;padding:12px;border-radius:12px;margin-bottom:10px;}
.imgs img{height:60px;margin-right:6px;border-radius:8px;}
.delete{color:red;font-size:22px;text-decoration:none;}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order — Editor</h2>

<form method="post" enctype="multipart/form-data">

<input name="text" placeholder="Sentence (TTS automatic)">

Images:
<input type="file" name="images[]" multiple accept="image/*">

<br>
<button name="add">+ Add Block</button>

</form>

<br>

<h3>📦 Blocks</h3>

<?php foreach($data as $i=>$row): ?>
<div class="item">

<div>
<b><?=htmlspecialchars($row["text"])?></b>

<div class="imgs">
<?php foreach($row["images"] as $img): ?>
<img src="../../<?=$img?>">
<?php endforeach; ?>
</div>

</div>

<a class="delete" href="?unit=<?=$unit?>&delete=<?=$i?>">✖</a>

</div>
<?php endforeach; ?>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

</body>
</html>

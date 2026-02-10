<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* UPLOAD DIR */
$uploadDir = __DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

/* ================= SAVE ================= */
if($_SERVER["REQUEST_METHOD"]==="POST"){

$items=[];

/* Cargar existente */
$stmtOld=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmtOld->execute(["unit"=>$unit]);

$rowOld=$stmtOld->fetch(PDO::FETCH_ASSOC);
if($rowOld && $rowOld["data"]){
$items=json_decode($rowOld["data"],true) ?? [];
}

/* Agregar nuevos */
foreach($_POST["text"] as $i=>$text){

if(trim($text)=="") continue;
if(empty($_FILES["image"]["name"][$i])) continue;

$tmp=$_FILES["image"]["tmp_name"][$i];
$name=uniqid()."_".basename($_FILES["image"]["name"][$i]);

move_uploaded_file($tmp,$uploadDir."/".$name);

$items[]=[
"id"=>uniqid(),
"text"=>$text,
"image"=>"activities/match/uploads/".$unit."/".$name
];

}

/* Guardar */
$json=json_encode($items,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
INSERT INTO activities (id,unit_id,type,data)
VALUES (gen_random_uuid(),:unit,'match',:data)
ON CONFLICT (unit_id,type)
DO UPDATE SET data=EXCLUDED.data
");

$stmt->execute([
"unit"=>$unit,
"data"=>$json
]);

}

/* ================= DELETE ================= */
if(isset($_GET["delete"])){

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);

$data=array_filter($data,function($d){
return $d["id"]!=$_GET["delete"];
});

$json=json_encode(array_values($data));

$stmt=$pdo->prepare("
UPDATE activities SET data=:data
WHERE unit_id=:unit AND type='match'
");

$stmt->execute([
"data"=>$json,
"unit"=>$unit
]);

}

/* LOAD */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);
?>

<style>
body{font-family:Arial;background:#eef6ff;padding:20px;}
.card{background:white;padding:20px;border-radius:16px;max-width:700px;}
.row{display:flex;gap:10px;margin-bottom:10px;}
.pair{display:flex;justify-content:space-between;background:#f8f9fa;padding:10px;border-radius:10px;margin-bottom:6px;}
.delete{color:red;text-decoration:none;font-weight:bold;}
button{padding:10px 16px;border:none;border-radius:8px;background:#0b5ed7;color:white;}
.hub{background:#28a745;margin-top:10px;}
</style>

<div class="card">

<h2>🧩 Match Editor</h2>

<form method="post" enctype="multipart/form-data">

<div id="rows"></div>

<button type="button" onclick="addRow()">+ Agregar Par</button>
<button>💾 Guardar</button>

</form>

<h3>Pares Guardados</h3>

<?php foreach($data as $d): ?>
<div class="pair">
<span><?=$d["text"]?></span>
<a class="delete" href="?unit=<?=$unit?>&delete=<?=$d["id"]?>">❌</a>
</div>
<?php endforeach; ?>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="hub">← Volver al Hub</button>
</a>

</div>

<script>
function addRow(){
document.getElementById("rows").innerHTML+=`
<div class="row">
<input name="text[]" placeholder="Texto">
<input type="file" name="image[]">
</div>`;
}
</script>


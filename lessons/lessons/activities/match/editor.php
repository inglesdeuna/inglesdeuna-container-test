<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* =========================
UPLOAD DIR
========================= */
$uploadDir = __DIR__."/uploads/".$unit;

if(!is_dir($uploadDir)){
mkdir($uploadDir,0777,true);
}

/* =========================
GUARDAR
========================= */
if($_SERVER["REQUEST_METHOD"]==="POST"){

$items=[];

/* Cargar existente primero */
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

$imgPath="";

/* Si subieron imagen */
if(!empty($_FILES["image"]["name"][$i])){

$tmp=$_FILES["image"]["tmp_name"][$i];

$name=uniqid()."_".basename($_FILES["image"]["name"][$i]);

move_uploaded_file($tmp,$uploadDir."/".$name);

$imgPath="activities/match/uploads/".$unit."/".$name;

}else{
continue;
}

$items[]=[
"id"=>uniqid(),
"text"=>$text,
"image"=>$imgPath
];

}

/* Guardar JSON */
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

/* Volver al HUB */
header("Location: ../hub/index.php?unit=".$unit);
exit;

}

/* =========================
CARGAR EXISTENTE
========================= */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]",true);
?>

<h2>🧩 Match Editor</h2>

<form method="post" enctype="multipart/form-data">

<div id="rows">

<?php foreach($data as $d): ?>
<div style="display:flex;gap:10px;margin-bottom:10px;">
<input name="text[]" value="<?=htmlspecialchars($d["text"])?>">
<input type="file" name="image[]">
</div>
<?php endforeach; ?>

</div>

<br>

<button type="button" onclick="addRow()">+ Agregar Par</button>
<button>💾 Guardar Match</button>

</form>

<script>
function addRow(){
document.getElementById("rows").innerHTML+=`
<div style="display:flex;gap:10px;margin-bottom:10px;">
<input name="text[]" placeholder="Texto">
<input type="file" name="image[]">
</div>`;
}
</script>

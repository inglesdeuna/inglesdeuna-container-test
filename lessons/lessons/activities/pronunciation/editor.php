<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* =========================
UPLOAD FOLDER SAFE
========================= */

$uploadDir = __DIR__."/uploads/".$unit;

if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* =========================
LOAD EXISTING
========================= */

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='pronunciation'
");
$stmt->execute(["unit"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$items=json_decode($row["data"]??"[]",true);

/* =========================
SAVE
========================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    if(!isset($_POST["items"])) exit;

    $items=json_decode($_POST["items"],true);

    foreach($items as &$it){

        if(isset($_FILES["image"]["name"][$it["temp"]]) &&
           $_FILES["image"]["name"][$it["temp"]]!=""){

            $tmp=$_FILES["image"]["tmp_name"][$it["temp"]];
            $name=uniqid()."_".basename($_FILES["image"]["name"][$it["temp"]]);

            move_uploaded_file($tmp,$uploadDir."/".$name);

            $it["image"]="activities/pronunciation/uploads/".$unit."/".$name;
        }

        unset($it["temp"]);
    }

    $json=json_encode($items,JSON_UNESCAPED_UNICODE);

    $stmt=$pdo->prepare("
    INSERT INTO activities(unit_id,type,data,created_at)
    VALUES(:unit,'pronunciation',:json,NOW())
    ON CONFLICT(unit_id,type)
    DO UPDATE SET data=:json
    ");

    $stmt->execute([
        "unit"=>$unit,
        "json"=>$json
    ]);

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}
?>

<h2>🎧 Pronunciation Editor</h2>

<?php if(isset($_GET["saved"])) echo "✅ Guardado<br><br>"; ?>

<form method="POST" enctype="multipart/form-data" onsubmit="prepareSave()">

<div id="rows"></div>

<input type="hidden" name="items" id="itemsInput">

<br>

<button type="button" onclick="addRow()">+ Add</button>
<button>💾 Guardar Todo</button>

</form>

<hr>

<h3>📦 Guardados</h3>

<?php foreach($items as $it): ?>
<div>
<?php if(!empty($it["image"])): ?>
<img src="/lessons/lessons/<?=$it["image"]?>" height="60">
<?php endif; ?>
<b><?=$it["en"]?></b><br>
<?=$it["ph"]?><br>
<?=$it["es"]?>
</div>
<hr>
<?php endforeach; ?>

<script>

let rows=[];

function addRow(){

let id=Date.now();

rows.push({
temp:id,
en:"",
ph:"",
es:"",
image:""
});

render();
}

function render(){

let html="";

rows.forEach((r,i)=>{

html+=`
<div style="margin-bottom:10px">
<input placeholder="English"
oninput="rows[${i}].en=this.value">

<input placeholder="Phonetic"
oninput="rows[${i}].ph=this.value">

<input placeholder="Spanish"
oninput="rows[${i}].es=this.value">

<input type="file" name="image[${r.temp}]">

</div>
`;

});

document.getElementById("rows").innerHTML=html;
}

function prepareSave(){
document.getElementById("itemsInput").value=JSON.stringify(rows);
}

</script>

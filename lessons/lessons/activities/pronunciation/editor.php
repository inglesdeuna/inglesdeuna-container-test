<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit no especificada");

$uploadDir = __DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* ======================
LOAD EXISTING
====================== */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='pronunciation'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$existing = json_decode($row["data"] ?? "[]", true);

/* ======================
SAVE
====================== */
if($_SERVER["REQUEST_METHOD"]==="POST"){

    $items = $existing ?? [];

    if(isset($_POST["en"]) && is_array($_POST["en"])){

        foreach($_POST["en"] as $i=>$en){

            $en = trim($en);
            $ph = trim($_POST["ph"][$i] ?? "");
            $es = trim($_POST["es"][$i] ?? "");

            if(!$en) continue;

            $imgPath="";

            if(!empty($_FILES["img"]["name"][$i])){

                $tmp = $_FILES["img"]["tmp_name"][$i];
                $name = uniqid()."_".basename($_FILES["img"]["name"][$i]);

                move_uploaded_file($tmp,$uploadDir."/".$name);

                $imgPath="activities/pronunciation/uploads/".$unit."/".$name;
            }

            $items[]=[
                "id"=>uniqid(),
                "en"=>$en,
                "ph"=>$ph,
                "es"=>$es,
                "img"=>$imgPath
            ];
        }
    }

    $json=json_encode($items,JSON_UNESCAPED_UNICODE);

    $stmt=$pdo->prepare("
    INSERT INTO activities(id,unit_id,type,data)
    VALUES(gen_random_uuid(),:u,'pronunciation',:d)
    ON CONFLICT(unit_id,type)
    DO UPDATE SET data=EXCLUDED.data
    ");

    $stmt->execute([
        "u"=>$unit,
        "d"=>$json
    ]);

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}
?>

<h2>🎧 Pronunciation Editor</h2>

<?php if(isset($_GET["saved"])): ?>
<div style="color:green;font-weight:bold;">✔ Guardado</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

<div id="rows">

<div class="row">
<input name="en[]" placeholder="English">
<input name="ph[]" placeholder="Phonetic">
<input name="es[]" placeholder="Spanish">
<input type="file" name="img[]">
</div>

</div>

<br>

<button type="button" onclick="addRow()">➕ Add</button>
<button>💾 Guardar Todo</button>

</form>

<hr>

<h3>📋 Guardados</h3>

<?php foreach($existing as $e): ?>
<div class="saved">
<?php if($e["img"]): ?>
<img src="/lessons/lessons/<?=$e["img"]?>">
<?php endif; ?>
<b><?=$e["en"]?></b> — <?=$e["ph"]?> — <?=$e["es"]?>
</div>
<?php endforeach; ?>

<script>
function addRow(){
document.getElementById("rows").innerHTML+=`
<div class="row">
<input name="en[]" placeholder="English">
<input name="ph[]" placeholder="Phonetic">
<input name="es[]" placeholder="Spanish">
<input type="file" name="img[]">
</div>`;
}
</script>

<style>
.row{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:10px;
margin-bottom:10px;
}

.saved{
background:#fff;
padding:10px;
border-radius:10px;
margin-bottom:10px;
}

.saved img{
width:60px;
display:block;
margin-bottom:5px;
}
</style>

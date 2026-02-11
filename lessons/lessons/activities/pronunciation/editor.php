<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* =====================
UPLOAD DIR
===================== */
$uploadDir = __DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* =====================
SAVE
===================== */
if($_SERVER["REQUEST_METHOD"]==="POST"){

    $items=[];

    if(isset($_POST["en"]) && is_array($_POST["en"])){

        foreach($_POST["en"] as $i=>$en){

            $en=trim($en);
            $ph=trim($_POST["ph"][$i] ?? "");
            $es=trim($_POST["es"][$i] ?? "");

            if($en=="") continue;

            $imgPath="";

            if(isset($_FILES["img"]["name"][$i]) && $_FILES["img"]["name"][$i]!=""){

                $tmp=$_FILES["img"]["tmp_name"][$i];
                $name=uniqid()."_".basename($_FILES["img"]["name"][$i]);

                move_uploaded_file($tmp,$uploadDir."/".$name);

                $imgPath="activities/pronunciation/uploads/".$unit."/".$name;
            }

            $items[]=[
                "en"=>$en,
                "ph"=>$ph,
                "es"=>$es,
                "img"=>$imgPath
            ];
        }
    }

    $json=json_encode($items,JSON_UNESCAPED_UNICODE);

    /* =====================
    INSERT OR UPDATE
    ===================== */
    $stmt=$pdo->prepare("
        INSERT INTO activities(id,unit_id,type,data)
        VALUES(:id,:unit,'pronunciation',:data)

        ON CONFLICT(unit_id,type)
        DO UPDATE SET data=EXCLUDED.data
    ");

    $stmt->execute([
        "id"=>"act_".uniqid(),
        "unit"=>$unit,
        "data"=>$json
    ]);

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}

/* =====================
LOAD EXISTING
===================== */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='pronunciation'
");

$stmt->execute(["unit"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);
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

.card{
background:white;
max-width:900px;
margin:auto;
padding:25px;
border-radius:18px;
box-shadow:0 4px 10px rgba(0,0,0,0.1);
}

.row{
display:grid;
grid-template-columns:2fr 2fr 2fr 2fr auto;
gap:10px;
margin-bottom:10px;
}

input{
padding:10px;
border-radius:8px;
border:1px solid #ccc;
}

button{
padding:10px 16px;
border:none;
border-radius:10px;
background:#0b5ed7;
color:white;
cursor:pointer;
}

.save{
background:#0b5ed7;
}

.hub{
background:#28a745;
text-decoration:none;
padding:10px 18px;
border-radius:10px;
color:white;
display:inline-block;
margin-top:15px;
}

.savedItem{
display:flex;
align-items:center;
gap:15px;
background:#f5f7fb;
padding:12px;
border-radius:10px;
margin-top:8px;
}

.savedItem img{
width:60px;
height:60px;
object-fit:contain;
background:white;
border-radius:8px;
}

.delete{
margin-left:auto;
color:red;
cursor:pointer;
font-size:20px;
}
</style>

</head>
<body>

<div class="card">

<h2>🎧 Pronunciation Editor</h2>

<?php if(isset($_GET["saved"])) echo "<p style='color:green'>✔ Guardado</p>"; ?>

<form method="post" enctype="multipart/form-data">

<div id="rows">

<?php foreach($data as $d): ?>
<div class="row">
<input name="en[]" value="<?=htmlspecialchars($d["en"])?>" placeholder="English">
<input name="ph[]" value="<?=htmlspecialchars($d["ph"])?>" placeholder="Phonetic">
<input name="es[]" value="<?=htmlspecialchars($d["es"])?>" placeholder="Spanish">
<input type="file" name="img[]">
</div>
<?php endforeach; ?>

</div>

<br>

<button type="button" onclick="addRow()">+ Add</button>
<button class="save">💾 Guardar Todo</button>

</form>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">← Volver Hub</a>

<hr>

<h3>📦 Guardados</h3>

<?php foreach($data as $d): ?>
<div class="savedItem">
<?php if(!empty($d["img"])): ?>
<img src="/lessons/lessons/<?=$d["img"]?>">
<?php endif; ?>
<div>
<b><?=$d["en"]?></b><br>
<?=$d["ph"]?><br>
<?=$d["es"]?>
</div>
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
</div>`;
}
</script>

</body>
</html>


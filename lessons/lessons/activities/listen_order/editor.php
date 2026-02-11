<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ================= UPLOAD DIR ================= */

$uploadDir = __DIR__."/uploads/".$unit;

if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* ================= SAVE ================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    $items = [];

    if(isset($_POST["sentence"])){

        foreach($_POST["sentence"] as $i=>$sentence){

            if(trim($sentence)=="") continue;

            $imgPath = "";

            if(!empty($_FILES["image"]["name"][$i])){

                $tmp  = $_FILES["image"]["tmp_name"][$i];
                $name = uniqid()."_".basename($_FILES["image"]["name"][$i]);

                move_uploaded_file($tmp,$uploadDir."/".$name);

                $imgPath = "activities/listen_order/uploads/".$unit."/".$name;
            }

            $items[]=[
                "id"=>uniqid(),
                "sentence"=>$sentence,
                "image"=>$imgPath
            ];
        }
    }

    $json = json_encode($items,JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO activities (id,unit_id,type,data)
        VALUES (:id,:unit,'listen_order',:json)

        ON CONFLICT (unit_id,type)
        DO UPDATE SET data = EXCLUDED.data
    ");

    $stmt->execute([
        "id"=>uniqid(),
        "unit"=>$unit,
        "json"=>$json
    ]);

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}

/* ================= LOAD ================= */

$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='listen_order'
");

$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]",true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order Editor</title>

<style>
body{
font-family:Arial;
background:#eef6ff;
padding:30px;
}

h1{
text-align:center;
color:#0b5ed7;
}

.card{
max-width:900px;
margin:auto;
background:white;
padding:25px;
border-radius:16px;
box-shadow:0 4px 10px rgba(0,0,0,0.1);
}

.row{
display:flex;
gap:10px;
margin-bottom:12px;
}

input[type=text]{
flex:1;
padding:10px;
border-radius:8px;
border:1px solid #ccc;
}

button{
padding:10px 18px;
border:none;
border-radius:10px;
background:#0b5ed7;
color:white;
cursor:pointer;
}

.save{ background:#0b5ed7; }
.add{ background:#198754; }

.hub{
background:#28a745;
text-decoration:none;
color:white;
padding:10px 18px;
border-radius:10px;
display:inline-block;
margin-top:15px;
}
</style>
</head>

<body>

<h1>🎧 Listen & Order — Editor</h1>

<div class="card">

<?php if(isset($_GET["saved"])): ?>
<div style="color:green;margin-bottom:10px;">✔ Guardado</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">

<div id="rows">

<?php if($data): foreach($data as $d): ?>
<div class="row">
<input type="text" name="sentence[]" value="<?=htmlspecialchars($d["sentence"])?>">
<input type="file" name="image[]">
</div>
<?php endforeach; endif; ?>

</div>

<br>

<button type="button" class="add" onclick="addRow()">+ Add</button>
<button class="save">💾 Guardar</button>

</form>

<br>

<a class="hub" href="../hub/index.php?unit=<?=$unit?>">← Volver Hub</a>

</div>

<script>
function addRow(){
document.getElementById("rows").innerHTML += `
<div class="row">
<input type="text" name="sentence[]" placeholder="Correct sentence">
<input type="file" name="image[]">
</div>`;
}
</script>

</body>
</html>

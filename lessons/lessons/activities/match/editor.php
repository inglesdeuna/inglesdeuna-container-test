<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/cloudinary_upload.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ================= UPLOAD DIR ================= */

$uploadDir = __DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* ================= DELETE ================= */

if(isset($_GET["delete"])){

    $stmt=$pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id=:unit AND type='match'
    ");
    $stmt->execute(["unit"=>$unit]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);

    $items=json_decode($row["data"] ?? "[]",true);

    $items=array_filter($items,function($p){
        return $p["id"] != $_GET["delete"];
    });

    $json=json_encode(array_values($items));

    $stmt=$pdo->prepare("
        UPDATE activities SET data=:data
        WHERE unit_id=:unit AND type='match'
    ");
    $stmt->execute([
        "data"=>$json,
        "unit"=>$unit
    ]);

}

/* ================= SAVE ================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    /* Leer existentes */
    $stmtOld=$pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id=:unit AND type='match'
    ");
    $stmtOld->execute(["unit"=>$unit]);
    $rowOld=$stmtOld->fetch(PDO::FETCH_ASSOC);

    $items=[];
    if($rowOld && $rowOld["data"]){
        $items=json_decode($rowOld["data"],true) ?? [];
    }

    /* Agregar nuevos */
    if(isset($_POST["text"]) && is_array($_POST["text"])){

        foreach($_POST["text"] as $i=>$text){

            if(trim($text)=="") continue;
            if(empty($_FILES["image"]["name"][$i])) continue;

            $tmp = $_FILES["image"]["tmp_name"][$i];

$imageUrl = uploadImageToCloudinary($tmp);

if(!$imageUrl) continue;

$items[] = [
    "id" => uniqid(),
    "text" => $text,
    "image" => $imageUrl
];
        }

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

/* ================= LOAD ================= */

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Match Editor</title>

<style>
body{
font-family:Arial;
background:#eef6ff;
padding:20px;
}

.card{
background:white;
padding:20px;
border-radius:16px;
max-width:700px;
}

.row{
display:flex;
gap:10px;
margin-bottom:10px;
}

.pair{
display:flex;
justify-content:space-between;
background:#f8f9fa;
padding:10px;
border-radius:10px;
margin-bottom:6px;
}

.delete{
color:red;
text-decoration:none;
font-weight:bold;
}

button{
padding:10px 16px;
border:none;
border-radius:8px;
background:#0b5ed7;
color:white;
cursor:pointer;
}

.hub{
background:#28a745;
margin-top:10px;
}
</style>
</head>

<body>

<div class="card">

<h2>🧩 Match Editor</h2>

<form method="post" enctype="multipart/form-data">

<div id="rows"></div>

<button type="button" onclick="addRow()">+ Agregar Par</button>
<button>💾 Guardar</button>

</form>

<h3>Pares Guardados</h3>

<?php foreach($data as $p): ?>
<div class="pair">
<span><?=htmlspecialchars($p["text"])?></span>
<a class="delete"
href="?unit=<?=$unit?>&delete=<?=$p["id"]?>"
onclick="return confirm('Delete pair?')">❌</a>
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

</body>
</html>


<?php
require_once __DIR__ . "/../../config/db.php";

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
LOAD EXISTING
========================= */
$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='listen_order'
");
$stmt->execute(["u"=>$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);

$data=json_decode($row["data"] ?? "[]", true);

/* =========================
SAVE
========================= */
if($_SERVER["REQUEST_METHOD"]==="POST"){

    $activities=[];

    if(isset($_FILES["images"]["name"])){

        foreach($_FILES["images"]["name"] as $block=>$imgs){

            $blockImages=[];

            foreach($imgs as $i=>$name){

                if(!$name) continue;

                $tmp=$_FILES["images"]["tmp_name"][$block][$i];
                $new=uniqid()."_".basename($name);

                move_uploaded_file($tmp,$uploadDir."/".$new);

                $blockImages[]=
                "activities/listen_order/uploads/".$unit."/".$new;
            }

            $activities[]=[
                "audio"=>"",
                "images"=>$blockImages
            ];
        }
    }

    /* AUDIO */
    if(isset($_FILES["audio"]["name"])){

        foreach($_FILES["audio"]["name"] as $i=>$name){

            if(!$name) continue;

            $tmp=$_FILES["audio"]["tmp_name"][$i];
            $new=uniqid()."_".basename($name);

            move_uploaded_file($tmp,$uploadDir."/".$new);

            $activities[$i]["audio"]=
            "activities/listen_order/uploads/".$unit."/".$new;
        }
    }

    $json=json_encode($activities,JSON_UNESCAPED_UNICODE);

    $stmt=$pdo->prepare("
    INSERT INTO activities(id,unit_id,type,data)
    VALUES(gen_random_uuid(),:u,'listen_order',:d)
    ON CONFLICT (unit_id,type)
    DO UPDATE SET data=EXCLUDED.data
    ");

    $stmt->execute([
        "u"=>$unit,
        "d"=>$json
    ]);

    header("Location:?unit=".$unit."&saved=1");
    exit;
}
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

.box{
background:white;
padding:25px;
border-radius:16px;
max-width:1000px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
}

.block{
border:1px solid #ddd;
padding:15px;
border-radius:12px;
margin-bottom:15px;
background:#fafafa;
}

button{
background:#0b5ed7;
color:white;
border:none;
padding:10px 16px;
border-radius:10px;
cursor:pointer;
margin:5px;
}

.green{ background:#28a745; }

.remove{
background:red;
}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order — Editor</h2>

<?php if(isset($_GET["saved"])) echo "<p style='color:green'>Guardado</p>"; ?>

<form method="post" enctype="multipart/form-data">

<div id="blocks"></div>

<button type="button" onclick="addBlock()">+ Add</button>
<button>💾 Guardar Todo</button>

</form>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

<script>

let container=document.getElementById("blocks");
let index=0;

function addBlock(){

let div=document.createElement("div");
div.className="block";

div.innerHTML=`
<h4>Actividad</h4>

Audio:
<input type="file" name="audio[]" accept="audio/*">

<br><br>
Images:
<input type="file" name="images[${index}][]" multiple accept="image/*">

<br>
<button type="button" class="remove" onclick="this.parentElement.remove()">X</button>
`;

container.appendChild(div);
index++;
}

</script>

</body>
</html>

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
$data=json_decode($row["data"]??"[]",true);

/* =========================
SAVE
========================= */
if($_SERVER["REQUEST_METHOD"]==="POST"){

    $items=[];

    if(isset($_FILES["images"]["name"])){

        foreach($_FILES["images"]["name"] as $i=>$name){

            if(!$name) continue;

            $tmp=$_FILES["images"]["tmp_name"][$i];
            $new=uniqid()."_".basename($name);

            move_uploaded_file($tmp,$uploadDir."/".$new);

            $items[]=[
                "img"=>"activities/listen_order/uploads/".$unit."/".$new
            ];
        }
    }

    $audioPath=$data["audio"]??"";

    if(!empty($_FILES["audio"]["name"])){

        $tmp=$_FILES["audio"]["tmp_name"];
        $new=uniqid()."_".basename($_FILES["audio"]["name"]);

        move_uploaded_file($tmp,$uploadDir."/".$new);

        $audioPath="activities/listen_order/uploads/".$unit."/".$new;
    }

    $final=[
        "audio"=>$audioPath,
        "items"=>$items
    ];

    $json=json_encode($final,JSON_UNESCAPED_UNICODE);

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
max-width:900px;
margin:auto;
box-shadow:0 4px 10px rgba(0,0,0,.1);
}

input[type=file]{
margin:8px 0;
}

button{
background:#0b5ed7;
color:white;
border:none;
padding:10px 18px;
border-radius:10px;
margin-top:10px;
cursor:pointer;
}

.green{
background:#28a745;
}
</style>
</head>

<body>

<div class="box">

<h2>🎧 Listen & Order — Editor</h2>

<?php if(isset($_GET["saved"])) echo "<p style='color:green'>Saved</p>"; ?>

<form method="post" enctype="multipart/form-data">

<h4>Audio (auto play in viewer)</h4>
<input type="file" name="audio" accept="audio/*">

<h4>Upload Images (order = correct order)</h4>
<input type="file" name="images[]" multiple accept="image/*">

<br>
<button>💾 Save Activity</button>

</form>

<br>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="green">← Volver Hub</button>
</a>

</div>

</body>
</html>

<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ========= LOAD ========= */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'flashcards'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

/* ========= DELETE ========= */

if(isset($_GET["delete"])){

    $i = intval($_GET["delete"]);

    if(isset($data[$i])){
        array_splice($data,$i,1);
    }

    $json = json_encode($data,JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO activities(id,unit_id,type,data)
        VALUES(gen_random_uuid(),:u,'flashcards',:d)
        ON CONFLICT (unit_id,type)
        DO UPDATE SET data = EXCLUDED.data
    ");

    $stmt->execute([
        "u"=>$unit,
        "d"=>$json
    ]);

    header("Location: editor.php?unit=".$unit);
    exit;
}

/* ========= SAVE ========= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    $text = trim($_POST["text"] ?? "");

    if($text != ""){

        $uploadDir = __DIR__."/uploads/".$unit;

        if(!is_dir($uploadDir)){
            mkdir($uploadDir,0777,true);
        }

        $imgPath = "";

        if(!empty($_FILES["image"]["name"])){

            $tmp  = $_FILES["image"]["tmp_name"];
            $name = uniqid()."_".basename($_FILES["image"]["name"]);

            move_uploaded_file($tmp,$uploadDir."/".$name);

            $imgPath = "activities/flashcards/uploads/".$unit."/".$name;
        }

        $data[] = [
            "text"=>$text,
            "image"=>$imgPath
        ];

        $json = json_encode($data,JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO activities(id,unit_id,type,data)
            VALUES(gen_random_uuid(),:u,'flashcards',:d)
            ON CONFLICT (unit_id,type)
            DO UPDATE SET data = EXCLUDED.data
        ");

        $stmt->execute([
            "u"=>$unit,
            "d"=>$json
        ]);
    }

    header("Location: editor.php?unit=".$unit);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flashcards Editor</title>

<style>
body{
    font-family:Arial;
    background:#eef6ff;
    padding:40px;
}

/* CONTENEDOR IGUAL A DRAG & DROP */
.container{
    max-width:700px;
    background:white;
    padding:25px;
    border-radius:16px;
    box-shadow:0 4px 12px rgba(0,0,0,.1);
}

/* TITULO */
h2{
    display:flex;
    align-items:center;
    gap:10px;
}

/* INPUT GRANDE */
.input{
    width:100%;
    padding:12px;
    border-radius:8px;
    border:1px solid #ccc;
    margin-bottom:15px;
}

/* BOTON GUARDAR IGUAL */
.save{
    width:100%;
    padding:12px;
    border:none;
    border-radius:8px;
    background:#2f6fed;
    color:white;
    font-weight:bold;
    cursor:pointer;
}

/* LISTA */
.list{
    margin-top:20px;
}

.item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    background:#f7f7f7;
    padding:12px;
    border-radius:12px;
    margin-bottom:10px;
}

.item img{
    width:50px;
    height:50px;
    object-fit:contain;
    margin-right:10px;
}

.left{
    display:flex;
    align-items:center;
    gap:10px;
}

.delete{
    color:red;
    font-weight:bold;
    text-decoration:none;
}

/* HUB BUTTON IGUAL */
.hub{
    margin-top:20px;
    width:100%;
    padding:12px;
    border:none;
    border-radius:10px;
    background:#28a745;
    color:white;
    font-weight:bold;
    cursor:pointer;
}
</style>
</head>

<body>

<div class="container">

<h2>🧩 Flashcards – Editor</h2>

<form method="post" enctype="multipart/form-data">

<input class="input" name="text" placeholder="Write the word">

<input type="file" name="image" class="input">

<button class="save">💾 Guardar</button>

</form>

<div class="list">

<h3>📚 Flashcards</h3>

<?php if(empty($data)): ?>
<p>No flashcards yet.</p>
<?php endif; ?>

<?php foreach($data as $i=>$item): ?>
<div class="item">

    <div class="left">
        <?php if(!empty($item["image"])): ?>
            <img src="/lessons/lessons/<?=$item["image"]?>">
        <?php endif; ?>
        <strong><?=$item["text"]?></strong>
    </div>

    <a class="delete"
       href="?unit=<?=$unit?>&delete=<?=$i?>"
       onclick="return confirm('Delete flashcard?')">
       ❌
    </a>

</div>
<?php endforeach; ?>

</div>

<a href="../hub/index.php?unit=<?=$unit?>">
<button class="hub">↩ Volver al Hub</button>
</a>

</div>

</body>
</html>

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
<link rel="stylesheet" href="../../assets/css/ui.css">

<style>

/* Layout adjustments specific to flashcards */

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

</style>
</head>

<body>

<div class="box">

<h1 class="title">🃏 Flashcards Editor</h1>

<form method="post" enctype="multipart/form-data">

<input name="text" placeholder="Write the word">

<input type="file" name="image">

<button type="submit" class="primary-btn">💾 Save</button>

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

<button 
class="back-btn"
onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

</div>

</body>
</html>

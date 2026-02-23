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

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

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

        $imgPath = "";

        if(!empty($_FILES["image"]["tmp_name"])){

            $cloud = $_ENV["CLOUDINARY_CLOUD_NAME"];
            $key = $_ENV["CLOUDINARY_API_KEY"];
            $secret = $_ENV["CLOUDINARY_API_SECRET"];

            $timestamp = time();
            $signature = sha1("timestamp=$timestamp$secret");

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/$cloud/image/upload");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                "file" => new CURLFile($_FILES["image"]["tmp_name"]),
                "api_key"=>$key,
                "timestamp"=>$timestamp,
                "signature"=>$signature
            ]);

            $response = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $imgPath = $response["secure_url"] ?? "";
        }

        $data[] = [
            "text"=>$text,
            "image"=>$imgPath
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

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
.list{ margin-top:20px; }

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
<input name="text" placeholder="Write the word" required>
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
            <img src="<?= $item["image"] ?>">
        <?php endif; ?>
        <strong><?= htmlspecialchars($item["text"]) ?></strong>
    </div>

    <a class="delete"
       href="?unit=<?= urlencode($unit) ?>&delete=<?= $i ?>">
       ❌
    </a>
</div>
<?php endforeach; ?>

</div>

<button 
class="back-btn"
onclick="window.location.href='../../academic/unit_view.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

</div>

</body>
</html>

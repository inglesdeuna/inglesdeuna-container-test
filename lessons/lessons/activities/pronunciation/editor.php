<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
   OBTENER ACTIVIDAD
========================= */

$stmt = $pdo->prepare("
    SELECT id, data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'pronunciation'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$row){
    $pdo->prepare("
        INSERT INTO activities (unit_id, type, data)
        VALUES (:unit, 'pronunciation', '[]')
    ")->execute(["unit"=>$unit]);

    $stmt->execute(["unit"=>$unit]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}

$activityId = $row["id"];
$data = json_decode($row["data"] ?? "[]", true);

/* =========================
   ELIMINAR
========================= */

if(isset($_GET["delete"])){
    $index = (int)$_GET["delete"];
    if(isset($data[$index])){
        array_splice($data, $index, 1);

        $pdo->prepare("
            UPDATE activities SET data=:data WHERE id=:id
        ")->execute([
            "data"=>json_encode($data),
            "id"=>$activityId
        ]);
    }
    header("Location: editor.php?unit=".$unit);
    exit;
}

/* =========================
   AGREGAR
========================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    $word = trim($_POST["word"]);
    $phonetic = trim($_POST["phonetic"]);
    $translation = trim($_POST["translation"]);

    $imageUrl = "";

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

        $imageUrl = $response["secure_url"] ?? "";
    }

    $data[] = [
        "word"=>$word,
        "phonetic"=>$phonetic,
        "translation"=>$translation,
        "image"=>$imageUrl
    ];

    $pdo->prepare("
        UPDATE activities SET data=:data WHERE id=:id
    ")->execute([
        "data"=>json_encode($data),
        "id"=>$activityId
    ]);

    header("Location: editor.php?unit=".$unit);
    exit;
}
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
    position:relative;
}

/* BACK BOTON UNIFICADO */
.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    color:white;
    padding:10px 18px;
    border-radius:12px;
    text-decoration:none;
    font-weight:bold;
}

/* CARD */
.card{
    background:white;
    max-width:750px;
    margin:80px auto;
    padding:30px;
    border-radius:20px;
    box-shadow:0 10px 30px rgba(0,0,0,0.05);
}

h2{
    color:#0b5ed7;
    margin-bottom:5px;
}

input{
    width:100%;
    padding:10px;
    margin-bottom:10px;
    border-radius:10px;
    border:1px solid #ccc;
}

button{
    padding:10px 18px;
    border:none;
    border-radius:12px;
    background:#2563eb;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

.item{
    background:#f3f6ff;
    padding:15px;
    border-radius:12px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.item img{
    width:60px;
    border-radius:8px;
}

.delete{
    color:red;
    font-weight:bold;
    text-decoration:none;
    font-size:20px;
}
</style>
</head>
<body>

<a class="back-btn" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
    ↩ Volver al Hub
</a>

<div class="card">

<h2>🎧 Pronunciation Editor</h2>
<p>Add pronunciation words for this unit.</p>

<form method="post" enctype="multipart/form-data">
    <input name="word" placeholder="Word" required>
    <input name="phonetic" placeholder="Phonetic">
    <input name="translation" placeholder="Translation">
    <input type="file" name="image">
    <button>+ Add</button>
</form>

<hr>

<h3>📦 Guardados</h3>

<?php foreach($data as $i=>$item): ?>
<div class="item">
    <div>
        <?php if(!empty($item["image"])): ?>
            <img src="<?= $item["image"] ?>">
        <?php endif; ?>
        <strong><?= htmlspecialchars($item["word"]) ?></strong><br>
        <?= htmlspecialchars($item["phonetic"] ?? "") ?><br>
        <?= htmlspecialchars($item["translation"] ?? "") ?>
    </div>

    <a class="delete"
       href="?unit=<?= urlencode($unit) ?>&delete=<?= $i ?>">
       ✖
    </a>
</div>
<?php endforeach; ?>

</div>
</body>
</html>

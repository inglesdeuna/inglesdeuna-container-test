<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__."/flashcards.json";
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

if(!isset($data[$unit])) $data[$unit]=[];

$uploadDir = __DIR__."/uploads/images/".$unit;
if(!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

/* GUARDAR */
if($_SERVER["REQUEST_METHOD"]==="POST"){

  $text = trim($_POST["text"] ?? "");

  if($text && isset($_FILES["image"]["tmp_name"])){

    $name = uniqid()."_".basename($_FILES["image"]["name"]);
    move_uploaded_file($_FILES["image"]["tmp_name"], $uploadDir."/".$name);

    $data[$unit][] = [
      "text"=>$text,
      "image"=>"uploads/images/".$unit."/".$name
    ];

    file_put_contents($jsonFile, json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  }
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
padding:30px;
}

.box{
background:white;
padding:25px;
border-radius:18px;
max-width:700px;
margin:auto;
box-shadow:0 4px 12px rgba(0,0,0,.1);
}

input,button{
padding:10px;
margin:6px 0;
border-radius:10px;
border:1px solid #ccc;
}

button{
background:#2563eb;
color:white;
border:none;
font-weight:bold;
cursor:pointer;
}

.save{
background:#2563eb;
width:100%;
}

.cardPreview{
display:flex;
align-items:center;
gap:10px;
background:#f3f4f6;
padding:10px;
border-radius:12px;
margin-top:8px;
}

.cardPreview img{
height:60px;
border-radius:10px;
}

.backBtn{
background:#16a34a;
color:white;
padding:12px;
border-radius:12px;
display:block;
text-align:center;
margin-top:15px;
text-decoration:none;
font-weight:bold;
}
</style>
</head>

<body>

<div class="box">

<h2>🧠 Flashcards Editor</h2>

<form method="POST" enctype="multipart/form-data">
<input name="text" placeholder="Texto tarjeta">
<input type="file" name="image" accept="image/*" required>
<button class="save">💾 Guardar</button>
</form>

<h3>Tarjetas</h3>

<?php foreach($data[$unit] as $card): ?>
<div class="cardPreview">
<img src="<?= $card["image"] ?>">
<span><?= htmlspecialchars($card["text"]) ?></span>
</div>
<?php endforeach; ?>

<a class="backBtn" href="../hub/index.php?unit=<?=urlencode($unit)?>">
← Volver al Hub
</a>

</div>
</body>
</html>

<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/listen_order.json";

$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];

if (!isset($data[$unit])) {
    $data[$unit] = [];
}

/* ========= UPLOAD DIR ========= */
$uploadDir = __DIR__ . "/uploads/" . $unit;
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

/* ========= GUARDAR ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $images = [];
    $audioPath = null;

    /* AUDIO */
    if (!empty($_FILES['audio']['name'])) {

        $audioName = time() . "_" . basename($_FILES['audio']['name']);
        move_uploaded_file(
            $_FILES['audio']['tmp_name'],
            $uploadDir . "/" . $audioName
        );

        $audioPath = "activities/listen_order/uploads/$unit/$audioName";
    }

    /* IMAGENES */
    if (!empty($_FILES['images']['name'][0])) {

        foreach ($_FILES['images']['name'] as $i => $name) {

            if (!$name) continue;

            $imgName = time() . "_" . basename($name);

            move_uploaded_file(
                $_FILES['images']['tmp_name'][$i],
                $uploadDir . "/" . $imgName
            );

            $images[] = "activities/listen_order/uploads/$unit/$imgName";
        }
    }

    if ($audioPath && count($images) > 0) {

        $data[$unit][] = [
            "audio" => $audioPath,
            "images" => $images
        ];

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

/* ========= DELETE ========= */
if (isset($_GET['delete'])) {

    $i = (int)$_GET['delete'];

    if (isset($data[$unit][$i])) {
        array_splice($data[$unit], $i, 1);

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order Editor</title>

<style>
body{
    font-family: Arial;
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

h2{
    color:#0b5ed7;
    margin-bottom:20px;
}

label{
    font-weight:bold;
}

input[type=file]{
    margin-top:6px;
    margin-bottom:15px;
}

button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    margin-top:5px;
}

button:hover{
    opacity:.9;
}

.green{
    background:#16a34a;
}

.block{
    display:flex;
    justify-content:space-between;
    align-items:center;
    background:#f8f9fa;
    padding:12px;
    border-radius:12px;
    margin-bottom:10px;
}

.imgs img{
    height:60px;
    margin-right:6px;
    border-radius:8px;
}

.delete{
    color:red;
    font-size:22px;
    text-decoration:none;
    font-weight:bold;
}
</style>

</head>
<body>

<div class="box">

<h2>🎧 Listen & Order — Editor</h2>

<form method="post" enctype="multipart/form-data">

<label>Audio MP3</label><br>
<input type="file" name="audio" accept="audio/mp3,audio/mpeg" required>

<br>

<label>Imágenes</label><br>
<input type="file" name="images[]" multiple accept="image/*" required>

<br>

<button type="submit">💾 Guardar</button>

</form>

<hr>

<h3>📦 Bloques</h3>

<?php foreach($data[$unit] as $i=>$b): ?>

<div class="block">

<div>

<div>🎧 Audio ✓</div>

<div class="imgs">
<?php foreach($b["images"] as $img): ?>
<img src="../../<?= $img ?>">
<?php endforeach; ?>
</div>

</div>

<a class="delete" href="?unit=<?= $unit ?>&delete=<?= $i ?>">✖</a>

</div>

<?php endforeach; ?>

<br>

<a href="../hub/index.php?unit=<?= $unit ?>">
<button class="green">↩ Volver al Hub</button>
</a>

</div>

</body>
</html>

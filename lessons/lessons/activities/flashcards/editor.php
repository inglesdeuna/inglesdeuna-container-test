<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
JSON PATH
========================= */
$jsonFile = __DIR__ . "/flashcards.json";

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, "{}");
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data) $data = [];

if (!isset($data[$unit])) {
    $data[$unit] = [];
}

/* =========================
UPLOAD PATH
========================= */
$uploadDir = __DIR__ . "/uploads/images/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* =========================
GUARDAR FLASHCARD
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $text = trim($_POST["text"] ?? "");

    $imagePath = "";

    if (!empty($_FILES["image"]["name"])) {

        $ext = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
        $newName = "img_" . time() . "_" . rand(100,999) . "." . $ext;

        move_uploaded_file(
            $_FILES["image"]["tmp_name"],
            $uploadDir . $newName
        );

        /* 👇 ESTA ES LA RUTA QUE USA EL VIEWER */
       $imagePath = "activities/flashcards/uploads/images/" . $newName;

    }

    if ($imagePath !== "" && $text !== "") {

        $data[$unit][] = [
            "image" => $imagePath,
            "text" => $text
        ];

        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
    border-radius:16px;
    max-width:900px;
    margin:auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}

input[type=text]{
    width:100%;
    padding:10px;
    margin-bottom:10px;
    border-radius:8px;
    border:1px solid #ccc;
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

.card{
    background:#f8f9fa;
    padding:12px;
    border-radius:12px;
    margin-bottom:10px;
    display:flex;
    align-items:center;
    gap:15px;
}

.card img{
    height:60px;
    border-radius:8px;
}
</style>
</head>

<body>

<div class="box">

<h2>🃏 Flashcards — Editor</h2>

<form method="post" enctype="multipart/form-data">

Texto:
<input name="text" required>

Imagen:
<input type="file" name="image" accept="image/*" required>

<br>
<button>💾 Guardar</button>

</form>

<br>

<h3>📦 Guardadas</h3>

<?php if (!empty($data[$unit])): ?>
    <?php foreach($data[$unit] as $c): ?>
        <div class="card">
            <img src="<?= htmlspecialchars($c["image"]) ?>">
            <b><?= htmlspecialchars($c["text"]) ?></b>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<br>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button class="green">← Volver Hub</button>
</a>

</div>

</body>
</html>

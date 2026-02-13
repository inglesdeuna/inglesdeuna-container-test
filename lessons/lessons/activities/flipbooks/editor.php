<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* JSON */
$jsonFile = __DIR__ . "/flipbooks.json";

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, "{}");
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data) $data = [];

if (!isset($data[$unit])) {
    $data[$unit] = [];
}

/* UPLOAD DIR */
$uploadDir = __DIR__ . "/uploads/pdfs/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* SAVE */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!empty($_FILES["pdf"]["name"])) {

        $ext = strtolower(pathinfo($_FILES["pdf"]["name"], PATHINFO_EXTENSION));

        if ($ext === "pdf") {

            $newName = "flip_" . time() . "_" . rand(100,999) . ".pdf";

            move_uploaded_file(
                $_FILES["pdf"]["tmp_name"],
                $uploadDir . $newName
            );

            $pdfPath = "uploads/pdfs/" . $newName;

            $data[$unit] = [
                "pdf" => $pdfPath
            ];

            file_put_contents(
                $jsonFile,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }
}

$currentPdf = $data[$unit]["pdf"] ?? "";

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flipbook Editor</title>

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
button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:10px 16px;
    border-radius:10px;
    cursor:pointer;
    margin-top:10px;
}
.green{ background:#28a745; }
</style>
</head>

<body>

<div class="box">

<h2>📖 Flipbook — Editor</h2>

<form method="post" enctype="multipart/form-data">

Subir PDF:
<input type="file" name="pdf" accept="application/pdf" required>

<br>
<button>💾 Guardar</button>

</form>

<?php if($currentPdf): ?>
<p>PDF actual guardado ✔</p>
<?php endif; ?>

<br>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button class="green">← Volver Hub</button>
</a>

</div>

</body>
</html>

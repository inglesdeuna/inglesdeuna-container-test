<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
JSON PATH
========================= */
$jsonFile = __DIR__ . "/flipbooks.json";

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
$uploadDir = __DIR__ . "/../../uploads/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* =========================
GUARDAR PDF
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $pdfPath = "";

    if (!empty($_FILES["pdf"]["name"])) {

        $ext = strtolower(pathinfo($_FILES["pdf"]["name"], PATHINFO_EXTENSION));
        $newName = "pdf_" . time() . "_" . rand(100,999) . "." . $ext;

        move_uploaded_file(
            $_FILES["pdf"]["tmp_name"],
            $uploadDir . $newName
        );

        $pdfPath = "uploads/" . $newName;
    }

    if ($pdfPath !== "") {

        $data[$unit] = [
            "pdf" => $pdfPath
        ];

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

$currentPdf = $data[$unit]["pdf"] ?? "";
/* =========================
ELIMINAR PDF
========================= */
if (isset($_GET["delete"])) {

    if (!empty($currentPdf)) {

        $filePath = __DIR__ . "/../../" . $currentPdf;

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        unset($data[$unit]);

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    header("Location: editor.php?unit=" . $unit);
    exit;
}

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
margin:5px;
}

.green{ background:#28a745; }
</style>

</head>
<body>

<div class="box">

<h2>📖 Flipbook — Editor</h2>

<form method="post" enctype="multipart/form-data">

PDF:
<input type="file" name="pdf" accept="application/pdf" required>

<br><br>
<button>💾 Guardar</button>

</form>

<?php if($currentPdf): ?>
<p>
PDF guardado ✔ 
<a href="?unit=<?= $unit ?>&delete=1" 
   style="color:red; font-weight:bold; text-decoration:none; margin-left:10px;">
   ✖
</a>
</p>
<?php endif; ?>

<br>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button class="green">← Volver Hub</button>
</a>

</div>

</body>
</html>

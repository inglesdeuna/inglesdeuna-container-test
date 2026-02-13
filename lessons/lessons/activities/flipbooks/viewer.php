<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/flipbooks.json";

if (!file_exists($jsonFile)) die("No config");

$data = json_decode(file_get_contents($jsonFile), true);

$pdf = $data[$unit]["pdf"] ?? null;

if (!$pdf) die("No flipbook");

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flipbook</title>

<style>
body{
    margin:0;
    font-family:Arial;
    background:#eef6ff;
}

.header{
    text-align:center;
    padding:15px;
}

iframe{
    width:100%;
    height:90vh;
    border:none;
}

.back{
    position:fixed;
    top:20px;
    right:20px;
    background:#28a745;
    color:white;
    padding:10px 18px;
    border-radius:10px;
    text-decoration:none;
}
</style>

</head>

<body>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
← Volver Hub
</a>

<div class="header">
<h2>📖 Flipbook</h2>
</div>

<!-- VISOR PDF TIPO FLIP -->
<iframe src="<?= htmlspecialchars($pdf) ?>"></iframe>

</body>
</html>

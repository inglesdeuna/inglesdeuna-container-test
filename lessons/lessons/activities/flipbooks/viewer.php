<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
JSON PATH
========================= */
$jsonFile = __DIR__ . "/flipbooks.json";

if (!file_exists($jsonFile)) {
    die("No hay flipbooks guardados");
}

$data = json_decode(file_get_contents($jsonFile), true);

$pdf = $data[$unit]["pdf"] ?? "";

if (!$pdf) die("No hay PDF para esta unidad");

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flipbook</title>

<style>
body{
margin:0;
background:#eef6ff;
font-family:Arial;
text-align:center;
}

<iframe src="/lessons/lessons/activities/flipbooks/<?=$currentPdf?>" width="100%" height="600"></iframe>

}

.hub{
position:fixed;
right:20px;
top:20px;
background:#28a745;
color:white;
padding:10px 18px;
border-radius:10px;
text-decoration:none;
font-weight:bold;
}
</style>

</head>
<body>

<a class="hub" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
← Volver Hub
</a>

<iframe src="../../<?= $pdf ?>"></iframe>

</body>
</html>

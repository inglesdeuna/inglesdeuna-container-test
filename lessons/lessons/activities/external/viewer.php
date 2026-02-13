<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/external.json";

if (!file_exists($jsonFile)) die("No config");

$data = json_decode(file_get_contents($jsonFile), true);

$url = $data[$unit]["url"] ?? null;

if (!$url) die("No URL configurada");

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>External</title>

<style>
body{
    font-family:Arial;
    background:#eef6ff;
    padding:30px;
    text-align:center;
}

.box{
    background:white;
    padding:30px;
    border-radius:16px;
    max-width:600px;
    margin:auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}

button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:14px 24px;
    border-radius:12px;
    cursor:pointer;
    font-size:16px;
}

.green{
    background:#28a745;
}
</style>

</head>

<body>

<div class="box">

<h2>🌐 Recurso Externo</h2>

<p>Haz clic para abrir el recurso.</p>

<button onclick="openExternal()">🔗 Abrir Recurso</button>

<br><br>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button class="green">← Volver Hub</button>
</a>

</div>

<script>

function openExternal(){
    window.open("<?= htmlspecialchars($url) ?>", "_blank");
}

</script>

</body>
</html>

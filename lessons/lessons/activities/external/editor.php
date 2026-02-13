<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
JSON PATH
========================= */
$jsonFile = __DIR__ . "/external.json";

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, "{}");
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data) $data = [];

/* =========================
SAVE URL
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $url = trim($_POST["url"] ?? "");

    if ($url !== "") {

        $data[$unit] = [
            "url" => $url
        ];

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        header("Location: ../hub/index.php?unit=" . urlencode($unit));
        exit;
    }
}

$currentUrl = $data[$unit]["url"] ?? "";

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>External Link Editor</title>

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

input{
    width:100%;
    padding:12px;
    border-radius:10px;
    border:1px solid #ccc;
}

button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:12px 20px;
    border-radius:12px;
    cursor:pointer;
    margin-top:10px;
}

.green{ background:#28a745; }
</style>
</head>

<body>

<div class="box">

<h2>🌐 External Link — Editor</h2>

<form method="post">

URL externa:
<input name="url" required
value="<?= htmlspecialchars($currentUrl) ?>"
placeholder="https://">

<button>💾 Guardar</button>

</form>

<br>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button class="green">← Volver Hub</button>
</a>

</div>

</body>
</html>

<?php

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
JSON PATH
========================= */
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
</head>

<body>

<script>

/* Abre en nueva pestaña */
window.open("<?= htmlspecialchars($url) ?>", "_blank");

/* Redirige Hub */
window.location.href = "../hub/index.php?unit=<?= urlencode($unit) ?>";

</script>

</body>
</html>

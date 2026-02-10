<?php
require_once __DIR__ . "/../../config/db.php";

/* =========================
   VALIDAR UNIT
========================= */
$unit = $_GET["unit"] ?? null;

if (!$unit) {
    die("Unit no especificada");
}

/* =========================
   GUARDAR MATCH
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $json = $_POST["json"] ?? "[]";

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data)
        VALUES (:unit, 'match', :json)
        ON CONFLICT (unit_id, type)
DO UPDATE SET content_json = EXCLUDED.content_json
    ");

    $stmt->execute([
        "unit" => $unit,
        "json" => $json
    ]);

    echo "<h2>✅ Match guardado correctamente</h2>";
}

/* =========================
   CARGAR MATCH EXISTENTE
========================= */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'match'
    LIMIT 1
");

$stmt->execute([
    "unit" => $unit
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$existing = $row["data"] ?? "[]";

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Match Editor</title>

<style>
body{
    font-family: Arial;
    background:#eef6ff;
    padding:40px;
}

.box{
    max-width:900px;
    margin:auto;
    background:white;
    padding:30px;
    border-radius:16px;
    box-shadow:0 8px 20px rgba(0,0,0,0.1);
}

textarea{
    width:100%;
    height:350px;
    padding:15px;
    font-family: monospace;
    border-radius:10px;
    border:1px solid #ccc;
}

button{
    margin-top:20px;
    padding:14px 20px;
    border:none;
    background:#0b5ed7;
    color:white;
    font-size:16px;
    border-radius:10px;
    cursor:pointer;
}

button:hover{
    background:#094db5;
}
</style>

</head>
<body>

<div class="box">

<h1>🧩 Match Editor</h1>

<form method="POST">

<textarea name="json">
<?= htmlspecialchars($existing) ?>
</textarea>

<br>

<button type="submit">Guardar Match</button>

</form>

</div>

</body>
</html>

<?php
require_once __DIR__ . "/../../config/db.php";

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =========================
   VALIDAR UNIT
========================= */
$unit = $_GET["unit"] ?? null;
if (!$unit) {
    die("Unidad no especificada");
}

/* =========================
   OBTENER DATOS EXISTENTES
========================= */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'hangman'
");

$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

if (!is_array($data)) {
    $data = [];
}

/* =========================
   GUARDAR PALABRA
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $word = trim($_POST["word"] ?? "");

    if ($word !== "") {

        $data[] = [
            "word" => strtoupper($word)
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($row) {
            // UPDATE
            $update = $pdo->prepare("
                UPDATE activities
                SET data = :data
                WHERE unit_id = :unit
                AND type = 'hangman'
            ");

            $update->execute([
                "data"=>$json,
                "unit"=>$unit
            ]);

        } else {
            // INSERT
            $insert = $pdo->prepare("
                INSERT INTO activities (unit_id, type, data)
                VALUES (:unit, 'hangman', :data)
            ");

            $insert->execute([
                "unit"=>$unit,
                "data"=>$json
            ]);
        }
    }

    header("Location: editor.php?unit=" . urlencode($unit));
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef6ff;
    padding:40px;
}

.back-top{
    position:absolute;
    top:25px;
    left:25px;
    text-decoration:none;
    font-weight:bold;
    color:#16a34a;
}

.box{
    background:white;
    padding:30px;
    border-radius:15px;
    max-width:600px;
    margin:60px auto;
    box-shadow:0 5px 20px rgba(0,0,0,0.08);
}

h2{
    color:#0b5ed7;
    margin-bottom:5px;
}

.subtitle{
    font-size:14px;
    color:#6b7280;
    margin-bottom:20px;
}

input{
    padding:10px;
    width:100%;
    border-radius:8px;
    border:1px solid #d1d5db;
}

button{
    padding:10px 18px;
    border:none;
    border-radius:10px;
    background:#2563eb;
    color:white;
    font-weight:bold;
    cursor:pointer;
    margin-top:10px;
}

button:hover{
    background:#1e40af;
}

.word{
    padding:10px;
    background:#eef2ff;
    margin-top:8px;
    border-radius:8px;
}
</style>
</head>

<body>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
  ↩ Back
</a>

<div class="box">

<h2>🎯 Hangman Editor</h2>
<div class="subtitle">Add words for this unit.</div>

<form method="post">
    <input name="word" placeholder="Enter word" required>
    <button type="submit">Guardar</button>
</form>

<hr>

<h3>Saved words</h3>

<?php if(empty($data)): ?>
<p>No words yet</p>
<?php else: ?>
<?php foreach($data as $w): ?>
<div class="word">
<?= htmlspecialchars($w["word"]) ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>

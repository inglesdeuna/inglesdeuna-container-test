<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
   OBTENER DATOS
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
if (!is_array($data)) $data = [];

/* =========================
   AGREGAR PALABRA
========================= */
if (isset($_POST["add"])) {

    $word = trim($_POST["word"] ?? "");

    if ($word !== "") {
        $data[] = ["word"=>strtoupper($word)];
    }

    saveData($pdo, $unit, $data, $row);
}

/* =========================
   ELIMINAR PALABRA
========================= */
if (isset($_POST["delete"])) {

    $index = intval($_POST["delete"]);

    if (isset($data[$index])) {
        unset($data[$index]);
        $data = array_values($data);
    }

    saveData($pdo, $unit, $data, $row);
}

/* =========================
   FUNCION GUARDAR
========================= */
function saveData($pdo, $unit, $data, $row){

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    if ($row) {
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
        $insert = $pdo->prepare("
            INSERT INTO activities (unit_id, type, data)
            VALUES (:unit, 'hangman', :data)
        ");
        $insert->execute([
            "unit"=>$unit,
            "data"=>$json
        ]);
    }

    header("Location: editor.php?unit=" . urlencode($unit));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef6ff;
    padding:40px;
    text-align:center;
}

.box{
    background:white;
    padding:30px;
    border-radius:15px;
    max-width:600px;
    margin:20px auto;
}

h2{
    color:#0b5ed7;
    margin-bottom:5px;
}

.subtitle{
    color:#444;
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
    border-radius:12px;
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
    background:#eef2ff;
    padding:10px;
    border-radius:8px;
    margin-top:8px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.delete-btn{
    background:#dc2626;
    padding:5px 10px;
    border-radius:8px;
    font-size:12px;
    cursor:pointer;
}

.delete-btn:hover{
    background:#b91c1c;
}

.back-btn{
    display:inline-block;
    margin-top:20px;
    background:#16a34a;
    color:white;
    padding:10px 18px;
    border-radius:12px;
    font-weight:bold;
    text-decoration:none;
}
</style>
</head>

<body>

<div class="box">

<h2>🎯 Hangman Editor</h2>
<p class="subtitle">Add words for this unit.</p>

<form method="post">
    <input name="word" placeholder="Enter word" required>
    <button type="submit" name="add">Guardar</button>
</form>

<hr>

<h3>Saved words</h3>

<?php if(empty($data)): ?>
<p>No words yet</p>
<?php else: ?>
<?php foreach($data as $i=>$w): ?>
<div class="word">
    <?= htmlspecialchars($w["word"]) ?>
    <form method="post" style="margin:0;">
        <input type="hidden" name="delete" value="<?= $i ?>">
        <button type="submit" class="delete-btn">✕</button>
    </form>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

<!-- BOTÓN VERDE REAL -->
<a class="back-btn" href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>">
  ↩ Back
</a>

</body>
</html>

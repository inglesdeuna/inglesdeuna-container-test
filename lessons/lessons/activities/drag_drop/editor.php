<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* GUARDAR */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $sentences = $_POST["sentences"] ?? [];
    $data = [];

    foreach ($sentences as $s) {
        if (trim($s) !== "") {
            $data[] = ["sentence" => trim($s)];
        }
    }

    $json = json_encode($data);

    // Verificar si ya existe
    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = 'drag_drop'
    ");
    $check->execute(["unit"=>$unit]);

    if ($check->fetch()) {
        // UPDATE
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE unit_id = :unit
            AND type = 'drag_drop'
        ");
        $stmt->execute([
            "data"=>$json,
            "unit"=>$unit
        ]);
    } else {
        // INSERT
        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type, data)
            VALUES (:id, :unit, 'drag_drop', :data)
        ");
        $stmt->execute([
            "id"=>md5(random_bytes(16)),
            "unit"=>$unit,
            "data"=>$json
        ]);
    }

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}

/* CARGAR DATOS EXISTENTES */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'drag_drop'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Drag & Drop Editor</title>

<style>
body{
    font-family: Arial;
    background:#eef6ff;
    padding:20px;
}

h1{ color:#0b5ed7; }

.card{
    background:white;
    padding:20px;
    border-radius:15px;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
    margin-bottom:20px;
}

input{
    width:100%;
    padding:10px;
    margin:8px 0;
    border-radius:10px;
    border:1px solid #ccc;
}

button{
    padding:10px 18px;
    border:none;
    border-radius:10px;
    background:#0b5ed7;
    color:white;
    cursor:pointer;
    margin-top:10px;
}

.add{
    background:#16a34a;
}

.success{
    color:green;
    font-weight:bold;
}
</style>
</head>

<body>

<h1>✏ Drag & Drop Editor</h1>

<?php if(isset($_GET["saved"])): ?>
<p class="success">✔ Guardado correctamente</p>
<?php endif; ?>

<div class="card">
<form method="POST">

<div id="sentences">

<?php
if (!empty($data)) {
    foreach ($data as $item) {
        echo '<input type="text" name="sentences[]" value="'.htmlspecialchars($item["sentence"]).'">';
    }
}
?>

</div>

<button type="button" class="add" onclick="addSentence()">+ Add Sentence</button>
<br>
<button type="submit">💾 Save</button>

</form>
</div>

<br><br>

<button 
    type="button" 
    onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'"
    style="
        background:#16a34a;
        padding:10px 18px;
        border:none;
        border-radius:10px;
        color:white;
        cursor:pointer;
        font-weight:bold;
    "
>
↩ Back
</button>


<script>
function addSentence(){
    const div = document.getElementById("sentences");
    const input = document.createElement("input");
    input.type = "text";
    input.name = "sentences[]";
    div.appendChild(input);
}
</script>

</body>
</html>

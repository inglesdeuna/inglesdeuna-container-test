<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit no especificada");

/* =========================
   UPLOAD DIR
========================= */
$uploadDir = __DIR__ . "/uploads/" . $unit;
if(!is_dir($uploadDir)){
    mkdir($uploadDir, 0777, true);
}

/* =========================
   GUARDAR
========================= */
if($_SERVER["REQUEST_METHOD"] === "POST"){

    $items = [];

    foreach($_POST["text"] as $i => $text){

        if(trim($text) == "") continue;

        $imgPath = "";

        if(isset($_FILES["image"]["name"][$i]) &&
           $_FILES["image"]["name"][$i] != ""){

            $tmp = $_FILES["image"]["tmp_name"][$i];
            $name = uniqid()."_".basename($_FILES["image"]["name"][$i]);

            move_uploaded_file($tmp, $uploadDir."/".$name);

            $imgPath = "activities/match/uploads/".$unit."/".$name;
        }

        $items[] = [
            "id" => uniqid(),
            "text" => $text,
            "image" => $imgPath
        ];
    }

    $json = json_encode($items, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
    INSERT INTO activities (unit_id, type, content_json)
    VALUES (:unit,'match',:json)

    ON CONFLICT (unit_id,type)
    DO UPDATE SET content_json = EXCLUDED.content_json
    ");

    $stmt->execute([
        "unit"=>$unit,
        "json"=>$json
    ]);

    header("Location: ../hub/index.php?unit=".$unit);
    exit;
}

/* =========================
   CARGAR EXISTENTE
========================= */
$stmt = $pdo->prepare("
SELECT content_json FROM activities
WHERE unit_id=:unit AND type='match'
");

$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["content_json"] ?? "[]", true);
?>
<h2>🧩 Match Editor</h2>

<form method="post" enctype="multipart/form-data">

<div id="rows">

<?php foreach($data as $d): ?>
<div class="row">
<input type="text" name="text[]" value="<?=htmlspecialchars($d["text"])?>" placeholder="Texto">
<input type="file" name="image[]">
</div>
<?php endforeach; ?>

</div>

<br>

<button type="button" onclick="addRow()">+ Agregar</button>
<button>💾 Guardar</button>

</form>

<script>
function addRow(){
    document.getElementById("rows").innerHTML += `
    <div class="row">
        <input type="text" name="text[]" placeholder="Texto">
        <input type="file" name="image[]">
    </div>`;
}
</script>

<style>
.row{
display:flex;
gap:10px;
margin-bottom:10px;
}
</style>

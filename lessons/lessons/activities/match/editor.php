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

    $pairs = [];

    foreach($_POST["text"] as $i => $text){

        if(trim($text) == "") continue;

        $imgPath = $_POST["existing_image"][$i] ?? "";

        // Si subieron nueva imagen
        if(isset($_FILES["image"]["name"][$i]) &&
           $_FILES["image"]["name"][$i] != ""){

            $tmp = $_FILES["image"]["tmp_name"][$i];
            $name = uniqid()."_".basename($_FILES["image"]["name"][$i]);

            move_uploaded_file($tmp, $uploadDir."/".$name);

            $imgPath = "activities/match/uploads/".$unit."/".$name;
        }

        if(!$imgPath) continue;

        $pairs[] = [
            "id" => uniqid(),
            "text" => $text,
            "image" => $imgPath
        ];
    }

    $json = json_encode($pairs, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
    INSERT INTO activities (unit_id, type, data)
    VALUES (:unit,'match',:json::jsonb)
    ON CONFLICT (unit_id,type)
    DO UPDATE SET data = EXCLUDED.data
    ");

    $stmt->execute([
        "unit"=>$unit,
        "json"=>$json
    ]);

    header("Location: ../../academic/unit_view.php?unit=".$unit);
    exit;
}

/* =========================
CARGAR EXISTENTE
========================= */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = [];
if($row && $row["data"]){
    $data = json_decode($row["data"], true) ?? [];
}
?>

<h2>🧩 Match Editor</h2>

<form method="post" enctype="multipart/form-data">

<div id="pairs">

<?php foreach($data as $p): ?>
<div class="pair">
<img src="/lessons/lessons/<?= $p["image"] ?>" height="60">
<input type="hidden" name="existing_image[]" value="<?= $p["image"] ?>">
<input type="file" name="image[]">
<input type="text" name="text[]" value="<?= htmlspecialchars($p["text"]) ?>">
</div>
<?php endforeach; ?>

</div>

<button type="button" onclick="addPair()">+ Agregar Par</button>
<button>💾 Guardar Match</button>

</form>

<script>
function addPair(){
    document.getElementById("pairs").innerHTML += `
    <div class="pair">
        <input type="hidden" name="existing_image[]" value="">
        <input type="file" name="image[]" required>
        <input type="text" name="text[]" placeholder="Texto" required>
    </div>`;
}
</script>

<style>
.pair{
display:flex;
gap:10px;
margin:10px 0;
align-items:center;
}
</style>


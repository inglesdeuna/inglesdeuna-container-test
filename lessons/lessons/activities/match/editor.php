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
DELETE PAR
========================= */
if(isset($_GET["delete"])){

    $deleteId = $_GET["delete"];

    $stmtOld = $pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id=:unit AND type='match'
    ");
    $stmtOld->execute(["unit"=>$unit]);

    $rowOld = $stmtOld->fetch(PDO::FETCH_ASSOC);

    if($rowOld && $rowOld["data"]){

        $pairs = json_decode($rowOld["data"], true) ?? [];

        $pairs = array_filter($pairs, function($p) use ($deleteId){
            return $p["id"] !== $deleteId;
        });

        $json = json_encode(array_values($pairs), JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :json::jsonb
            WHERE unit_id=:unit AND type='match'
        ");

        $stmt->execute([
            "unit"=>$unit,
            "json"=>$json
        ]);
    }

    header("Location: editor.php?unit=".$unit);
    exit;
}

/* =========================
GUARDAR (SUMANDO)
========================= */
if($_SERVER["REQUEST_METHOD"] == "POST"){

    $stmtOld = $pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id=:unit AND type='match'
    ");
    $stmtOld->execute(["unit"=>$unit]);

    $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

    $pairs = [];

    if($oldRow && $oldRow["data"]){
        $pairs = json_decode($oldRow["data"], true) ?? [];
    }

    if(isset($_POST["text"])){

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

            if(!$imgPath) continue;

            $pairs[] = [
                "id" => uniqid(),
                "text" => $text,
                "image" => $imgPath
            ];
        }
    }

    $json = json_encode($pairs, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO activities (id, unit_id, type, data)
        VALUES (:id, :unit,'match',:json::jsonb)
        ON CONFLICT (unit_id,type)
        DO UPDATE SET data = EXCLUDED.data
    ");

    $stmt->execute([
        "id"=>uniqid("act_"),
        "unit"=>$unit,
        "json"=>$json
    ]);

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}

/* =========================
LOAD DATA
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

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Match Editor</title>

<style>
body{background:#e8f1ff;font-family:Arial;}

.editor-card{
background:white;
max-width:650px;
margin:40px auto;
padding:30px;
border-radius:14px;
box-shadow:0 8px 20px rgba(0,0,0,.1);
}

.pair-row{display:flex;gap:10px;margin-bottom:12px;}

.saved-item{
display:flex;
align-items:center;
gap:10px;
margin:6px 0;
}

.saved-item img{height:40px;border-radius:6px;}

.delete-x{
margin-left:auto;
color:red;
font-weight:bold;
text-decoration:none;
font-size:18px;
}

.btn-add{
background:#ddd;
padding:10px 18px;
border:none;
border-radius:8px;
margin-top:10px;
cursor:pointer;
}

.btn-save{
background:#2f5fe3;
color:white;
padding:12px;
border:none;
border-radius:10px;
width:100%;
margin-top:15px;
font-weight:bold;
}

.btn-back{
display:block;
background:#1f9d4c;
color:white;
text-align:center;
padding:12px;
border-radius:10px;
margin-top:20px;
text-decoration:none;
}
</style>
</head>

<body>

<div class="editor-card">

<h2>🧩 Match – Editor</h2>

<?php if(isset($_GET["saved"])): ?>
<div style="background:#d4edda;padding:10px;border-radius:8px;margin-bottom:10px;">
✅ Guardado
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

<div id="pairs"></div>

<button type="button" class="btn-add" onclick="addPair()">+ Agregar Par</button>
<button class="btn-save">💾 Guardar Match</button>

</form>

<hr>

<h3>📦 Pares guardados</h3>

<?php foreach($data as $p): ?>
<div class="saved-item">
<img src="/lessons/lessons/<?= $p["image"] ?>">
<span><?= htmlspecialchars($p["text"]) ?></span>
<a class="delete-x"
href="editor.php?unit=<?=$unit?>&delete=<?=$p["id"]?>"
onclick="return confirm('Delete this pair?')">❌</a>
</div>
<?php endforeach; ?>

<a class="btn-back" href="../hub/index.php?unit=<?=$unit?>">
↩ Volver al Hub
</a>

</div>

<script>
function addPair(){
document.getElementById("pairs").innerHTML += `
<div class="pair-row">
<input type="file" name="image[]" required>
<input type="text" name="text[]" placeholder="Texto" required>
</div>`;
}
</script>

</body>
</html>


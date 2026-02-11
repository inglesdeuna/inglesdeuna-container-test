<?php
require_once __DIR__."/../config/db.php";

$type = "CHANGE_ACTIVITY_NAME"; // ← CAMBIAR SOLO ESTO

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ===== UPLOAD DIR ===== */
$uploadDir = __DIR__."/../activities/".$type."/uploads/".$unit;
if(!is_dir($uploadDir)){
    mkdir($uploadDir,0777,true);
}

/* ===== LOAD ===== */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type=:t
");
$stmt->execute([
"u"=>$unit,
"t"=>$type
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);
if(!is_array($data)) $data = [];

/* ===== ADD ITEM (CUSTOMIZE JSON HERE) ===== */
if(isset($_POST["add"])){

    // ===== CUSTOM DATA BUILD =====
    // EDIT ONLY THIS BLOCK PER ACTIVITY

    $text = trim($_POST["text"] ?? "");

    if($text != ""){

        $img = "";

        if(!empty($_FILES["image"]["name"])){

            $tmp = $_FILES["image"]["tmp_name"];
            $new = uniqid()."_".basename($_FILES["image"]["name"]);

            move_uploaded_file($tmp, $uploadDir."/".$new);

            $img = "activities/".$type."/uploads/".$unit."/".$new;
        }

        $data[] = [
            "text"=>$text,
            "image"=>$img
        ];
    }
}

/* ===== DELETE ===== */
if(isset($_GET["delete"])){
    $i = (int)$_GET["delete"];
    if(isset($data[$i])){
        array_splice($data,$i,1);
    }
}

/* ===== SAVE DB ===== */
$json = json_encode($data, JSON_UNESCAPED_UNICODE);

$stmt = $pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(gen_random_uuid(),:u,:t,:d)
ON CONFLICT (unit_id,type)
DO UPDATE SET data = EXCLUDED.data
");

$stmt->execute([
"u"=>$unit,
"t"=>$type,
"d"=>$json
]);
?>

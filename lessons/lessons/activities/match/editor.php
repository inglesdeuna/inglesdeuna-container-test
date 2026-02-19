<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/cloudinary_upload.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ================= LOAD ================= */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit AND type = 'match'
");
$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

/* ================= DELETE ================= */

if(isset($_GET["delete"])){

    $id = $_GET["delete"];

    $data = array_filter($data, function($p) use ($id){
        return $p["id"] !== $id;
    });

    $json = json_encode(array_values($data), JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO activities (id,unit_id,type,data)
        VALUES (gen_random_uuid(),:unit,'match',:data)
        ON CONFLICT (unit_id,type)
        DO UPDATE SET data = EXCLUDED.data
    ");

    $stmt->execute([
        "unit"=>$unit,
        "data"=>$json
    ]);

    header("Location: editor.php?unit=".$unit);
    exit;
}

/* ================= SAVE ================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    if(isset($_POST["text"]) && is_array($_POST["text"])){

        foreach($_POST["text"] as $i=>$text){

            $text = trim($text);
            if($text=="") continue;
            if(empty($_FILES["image"]["tmp_name"][$i])) continue;

            $imageUrl = uploadImageToCloudinary($_FILES["image"]["tmp_name"][$i]);
            if(!$imageUrl) continue;

            $data[] = [
                "id" => uniqid(),
                "text" => $text,
                "image" => $imageUrl
            ];
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO activities (id,unit_id,type,data)
            VALUES (gen_random_uuid(),:unit,'match',:data)
            ON CONFLICT (unit_id,type)
            DO UPDATE SET data = EXCLUDED.data
        ");

        $stmt->execute([
            "unit"=>$unit,
            "data"=>$json
        ]);
    }

    header("Location: editor.php?unit=".$unit);
    exit;
}
?>

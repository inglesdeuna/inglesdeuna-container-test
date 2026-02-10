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

    if(isset($_POST["text"])){

        foreach($_POST["text"] as $i => $text){

            if(trim($text) == "") continue;

            $imgPath = $_POST["existing_image"][$i] ?? "";

            if(
                isset($_FILES["image"]["name"][$i]) &&
                $_FILES["image"]["name"][$i] != ""
            ){

                $tmp = $_FILES["image"]["tmp_name"][$i];
                $name = uniqid()."_".basename($_FILES["image"]["name"][$i]);

                move_uploaded_file($tmp, $uploadDir."/".$name);

                $imgPath = "activities/match/uploads/".$unit."/".$name;
            }

            $items[] = [
                "id" => uniqid("m_"),
                "text" => trim($text),
                "image" => $imgPath
            ];
        }
    }

    $json = json_encode($items, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
    INSERT INTO activities (unit_id, type, data)
    VALUES (:unit,'match',:json)

    ON CONFLICT (unit_id,type)
    DO UPDATE SET data = EXCLUDED.data
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
SELECT data FROM activities
WHERE unit_id=:unit AND type='match'
");

$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);
?>

<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ================= HANDLE DELETE ================= */

if(isset($_GET["delete"])){

    $stmt = $pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id = :u AND type = 'match'
    ");
    $stmt->execute(["u"=>$unit]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $data = json_decode($row["data"] ?? "[]", true);

    $data = array_filter($data, function($p){
        return $p["id"] !== $_GET["delete"];
    });

    $json = json_encode(array_values($data), JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO activities (id,unit_id,type,data)
        VALUES (gen_random_uuid(),:u,'match',:d)
        ON CONFLICT (unit_id,type)
        DO UPDATE SET data = EXCLUDED.data
    ");

    $stmt->execute([
        "u"=>$unit,
        "d"=>$json
    ]);

    header("Location: editor.php?unit=".$unit);
    exit;
}

/* ================= HANDLE SAVE ================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    $stmt = $pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id = :u AND type = 'match'
    ");
    $stmt->execute(["u"=>$unit]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $data = json_decode($row["data"] ?? "[]", true);

    if(isset($_POST["text"])){

        foreach($_POST["text"] as $i=>$text){

            if(trim($text)=="" || empty($_FILES["image"]["tmp_name"][$i])) continue;

            $cloud = $_ENV["CLOUDINARY_CLOUD_NAME"];
            $key = $_ENV["CLOUDINARY_API_KEY"];
            $secret = $_ENV["CLOUDINARY_API_SECRET"];

            $timestamp = time();
            $signature = sha1("timestamp=$timestamp$secret");

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/$cloud/image/upload");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                "file" => new CURLFile($_FILES["image"]["tmp_name"][$i]),
                "api_key"=>$key,
                "timestamp"=>$timestamp,
                "signature"=>$signature
            ]);

            $response = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $imageUrl = $response["secure_url"] ?? "";
            if(!$imageUrl) continue;

            $data[] = [
                "id"=>uniqid(),
                "text"=>$text,
                "image"=>$imageUrl
            ];
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO activities (id,unit_id,type,data)
            VALUES (gen_random_uuid(),:u,'match',:d)
            ON CONFLICT (unit_id,type)
            DO UPDATE SET data = EXCLUDED.data
        ");

        $stmt->execute([
            "u"=>$unit,
            "d"=>$json
        ]);
    }

    header("Location: editor.php?unit=".$unit);
    exit;
}

/* ================= LOAD FOR DISPLAY ================= */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'match'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);
?>

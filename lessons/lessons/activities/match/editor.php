<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ================= LOAD ================= */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'match'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = [];

if($row && !empty($row["data"])){
    $data = json_decode($row["data"], true) ?? [];
}

/* ================= DELETE ================= */

if(isset($_GET["delete"])){

    $id = $_GET["delete"];

    $data = array_filter($data, function($p) use ($id){
        return $p["id"] !== $id;
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

/* ================= SAVE ================= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    if(isset($_POST["text"]) && is_array($_POST["text"])){

        foreach($_POST["text"] as $i=>$text){

            $text = trim($text);
            if($text=="") continue;
            if(empty($_FILES["image"]["tmp_name"][$i])) continue;

            /* ===== Cloudinary Upload (inline como Flashcards) ===== */

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
                "id" => uniqid(),
                "text" => $text,
                "image" => $imageUrl
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
?>

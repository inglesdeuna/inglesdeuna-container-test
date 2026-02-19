<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ========= LOAD ========= */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'listen_order'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

/* ========= DELETE ========= */

if(isset($_GET["delete"])){

    $i = intval($_GET["delete"]);

    if(isset($data[$i])){
        array_splice($data,$i,1);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO activities(id,unit_id,type,data)
        VALUES(gen_random_uuid(),:u,'listen_order',:d)
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

/* ========= SAVE ========= */

if($_SERVER["REQUEST_METHOD"]==="POST"){

    $sentence = trim($_POST["sentence"] ?? "");
    $images = [];

    if($sentence != ""){

        if(!empty($_FILES["images"]["tmp_name"][0])){

            foreach($_FILES["images"]["tmp_name"] as $i=>$tmp){

                if(!$tmp) continue;

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
                    "file" => new CURLFile($tmp),
                    "api_key"=>$key,
                    "timestamp"=>$timestamp,
                    "signature"=>$signature
                ]);

                $response = json_decode(curl_exec($ch), true);
                curl_close($ch);

                if(!empty($response["secure_url"])){
                    $images[] = $response["secure_url"];
                }
            }
        }

        if(count($images) > 0){

            $data[] = [
                "sentence"=>$sentence,
                "images"=>$images
            ];

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("
                INSERT INTO activities(id,unit_id,type,data)
                VALUES(gen_random_uuid(),:u,'listen_order',:d)
                ON CONFLICT (unit_id,type)
                DO UPDATE SET data = EXCLUDED.data
            ");

            $stmt->execute([
                "u"=>$unit,
                "d"=>$json
            ]);
        }
    }

    header("Location: editor.php?unit=".$unit);
    exit;
}
?>

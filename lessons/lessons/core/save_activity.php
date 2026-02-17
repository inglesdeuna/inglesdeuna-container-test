<?php
// core/save_activity.php

require_once __DIR__ . "/db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
    exit;
}

$unit = $_POST["unit"] ?? null;
$type = $_POST["type"] ?? null;
$content = $_POST["content_json"] ?? null;

if (!$unit || !$type) {
    echo json_encode(["status" => "error", "message" => "Missing parameters"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO activities (unit, type, content_json, updated_at)
        VALUES (:unit, :type, :content_json, NOW())
        ON CONFLICT (unit, type)
        DO UPDATE SET
            content_json = EXCLUDED.content_json,
            updated_at = NOW()
    ");

    $stmt->execute([
        ":unit" => $unit,
        ":type" => $type,
        ":content_json" => $content
    ]);

    echo json_encode(["status" => "success"]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

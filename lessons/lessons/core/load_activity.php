<?php
// core/load_activity.php

require_once __DIR__ . "/db.php";

header("Content-Type: application/json");

$unit = $_GET["unit"] ?? null;
$type = $_GET["type"] ?? null;

if (!$unit || !$type) {
    echo json_encode(["status" => "error", "message" => "Missing parameters"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT content_json
        FROM activities
        WHERE unit = :unit AND type = :type
        LIMIT 1
    ");

    $stmt->execute([
        ":unit" => $unit,
        ":type" => $type
    ]);

    $data = $stmt->fetchColumn();

    echo json_encode([
        "status" => "success",
        "content" => $data ? json_decode($data) : null
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

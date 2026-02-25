<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$unitId = $_POST["unit"] ?? null;
$types  = $_POST["types"] ?? [];

if (!$unitId || empty($types)) {
    header("Location: hub/index.php?unit=" . urlencode($unitId));
    exit;
}

$allowedTypes = [
    "drag_drop",
    "external",
    "flashcards",
    "flipbooks",
    "hangman",
    "listen_order",
    "match",
    "multiple_choice",
    "pronunciation"
];

foreach ($types as $type) {

    if (!in_array($type, $allowedTypes)) {
        continue;
    }

    $stmt = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = :type
        LIMIT 1
    ");

    $stmt->execute([
        "unit" => $unitId,
        "type" => $type
    ]);

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $activityId = uniqid("act_");

        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type)
            VALUES (:id, :unit, :type)
        ");

        $stmt->execute([
            "id"   => $activityId,
            "unit" => $unitId,
            "type" => $type
        ]);
    }
}

/* Redirige nuevamente al HUB */
header("Location: hub/index.php?unit=" . urlencode($unitId));
exit;

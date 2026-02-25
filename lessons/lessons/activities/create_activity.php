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
   header("Location: ../academic/unit_view.php?unit=" . urlencode($unitId));
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

$createdIds = [];

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

    if ($existing) {
        $createdIds[] = $existing["id"];
    } else {
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

        $createdIds[] = $activityId;
    }
}

/* ===============================
   REDIRECCIÓN INTELIGENTE
=============================== */

if (count($createdIds) === 1) {

    // Si solo es una actividad, abrir su editor
    $stmt = $pdo->prepare("
        SELECT type FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(["id" => $createdIds[0]]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activity) {
        header("Location: ../activities/" . $activity["type"] . "/editor.php?id=" . urlencode($createdIds[0]) . "&unit=" . urlencode($unitId));
exit;
    
}

/* Si son varias → ir a la vista completa de la unidad */
header("Location: unit_view.php?unit=" . urlencode($unitId));
exit;

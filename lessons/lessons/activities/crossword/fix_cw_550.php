<?php
// Script de corrección puntual para la actividad crossword id=550
require_once __DIR__ . "/../../config/db.php";

$activityId = 550;

$stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'crossword' LIMIT 1");
$stmt->execute(["id" => $activityId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    die("No se encontró la actividad 550.");
}

$data = json_decode($row["data"], true);
if (!is_array($data) || !isset($data["words"][3])) {
    die("No se encontró la palabra a corregir en el índice 3.");
}

// Mostrar ANTES
file_put_contents(__DIR__ . "/debug_cw550_before.json", json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// Corregir solo la palabra 3
$data["words"][3]["word"] = "HAVEAPIECEOFTHISCAKE";
$data["words"][3]["clue"] = "Come una porción de esta torta.";
$data["words"][3]["image"] = "";

// Mostrar DESPUÉS
file_put_contents(__DIR__ . "/debug_cw550_after.json", json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// Guardar en la base
$stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'crossword'");
$stmt->execute([
    "data" => json_encode($data, JSON_UNESCAPED_UNICODE),
    "id" => $activityId
]);

echo "Corregido. Verifica debug_cw550_before.json y debug_cw550_after.json para comparar.";

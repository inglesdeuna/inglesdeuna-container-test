<?php
require_once __DIR__."/../config/db.php";

$type = "CHANGE_ACTIVITY_NAME"; // ← CAMBIAR

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* ===== LOAD ===== */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type=:t
");
$stmt->execute([
"u"=>$unit,
"t"=>$type
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);
if(!is_array($data)) $data = [];
?>

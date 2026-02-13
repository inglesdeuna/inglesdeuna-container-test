<?php
/*
CORE TEMPLATE — ACTIVITY VIEWER

Requisitos:
- $pdo debe existir (lo carga viewer.php desde config)
- $type debe existir (lo define viewer.php)
*/

if (!isset($pdo)) {
    die("PDO connection missing");
}

if (!isset($type)) {
    die("Activity type missing");
}

/* ===== UNIT ===== */
$unit = $_GET["unit"] ?? null;
if (!$unit) die("Unit missing");

/* ===== LOAD DATA ===== */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id = :u AND type = :t
");

$stmt->execute([
    "u" => $unit,
    "t" => $type
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);
if (!is_array($data)) $data = [];

/* ===== BASE PATH (POR SI NECESITAS EN HTML) ===== */
$baseUploadsPath = "activities/" . $type . "/uploads/" . $unit;

/*
$data queda disponible para el viewer específico
Ejemplo esperado:
[
   [
      "text" => "...",
      "image" => "activities/type/uploads/unit/file.png"
   ]
]
*/
?>

<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// =======================
// VALIDAR PARAMETRO UNIT
// =======================

if (!isset($_GET['unit']) || empty($_GET['unit'])) {
    die("❌ ERROR: No se recibió parámetro UNIT");
}

$unit_id = $_GET['unit'];

echo "<pre>";
echo "✅ UNIT RECIBIDA: " . $unit_id . "\n";


// =======================
// BUSCAR UNIT EN DB
// =======================

try {

    $sql = "SELECT * FROM units WHERE id = :unit LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(":unit", $unit_id);
    $stmt->execute();

    $unitData = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "📦 RESULTADO DB:\n";
    var_dump($unitData);

    if (!$unitData) {
        die("❌ ERROR: Unidad no encontrada en DB");
    }

    echo "✅ Unidad encontrada correctamente\n";

} catch (Exception $e) {
    die("❌ ERROR DB: " . $e->getMessage());
}

?>

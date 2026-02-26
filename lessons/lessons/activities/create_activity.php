<?php
session_start();
require_once "../../config/db.php";

// Validar datos recibidos
$unitId = $_POST['unit'] ?? null;
$types  = $_POST['types'] ?? [];

if (!$unitId || empty($types)) {
    die("Unidad o tipos de actividades no especificados.");
}

// Verificar que la unidad existe
$stmtUnit = $pdo->prepare("SELECT * FROM units WHERE id = :id");
$stmtUnit->execute(["id" => $unitId]);
$unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

// Crear actividades seleccionadas
foreach ($types as $type) {
    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, created_at, position) 
        VALUES (:unit_id, :type, NOW(), 
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id = :unit_id2)
        )
    ");
    $stmt->execute([
        "unit_id"  => $unitId,
        "unit_id2" => $unitId,
        "type"     => $type
    ]);
}

// Redirigir a la vista de la unidad
header("Location: ../academic/unit_view.php?unit=" . urlencode($unitId));
exit;
?>

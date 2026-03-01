<?php
session_start();
require_once __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR DATOS RECIBIDOS
=============================== */
$unitId = $_POST['unit'] ?? null;
$types  = $_POST['types'] ?? [];

if (!$unitId || empty($types)) {
    die("Unidad o tipos de actividades no especificados.");
}

/* ===============================
   VERIFICAR QUE LA UNIDAD EXISTE
=============================== */
$stmtUnit = $pdo->prepare("
    SELECT * FROM units 
    WHERE id = :id
    LIMIT 1
");
$stmtUnit->execute([
    "id" => $unitId
]);

$unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ===============================
   CREAR ACTIVIDADES SELECCIONADAS
=============================== */
foreach ($types as $type) {

    // Evitar duplicados
    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit_id AND type = :type
        LIMIT 1
    ");

    $check->execute([
        "unit_id" => $unitId,
        "type"    => $type
    ]);

    if ($check->fetch()) {
        continue; // ya existe, no insertar
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities 
        (unit_id, type, created_at, position) 
        VALUES (
            :unit_id, 
            :type, 
            NOW(),
            (
                SELECT COALESCE(MAX(position),0)+1 
                FROM activities 
                WHERE unit_id = :unit_id2
            )
        )
    ");

    $stmt->execute([
        "unit_id"  => $unitId,
        "unit_id2" => $unitId,
        "type"     => $type
    ]);
}

/* ===============================
   REDIRECCIÓN CORRECTA
=============================== */
header("Location: ../../academic/unit_view.php?unit=" . urlencode($unitId));
header("Location: ../academic/unit_view.php?unit=" . urlencode($unitId));
exit;
?>

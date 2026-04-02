<?php
session_start();
require_once __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR DATOS RECIBIDOS
=============================== */
$unitId = $_POST['unit'] ?? null;
$types  = $_POST['types'] ?? [];

if (!$unitId) {
    die("Unit not specified.");
}

if (empty($types)) {
    header("Location: /lessons/lessons/academic/unit_view.php?unit=" . urlencode($unitId));
    exit;
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
    die("Unit not found.");
}

/* ===============================
   CREAR ACTIVIDADES SELECCIONADAS
=============================== */
$checkedTypes = $_POST['checked_types'] ?? $_POST['types'] ?? [];
$qtyCounts    = $_POST['qty'] ?? [];

foreach ($checkedTypes as $type) {

    // Validar formato del tipo
    if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $type)) {
        continue;
    }

    $n = max(1, min(9, (int) ($qtyCounts[$type] ?? 1)));

    for ($i = 0; $i < $n; $i++) {
        $stmt = $pdo->prepare("
            INSERT INTO activities 
            (unit_id, type, created_at, position) 
            VALUES (
                :unit_id, 
                :type, 
                NOW(),
                (
                    SELECT COALESCE(MAX(a2.position),0)+1 
                    FROM activities a2
                    WHERE a2.unit_id = :unit_id2
                )
            )
        ");

        $stmt->execute([
            "unit_id"  => $unitId,
            "unit_id2" => $unitId,
            "type"     => $type
        ]);
    }
}

/* ===============================
   REDIRECCIÓN
=============================== */
header("Location: /lessons/lessons/academic/unit_view.php?unit=" . urlencode($unitId));
exit;

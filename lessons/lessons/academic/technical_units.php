/* ===============================
   CREAR UNIDAD
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    // Verificar duplicado
    $check = $pdo->prepare("
        SELECT id FROM units
        WHERE course_id = :course_id
        AND name = :name
        LIMIT 1
    ");

    $check->execute([
        "course_id" => $courseId,
        "name"      => $unitName
    ]);

    $existingUnit = $check->fetch(PDO::FETCH_ASSOC);

    if ($existingUnit) {
        // 🔥 REDIRIGE AL HUB (CHECKLIST)
        header("Location: ../activities/hub/index.php?unit=" . urlencode($existingUnit["id"]));
        exit;
    }

    // Crear unidad nueva
    $stmtInsert = $pdo->prepare("
        INSERT INTO units (course_id, name, created_at)
        VALUES (:course_id, :name, NOW())
    ");

    $stmtInsert->execute([
        "course_id" => $courseId,
        "name"      => $unitName
    ]);

    $newUnitId = $pdo->lastInsertId();

    // 🔥 REDIRIGE AL HUB (CHECKLIST)
    header("Location: ../activities/hub/index.php?unit=" . urlencode($newUnitId));
    exit;
}

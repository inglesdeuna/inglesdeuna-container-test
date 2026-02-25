if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    /* Buscar si ya existe */
    $check = $pdo->prepare("
        SELECT id FROM units
        WHERE course_id = :course_id
        AND name = :name
        LIMIT 1
    ");

    $check->execute([
        "course_id" => $courseId,
        "name" => $unitName
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $unitId = $existing["id"];
    } else {
        $unitId = uniqid("unit_");

        $stmt = $pdo->prepare("
            INSERT INTO units (id, course_id, name)
            VALUES (:id, :course_id, :name)
        ");

        $stmt->execute([
            "id" => $unitId,
            "course_id" => $courseId,
            "name" => $unitName
        ]);
    }

    /* SIEMPRE REDIRIGE */
    header("Location: ../activities/unit_view.php?unit=" . urlencode($unitId));
    exit;
}

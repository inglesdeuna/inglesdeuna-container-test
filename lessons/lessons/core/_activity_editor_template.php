/* ===== DELETE ===== */
if (isset($_GET["delete"])) {

    $i = (int)$_GET["delete"];

    // Volver a cargar datos actuales
    $stmt = $pdo->prepare("
        SELECT data FROM activities
        WHERE unit_id = :u AND type = :t
    ");
    $stmt->execute([
        "u" => $unit,
        "t" => $type
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentData = [];

    if ($row && !empty($row["data"])) {
        $currentData = json_decode($row["data"], true);
        if (!is_array($currentData)) {
            $currentData = [];
        }
    }

    if (isset($currentData[$i])) {
        array_splice($currentData, $i, 1);

        $json = json_encode($currentData, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :d
            WHERE unit_id = :u AND type = :t
        ");

        $stmt->execute([
            "u" => $unit,
            "t" => $type,
            "d" => $json
        ]);
    }

    header("Location: editor.php?unit=" . $unit);
    exit;
}

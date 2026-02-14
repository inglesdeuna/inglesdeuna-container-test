<?php
/*
CORE TEMPLATE — ACTIVITY EDITOR
Requiere:
- $pdo (desde init_db.php)
- $type definido antes de incluir este archivo
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

/* ===== LOAD EXISTING DATA ===== */
$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id = :u AND type = :t
");

$stmt->execute([
    "u" => $unit,
    "t" => $type
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = [];
if ($row && !empty($row["data"])) {
    $data = json_decode($row["data"], true);
    if (!is_array($data)) {
        $data = [];
    }
}

/* ===== UPLOAD DIR ===== */
$uploadDir = __DIR__ . "/../activities/" . $type . "/uploads/" . $unit;

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* ===== ADD ITEM ===== */
if (isset($_POST["add"])) {

    $text = trim($_POST["text"] ?? "");
    $img = "";

    if (!empty($_FILES["image"]["name"])) {

        $tmp = $_FILES["image"]["tmp_name"];
        $new = uniqid() . "_" . basename($_FILES["image"]["name"]);

        move_uploaded_file($tmp, $uploadDir . "/" . $new);

        $img = "activities/" . $type . "/uploads/" . $unit . "/" . $new;
    }

    if ($text !== "" || $img !== "") {

        $data[] = [
            "text" => $text,
            "image" => $img
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data)
        VALUES (:u, :t, :d)
        ON CONFLICT (unit_id, type)
        DO UPDATE SET data = EXCLUDED.data
        ");

        $stmt->execute([
            "u" => $unit,
            "t" => $type,
            "d" => $json
        ]);

        // Recargar datos después de guardar
        header("Location: editor.php?unit=" . $unit);
        exit;
    }
}

/* ===== DELETE ===== */
if (isset($_GET["delete"])) {

    $i = (int)$_GET["delete"];

    if (isset($data[$i])) {

        array_splice($data, $i, 1);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

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

        header("Location: editor.php?unit=" . $unit);
        exit;
    }
}
?>

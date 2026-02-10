<?php
require_once __DIR__ . "/../../config/db.php";

/* =========================
   UNIT
========================= */
$unit = $_GET["unit"] ?? null;

if (!$unit) {
    die("Unit no especificada");
}

/* =========================
   GUARDAR
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $json = $_POST["json"] ?? "[]";

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, content_json)
        VALUES (:unit, 'match', :json)
        ON CONFLICT (unit_id, type)
        DO UPDATE SET content_json = :json
    ");

    $stmt->execute([
        "unit" => $unit,
        "json" => $json
    ]);

    echo "<h2>✅ Match guardado</h2>";
}

/* =========================
   CARGAR EXISTENTE
========================= */
$stmt = $pdo->prepare("
    SELECT content_json
    FROM activities
    WHERE unit_id = :unit
    AND type = 'match'
    LIMIT 1
");

$stmt->execute(["unit"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$existing = $row["content_json"] ?? "[]";
?>

<h1>Match Editor</h1>

<form method="post">

<textarea name="json" style="width:100%;height:300px;">
<?= htmlspecialchars($existing) ?>
</textarea>

<br><br>

<button>Guardar Match</button>

</form>

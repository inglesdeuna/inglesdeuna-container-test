<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* GUARDAR */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $sentences = $_POST["sentences"] ?? [];
    $data = [];

    foreach ($sentences as $s) {
        if (trim($s) !== "") {
            $data[] = ["sentence" => trim($s)];
        }
    }

    $json = json_encode($data);

    // Verificar si ya existe
    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = 'drag_drop'
    ");
    $check->execute(["unit"=>$unit]);

    if ($check->fetch()) {
        // UPDATE
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE unit_id = :unit
            AND type = 'drag_drop'
        ");
        $stmt->execute([
            "data"=>$json,
            "unit"=>$unit
        ]);
    } else {
        // INSERT
        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type, data)
            VALUES (:id, :unit, 'drag_drop', :data)
        ");
        $stmt->execute([
            "id"=>md5(random_bytes(16)),
            "unit"=>$unit,
            "data"=>$json
        ]);
    }

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}

/* CARGAR DATOS EXISTENTES */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'drag_drop'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);
?>

<?php
require_once __DIR__ . "/../../core/_activity_editor_template.php";

/* 3️⃣ GENERAR CONTENIDO */
ob_start();
?>

<?php if(isset($_GET["saved"])): ?>
<p style="color:green;font-weight:bold;">✔ Guardado correctamente</p>
<?php endif; ?>

<div class="card">
<form method="POST">

<div id="sentences">

<?php
if (!empty($data)) {
    foreach ($data as $item) {
        echo '<input type="text" name="sentences[]" value="'.htmlspecialchars($item["sentence"]).'">';
    }
}
?>

</div>

<button type="button" onclick="addSentence()">+ Add Sentence</button>
<br>
<button type="submit">💾 Save</button>

</form>
</div>

<script>
function addSentence(){
    const div = document.getElementById("sentences");
    const input = document.createElement("input");
    input.type = "text";
    input.name = "sentences[]";
    div.appendChild(input);
}
</script>

<?php
$content = ob_get_clean();

render_activity_editor("✏ Drag & Drop Editor", "✏", $content);



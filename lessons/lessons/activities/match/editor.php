<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==============================
   GUARDAR
============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $texts  = $_POST["text"] ?? [];
    $images = $_POST["image"] ?? [];

    $data = [];

    for ($i = 0; $i < count($texts); $i++) {

        $text  = trim($texts[$i]);
        $image = trim($images[$i]);

        if ($text !== "" && $image !== "") {
            $data[] = [
                "id"    => uniqid(),
                "text"  => $text,
                "image" => $image
            ];
        }
    }

    $json = json_encode($data);

    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = 'match'
    ");
    $check->execute(["unit"=>$unit]);

    if ($check->fetch()) {

        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE unit_id = :unit
            AND type = 'match'
        ");

        $stmt->execute([
            "data"=>$json,
            "unit"=>$unit
        ]);

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type, data)
            VALUES (:id, :unit, 'match', :data)
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

/* ==============================
   CARGAR DATOS
============================== */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'match'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

/* ==============================
   TEMPLATE
============================== */
require_once __DIR__ . "/../../core/_activity_editor_template.php";

ob_start();
?>

<?php if(isset($_GET["saved"])): ?>
    <p class="success-msg">✔ Guardado correctamente</p>
<?php endif; ?>

<form method="POST" class="match-editor-form">

    <div id="items-container">

        <?php if (!empty($data)): ?>
            <?php foreach ($data as $item): ?>
                <div class="match-item">
                    <input type="text"
                           name="text[]"
                           value="<?= htmlspecialchars($item["text"]) ?>"
                           placeholder="Text">

                    <input type="text"
                           name="image[]"
                           value="<?= htmlspecialchars($item["image"]) ?>"
                           placeholder="Image URL (Cloudinary)">

                    <button type="button" onclick="removeItem(this)">✖</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <button type="button" onclick="addItem()" class="btn-add">
        + Add Item
    </button>

    <button type="submit" class="btn-save">
        💾 Save
    </button>

</form>

<script>
function addItem(){
    const container = document.getElementById("items-container");

    const div = document.createElement("div");
    div.className = "match-item";

    div.innerHTML = `
        <input type="text" name="text[]" placeholder="Text">
        <input type="text" name="image[]" placeholder="Image URL (Cloudinary)">
        <button type="button" onclick="removeItem(this)">✖</button>
    `;

    container.appendChild(div);
}

function removeItem(button){
    button.parentElement.remove();
}
</script>

<?php
$content = ob_get_clean();

render_activity_editor("🧩 Match Editor", "🧩", $content);

<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/cloudinary_upload.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==============================
   GUARDAR
============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $texts      = $_POST["text"] ?? [];
    $images     = $_POST["image"] ?? [];
    $imageFiles = $_FILES["image_file"] ?? null;

    $data = [];

    for ($i = 0; $i < count($texts); $i++) {

        $text     = trim($texts[$i]);
        $imageUrl = $images[$i] ?? "";

        if (!empty($imageFiles["name"][$i])) {
            $imageUrl = upload_to_cloudinary($imageFiles["tmp_name"][$i]);
        }

        if ($text !== "" && $imageUrl !== "") {
            $data[] = [
                "id"    => uniqid(),
                "text"  => $text,
                "image" => $imageUrl
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

require_once __DIR__ . "/../../core/_activity_editor_template.php";

ob_start();
?>

<?php if(isset($_GET["saved"])): ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">
        ✔ Guardado correctamente
    </p>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" style="text-align:center;">

    <div id="items" style="margin-bottom:25px;">

        <?php if (!empty($data)): ?>
            <?php foreach ($data as $item): ?>
                <div style="margin-bottom:12px;">

                    <input 
                        type="text" 
                        name="text[]" 
                        value="<?= htmlspecialchars($item['text']) ?>" 
                        placeholder="Text"
                        style="padding:10px;margin:8px;border-radius:10px;border:1px solid #ccc;width:260px;"
                    >

                    <input 
                        type="file" 
                        name="image_file[]" 
                        accept="image/*"
                        style="padding:10px;margin:8px;border-radius:10px;border:1px solid #ccc;width:260px;"
                    >

                    <input 
                        type="hidden" 
                        name="image[]" 
                        value="<?= htmlspecialchars($item['image']) ?>"
                    >

                    <button 
                        type="button" 
                        onclick="removeItem(this)"
                        style="padding:8px 12px;border:none;border-radius:8px;cursor:pointer;"
                    >✖</button>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <button 
        type="button" 
        onclick="addItem()" 
        style="
            background:#16a34a;
            padding:10px 16px;
            border:none;
            border-radius:10px;
            color:white;
            cursor:pointer;
            margin-right:10px;
        "
    >
        + Add Item
    </button>

    <button 
        type="submit"
        style="
            background:#0b5ed7;
            padding:10px 16px;
            border:none;
            border-radius:10px;
            color:white;
            cursor:pointer;
        "
    >
        💾 Save
    </button>

</form>

<script>
function addItem(){
    const div = document.getElementById("items");

    const wrapper = document.createElement("div");
    wrapper.style.marginBottom = "12px";

    wrapper.innerHTML = `
        <input type="text" name="text[]" placeholder="Text"
            style="padding:10px;margin:8px;border-radius:10px;border:1px solid #ccc;width:260px;">

        <input type="file" name="image_file[]" accept="image/*"
            style="padding:10px;margin:8px;border-radius:10px;border:1px solid #ccc;width:260px;">

        <input type="hidden" name="image[]" value="">

        <button type="button" onclick="removeItem(this)"
            style="padding:8px 12px;border:none;border-radius:8px;cursor:pointer;">
            ✖
        </button>
    `;

    div.appendChild(wrapper);
}

function removeItem(button){
    button.parentElement.remove();
}
</script>

<?php
$content = ob_get_clean();
render_activity_editor("🧩 Match Editor", "🧩", $content);

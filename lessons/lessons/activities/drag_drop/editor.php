<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==============================
   GUARDAR
============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $sentences = $_POST["sentences"] ?? [];
    $data = [];

    foreach ($sentences as $s) {
        if (trim($s) !== "") {
            $data[] = ["sentence" => trim($s)];
        }
    }

    $json = json_encode($data);

    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = 'drag_drop'
    ");
    $check->execute(["unit"=>$unit]);

    if ($check->fetch()) {

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

/* ==============================
   CARGAR DATOS
============================== */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'drag_drop'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

/* ==============================
   TEMPLATE EDITOR
============================== */
require_once __DIR__ . "/../../core/_activity_editor_template.php";

ob_start();
?>

<?php if(isset($_GET["saved"])): ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">
        ✔ Guardado correctamente
    </p>
<?php endif; ?>

<form method="POST" style="text-align:center;">

    <div id="sentences" style="margin-bottom:25px;">

        <?php
        if (!empty($data)) {
            foreach ($data as $item) {
                echo '<input 
                        type="text" 
                        name="sentences[]" 
                        value="'.htmlspecialchars($item["sentence"]).'" 
                        style="
                            padding:10px;
                            margin:8px;
                            border-radius:10px;
                            border:1px solid #ccc;
                            width:260px;
                        "
                      >';
            }
        }
        ?>

    </div>

    <button 
        type="button" 
        onclick="addSentence()" 
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
        + Add Sentence
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
function addSentence(){
    const div = document.getElementById("sentences");

    const input = document.createElement("input");
    input.type = "text";
    input.name = "sentences[]";

    input.style.padding = "10px";
    input.style.margin = "8px";
    input.style.borderRadius = "10px";
    input.style.border = "1px solid #ccc";
    input.style.width = "260px";

    div.appendChild(input);
}
</script>

<?php
$content = ob_get_clean();

render_activity_editor("✏ Drag & Drop Editor", "✏", $content);

<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==============================
   SAVE
============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $words = $_POST["word"] ?? [];
    $existingAudio = $_POST["audio"] ?? [];
    $audioFiles = $_FILES["audio_file"] ?? null;

    $data = [];

    for ($i = 0; $i < count($words); $i++) {

        if (trim($words[$i]) !== "") {

            $audioUrl = $existingAudio[$i] ?? "";

            if (!empty($audioFiles["name"][$i])) {
                require_once __DIR__."/../../core/cloudinary_upload.php";
                $audioUrl = upload_to_cloudinary($audioFiles["tmp_name"][$i]);
            }

            $data[] = [
                "word"  => trim($words[$i]),
                "audio" => $audioUrl
            ];
        }
    }

    $json = json_encode($data);

    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = 'pronunciation'
    ");
    $check->execute(["unit"=>$unit]);

    if ($check->fetch()) {

        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE unit_id = :unit
            AND type = 'pronunciation'
        ");

        $stmt->execute([
            "data"=>$json,
            "unit"=>$unit
        ]);

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type, data)
            VALUES (:id, :unit, 'pronunciation', :data)
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
   LOAD DATA
============================== */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'pronunciation'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

require_once __DIR__ . "/../../core/_activity_editor_template.php";

ob_start();
?>

<form method="POST" enctype="multipart/form-data">

<?php if(isset($_GET["saved"])): ?>
<p style="color:green;font-weight:bold;margin-bottom:15px;">
✔ Guardado correctamente
</p>
<?php endif; ?>

<div id="items">

<?php if(!empty($data)): ?>
<?php foreach($data as $i => $item): ?>
<div class="pron-block">

<input type="text" name="word[]" 
value="<?= htmlspecialchars($item['word']) ?>" 
placeholder="Word">

<input type="file" name="audio_file[]" accept="audio/*">

<input type="hidden" name="audio[]" value="<?= htmlspecialchars($item['audio'] ?? '') ?>">

<button type="button" onclick="removeItem(this)" class="btn-remove">✖</button>

</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

<button type="button" onclick="addItem()" class="btn-add">
+ Add Word
</button>

<button type="submit" class="btn-save">
💾 Save
</button>

</form>

<style>
.pron-block{
    background:#f9fafb;
    padding:14px;
    margin-bottom:15px;
    border-radius:12px;
    border:1px solid #e5e7eb;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.pron-block input{
    padding:8px 10px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:14px;
}

.btn-add{
    background:#16a34a;
    color:white;
    padding:8px 14px;
    border:none;
    border-radius:8px;
    cursor:pointer;
    margin-right:10px;
}

.btn-save{
    background:#0b5ed7;
    color:white;
    padding:8px 14px;
    border:none;
    border-radius:8px;
    cursor:pointer;
}

.btn-remove{
    background:#ef4444;
    color:white;
    border:none;
    padding:6px 10px;
    border-radius:6px;
    cursor:pointer;
    align-self:flex-end;
}
</style>

<script>
function addItem(){
    const container = document.getElementById("items");

    const div = document.createElement("div");
    div.className = "pron-block";

    div.innerHTML = `
        <input type="text" name="word[]" placeholder="Word">
        <input type="file" name="audio_file[]" accept="audio/*">
        <input type="hidden" name="audio[]" value="">
        <button type="button" onclick="removeItem(this)" class="btn-remove">✖</button>
    `;

    container.appendChild(div);
}

function removeItem(btn){
    btn.parentElement.remove();
}
</script>

<?php
$content = ob_get_clean();
render_activity_editor("Pronunciation Editor", "🔊", $content);

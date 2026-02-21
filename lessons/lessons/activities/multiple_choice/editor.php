<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==============================
   GUARDAR
============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $questions = $_POST["question"] ?? [];
    $optionsA  = $_POST["option_a"] ?? [];
    $optionsB  = $_POST["option_b"] ?? [];
    $optionsC  = $_POST["option_c"] ?? [];
    $corrects  = $_POST["correct"] ?? [];

    $imageFiles     = $_FILES["image_file"] ?? null;
    $existingImages = $_POST["image"] ?? [];

    $data = [];

    for ($i = 0; $i < count($questions); $i++) {

        if (trim($questions[$i]) !== "") {

            $imageUrl = $existingImages[$i] ?? "";

            if (!empty($imageFiles["name"][$i])) {
                require_once __DIR__."/../../core/cloudinary_upload.php";
                $imageUrl = upload_to_cloudinary($imageFiles["tmp_name"][$i]);
            }

            $data[] = [
                "question" => trim($questions[$i]),
                "image"    => $imageUrl,
                "options"  => [
                    $optionsA[$i],
                    $optionsB[$i],
                    $optionsC[$i]
                ],
                "correct"  => (int)$corrects[$i]
            ];
        }
    }

    $json = json_encode($data);

    $check = $pdo->prepare("
        SELECT id FROM activities
        WHERE unit_id = :unit
        AND type = 'multiple_choice'
    ");
    $check->execute(["unit"=>$unit]);

    if ($check->fetch()) {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE unit_id = :unit
            AND type = 'multiple_choice'
        ");
        $stmt->execute(["data"=>$json,"unit"=>$unit]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type, data)
            VALUES (:id, :unit, 'multiple_choice', :data)
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
    AND type = 'multiple_choice'
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

<div id="questions">

<?php if(!empty($data)): ?>
<?php foreach($data as $i => $item): ?>
<div class="mc-block">

<input type="text" name="question[]" 
value="<?= htmlspecialchars($item['question']) ?>" 
placeholder="Question">

<input type="file" name="image_file[]" accept="image/*">
<input type="hidden" name="image[]" value="<?= htmlspecialchars($item['image'] ?? '') ?>">

<input type="text" name="option_a[]" 
value="<?= htmlspecialchars($item['options'][0]) ?>" 
placeholder="Option A">

<input type="text" name="option_b[]" 
value="<?= htmlspecialchars($item['options'][1]) ?>" 
placeholder="Option B">

<input type="text" name="option_c[]" 
value="<?= htmlspecialchars($item['options'][2]) ?>" 
placeholder="Option C">

<select name="correct[]">
<option value="0" <?= $item['correct']==0?'selected':'' ?>>Correct: A</option>
<option value="1" <?= $item['correct']==1?'selected':'' ?>>Correct: B</option>
<option value="2" <?= $item['correct']==2?'selected':'' ?>>Correct: C</option>
</select>

<button type="button" onclick="removeQuestion(this)" class="btn-remove">✖</button>

</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

<button type="button" onclick="addQuestion()" class="btn-add">
+ Add Question
</button>

<button type="submit" class="btn-save">
💾 Save
</button>

</form>

<style>
.mc-block{
    background:#f9fafb;
    padding:16px;
    margin-bottom:18px;
    border-radius:12px;
    border:1px solid #e5e7eb;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.mc-block input,
.mc-block select{
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
function addQuestion(){
    const container = document.getElementById("questions");

    const div = document.createElement("div");
    div.className = "mc-block";

    div.innerHTML = `
        <input type="text" name="question[]" placeholder="Question">
        <input type="file" name="image_file[]" accept="image/*">
        <input type="hidden" name="image[]" value="">
        <input type="text" name="option_a[]" placeholder="Option A">
        <input type="text" name="option_b[]" placeholder="Option B">
        <input type="text" name="option_c[]" placeholder="Option C">
        <select name="correct[]">
            <option value="0">Correct: A</option>
            <option value="1">Correct: B</option>
            <option value="2">Correct: C</option>
        </select>
        <button type="button" onclick="removeQuestion(this)" class="btn-remove">✖</button>
    `;

    container.appendChild(div);
}

function removeQuestion(btn){
    btn.parentElement.remove();
}
</script>

<?php
$content = ob_get_clean();
render_activity_editor("📝 Multiple Choice Editor", "📝", $content);

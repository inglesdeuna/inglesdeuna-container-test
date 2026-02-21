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

            /* Si se sube nueva imagen */
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

        $stmt->execute([
            "data"=>$json,
            "unit"=>$unit
        ]);

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
<div style="margin-bottom:30px;border-bottom:1px solid #ddd;padding-bottom:20px;">

<label>Question</label>
<input type="text" name="question[]" 
value="<?= htmlspecialchars($item['question']) ?>" 
style="width:100%;padding:12px;margin-bottom:10px;border-radius:8px;border:1px solid #ccc;">

<label>Image</label>
<input type="file" name="image_file[]" accept="image/*">
<input type="hidden" name="image[]" value="<?= htmlspecialchars($item['image'] ?? '') ?>">

<label>Option A</label>
<input type="text" name="option_a[]" 
value="<?= htmlspecialchars($item['options'][0]) ?>" 
style="width:100%;padding:10px;margin-bottom:8px;border-radius:8px;border:1px solid #ccc;">

<label>Option B</label>
<input type="text" name="option_b[]" 
value="<?= htmlspecialchars($item['options'][1]) ?>" 
style="width:100%;padding:10px;margin-bottom:8px;border-radius:8px;border:1px solid #ccc;">

<label>Option C</label>
<input type="text" name="option_c[]" 
value="<?= htmlspecialchars($item['options'][2]) ?>" 
style="width:100%;padding:10px;margin-bottom:8px;border-radius:8px;border:1px solid #ccc;">

<select name="correct[]" style="margin-top:10px;">
<option value="0" <?= $item['correct']==0?'selected':'' ?>>Correct: A</option>
<option value="1" <?= $item['correct']==1?'selected':'' ?>>Correct: B</option>
<option value="2" <?= $item['correct']==2?'selected':'' ?>>Correct: C</option>
</select>

</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

<button type="submit" 
style="background:#0b5ed7;color:white;padding:10px 18px;border:none;border-radius:8px;cursor:pointer;">
💾 Save
</button>

</form>

<?php
$content = ob_get_clean();
render_activity_editor("📝 Multiple Choice Editor", "📝", $content);

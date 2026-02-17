<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$unit = $_GET['unit'] ?? null;
if(!$unit){ die("Unit not specified"); }

$jsonFile = __DIR__ . "/multiple_choice.json";
$uploadDir = __DIR__ . "/../../uploads/";

if(!file_exists($uploadDir)){
    mkdir($uploadDir, 0777, true);
}

$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];

if(!isset($data[$unit])){
    $data[$unit] = [];
}

/* =========================
   GUARDAR
========================= */

if($_SERVER['REQUEST_METHOD'] === 'POST'){

    $question = trim($_POST['question']);
    $options = $_POST['options'] ?? [];
    $correct = $_POST['correct'] ?? 0;

    $imagePath = null;

    if(!empty($_FILES['image']['name'])){
        $filename = time() . "_" . basename($_FILES['image']['name']);
        $targetPath = $uploadDir . $filename;

        if(move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)){
            $imagePath = "../../uploads/" . $filename;
        }
    }

    $data[$unit][] = [
        "question" => $question,
        "options" => $options,
        "correct" => (int)$correct,
        "image" => $imagePath
    ];

    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

    header("Location: editor.php?unit=" . urlencode($unit));
    exit;
}

$questions = $data[$unit];
?>

<?php
$activityTitle = "Multiple Choice Editor";
$activitySubtitle = "Add questions and images.";
ob_start();
?>

<form method="post" enctype="multipart/form-data">

<input type="text" name="question" placeholder="Question" required style="width:100%;padding:10px;margin-bottom:10px;">

<input type="file" name="image" style="margin-bottom:15px;">

<?php for($i=0;$i<3;$i++): ?>
<input type="text" name="options[]" placeholder="Option <?= $i+1 ?>" required style="width:100%;padding:10px;margin-bottom:8px;">
<?php endfor; ?>

<label>Correct answer (0,1,2)</label>
<input type="number" name="correct" min="0" max="2" required style="width:100%;padding:10px;margin-bottom:15px;">

<button type="submit" class="btn-primary">Save Question</button>

</form>

<hr>

<h3>Saved Questions</h3>

<?php foreach($questions as $q): ?>
<div style="background:#f1f5f9;padding:15px;border-radius:12px;margin-bottom:10px;">
<strong><?= htmlspecialchars($q['question']) ?></strong>
<?php if(!empty($q['image'])): ?>
<br><img src="<?= $q['image'] ?>" style="width:120px;margin-top:8px;">
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php
$editorContent = ob_get_clean();
include "../../core/_activity_editor_template.php";


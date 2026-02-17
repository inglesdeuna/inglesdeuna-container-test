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
   SAVE QUESTION
========================= */

if($_SERVER['REQUEST_METHOD'] === 'POST'){

    $question = trim($_POST['question']);
    $options  = $_POST['options'] ?? [];
    $correct  = $_POST['correct'] ?? 0;

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
        "options"  => $options,
        "correct"  => (int)$correct,
        "image"    => $imagePath
    ];

    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

    header("Location: editor.php?unit=" . urlencode($unit));
    exit;
}

$questions = $data[$unit];

/* =========================
   TEMPLATE VARIABLES
========================= */

$activityTitle = "Multiple Choice Editor";
$activitySubtitle = "Add questions and images for this unit.";

ob_start();
?>

<form method="post" enctype="multipart/form-data">

<input type="text" name="question" placeholder="Enter question" required>

<input type="file" name="image">

<?php for($i=0;$i<3;$i++): ?>
<input type="text" name="options[]" placeholder="Option <?= $i+1 ?>" required>
<?php endfor; ?>

<label>Correct answer index (0, 1 or 2)</label>
<input type="number" name="correct" min="0" max="2" required>

<button type="submit">Save</button>

</form>

<hr>

<h3>Saved Questions</h3>

<?php if(empty($questions)): ?>
<p>No questions yet.</p>
<?php else: ?>
<?php foreach($questions as $q): ?>
<div class="saved-item">
<strong><?= htmlspecialchars($q['question']) ?></strong>

<?php if(!empty($q['image'])): ?>
<br><img src="<?= $q['image'] ?>" width="120">
<?php endif; ?>

</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
$editorContent = ob_get_clean();
include "../../core/_activity_editor_template.php";

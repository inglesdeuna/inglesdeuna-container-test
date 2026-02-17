<?php
$unit = $_GET['unit'] ?? null;
if(!$unit){ die("Unit not specified"); }

$jsonFile = __DIR__ . "/multiple_choice.json";
$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];

$questions = $data[$unit] ?? [];

$activityTitle = "Multiple Choice";
$activitySubtitle = "Choose the correct answer.";

ob_start();
?>

<div style="display:flex;flex-direction:column;gap:25px;">

<?php foreach($questions as $index => $q): ?>

<div style="background:white;padding:25px;border-radius:20px;box-shadow:0 8px 20px rgba(0,0,0,0.05);">

<h3><?= htmlspecialchars($q['question']) ?></h3>

<?php if(!empty($q['image'])): ?>
<img src="<?= $q['image'] ?>" style="width:180px;margin:15px 0;">
<?php endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;">
<?php foreach($q['options'] as $i=>$opt): ?>
<button class="option-btn" onclick="checkAnswer(<?= $index ?>, <?= $i ?>)">
<?= htmlspecialchars($opt) ?>
</button>
<?php endforeach; ?>
</div>

<div id="feedback-<?= $index ?>" class="feedback"></div>

</div>

<?php endforeach; ?>

</div>

<style>
.option-btn{
    background:#2563eb;
    color:white;
    border:none;
    padding:8px 15px;
    border-radius:8px;
    cursor:pointer;
}
.option-btn:hover{
    background:#1e40af;
}
</style>

<script>
const questions = <?= json_encode($questions) ?>;

function checkAnswer(qIndex, selected){
    const feedback = document.getElementById("feedback-"+qIndex);

    if(selected === questions[qIndex].correct){
        feedback.className = "feedback correct";
        feedback.innerHTML = "⭐ Correct!";
    }else{
        feedback.className = "feedback wrong";
        feedback.innerHTML = "❌ Try Again";
    }
}
</script>

<?php
$activityContent = ob_get_clean();
include "../../core/_activity_viewer_template.php";

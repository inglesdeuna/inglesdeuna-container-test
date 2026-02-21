<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/_activity_viewer_template.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'multiple_choice'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

ob_start();
?>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef6ff;
    padding:40px 20px;
}

/* BACK BUTTON TOP LEFT */
.top-back{
    position:absolute;
    top:30px;
    left:40px;
}

.back-btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:12px 28px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    min-width:120px;
    transition:0.2s ease;
}

.back-btn:hover{
    background:#15803d;
}

/* TITLE */
.title{
    text-align:center;
    color:#0b5ed7;
    font-size:28px;
    font-weight:bold;
    margin-bottom:30px;
}

/* MAIN BOX */
.box{
    background:white;
    padding:30px;
    border-radius:18px;
    max-width:600px;
    margin:0 auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
    text-align:center;
}

/* QUESTION */
.question{
    font-size:18px;
    font-weight:bold;
    margin-bottom:20px;
}

/* IMAGE SMALLER */
.question-image{
    width:140px;
    height:auto;
    margin-bottom:20px;
}

/* OPTIONS */
.option{
    background:#1e5dc8;
    color:white;
    border:none;
    padding:12px;
    border-radius:14px;
    font-size:14px;
    font-weight:bold;
    cursor:pointer;
    margin-bottom:10px;
    width:100%;
    transition:0.2s;
}

.option:hover{
    background:#174ea6;
}

/* CONTROLS (exact drag & drop style) */
.controls{
    margin-top:15px;
}

.controls button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:10px 22px;
    border-radius:14px;
    font-weight:bold;
    cursor:pointer;
    font-size:14px;
    margin:0 5px;
}

.controls button:hover{
    background:#084298;
}
</style>

<div class="top-back">
    
</div>

<div class="box">

<?php if(!empty($data)): ?>
<?php $item = $data[0]; ?>

<div class="question">
    1. <?= htmlspecialchars($item['question']) ?>
</div>

<?php if(!empty($item['image'])): ?>
<img src="<?= htmlspecialchars($item['image']) ?>" class="question-image">
<?php endif; ?>

<?php foreach($item['options'] as $index => $opt): ?>
<button class="option" onclick="selectOption(<?= $index ?>)">
    <?= htmlspecialchars($opt) ?>
</button>
<?php endforeach; ?>

<div class="controls">
    <button onclick="checkAnswer()">✅ Check</button>
    <button onclick="nextQuestion()">➡️</button>
</div>

<?php endif; ?>

</div>

<script>
let selected = null;
const correct = <?= $item['correct'] ?? 0 ?>;

function selectOption(index){
    selected = index;
}

function checkAnswer(){
    if(selected === null) return;

    if(selected === correct){
        alert("Excellent!");
    } else {
        alert("Try Again");
    }
}

function nextQuestion(){
    location.reload();
}
</script>

<?php
$content = ob_get_clean();
render_activity_viewer("📝 Multiple Choice", "📝", $content);

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
.btn-check,
.btn-next {
    background: #1f5cc4;
    color: white;
    border: none;
    padding: 12px 28px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    min-width: 130px;
    transition: 0.2s ease;
}

.btn-check:hover,
.btn-next:hover {
    background: #1749a0;
}

.btn-next {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
</style>

<div class="mc-wrapper">
<?php if(!empty($data)): ?>
<?php $q = $data[0]; ?>

<div class="mc-card">

    <div class="mc-question">
        <?= htmlspecialchars($q["question"]) ?>
    </div>

    <div class="mc-grid">

        <div>
            <div class="mc-options">
                <?php foreach($q["options"] as $index=>$opt): ?>
                <button class="mc-option"
                        onclick="selectOption(this, <?= $index ?>)">
                    <?= htmlspecialchars($opt) ?>
                </button>
                <?php endforeach; ?>
            </div>

           <div class="mc-buttons">
    <button id="checkBtn" class="btn-check">
        ✔ Check
    </button>

    <button id="nextBtn" class="btn-next">
        ➜
    </button>
</div>

            <div id="mc-message" class="mc-message"></div>
        </div>

        <div>
            <?php if(!empty($q["image"])): ?>
                <img src="<?= htmlspecialchars($q["image"]) ?>" class="mc-image">
            <?php endif; ?>
        </div>

    </div>

</div>

<script>
let selected = null;
const correct = <?= (int)$q["correct"] ?>;

function selectOption(btn, index){
    document.querySelectorAll(".mc-option").forEach(b=>b.classList.remove("selected"));
    btn.classList.add("selected");
    selected = index;
}

function checkAnswer(){
    const msg = document.getElementById("mc-message");
    if(selected === null){
        msg.innerHTML = "Select an option.";
        msg.className = "mc-message wrong-msg";
        return;
    }

    if(selected === correct){
        msg.innerHTML = "✅ Excellent! Completed.";
        msg.className = "mc-message correct-msg";
    }else{
        msg.innerHTML = "❌ Try Again.";
        msg.className = "mc-message wrong-msg";
    }
}

function resetOptions(){
    document.querySelectorAll(".mc-option").forEach(b=>b.classList.remove("selected"));
    document.getElementById("mc-message").innerHTML = "";
    selected = null;
}
</script>

<?php endif; ?>
</div>

<?php
$content = ob_get_clean();
render_activity_viewer("Multiple Choice", "📝", $content);

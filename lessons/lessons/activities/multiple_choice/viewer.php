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
.mc-wrapper{
    max-width:1000px;
    margin:0 auto;
}

.mc-card{
    background:white;
    border-radius:18px;
    padding:30px 40px;
    box-shadow:0 8px 20px rgba(0,0,0,0.08);
}

.mc-grid{
    display:grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap:30px;
    align-items:center;
}

.mc-question{
    font-size:20px;
    font-weight:600;
    margin-bottom:20px;
}

.mc-options{
    display:flex;
    gap:14px;
}

.mc-option{
    flex:1;
    padding:14px;
    background:#1f5cc4;
    color:white;
    border:none;
    border-radius:10px;
    font-weight:600;
    cursor:pointer;
    transition:0.2s;
}

.mc-option:hover{
    background:#184ca4;
}

.mc-option.selected{
    outline:3px solid #0b5ed7;
}

.mc-image{
    width:100%;
    max-height:180px;
    object-fit:contain;
}

.mc-buttons{
    margin-top:25px;
    display:flex;
    gap:12px;
}

.mc-check{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:10px 18px;
    border-radius:8px;
    cursor:pointer;
}

.mc-try{
    background:#6c757d;
    color:white;
    border:none;
    padding:10px 18px;
    border-radius:8px;
    cursor:pointer;
}

.mc-message{
    margin-top:15px;
    font-weight:600;
}

.correct-msg{ color:#16a34a; }
.wrong-msg{ color:#dc2626; }
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

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
    border-radius:20px;
    padding:40px;
    box-shadow:0 6px 18px rgba(0,0,0,0.08);
}

.mc-grid{
    display:grid;
    grid-template-columns: 1fr 350px;
    gap:40px;
    align-items:center;
}

.mc-question{
    font-size:22px;
    font-weight:600;
    margin-bottom:25px;
}

.mc-options{
    display:flex;
    gap:20px;
}

.mc-option{
    flex:1;
    background:#1f5fc4;
    color:white;
    border:none;
    padding:14px;
    border-radius:12px;
    font-size:16px;
    font-weight:600;
    cursor:pointer;
    transition:0.2s;
}

.mc-option:hover{
    opacity:0.9;
}

.mc-option.selected{
    background:#0b3d91;
}

.mc-image img{
    width:100%;
    max-height:260px;
    object-fit:contain;
}

.mc-buttons{
    margin-top:30px;
    display:flex;
    gap:20px;
}

.mc-feedback{
    margin-top:20px;
    font-weight:600;
}
.correct-msg{ color:green; }
.wrong-msg{ color:#d9534f; }
</style>

<div class="mc-wrapper">
<div class="mc-card">

<div id="mc-container"></div>

</div>
</div>

<script>
const DATA = <?= json_encode($data ?? []) ?>;
let current = 0;
let selected = null;

function renderQuestion(){

    const item = DATA[current];

    let imageHtml = '';
    if(item.image){
        imageHtml = `
        <div class="mc-image">
            <img src="${item.image}">
        </div>`;
    }

    document.getElementById("mc-container").innerHTML = `
        <div class="mc-grid">

            <div>
                <div class="mc-question">
                    ${current+1}. ${item.question}
                </div>

                <div class="mc-options">
                    ${item.options.map((opt,i)=>`
                        <button class="mc-option"
                            onclick="selectOption(${i},this)">
                            ${opt}
                        </button>
                    `).join('')}
                </div>

                <div class="mc-buttons">
                    <button class="btn-primary" onclick="checkAnswer()">✔ Check</button>
                    <button class="btn-primary" onclick="nextQuestion()">➡</button>
                </div>

                <div id="feedback" class="mc-feedback"></div>
            </div>

            ${imageHtml}

        </div>
    `;
}

function selectOption(index,el){
    selected = index;
    document.querySelectorAll(".mc-option").forEach(b=>b.classList.remove("selected"));
    el.classList.add("selected");
}

function checkAnswer(){
    const item = DATA[current];
    const feedback = document.getElementById("feedback");

    if(selected === null){
        feedback.innerHTML = "Select an option";
        feedback.className="mc-feedback wrong-msg";
        return;
    }

    if(selected === item.correct){
        feedback.innerHTML = "Excellent!";
        feedback.className="mc-feedback correct-msg";
    }else{
        feedback.innerHTML = "Try again";
        feedback.className="mc-feedback wrong-msg";
    }
}

function nextQuestion(){
    if(current < DATA.length-1){
        current++;
        selected = null;
        renderQuestion();
    }else{
        document.getElementById("mc-container").innerHTML =
            "<h3 style='text-align:center;color:green;'>Completed 🎉</h3>";
    }
}

renderQuestion();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer("📝 Multiple Choice", "📝", $content);

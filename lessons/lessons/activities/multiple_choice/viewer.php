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
    max-width:900px;
    margin:40px auto;
    background:white;
    padding:40px;
    border-radius:20px;
    box-shadow:0 10px 25px rgba(0,0,0,0.08);
}

.mc-question{
    font-size:22px;
    font-weight:600;
    margin-bottom:20px;
}

.mc-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}

.mc-option{
    background:#0b5ed7;
    color:white;
    padding:18px;
    border-radius:12px;
    cursor:pointer;
    text-align:center;
    font-weight:600;
    transition:0.2s;
}

.mc-option:hover{
    background:#084298;
}

.mc-option.selected{
    outline:4px solid #ffc107;
}

.mc-option.correct{
    background:#198754;
}

.mc-option.wrong{
    background:#dc3545;
}

.mc-buttons{
    margin-top:30px;
    text-align:center;
}

.mc-btn{
    padding:12px 22px;
    border:none;
    border-radius:10px;
    font-weight:bold;
    cursor:pointer;
    margin:5px;
}

.check-btn{
    background:#0b5ed7;
    color:white;
}

.try-btn{
    background:#6c757d;
    color:white;
}

.mc-message{
    margin-top:20px;
    font-size:18px;
    font-weight:bold;
    text-align:center;
}
</style>

<div class="mc-wrapper">

    <div id="mc-container"></div>

    <div class="mc-buttons">
        <button class="mc-btn check-btn" onclick="checkAnswer()">Check</button>
        <button class="mc-btn try-btn" onclick="nextQuestion()">Try Again</button>
    </div>

    <div class="mc-message" id="mc-message"></div>

</div>

<script>
const MC_DATA = <?= json_encode($data ?? []) ?>;

let current = 0;
let selected = null;

function renderQuestion(){

    if(current >= MC_DATA.length){
        document.getElementById("mc-container").innerHTML = 
        "<h2 style='text-align:center;color:#198754;'>🎉 Completed!</h2>";
        document.querySelector(".mc-buttons").style.display="none";
        return;
    }

    const q = MC_DATA[current];

    let html = `
        <div class="mc-question">${q.question}</div>
    `;

    if(q.image){
        html += `<div style="text-align:center;margin-bottom:20px;">
                    <img src="${q.image}" style="max-width:300px;border-radius:12px;">
                 </div>`;
    }

    html += `<div class="mc-grid">`;

    q.options.forEach((opt,index)=>{
        html += `
            <div class="mc-option" onclick="selectOption(this,${index})">
                ${opt}
            </div>
        `;
    });

    html += `</div>`;

    document.getElementById("mc-container").innerHTML = html;
    document.getElementById("mc-message").innerHTML = "";
    selected = null;
}

function selectOption(el,index){
    document.querySelectorAll(".mc-option").forEach(o=>o.classList.remove("selected"));
    el.classList.add("selected");
    selected = index;
}

function checkAnswer(){
    if(selected === null) return;

    const correct = MC_DATA[current].correct;
    const options = document.querySelectorAll(".mc-option");

    if(selected === correct){
        options[selected].classList.add("correct");
        document.getElementById("mc-message").innerHTML = "Excellent! 🎉";
        setTimeout(()=>{ current++; renderQuestion(); },1500);
    }else{
        options[selected].classList.add("wrong");
        document.getElementById("mc-message").innerHTML = "Try again!";
    }
}

function nextQuestion(){
    renderQuestion();
}

renderQuestion();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer("📝 Multiple Choice", "📝", $content);

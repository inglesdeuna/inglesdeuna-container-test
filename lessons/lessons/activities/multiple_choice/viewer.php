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
    text-align:center;
}

/* White box */
.box{
    background:white;
    padding:30px;
    border-radius:18px;
    max-width:600px;
    margin:20px auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}

/* Question */
.question{
    font-size:18px;
    font-weight:bold;
    margin-bottom:20px;
}

/* Options vertical */
.options{
    display:flex;
    flex-direction:column;
    gap:12px;
    margin-bottom:20px;
}

.option-btn{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:12px 20px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    transition:0.2s;
}

.option-btn:hover{
    background:#084298;
}

.option-btn.selected{
    outline:3px solid #084298;
}

/* Controls EXACT like requested */
.controls{
    display:flex;
    justify-content:center;
    gap:15px;
    margin-top:10px;
}

.controls button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:12px 26px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    transition:0.2s ease;
}

.controls button:hover{
    background:#084298;
}

.feedback{
    margin-top:15px;
    font-weight:bold;
}

.correct{
    color:#16a34a;
}

.wrong{
    color:#dc2626;
}

</style>

<div class="box" id="mc-app"></div>

<script>

const QUESTIONS = <?= json_encode($data ?? []) ?>;

let current = 0;
let selected = null;

function renderQuestion(){

    const q = QUESTIONS[current];

    document.getElementById("mc-app").innerHTML = `
        <div class="question">
            ${current+1}. ${q.question}
        </div>

        ${q.image ? `<img src="${q.image}" style="max-width:200px;margin-bottom:20px;">` : ""}

        <div class="options">
            ${q.options.map((opt,i)=>`
                <button class="option-btn" onclick="selectOption(${i})">
                    ${opt}
                </button>
            `).join("")}
        </div>

        <div class="controls">
            <button onclick="checkAnswer()">✅ Check</button>
            <button onclick="nextQuestion()">➡️</button>
        </div>

        <div id="feedback" class="feedback"></div>
    `;
}

function selectOption(i){
    selected = i;
    document.querySelectorAll(".option-btn").forEach(btn=>{
        btn.classList.remove("selected");
    });
    document.querySelectorAll(".option-btn")[i].classList.add("selected");
}

function checkAnswer(){

    const q = QUESTIONS[current];
    const feedback = document.getElementById("feedback");

    if(selected === null){
        feedback.innerHTML = "Select an option.";
        feedback.className = "feedback";
        return;
    }

    if(selected == q.correct){
        feedback.innerHTML = "Excellent!";
        feedback.className = "feedback correct";
    }else{
        feedback.innerHTML = "Try again.";
        feedback.className = "feedback wrong";
    }
}

function nextQuestion(){
    selected = null;

    if(current < QUESTIONS.length - 1){
        current++;
        renderQuestion();
    }else{
        document.getElementById("mc-app").innerHTML =
        `<h3>🎉 Completed!</h3>`;
    }
}

renderQuestion();

</script>

<?php
$content = ob_get_clean();
render_activity_viewer("Multiple Choice", "📝", $content);

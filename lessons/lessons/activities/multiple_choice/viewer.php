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

/* ===== CONTENEDOR BLANCO ===== */
.mc-wrapper{
    background:#f3f3f3;
    max-width:900px;
    margin:0 auto;
    padding:40px;
    border-radius:22px;
}

/* ===== GRID DOS COLUMNAS ===== */
.mc-grid{
    display:grid;
    grid-template-columns: 2fr 1fr;
    align-items:center;
    gap:40px;
}

/* ===== PREGUNTA ===== */
.mc-question{
    font-size:20px;
    font-weight:600;
    margin-bottom:25px;
}

/* ===== OPCIONES EN FILA ===== */
.mc-options{
    display:flex;
    gap:20px;
    flex-wrap:wrap;
}

.mc-option{
    background:#2f63c6;
    color:white;
    border:none;
    padding:14px 22px;
    border-radius:14px;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
    transition:0.2s;
}

.mc-option:hover{
    background:#1f5fbf;
}

.mc-option.selected{
    outline:3px solid #174a94;
}

/* ===== IMAGEN ===== */
.mc-image img{
    width:100%;
    max-width:220px;
    object-fit:contain;
}

/* ===== BOTONES (IGUAL DRAG & DROP) ===== */
.mc-actions{
    margin-top:30px;
    display:flex;
    gap:18px;
}

.btn-check{
    background:#1f5fbf;
    color:white;
    border:none;
    padding:12px 22px;
    border-radius:14px;
    font-weight:600;
    font-size:15px;
    cursor:pointer;
}

.btn-check:hover{
    background:#174a94;
}

.btn-next{
    width:52px;
    height:44px;
    background:#1f5fbf;
    color:white;
    border:none;
    border-radius:14px;
    font-size:18px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
}

.btn-next:hover{
    background:#174a94;
}

/* ===== FEEDBACK ===== */
.mc-feedback{
    margin-top:20px;
    font-weight:600;
}

.correct{
    color:#16a34a;
}

.wrong{
    color:#dc2626;
}

</style>

<div class="mc-wrapper" id="mc-app"></div>

<script>

const QUESTIONS = <?= json_encode($data ?? []) ?>;

let current = 0;
let selected = null;

function renderQuestion(){

    const q = QUESTIONS[current];
    const container = document.getElementById("mc-app");

    container.innerHTML = `
        <div class="mc-grid">

            <div>
                <div class="mc-question">
                    ${current+1}. ${q.question}
                </div>

                <div class="mc-options">
                    ${q.options.map((opt,i)=>`
                        <button class="mc-option"
                            onclick="selectOption(${i})">
                            ${opt}
                        </button>
                    `).join("")}
                </div>

                <div class="mc-actions">
                    <button class="btn-check" onclick="checkAnswer()">✓ Check</button>
                    <button class="btn-next" onclick="nextQuestion()">→</button>
                </div>

                <div id="feedback" class="mc-feedback"></div>
            </div>

            <div class="mc-image">
                ${q.image ? `<img src="${q.image}">` : ""}
            </div>

        </div>
    `;
}

function selectOption(i){
    selected = i;

    document.querySelectorAll(".mc-option").forEach(btn=>{
        btn.classList.remove("selected");
    });

    document.querySelectorAll(".mc-option")[i].classList.add("selected");
}

function checkAnswer(){

    const q = QUESTIONS[current];
    const feedback = document.getElementById("feedback");

    if(selected === null){
        feedback.innerHTML = "Select an option.";
        feedback.className = "mc-feedback";
        return;
    }

    if(selected == q.correct){
        feedback.innerHTML = "Excellent!";
        feedback.className = "mc-feedback correct";
    }else{
        feedback.innerHTML = "Try again.";
        feedback.className = "mc-feedback wrong";
    }
}

function nextQuestion(){
    selected = null;

    if(current < QUESTIONS.length - 1){
        current++;
        renderQuestion();
    }else{
        document.getElementById("mc-app").innerHTML =
        `<div class="mc-wrapper">
            <h2 style="text-align:center;">🎉 Completed!</h2>
        </div>`;
    }
}

renderQuestion();

</script>

<?php
$content = ob_get_clean();
render_activity_viewer("📝 Multiple Choice", "📝", $content);

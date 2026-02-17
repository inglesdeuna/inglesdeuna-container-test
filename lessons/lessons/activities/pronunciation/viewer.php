<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
   OBTENER ACTIVIDAD
========================= */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'pronunciation'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

if(empty($data)){
    die("No hay palabras para esta unidad");
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pronunciation Practice</title>

<style>
body{
    font-family: Arial;
    background:#eef6ff;
    padding:30px;
}

.back-btn{
    display:inline-block;
    background:#16a34a;
    color:white;
    padding:10px 18px;
    border-radius:12px;
    text-decoration:none;
    font-weight:bold;
    margin-bottom:20px;
}

h1{
    text-align:center;
    color:#0b5ed7;
    margin-bottom:5px;
}

.subtitle{
    text-align:center;
    color:#666;
    margin-bottom:30px;
}

.grid{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(220px,1fr));
    gap:20px;
}

.card{
    background:white;
    border-radius:18px;
    padding:20px;
    text-align:center;
    box-shadow:0 8px 20px rgba(0,0,0,0.05);
}

.card img{
    width:90px;
    height:90px;
    object-fit:contain;
    margin-bottom:15px;
}

.word{
    font-size:18px;
    font-weight:bold;
}

.phonetic{
    font-size:14px;
    color:#666;
}

.translation{
    font-size:14px;
    margin-top:6px;
}

.btn{
    margin:6px 4px;
    padding:8px 14px;
    border:none;
    border-radius:10px;
    background:#2563eb;
    color:white;
    cursor:pointer;
    font-size:13px;
}

.feedback{
    margin-top:10px;
    font-weight:bold;
    font-size:14px;
}

.good{ color:green; }
.bad{ color:crimson; }

.finish{
    text-align:center;
    margin-top:30px;
    font-size:18px;
    font-weight:bold;
    color:green;
}
</style>
</head>
<body>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>" class="back-btn">
    ↩ Back
</a>


<h1>Pronunciation Practice</h1>
<div class="subtitle">Listen and speak the correct word.</div>

<div class="grid">

<?php foreach($data as $item): ?>

<div class="card">

    <?php if(!empty($item["image"])): ?>
        <img src="<?= htmlspecialchars($item["image"]) ?>">
    <?php endif; ?>

    <div class="word"><?= htmlspecialchars($item["word"]) ?></div>

    <?php if(!empty($item["phonetic"])): ?>
        <div class="phonetic">/<?= htmlspecialchars($item["phonetic"]) ?>/</div>
    <?php endif; ?>

    <?php if(!empty($item["translation"])): ?>
        <div class="translation"><?= htmlspecialchars($item["translation"]) ?></div>
    <?php endif; ?>

    <div>
        <button class="btn" onclick="listenWord('<?= htmlspecialchars($item["word"]) ?>')">
            🔊 Listen
        </button>
        <button class="btn" onclick="speakWord(this,'<?= strtolower(htmlspecialchars($item["word"])) ?>')">
            🎤 Speak
        </button>
    </div>

    <div class="feedback"></div>

</div>

<?php endforeach; ?>

</div>

<div id="finishMessage" class="finish"></div>

<script>

let completed = 0;
const total = document.querySelectorAll(".card").length;

/* LISTEN */
function listenWord(word){
    const msg = new SpeechSynthesisUtterance(word);
    msg.lang = "en-US";
    speechSynthesis.speak(msg);
}

/* SPEAK + VALIDAR */
function speakWord(button, correctWord){

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if(!SpeechRecognition){
        return;
    }

    const recognition = new SpeechRecognition();
    recognition.lang = "en-US";
    recognition.start();

    const card = button.closest(".card");
    const feedback = card.querySelector(".feedback");

    recognition.onresult = function(event){
        let spoken = event.results[0][0].transcript.toLowerCase().trim();

        if(spoken === correctWord){
            feedback.textContent = "🌟 Excellent!";
            feedback.className = "feedback good";

            completed++;
            if(completed >= total){
                document.getElementById("finishMessage")
                    .textContent = "🏆 You finished all words!";
            }

        }else{
            feedback.textContent = "🔁 Try again!";
            feedback.className = "feedback bad";
        }
    };
}

</script>

</body>
</html>

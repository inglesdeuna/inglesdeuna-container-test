<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/_activity_viewer_template.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==============================
   LOAD DATA
============================== */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'pronunciation'
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

/* Word */
.word{
    font-size:30px;
    font-weight:bold;
    margin:20px 0;
}

/* Primary button style (same as external) */
.primary-btn{
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

.primary-btn:hover{
    background:#084298;
}

/* Controls */
.controls{
    margin-top:20px;
}
</style>

<div class="box">

<?php if(!empty($data)): ?>

<div id="word" class="word"></div>

<button class="primary-btn" onclick="playAudio()">🔊 Listen</button>

<div class="controls">
    <button class="primary-btn" onclick="nextWord()">➡️</button>
</div>

<?php else: ?>
<p>No pronunciation data available.</p>
<?php endif; ?>

</div>

<script>
const PRON_DATA = <?= json_encode($data ?? []) ?>;

let current = 0;

function renderWord(){
    if(PRON_DATA.length === 0) return;
    document.getElementById("word").innerText = PRON_DATA[current].word;
}

function playAudio(){
    if(PRON_DATA.length === 0) return;
    const audio = new Audio(PRON_DATA[current].audio);
    audio.play();
}

function nextWord(){
    if(PRON_DATA.length === 0) return;
    current++;
    if(current >= PRON_DATA.length){
        current = 0;
    }
    renderWord();
}

renderWord();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer("Pronunciation", "🔊", $content);

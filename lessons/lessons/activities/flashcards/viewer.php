<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unit missing');
}

$stmt = $pdo->prepare(
    "SELECT data
     FROM activities
     WHERE unit_id = :unit
       AND type = 'flashcards'
     LIMIT 1"
);
$stmt->execute(array('unit' => $unit));

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$raw = isset($row['data']) ? $row['data'] : '[]';
$decoded = json_decode($raw, true);
$data = is_array($decoded) ? $decoded : array();

if (count($data) === 0) {
    die('No hay flashcards para esta unidad');
}

ob_start();
?>
<style>
.flashcards-wrap{
    text-align:center;
}

.listen-wrapper{
    margin-bottom:15px;
}

button{
    padding:8px 16px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:bold;
}

.listen{ background:#0b5ed7; color:white;}
.next{ background:#28a745; color:white;}

.card-container{
    perspective:1000px;
    display:flex;
    justify-content:center;
}

.card{
    width:380px;
    height:420px;
    position:relative;
    transform-style:preserve-3d;
    transition:transform .6s;
    cursor:pointer;
}

.card.flip{
    transform:rotateY(180deg);
}

.side{
    position:absolute;
    width:100%;
    height:100%;
    backface-visibility:hidden;
    border-radius:20px;
    box-shadow:0 10px 25px rgba(0,0,0,.15);
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    padding:25px;
}

.front{ background:white; }

.back{
    background:#2f6fed;
    color:white;
    transform:rotateY(180deg);
    font-size:30px;
    font-weight:bold;
    text-align:center;
    word-break:break-word;
}

.front img{
    max-width:260px;
    max-height:260px;
    object-fit:contain;
    margin-bottom:20px;
}

.next-wrapper{
    position:absolute;
    bottom:20px;
}
</style>

<div class="flashcards-wrap">
    <div class="listen-wrapper">
        <button class="listen" id="listenBtn" onclick="speak(event)">🔊 Listen</button>
    </div>

    <div class="card-container">
        <div class="card" id="card">
            <div class="side front" id="front"></div>
            <div class="side back" id="back"></div>
        </div>
    </div>
</div>

<script>
const data = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;

let index = 0;

const front = document.getElementById('front');
const back = document.getElementById('back');
const card = document.getElementById('card');

function getText(item) {
    return item && typeof item.text === 'string' ? item.text : '';
}

function getImage(item) {
    return item && typeof item.image === 'string' ? item.image : '';
}

function loadCard() {
    const item = data[index] || {};
    const image = getImage(item);
    const text = getText(item);

    front.innerHTML = `
        ${image ? `<img src="${image}" alt="flashcard-image">` : ''}
        <div class="next-wrapper">
            <button class="next" onclick="nextCard(event)">Next ➜</button>
        </div>
    `;

    back.textContent = text;
}

function speak(event) {
    event.stopPropagation();

    speechSynthesis.cancel();

    const item = data[index] || {};
    const text = getText(item);

    if (!text) {
        return;
    }

    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = 'en-US';
    speechSynthesis.speak(utter);
}

function nextCard(event) {
    event.stopPropagation();
    card.classList.remove('flip');
    index += 1;

    if (index >= data.length) {
        index = 0;
    }

    loadCard();
}

card.addEventListener('click', function () {
    card.classList.toggle('flip');
});

loadCard();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Flashcards', '🧸', $content);

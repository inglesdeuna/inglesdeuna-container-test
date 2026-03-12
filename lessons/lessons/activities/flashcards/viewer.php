<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
if ($unit === '') {
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
:root{
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --title:#1f4ec9;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --shadow:0 8px 24px rgba(0,0,0,.08);
}

*{ box-sizing:border-box; }

body{
    margin:0;
    padding:24px;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
}

.activity-container{
    max-width:760px;
    margin:0 auto;
}

.activity-body{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:14px;
    box-shadow:var(--shadow);
    padding:24px;
}

.flashcards-wrap{ text-align:center; }

.listen-wrapper{ margin-bottom:18px; }

.flash-btn{
    display:inline-block;
    padding:8px 14px;
    border:none;
    border-radius:8px;
    background:var(--blue);
    color:#fff;
    font-weight:700;
    font-size:14px;
    cursor:pointer;
    transition:background .2s ease;
}
.flash-btn:hover{ background:var(--blue-hover); }

.card-container{
    perspective:1000px;
    display:flex;
    justify-content:center;
    position:relative;
}

.card{
    width:100%;
    max-width:420px;
    height:420px;
    position:relative;
    transform-style:preserve-3d;
    transition:transform .6s ease;
    cursor:pointer;
}

.card.flip{ transform:rotateY(180deg); }

.side{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    backface-visibility:hidden;
    border-radius:14px;
    border:1px solid var(--line);
    box-shadow:var(--shadow);
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    padding:24px;
    background:#fff;
}

.front img{
    max-width:260px;
    max-height:240px;
    object-fit:contain;
}

.back{ transform:rotateY(180deg); text-align:center; }

.back-word{
    font-size:28px;
    font-weight:700;
    color:var(--title);
    line-height:1.2;
    word-break:break-word;
}

.card-arrows{
    position:absolute;
    top:50%;
    left:0;
    right:0;
    display:flex;
    justify-content:space-between;
    padding:0 12px;
    transform:translateY(-50%);
}

.arrow-btn{
    background:none;
    border:none;
    font-size:28px;
    font-weight:bold;
    color:var(--blue);
    cursor:pointer;
    transition:color .2s ease;
}
.arrow-btn:hover{ color:var(--blue-hover); }

@media (max-width:768px){
    body{ padding:16px; }
    .activity-body{ padding:18px; }
    .card{ height:360px; }
    .front img{ max-width:210px; max-height:190px; }
    .back-word{ font-size:22px; }
}
</style>

<div class="activity-container">
    <div class="activity-body">
        <div class="flashcards-wrap">
            <div class="listen-wrapper">
                <button class="flash-btn" id="listenBtn" type="button" onclick="speak(event)">🔊 Listen</button>
            </div>

            <div class="card-container">
                <div class="card" id="card" role="button" tabindex="0" aria-label="Flashcard">
                    <div class="side front" id="front"></div>
                    <div class="side back" id="back"></div>
                    <div class="card-arrows">
                        <button class="arrow-btn" type="button" onclick="previousCard(event)">←</button>
                        <button class="arrow-btn" type="button" onclick="nextCard(event)">→</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const data = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let index = 0;

const front = document.getElementById('front');
const back = document.getElementById('back');
const card = document.getElementById('card');

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

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

    front.innerHTML = `${image ? `<img src="${escapeHtml(image)}" alt="flashcard-image">` : ''}`;
    back.innerHTML = `<div class="back-word">${escapeHtml(text || 'Sin texto')}</div>`;
}

function speak(event) {
    if (event) event.stopPropagation();
    if (!('speechSynthesis' in window)) return;

    window.speechSynthesis.cancel();
    const item = data[index] || {};
    const text = getText(item);
    if (!text) return;

    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = 'en-US';
    window.speechSynthesis.speak(utter);
}

function nextCard(event) {
    if (event) event.stopPropagation();
    card.classList.remove('flip');
    index = (index + 1) % data.length;
    loadCard();
}

function previousCard(event) {
    if (event) event.stopPropagation();
    card.classList.remove('flip');
    index = (index - 1 + data.length) % data.length;
    loadCard();
}

card.addEventListener('click', () => card.classList.toggle('flip'));
card.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        card.classList.toggle('flip');
    }
});

loadCard();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('', '', $content);

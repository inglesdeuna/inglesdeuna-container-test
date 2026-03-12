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
<link rel="stylesheet" href="/lessons/lessons/activities/assets/activity-layout.css">

<style>
.flashcards-wrap{
    text-align:center;
}

.flashcard-stage{
    max-width:720px;
    margin:0 auto;
}

.flashcard-toolbar{
    margin-bottom:18px;
    display:flex;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
}

.flashcard-toolbar .activity-btn{
    min-width:120px;
}

.card-container{
    perspective:1000px;
    display:flex;
    justify-content:center;
}

.card{
    width:100%;
    max-width:420px;
    height:460px;
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
    inset:0;
    backface-visibility:hidden;
    border-radius:20px;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    padding:24px;
    border:1px solid #dce4f0;
}

.front{
    background:#ffffff;
}

.back{
    background:#1f66cc;
    color:#ffffff;
    transform:rotateY(180deg);
    font-size:28px;
    font-weight:700;
    text-align:center;
    word-break:break-word;
    line-height:1.25;
}

.front img{
    max-width:280px;
    max-height:260px;
    object-fit:contain;
    margin-bottom:18px;
}

.front-label{
    font-size:14px;
    color:#5b6577;
    margin-bottom:16px;
}

.flashcard-footer{
    margin-top:20px;
    display:flex;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
}

.flashcard-meta{
    margin-top:16px;
    text-align:center;
    font-size:13px;
    color:#5b6577;
}

@media (max-width: 768px){
    .card{
        max-width:100%;
        height:400px;
    }

    .back{
        font-size:22px;
    }

    .front img{
        max-width:220px;
        max-height:220px;
    }
}
</style>

<div class="activity-container">
    <div class="activity-header">
        <h2 class="activity-title">🧸 Flashcards</h2>
        <p class="activity-instructions">
            Mira la imagen, escucha la palabra y haz clic en la tarjeta para voltearla.
        </p>
    </div>

    <div class="activity-body">
        <div class="activity-content">
            <div class="flashcards-wrap">
                <div class="flashcard-stage">
                    <div class="flashcard-toolbar">
                        <button class="activity-btn" id="listenBtn" type="button" onclick="speak(event)">🔊 Listen</button>
                    </div>

                    <div class="card-container">
                        <div class="card" id="card" role="button" tabindex="0" aria-label="Flashcard">
                            <div class="side front" id="front"></div>
                            <div class="side back" id="back"></div>
                        </div>
                    </div>

                    <div class="flashcard-footer">
                        <button class="activity-btn" type="button" onclick="previousCard(event)">← Previous</button>
                        <button class="activity-btn" type="button" onclick="nextCard(event)">Next →</button>
                    </div>

                    <div class="flashcard-meta" id="flashcardMeta"></div>
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
const flashcardMeta = document.getElementById('flashcardMeta');

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

    front.innerHTML = `
        ${image ? `<img src="${escapeHtml(image)}" alt="flashcard-image">` : ''}
        <div class="front-label">Haz clic en la tarjeta para ver la respuesta</div>
    `;

    back.textContent = text || 'Sin texto';
    flashcardMeta.textContent = `Tarjeta ${index + 1} de ${data.length}`;
}

function speak(event) {
    if (event) {
        event.stopPropagation();
    }

    if (!('speechSynthesis' in window)) {
        return;
    }

    window.speechSynthesis.cancel();

    const item = data[index] || {};
    const text = getText(item);

    if (!text) {
        return;
    }

    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = 'en-US';
    window.speechSynthesis.speak(utter);
}

function nextCard(event) {
    if (event) {
        event.stopPropagation();
    }

    card.classList.remove('flip');
    index += 1;

    if (index >= data.length) {
        index = 0;
    }

    loadCard();
}

function previousCard(event) {
    if (event) {
        event.stopPropagation();
    }

    card.classList.remove('flip');
    index -= 1;

    if (index < 0) {
        index = data.length - 1;
    }

    loadCard();
}

card.addEventListener('click', function () {
    card.classList.toggle('flip');
});

card.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        card.classList.toggle('flip');
    }
});

loadCard();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Flashcards', '🧸', $content);

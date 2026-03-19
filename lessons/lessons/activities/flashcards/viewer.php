<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_flashcards_title(): string
{
    return 'Flashcards';
}

function normalize_flashcards_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_flashcards_title();
}

function normalize_flashcards_payload($rawData): array
{
    $default = array(
        'title' => default_flashcards_title(),
        'cards' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $cardsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['cards']) && is_array($decoded['cards'])) {
        $cardsSource = $decoded['cards'];
    }

    $cards = array();

    if (is_array($cardsSource)) {
        foreach ($cardsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $cards[] = array(
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('flashcard_'),
                'text' => isset($item['text']) ? trim((string) $item['text']) : '',
                'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            );
        }
    }

    return array(
        'title' => normalize_flashcards_title($title),
        'cards' => $cards,
    );
}

function load_flashcards_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'flashcards'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'flashcards'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'flashcards'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return array(
            'title' => default_flashcards_title(),
            'cards' => array(),
        );
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_flashcards_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'title' => normalize_flashcards_title((string) $payload['title']),
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_flashcards_activity($pdo, $unit, $activityId);
$data = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_flashcards_title();

if (count($data) === 0) {
    die('No hay flashcards para esta unidad');
}

ob_start();
?>
<style>
:root{
    --bg:#eef2f7;
    --card-front:#ffffff;
    --card-back:#7c3aed;
    --text:#1f2937;
    --white:#ffffff;
    --arrow:#4f46e5;
    --arrow-hover:#312e81;
    --shadow:0 10px 28px rgba(0,0,0,.14);
}

*{ box-sizing:border-box; }

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
}

.flashcards-wrap{
    max-width:980px;
    margin:0 auto;
    text-align:center;
    padding:10px 0 4px;
}

.flashcards-stage{
    position:relative;
    max-width:820px;
    margin:0 auto;
    padding:0 70px;
}

.card-container{
    perspective:1200px;
    display:flex;
    justify-content:center;
    align-items:center;
}

.card{
    width:100%;
    max-width:460px;
    height:460px;
    position:relative;
    transform-style:preserve-3d;
    transition:transform .65s ease;
    cursor:pointer;
}

.card.flip{
    transform:rotateY(180deg);
}

.side{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    backface-visibility:hidden;
    border-radius:22px;
    box-shadow:var(--shadow);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.front{
    background:var(--card-front);
    border:1px solid #e5e7eb;
    flex-direction:column;
}

.front img{
    max-width:300px;
    max-height:280px;
    object-fit:contain;
    display:block;
}

.back{
    transform:rotateY(180deg);
    background:var(--card-back);
    color:var(--white);
    text-align:center;
}

.back-word{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:100%;
    padding:10px;
    font-family:"Comic Sans MS", "Trebuchet MS", cursive, sans-serif;
    font-size:42px;
    font-weight:700;
    line-height:1.15;
    word-break:break-word;
    text-align:center;
}

.listen-row{
    margin-top:16px;
}

.flash-btn{
    display:inline-block;
    padding:10px 18px;
    border:none;
    border-radius:999px;
    background:#16a34a;
    color:#fff;
    font-weight:700;
    font-size:15px;
    cursor:pointer;
    box-shadow:0 8px 18px rgba(0,0,0,.12);
}

.arrow-btn{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:56px;
    height:56px;
    border:none;
    border-radius:999px;
    background:#ffffff;
    color:var(--arrow);
    font-size:28px;
    font-weight:700;
    cursor:pointer;
    box-shadow:0 10px 24px rgba(0,0,0,.14);
    transition:transform .18s ease, color .18s ease;
}

.arrow-btn:hover{
    transform:translateY(-50%) scale(1.05);
    color:var(--arrow-hover);
}

.arrow-left{
    left:0;
}

.arrow-right{
    right:0;
}

.flip-hint{
    margin-top:10px;
    font-size:14px;
    color:#64748b;
}

@media (max-width:768px){
    .flashcards-stage{
        padding:0 56px;
    }

    .card{
        max-width:360px;
        height:360px;
    }

    .front img{
        max-width:220px;
        max-height:210px;
    }

    .back-word{
        font-size:30px;
    }

    .arrow-btn{
        width:48px;
        height:48px;
        font-size:24px;
    }
}
</style>

<div class="flashcards-wrap">
    <div class="flashcards-stage">
        <button class="arrow-btn arrow-left" type="button" onclick="previousCard(event)" aria-label="Previous card">❮</button>

        <div class="card-container">
            <div class="card" id="card" role="button" tabindex="0" aria-label="Flashcard">
                <div class="side front" id="front"></div>
                <div class="side back" id="back"></div>
            </div>
        </div>

        <button class="arrow-btn arrow-right" type="button" onclick="nextCard(event)" aria-label="Next card">❯</button>
    </div>

    <div class="listen-row">
        <button class="flash-btn" id="listenBtn" type="button" onclick="speak(event)">🔊 Listen</button>
    </div>

    <div class="flip-hint">Tap or click the card to flip it.</div>
</div>

<script>
const data = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let index = 0;

const front = document.getElementById('front');
const back = document.getElementById('back');
const card = document.getElementById('card');

function escapeHtml(value) {
    return String(value || '')
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
        ${image ? `<img src="${escapeHtml(image)}" alt="flashcard-image">` : `<div style="font-size:20px;color:#64748b;">No image</div>`}
    `;

    back.innerHTML = `<div class="back-word">${escapeHtml(text || 'No text')}</div>`;
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
    utter.rate = 0.9;
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
render_activity_viewer($viewerTitle, '🃏', $content);

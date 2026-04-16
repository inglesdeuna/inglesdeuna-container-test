<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
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
                'english_text' => isset($item['english_text']) ? trim((string) $item['english_text']) : '',
                'spanish_text' => isset($item['spanish_text']) ? trim((string) $item['spanish_text']) : '',
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
    die('No flashcards found for this unit');
}

ob_start();
?>
<style>
:root {
    --page-bg: #0f172a;
    --panel-bg: #f8fafc;
    --panel-alt: #111827;
    --panel-border: #e2e8f0;
    --text-dark: #0f172a;
    --text-light: #f8fafc;
    --accent: #0ea5e9;
    --accent-strong: #0284c7;
    --shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    color: var(--text-dark);
    background: radial-gradient(circle at top left, rgba(14, 165, 233, 0.16), transparent 28%),
                linear-gradient(180deg, #e2e8f0 0%, #f8fafc 45%, #e0f2fe 100%);
}

.flashcards-wrap {
    width: 100%;
    max-width: 100%;
    min-height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 18px 32px;
}

.flashcards-intro,
.flashcards-stage,
.flashcards-controls,
.progress-text,
.flip-hint {
    width: min(760px, 100%);
    margin-left: auto;
    margin-right: auto;
}

.viewer-header {
    display: none !important;
}

.flashcards-intro {
    margin-bottom: 22px;
    padding: 24px 28px;
    border-radius: 28px;
    border: 1px solid rgba(14, 165, 233, 0.22);
    background: rgba(255, 255, 255, 0.96);
    box-shadow: var(--shadow);
}

.flashcards-intro h2 {
    margin: 0 0 10px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(32px, 4vw, 44px);
    line-height: 1.05;
    letter-spacing: -0.03em;
    color: var(--text-dark);
}

.flashcards-intro p {
    margin: 0;
    color: #334155;
    font-size: 17px;
    line-height: 1.75;
}

.flashcards-stage {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0;
}

.card-container {
    width: min(760px, 100%);
    max-width: 760px;
    perspective: 1400px;
    margin: 0 auto;
}

.card {
    width: 100%;
    min-height: 360px;
    max-height: 440px;
    position: relative;
    transform-style: preserve-3d;
    transition: transform 0.58s ease;
    border-radius: 28px;
    box-shadow: var(--shadow);
    cursor: pointer;
    outline: none;
}

.card.flip {
    transform: rotateY(180deg);
}

.side {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    backface-visibility: hidden;
    border-radius: 28px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 28px 36px;
}

.front {
    background: var(--panel-bg);
    color: var(--text-dark);
    border: 1px solid var(--panel-border);
}

.back {
    background: var(--panel-alt);
    color: var(--text-light);
    transform: rotateY(180deg);
    border: 1px solid rgba(255, 255, 255, 0.12);
}

.panel-label {
    text-transform: uppercase;
    letter-spacing: 0.22em;
    font-size: 0.85rem;
    font-weight: 800;
    opacity: 0.88;
}

.panel-copy {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-size: clamp(2rem, 4.5vw, 4.8rem);
    font-weight: 800;
    line-height: 1.02;
    word-break: break-word;
    padding: 12px 8px;
}

.listen-chip {
    align-self: center;
    padding: 14px 22px;
    border-radius: 999px;
    border: none;
    background: var(--accent);
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 16px 36px rgba(14, 165, 233, 0.24);
    transition: transform 0.16s ease, filter 0.16s ease;
}

.listen-chip:hover,
.listen-chip:focus {
    transform: translateY(-1px);
    filter: brightness(1.05);
}

.arrow-btn {
    width: 56px;
    height: 56px;
    border: none;
    border-radius: 999px;
    background: #fff;
    color: var(--accent-strong);
    font-size: 28px;
    font-weight: 700;
    box-shadow: var(--shadow);
    cursor: pointer;
    display: grid;
    place-items: center;
    transition: transform 0.18s ease, color 0.18s ease;
}

.arrow-btn:hover {
    transform: scale(1.05);
}

.arrow-left {
    flex-shrink: 0;
}

.arrow-right {
    flex-shrink: 0;
}

.controls-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 12px;
    margin-bottom: 16px;
}

.flashcards-controls {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 12px;
    margin-bottom: 16px;
}

.control-btn {
    min-width: 140px;
    border: none;
    border-radius: 999px;
    padding: 14px 20px;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    background: #fff;
    color: var(--text-dark);
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
    transition: transform 0.16s ease, filter 0.16s ease;
}

.control-btn:hover,
.control-btn:focus {
    transform: translateY(-1px);
    filter: brightness(1.04);
}

.progress-text {
    margin-top: 16px;
    text-align: center;
    font-size: 1rem;
    color: #334155;
    font-weight: 700;
}

.flip-hint {
    margin-top: 10px;
    font-size: 14px;
    text-align: center;
    color: #475569;
}

.completed-screen {
    display: none;
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
    padding: 40px 20px;
}

.completed-screen.active {
    display: block;
}

.completed-icon {
    font-size: 72px;
    margin-bottom: 16px;
}

.completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(32px, 4vw, 40px);
    font-weight: 800;
    margin: 0 0 14px;
    color: var(--accent-strong);
}

.completed-text {
    font-size: 1rem;
    line-height: 1.8;
    color: #475569;
    margin: 0 0 22px;
}

.completed-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 14px 28px;
    border: none;
    border-radius: 999px;
    background: var(--accent-strong);
    color: #fff;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 18px 44px rgba(2, 132, 199, 0.26);
}

.completed-button:hover {
    filter: brightness(1.05);
    transform: translateY(-1px);
}

@media (max-width: 960px) {
    .flashcards-stage {
        flex-direction: column;
        padding: 0;
    }

    .arrow-btn {
        width: 52px;
        height: 52px;
        font-size: 24px;
    }
}

@media (max-width: 720px) {
    .card {
        min-height: 320px;
    }

    .panel-copy {
        font-size: clamp(1.6rem, 6vw, 3.6rem);
    }

    .controls-row {
        gap: 10px;
    }
}
</style>

<div class="flashcards-wrap">
    <div class="flashcards-intro">
        <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p>Advanced English ↔ Spanish flashcards designed for classroom projection. Flip between formal expressions and nuanced translations while using dedicated listen buttons for each side.</p>
    </div>

    <div id="cards-stage" class="flashcards-stage">
        <div class="card-container">
            <div class="card" id="card" role="button" tabindex="0" aria-label="Flashcard">
                <div class="side front" id="front"></div>
                <div class="side back" id="back"></div>
            </div>
        </div>
    </div>

    <div class="controls-row flashcards-controls">
        <button class="control-btn" type="button" onclick="previousCard(event)">← Previous</button>
        <button class="control-btn" id="flipBtn" type="button" onclick="flipCard(event)">🔁 Flip card</button>
        <button class="control-btn" type="button" onclick="nextCard(event)">Next →</button>
    </div>

    <div class="progress-text">
        Card <strong><span id="currentIndex">1</span></strong> of <strong><span id="totalCards"><?= count($data) ?></span></strong>
    </div>

    <div class="flip-hint">Click the card or press Enter/Space to reveal the opposite language side.</div>

    <div id="completed-container" class="completed-screen">
        <div class="completed-icon">✅</div>
        <h2 class="completed-title">Completed</h2>
        <p class="completed-text">You've reviewed all flashcards. Excellent work with advanced vocabulary and idiomatic language.</p>
        <button class="completed-button" onclick="goBackToCards()">Back to Cards</button>
    </div>
</div>

<script>
const data = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let index = 0;
let isCompleted = false;

const front = document.getElementById('front');
const back = document.getElementById('back');
const card = document.getElementById('card');
const cardsStage = document.getElementById('cards-stage');
const completedContainer = document.getElementById('completed-container');

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
    const text = getText(item).trim();
    const image = getImage(item).trim();

    if (image) {
        front.innerHTML = `
            <div class="panel-label">Flashcard</div>
            <div class="panel-copy"><img src="${escapeHtml(image)}" alt="Flashcard image" class="card-image"></div>
            <button type="button" class="listen-chip" data-text="${escapeHtml(text)}" data-lang="en-US">🔊 Listen</button>
        `;
    } else {
        front.innerHTML = `
            <div class="panel-label">Flashcard</div>
            <div class="panel-copy">${escapeHtml(text || 'No text available')}</div>
            <button type="button" class="listen-chip" data-text="${escapeHtml(text)}" data-lang="en-US">🔊 Listen</button>
        `;
    }

    back.innerHTML = `
        <div class="panel-label">Answer</div>
        <div class="panel-copy">${escapeHtml(text || 'No text available')}</div>
        <button type="button" class="listen-chip" data-text="${escapeHtml(text)}" data-lang="en-US">🔊 Listen</button>
    `;

    document.getElementById('currentIndex').textContent = String(index + 1);
    document.getElementById('totalCards').textContent = String(data.length);
    card.classList.remove('flip');
}

function speakText(text, lang) {
    if (!text || !('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();
    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = lang;
    utter.rate = 0.92;
    utter.pitch = 1;
    window.speechSynthesis.speak(utter);
}

function showCompleted() {
    isCompleted = true;
    cardsStage.style.display = 'none';
    document.querySelector('.flip-hint').style.display = 'none';
    completedContainer.classList.add('active');
}

function goBackToCards() {
    isCompleted = false;
    index = 0;
    cardsStage.style.display = 'block';
    document.querySelector('.flip-hint').style.display = 'block';
    completedContainer.classList.remove('active');
    card.classList.remove('flip');
    loadCard();
}

function nextCard(event) {
    if (event) event.stopPropagation();
    if (isCompleted) return;
    
    card.classList.remove('flip');
    
    if (index >= data.length - 1) {
        showCompleted();
    } else {
        index = index + 1;
        loadCard();
    }
}

function previousCard(event) {
    if (event) event.stopPropagation();
    if (isCompleted) return;
    
    card.classList.remove('flip');
    index = (index - 1 + data.length) % data.length;
    loadCard();
}

function flipCard(event) {
    if (event) event.stopPropagation();
    if (isCompleted) return;
    card.classList.toggle('flip');
}

cardsStage.addEventListener('click', function (event) {
    const button = event.target.closest('.listen-chip');
    if (!button) return;
    event.stopPropagation();

    const text = button.dataset.text || '';
    const lang = button.dataset.lang || 'en-US';
    speakText(text, lang);
});

card.addEventListener('click', function () {
    if (!isCompleted) {
        card.classList.toggle('flip');
    }
});

card.addEventListener('keydown', function (event) {
    if (isCompleted) return;
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

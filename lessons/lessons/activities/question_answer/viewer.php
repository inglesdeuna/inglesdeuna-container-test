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

function default_qa_title(): string
{
    return 'Questions & Answers';
}

function normalize_qa_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_qa_title();
}

function normalize_qa_payload($rawData): array
{
    $default = array(
        'title' => default_qa_title(),
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
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('qa_'),
                'question' => isset($item['question']) ? trim((string) $item['question']) : '',
                'answer' => isset($item['answer']) ? trim((string) $item['answer']) : '',
            );
        }
    }

    return array(
        'title' => normalize_qa_title($title),
        'cards' => $cards,
    );
}

function load_qa_activity(PDO $pdo, string $unit, string $activityId): array
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
               AND type = 'question_answer'
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
               AND type = 'question_answer'
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
               AND type = 'question_answer'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return array(
            'title' => default_qa_title(),
            'cards' => array(),
        );
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_qa_payload($rawData);

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
        'title' => normalize_qa_title((string) $payload['title']),
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_qa_activity($pdo, $unit, $activityId);
$data = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_qa_title();

if (count($data) === 0) {
    die('No questions found for this activity');
}

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

:root {
    --page-bg: #e6f6f4;
    --panel-bg: #0f766e;
    --panel-alt: #115e59;
    --panel-border: rgba(255, 255, 255, 0.18);
    --text-dark: #ffffff;
    --text-light: #ffffff;
    --accent: #14b8a6;
    --accent-strong: #0f766e;
    --shadow: 0 24px 60px rgba(15, 23, 42, .14);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    color: var(--text-dark);
    background: var(--page-bg);
    padding: 24px 18px 32px;
}

.qa-wrap {
    width: 100%;
    max-width: 100%;
    min-height: calc(100vh - 120px);
    margin: 0 auto;
    padding: 10px 0 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.viewer-header {
    display: none !important;
}

.qa-intro {
    width: min(760px, 100%);
    margin: 0 auto 16px;
    padding: 20px 24px;
    border-radius: 24px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: linear-gradient(160deg, #14b8a6 0%, #0f766e 56%, #115e59 100%);
    box-shadow: var(--shadow);
}

.qa-intro {
    display: none;
}

.qa-intro h2 {
    margin: 0 0 10px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(28px, 3.2vw, 36px);
    font-weight: 700;
    color: #ffffff;
}

.qa-intro p {
    margin: 0;
    color: rgba(255, 255, 255, .92);
    font-size: 15px;
    line-height: 1.6;
}

.qa-stage {
    position: relative;
    width: min(760px, 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 18px;
    padding: 0;
}

.card-container {
    width: 100%;
    max-width: 760px;
    perspective: 1400px;
    padding: 18px;
    background: #d2ebe8;
    border: 1px solid #bae6e1;
    border-radius: 36px;
}

.card {
    width: 100%;
    min-height: 360px;
    max-height: min(68vh, 520px);
    position: relative;
    transform-style: preserve-3d;
    transition: transform 0.58s ease;
    border-radius: 28px;
    box-shadow: 0 28px 72px rgba(15, 23, 42, 0.2);
    cursor: pointer;
    outline: none;
}

.card.reveal {
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
    background: linear-gradient(160deg, #0f766e 0%, #0b5f59 100%);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.16);
}

.back {
    background: linear-gradient(160deg, #0ea5a2 0%, #0f766e 100%);
    color: #ffffff;
    transform: rotateY(180deg);
    border: 1px solid rgba(255, 255, 255, 0.18);
}

.panel-label {
    text-transform: uppercase;
    letter-spacing: 0.22em;
    font-size: 0.8rem;
    font-weight: 800;
    opacity: 0.82;
    color: rgba(255, 255, 255, .8);
}

.panel-copy {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-size: clamp(1.5rem, 3.9vw, 3.2rem);
    font-weight: 800;
    line-height: 1.24;
    word-break: break-word;
    padding: 12px 8px;
    color: #ffffff;
}

.listen-chip {
    align-self: center;
    padding: 12px 18px;
    border-radius: 999px;
    border: none;
    background: #ffffff;
    color: #0f766e;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 14px 30px rgba(8, 47, 73, .22);
    transition: transform 0.16s ease, filter 0.16s ease;
}

.side .listen-chip {
    display: none;
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
    background: #ffffff;
    color: #0f766e;
    font-size: 28px;
    font-weight: 700;
    box-shadow: 0 12px 26px rgba(15, 23, 42, .14);
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
    margin-top: 18px;
    width: min(760px, 100%);
}

.control-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 142px;
    border: none;
    border-radius: 999px;
    padding: 11px 18px;
    font-size: 14px;
    font-weight: 800;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    line-height: 1;
    cursor: pointer;
    background: linear-gradient(180deg, #14b8a6 0%, #0f766e 100%);
    color: #fff;
    box-shadow: 0 12px 26px rgba(15, 118, 110, .3);
    transition: transform 0.15s ease, filter 0.15s ease;
}

.control-btn:hover,
.control-btn:focus {
    transform: translateY(-1px);
    filter: brightness(1.04);
}

.progress-text {
    margin-top: 14px;
    text-align: center;
    font-size: 1rem;
    color: #0f766e;
    font-weight: 700;
    width: min(760px, 100%);
}

.reveal-hint {
    margin-top: 10px;
    font-size: 14px;
    text-align: center;
    color: #115e59;
    width: min(760px, 100%);
}

.progress-text,
.reveal-hint {
    display: none;
}

.reveal-hint:empty {
    display: none !important;
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
    color: #0f766e;
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
    padding: 10px 14px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(180deg, #3d73ee 0%, #2563eb 100%);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 10px 22px rgba(37, 99, 235, .28);
    transition: transform .18s ease, filter .18s ease;
}

.completed-button:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

@media (max-width: 960px) {
    .qa-stage {
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
        font-size: clamp(1.2rem, 5vw, 3rem);
    }

    .controls-row {
        gap: 10px;
    }
}
</style>

<div class="qa-wrap">
    <div id="qa-stage" class="qa-stage">
        <button class="arrow-btn arrow-left" type="button" onclick="previousCard(event)" aria-label="Previous question">❮</button>

        <div class="card-container">
            <div class="card" id="card" role="button" tabindex="0" aria-label="Question card">
                <div class="side front" id="front"></div>
                <div class="side back" id="back"></div>
            </div>
        </div>

        <button class="arrow-btn arrow-right" type="button" onclick="nextCard(event)" aria-label="Next question">❯</button>
    </div>

    <div class="controls-row">
        <button class="control-btn" type="button" onclick="previousCard(event)">← Previous</button>
        <button class="control-btn" type="button" onclick="listenCurrent(event)">🔊 Listen</button>
        <button class="control-btn" type="button" onclick="nextCard(event)">Next →</button>
    </div>

    <div class="progress-text">
        <span id="currentIndex">1</span>
        <span id="totalCards"><?= count($data) ?></span>
    </div>

    <div class="reveal-hint"></div>

    <div id="completed-container" class="completed-screen">
        <div class="completed-icon">✅</div>
        <h2 class="completed-title">Completed</h2>
        <p class="completed-text">You've finished all the questions. Great effort on your fluency practice!</p>
        <button class="completed-button" onclick="goBackToCards()">Back</button>
    </div>
</div>

<script>
const data = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let index = 0;
let isCompleted = false;

const front = document.getElementById('front');
const back = document.getElementById('back');
const card = document.getElementById('card');
const qaStage = document.getElementById('qa-stage');
const controlsRow = document.querySelector('.controls-row');
const completedContainer = document.getElementById('completed-container');

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getQuestion(item) {
    return item && typeof item.question === 'string' ? item.question : '';
}

function getAnswer(item) {
    return item && typeof item.answer === 'string' ? item.answer : '';
}

function loadCard() {
    const item = data[index] || {};
    const question = getQuestion(item).trim();
    const answer = getAnswer(item).trim();

    front.innerHTML = `
        <div class="panel-copy">${escapeHtml(question || 'No question available')}</div>
        <button type="button" class="listen-chip" data-speaker="question">🔊 Listen Question</button>
    `;

    back.innerHTML = `
        <div class="panel-copy">${escapeHtml(answer || 'No answer available')}</div>
        <button type="button" class="listen-chip" data-speaker="answer">🔊 Listen Answer</button>
    `;

    document.getElementById('currentIndex').textContent = String(index + 1);
    document.getElementById('totalCards').textContent = String(data.length);
    card.classList.remove('reveal');
}

function speakText(text, lang) {
    if (!text || !('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();
    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = lang || 'en-US';
    utter.rate = 0.92;
    window.speechSynthesis.speak(utter);
}

function showCompleted() {
    isCompleted = true;
    qaStage.style.display = 'none';
    if (controlsRow) {
        controlsRow.style.display = 'none';
    }
    document.querySelector('.reveal-hint').style.display = 'none';
    completedContainer.classList.add('active');
}

function goBackToCards() {
    isCompleted = false;
    index = 0;
    qaStage.style.display = 'flex';
    if (controlsRow) {
        controlsRow.style.display = 'flex';
    }
    document.querySelector('.reveal-hint').style.display = 'block';
    completedContainer.classList.remove('active');
    card.classList.remove('reveal');
    loadCard();
}

function nextCard(event) {
    if (event) event.stopPropagation();
    if (isCompleted) return;

    card.classList.remove('reveal');

    if (index >= data.length - 1) {
        showCompleted();
    } else {
        index += 1;
        loadCard();
    }
}

function previousCard(event) {
    if (event) event.stopPropagation();
    if (isCompleted) return;

    card.classList.remove('reveal');
    index = (index - 1 + data.length) % data.length;
    loadCard();
}

function listenCurrent(event) {
    if (event) event.stopPropagation();
    if (isCompleted) return;

    const item = data[index] || {};
    const visibleText = card.classList.contains('reveal') ? getAnswer(item) : getQuestion(item);
    speakText(visibleText, 'en-US');
}

qaStage.addEventListener('click', function (event) {
    const button = event.target.closest('.listen-chip');
    if (!button) return;
    event.stopPropagation();

    const item = data[index] || {};
    const speaker = button.dataset.speaker || 'question';
    const text = speaker === 'answer' ? getAnswer(item) : getQuestion(item);
    speakText(text, 'en-US');
});

card.addEventListener('click', function (event) {
    if (!isCompleted && !event.target.closest('.listen-chip')) {
        card.classList.toggle('reveal');
    }
});

card.addEventListener('keydown', function (event) {
    if (isCompleted) return;
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        card.classList.toggle('reveal');
    }
});

loadCard();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '❓', $content);

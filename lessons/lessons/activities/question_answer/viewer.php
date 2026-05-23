<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function qa_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;

    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function qa_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = qa_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit_id'])) return (string) $row['unit_id'];
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit FROM activities WHERE id = :id LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit'])) return (string) $row['unit'];
    }

    return '';
}

function qa_default_title(): string
{
    return 'Questions & Answers';
}

function qa_normalize_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : qa_default_title();
}

function qa_normalize_payload($rawData): array
{
    $default = array('title' => qa_default_title(), 'voice_id' => 'nzFihrBIvB34imQBuxub', 'cards' => array());
    if ($rawData === null || $rawData === '') return $default;

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title = '';
    $cardsSource = $decoded;

    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['cards']) && is_array($decoded['cards'])) $cardsSource = $decoded['cards'];

    $cards = array();
    foreach ($cardsSource as $item) {
        if (!is_array($item)) continue;
        $cards[] = array(
            'id'       => isset($item['id'])       ? trim((string) $item['id'])       : uniqid('qa_'),
            'question' => isset($item['question']) ? trim((string) $item['question']) : '',
            'answer'   => isset($item['answer'])   ? trim((string) $item['answer'])   : '',
        );
    }

    return array(
        'title' => qa_normalize_title($title),
        'voice_id' => trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'cards' => $cards
    );
}

function qa_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = qa_columns($pdo);
    $selectFields = array('id');

    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'question_answer' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'question_answer' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'question_answer' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return array('title' => qa_default_title(), 'cards' => array());

    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];

    $payload = qa_normalize_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);

    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return array(
        'title' => qa_normalize_title((string) $payload['title']),
        'voice_id' => isset($payload['voice_id']) ? (string) $payload['voice_id'] : 'nzFihrBIvB34imQBuxub',
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') $unit = qa_resolve_unit($pdo, $activityId);

$activity = qa_load_activity($pdo, $unit, $activityId);
$cards = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : qa_default_title();

if (count($cards) === 0) die('No questions found');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --fc-orange: #F97316;
    --fc-purple: #7F77DD;
    --fc-purple-dark: #534AB7;
    --fc-muted: #9B94BE;
    --fc-soft: #F4F2FD;
    --fc-border: #ECE9FA;
}

* { box-sizing: border-box; }
html, body { width: 100%; margin: 0; padding: 0; background: #F8F7FE; font-family: 'Nunito', sans-serif; }

.qa-premium-shell {
    width: 100%;
    min-height: 100vh;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #F8F7FE;
}

.qa-premium-app {
    width: min(700px, 100%);
    margin: 0 auto;
}

.qa-premium-title-panel {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}

.qa-premium-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.qa-premium-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(28px, 5vw, 48px);
    font-weight: 600;
    color: var(--fc-orange);
    margin: 0;
    line-height: 1;
}

.qa-premium-subtitle {
    margin: 8px 0 0;
    color: var(--fc-muted);
    font-size: 14px;
    font-weight: 700;
}

.qa-premium-board {
    background: #fff;
    border: 1px solid var(--fc-border);
    border-radius: 28px;
    padding: clamp(16px, 2.4vw, 24px);
    box-shadow: 0 8px 40px rgba(127,119,221,.12);
    margin-bottom: 14px;
}

.qa-premium-progress-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}

.qa-premium-progress-track {
    flex: 1;
    height: 10px;
    background: var(--fc-soft);
    border-radius: 999px;
    overflow: hidden;
}

.qa-premium-progress-fill {
    height: 100%;
    width: 0;
    background: linear-gradient(90deg, var(--fc-orange), var(--fc-purple));
    border-radius: 999px;
    transition: width .35s;
}

.qa-premium-progress-count {
    min-width: 92px;
    text-align: center;
    padding: 6px 10px;
    border-radius: 999px;
    background: var(--fc-purple);
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

.qa-premium-card-wrap {
    position: relative;
}

.qa-premium-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 42px;
    height: 42px;
    border-radius: 10px;
    border: 1px solid #D8D1F4;
    background: #fff;
    color: var(--fc-purple-dark);
    font-size: 20px;
    font-weight: 900;
    cursor: pointer;
    display: grid;
    place-items: center;
    box-shadow: 0 3px 10px rgba(127,119,221,.14);
    z-index: 2;
}

.qa-premium-arrow-left { left: 10px; }
.qa-premium-arrow-right { right: 10px; }

.qa-premium-card {
    perspective: 1200px;
    min-height: clamp(240px, 36vh, 360px);
    cursor: pointer;
    outline: none;
    width: 100%;
}

.qa-premium-card-inner {
    position: relative;
    width: 100%;
    height: 100%;
    min-height: inherit;
    transform-style: preserve-3d;
    transition: transform .65s cubic-bezier(.2,.8,.2,1);
}

.qa-premium-card.is-flipped .qa-premium-card-inner {
    transform: rotateY(180deg);
}

.qa-premium-face {
    position: absolute;
    inset: 0;
    border-radius: 18px;
    backface-visibility: hidden;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 16px 22px;
    border: 1px solid var(--fc-border);
    background: var(--fc-soft);
}

.qa-premium-back {
    transform: rotateY(180deg);
    background: #FFF7F0;
    border-color: #FCDDBF;
}

.qa-premium-label {
    position: absolute;
    top: 12px;
    left: 14px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.qa-premium-front .qa-premium-label {
    background: #EEEDFE;
    color: var(--fc-purple-dark);
}

.qa-premium-back .qa-premium-label {
    background: #FFF0E6;
    color: #C2580A;
}

.qa-premium-text {
    width: 100%;
    max-width: 100%;
    max-height: calc(100% - 64px);
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(18px, 3.1vw, 34px);
    font-weight: 600;
    line-height: 1.22;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--fc-purple-dark);
    overflow: auto;
    overflow-wrap: break-word;
    word-break: break-word;
    padding: 0 8px;
    scrollbar-width: thin;
}

.qa-premium-back .qa-premium-text {
    color: var(--fc-orange);
}

.qa-premium-hint {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    color: var(--fc-muted);
    font-size: 11px;
    font-weight: 800;
    text-align: center;
}

.qa-premium-actions {
    margin-top: 12px;
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}

.qa-premium-btn {
    border: 0;
    border-radius: 10px;
    padding: 12px 18px;
    min-width: 110px;
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    box-shadow: 0 6px 18px rgba(127,119,221,.15);
    transition: .18s;
}

.qa-premium-btn:hover { transform: translateY(-1px); }

.qa-premium-btn-blue { background: var(--fc-orange); }
.qa-premium-btn-pink { background: var(--fc-purple); }
.qa-premium-btn-teal { background: #1D9E75; }

.qa-premium-completed {
    display: none;
    width: min(680px, 100%);
    margin: 24px auto 0;
    text-align: center;
    padding: clamp(28px, 5vw, 54px);
    border-radius: 34px;
    background: #fff;
    border: 1px solid #E2F7EF;
    box-shadow: 0 8px 40px rgba(8,80,65,.12);
}

.qa-premium-completed.active {
    display: block;
    animation: qaPop .45s cubic-bezier(.2,.9,.2,1);
}

.qa-premium-done-icon {
    font-size: clamp(66px, 12vw, 100px);
    margin-bottom: 12px;
}

.qa-premium-done-title {
    margin: 0 0 10px;
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(34px, 6vw, 60px);
    color: #085041;
    line-height: 1;
}

.qa-premium-done-text {
    margin: 0 auto 22px;
    max-width: 520px;
    color: #7C739B;
    font-size: clamp(14px, 2vw, 17px);
    font-weight: 800;
    line-height: 1.5;
}

.qa-premium-done-track {
    height: 14px;
    max-width: 420px;
    margin: 0 auto 18px;
    border-radius: 999px;
    background: #E2F7EF;
    overflow: hidden;
}

.qa-premium-done-fill {
    height: 100%;
    width: 0%;
    border-radius: 999px;
    background: linear-gradient(90deg, #1D9E75, #7F77DD, #EC4899);
    transition: width .8s cubic-bezier(.2,.9,.2,1);
}

@keyframes qaPop {
    from { opacity: 0; transform: scale(.92); }
    to { opacity: 1; transform: scale(1); }
}

@media (max-width: 560px) {
    .qa-premium-shell { padding: 12px; }
    .qa-premium-arrow { display: none; }
    .qa-premium-actions { display: grid; grid-template-columns: 1fr 1fr; }
    .qa-premium-btn { width: 100%; }
}
</style>

<div class="qa-premium-shell">
    <div class="qa-premium-app" id="qa-premium-app">
        <div class="qa-premium-title-panel">
            <div class="qa-premium-kicker">Question & Answer</div>
            <h1 class="qa-premium-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="qa-premium-subtitle">Read and reveal each answer.</p>
        </div>
        <section class="qa-premium-board" id="qa-premium-board">
            <div class="qa-premium-progress-row">
                <div class="qa-premium-progress-track">
                    <div class="qa-premium-progress-fill" id="qa-premium-progress-fill"></div>
                </div>
                <div class="qa-premium-progress-count" id="qa-premium-progress-count">1 / <?php echo count($cards); ?></div>
            </div>

            <div class="qa-premium-card-wrap">
                <button type="button" class="qa-premium-arrow qa-premium-arrow-left" id="qa-premium-prev-arrow" aria-label="Previous question">&#8249;</button>

                <div class="qa-premium-card" id="qa-premium-card" role="button" tabindex="0" aria-label="Tap to reveal answer">
                    <div class="qa-premium-card-inner">
                        <div class="qa-premium-face qa-premium-front">
                            <div class="qa-premium-label">Question</div>
                            <div class="qa-premium-text" id="qa-premium-question"></div>
                            <div class="qa-premium-hint">Tap to reveal answer</div>
                        </div>

                        <div class="qa-premium-face qa-premium-back">
                            <div class="qa-premium-label">Answer</div>
                            <div class="qa-premium-text" id="qa-premium-answer"></div>
                            <div class="qa-premium-hint">Tap to see question</div>
                        </div>
                    </div>
                </div>

                <button type="button" class="qa-premium-arrow qa-premium-arrow-right" id="qa-premium-next-arrow" aria-label="Next question">&#8250;</button>
            </div>

            <div class="qa-premium-actions">
                <button type="button" class="qa-premium-btn qa-premium-btn-blue" id="qa-premium-prev">&#9664; Prev</button>
                <button type="button" class="qa-premium-btn qa-premium-btn-pink" id="qa-premium-listen">&#x1F50A; Listen</button>
                <button type="button" class="qa-premium-btn qa-premium-btn-blue" id="qa-premium-next">Next &#9654;</button>
            </div>
        </section>

        <section class="qa-premium-completed" id="qa-premium-completed">
            <div class="qa-premium-done-icon">🎉</div>
            <h2 class="qa-premium-done-title">All Done!</h2>
            <p class="qa-premium-done-text">You reviewed all questions. Excellent fluency practice!</p>
            <div class="qa-premium-done-track">
                <div class="qa-premium-done-fill" id="qa-premium-done-fill"></div>
            </div>
            <div class="qa-premium-actions">
                <button type="button" class="qa-premium-btn qa-premium-btn-teal" id="qa-premium-restart">Review Again</button>
            </div>
        </section>
    </div>
</div>

<audio id="qa-premium-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
'use strict';

var CARDS = <?php echo json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var TOTAL = CARDS.length;
var QA_VOICE_ID = <?php echo json_encode((string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var QA_TTS_URL = 'tts.php';
var idx = 0;
var flipped = false;
var done = false;

var els = {
    board: document.getElementById('qa-premium-board'),
    completed: document.getElementById('qa-premium-completed'),
    card: document.getElementById('qa-premium-card'),
    question: document.getElementById('qa-premium-question'),
    answer: document.getElementById('qa-premium-answer'),
    progressFill: document.getElementById('qa-premium-progress-fill'),
    progressCount: document.getElementById('qa-premium-progress-count'),
    doneFill: document.getElementById('qa-premium-done-fill'),
    win: document.getElementById('qa-premium-win')
};

var TTS = (function(){
    var audio = null;
    var audioUrl = '';

    function cleanup() {
        if (audio) {
            try { audio.pause(); } catch (e) {}
            try { audio.currentTime = 0; } catch (e) {}
            audio = null;
        }
        if (audioUrl) {
            try { URL.revokeObjectURL(audioUrl); } catch (e) {}
            audioUrl = '';
        }
    }

    function speak(text){
        text = String(text || '').trim();
        if (!text) return;

        if (audio) {
            if (!audio.paused) {
                audio.pause();
            } else {
                audio.play().catch(function(){});
            }
            return;
        }

        var fd = new FormData();
        fd.append('text', text);
        fd.append('voice_id', QA_VOICE_ID || 'nzFihrBIvB34imQBuxub');

        fetch(QA_TTS_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('TTS error ' + res.status);
                return res.blob();
            })
            .then(function (blob) {
                audioUrl = URL.createObjectURL(blob);
                audio = new Audio(audioUrl);

                audio.onended = function () {
                    cleanup();
                };

                audio.play().catch(function () {
                    cleanup();
                });
            })
            .catch(function () {
                cleanup();
            });
    }

    return { speak: speak, stop: cleanup };
})();

function getQuestion(card){
    return String((card && card.question) || '').trim();
}

function getAnswer(card){
    return String((card && card.answer) || '').trim();
}

function setFlipped(value){
    flipped = !!value;
    if (flipped) els.card.classList.add('is-flipped');
    else els.card.classList.remove('is-flipped');
}

function loadCard(){
    if (!TOTAL) return;

    var card = CARDS[idx] || {};
    els.question.textContent = getQuestion(card) || 'No question';
    els.answer.textContent = getAnswer(card) || 'No answer';

    setFlipped(false);

    var countText = (idx + 1) + ' / ' + TOTAL;
    var pct = Math.max(1, Math.round(((idx + 1) / TOTAL) * 100));
    els.progressFill.style.width = pct + '%';
    els.progressCount.textContent = countText;
}

function flipCard(){
    if (done) return;
    setFlipped(!flipped);
}

function prevCard(){
    if (done) return;
    idx = (idx - 1 + TOTAL) % TOTAL;
    loadCard();
}

function nextCard(){
    if (done) return;
    if (idx >= TOTAL - 1) {
        showDone();
        return;
    }
    idx++;
    loadCard();
}

function showDone(){
    done = true;
    TTS.stop();
    els.board.style.display = 'none';
    els.completed.classList.add('active');
    setTimeout(function(){ els.doneFill.style.width = '100%'; }, 120);
    try {
        els.win.pause();
        els.win.currentTime = 0;
        els.win.play();
    } catch(e) {}
}

function restart(){
    done = false;
    TTS.stop();
    idx = 0;
    els.doneFill.style.width = '0%';
    els.completed.classList.remove('active');
    els.board.style.display = '';
    loadCard();
}

function bind(id, eventName, handler){
    var el = document.getElementById(id);
    if (el) el.addEventListener(eventName, handler);
}

els.card.addEventListener('click', flipCard);
els.card.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        flipCard();
    }
});

bind('qa-premium-prev-arrow', 'click', prevCard);
bind('qa-premium-next-arrow', 'click', nextCard);
bind('qa-premium-prev', 'click', prevCard);
bind('qa-premium-next', 'click', nextCard);
bind('qa-premium-restart', 'click', restart);
bind('qa-premium-listen', 'click', function(){
    var card = CARDS[idx] || {};
    TTS.speak(flipped ? getAnswer(card) : getQuestion(card));
});

document.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        flipCard();
    }
    if (e.key === 'ArrowRight') nextCard();
    if (e.key === 'ArrowLeft') prevCard();
});

loadCard();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-circle-question', $content);

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function us_resolve_unit_from_activity_view(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us_default_title_view(): string
{
    return 'Unscramble the Sentence';
}

function us_parse_listen($raw): bool
{
    if (is_bool($raw)) return $raw;
    if (is_numeric($raw)) return (int) $raw === 1;
    if (is_string($raw)) {
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) return false;
    }
    return true;
}

function us_normalize_payload_view($rawData): array
{
    $default = [
        'title' => us_default_title_view(),
        'sentences' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));

    $sentencesSource = [];
    if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        $sentencesSource = $decoded['sentences'];
    }

    $sentences = [];

    foreach ($sentencesSource as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sentence = '';
        if (isset($item['sentence']) && is_string($item['sentence'])) {
            $sentence = trim($item['sentence']);
        } elseif (isset($item['text']) && is_string($item['text'])) {
            $sentence = trim($item['text']);
        }

        if ($sentence === '') {
            continue;
        }

        $listenEnabled = array_key_exists('listen_enabled', $item)
            ? us_parse_listen($item['listen_enabled'])
            : true;

        $sentences[] = [
            'sentence' => $sentence,
            'listen_enabled' => $listenEnabled,
        ];
    }

    return [
        'title' => $title !== '' ? $title : us_default_title_view(),
        'sentences' => $sentences,
    ];
}

function us_load_activity_view(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => us_default_title_view(),
        'sentences' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'unscramble'
            LIMIT 1
        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'unscramble'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = us_normalize_payload_view($row['data'] ?? null);

    return [
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => (string) ($payload['title'] ?? us_default_title_view()),
        'sentences' => is_array($payload['sentences'] ?? null) ? $payload['sentences'] : [],
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = us_resolve_unit_from_activity_view($pdo, $activityId);
}

$activity = us_load_activity_view($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? us_default_title_view());
$sentences = is_array($activity['sentences'] ?? null) ? $activity['sentences'] : [];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if (count($sentences) === 0) {
    die('No sentences found for this activity');
}

ob_start();
?>
<style>
.us-stage{
    max-width:980px;
    margin:0 auto;
}

.us-intro{
    margin-bottom:18px;
    padding:24px 26px;
    border-radius:26px;
    border:1px solid #ddd6fe;
    background:linear-gradient(135deg, #f5f3ff 0%, #ede9fe 52%, #e9d5ff 100%);
    box-shadow:0 16px 34px rgba(15,23,42,.09);
}

.us-intro h2{
    margin:0 0 8px;
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:30px;
    line-height:1.1;
    color:#5b21b6;
}

.us-intro p,
.us-instructions{
    margin:0;
    text-align:center;
    color:#6b21a8;
    font-size:16px;
    line-height:1.6;
}

#sentenceBox{
    margin:20px auto 0;
    padding:22px;
    background:linear-gradient(180deg,#faf9ff 0%,#f5f3ff 100%);
    border:1px solid #ddd6fe;
    border-radius:24px;
    max-width:920px;
    box-shadow:0 14px 28px rgba(15,23,42,.08);
}

#buildArea{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    min-height:56px;
    gap:8px;
    padding:12px;
    border-radius:16px;
    border:2px dashed #a78bfa;
    background:#faf5ff;
    margin-top:12px;
}

#buildArea.drag-over{
    border-color:#7c3aed;
    background:#ede9fe;
}

.us-placeholder{
    color:#a78bfa;
    font-size:15px;
    font-style:italic;
    pointer-events:none;
}

.us-chip{
    display:inline-flex;
    align-items:center;
    padding:10px 16px;
    border-radius:999px;
    font-weight:800;
    cursor:grab;
    user-select:none;
    transition:transform .1s ease, box-shadow .1s ease;
}

.us-chip:active{
    cursor:grabbing;
}

.us-chip.bank-chip{
    background:linear-gradient(180deg,#c4b5fd 0%,#a78bfa 100%);
    color:#3b0764;
    box-shadow:0 6px 14px rgba(167,139,250,.3);
}

.us-chip.bank-chip:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(167,139,250,.4);
}

.us-chip.built-chip{
    background:linear-gradient(180deg,#ddd6fe 0%,#c4b5fd 100%);
    color:#4c1d95;
    box-shadow:0 4px 10px rgba(167,139,250,.2);
}

.us-chip.built-chip:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 18px rgba(167,139,250,.35);
}

.us-chip.correct-chip{
    background:linear-gradient(180deg,#bbf7d0 0%,#86efac 100%);
    color:#14532d;
    box-shadow:0 4px 10px rgba(34,197,94,.2);
    cursor:default;
}

.us-chip.incorrect-chip{
    background:linear-gradient(180deg,#fecaca 0%,#f87171 100%);
    color:#7f1d1d;
    box-shadow:0 4px 10px rgba(239,68,68,.2);
    cursor:default;
}

#wordBank{
    display:flex;
    flex-wrap:wrap;
    justify-content:center;
    gap:12px;
    margin:18px 0;
    min-height:52px;
}

.us-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:11px 18px;
    border:none;
    border-radius:999px;
    color:white;
    cursor:pointer;
    min-width:142px;
    font-weight:800;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:14px;
    line-height:1;
    box-shadow:0 10px 22px rgba(15,23,42,.12);
    transition:transform .15s ease, filter .15s ease;
}

.us-btn:hover{
    filter:brightness(1.04);
    transform:translateY(-1px);
}

.us-btn-listen{background:linear-gradient(180deg,#14b8a6 0%,#0f766e 100%)}
.us-btn-show{background:linear-gradient(180deg,#d8b4fe 0%,#a855f7 100%)}
.us-btn-next{background:linear-gradient(180deg,#818cf8 0%,#6366f1 100%)}

#listenBtn.hidden{ display:none; }

#feedback{
    text-align:center;
    font-size:20px;
    font-weight:800;
    min-height:32px;
    margin-top:8px;
}

.good{ color:#15803d; }
.bad{ color:#dc2626; }

.controls{
    margin-top:15px;
    text-align:center;
}

.us-completed-screen{
    display:none;
    text-align:center;
    max-width:600px;
    margin:0 auto;
    padding:40px 20px;
}

.us-completed-screen.active{
    display:block;
}

.us-completed-icon{
    font-size:80px;
    margin-bottom:20px;
}

.us-completed-title{
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:36px;
    font-weight:700;
    color:#5b21b6;
    margin:0 0 16px;
    line-height:1.2;
}

.us-completed-text{
    font-size:16px;
    color:#6b21a8;
    line-height:1.6;
    margin:0 0 32px;
}

.us-completed-button{
    display:inline-block;
    padding:12px 24px;
    border:none;
    border-radius:999px;
    background:linear-gradient(180deg,#8b5cf6 0%,#7c3aed 100%);
    color:#fff;
    font-weight:700;
    font-size:16px;
    cursor:pointer;
    box-shadow:0 10px 24px rgba(0,0,0,.14);
    transition:transform .18s ease, filter .18s ease;
}

.us-completed-button:hover{
    transform:scale(1.05);
    filter:brightness(1.07);
}

@media (max-width:760px){
    .us-intro{padding:20px 18px}
    .us-intro h2{font-size:26px}
    #sentenceBox{padding:18px}
    .controls{display:flex;flex-direction:column;align-items:center}
    .us-btn{width:100%;max-width:320px}
}
</style>

<?= render_activity_header($viewerTitle, 'Unscramble the words to form the correct sentence.') ?>
<div class="us-stage">
    <div id="sentenceBox">
        <button id="listenBtn" class="us-btn us-btn-listen" type="button" onclick="usSpeak()">🔊 Listen</button>
        <div id="buildArea">
            <span class="us-placeholder" id="buildPlaceholder">Drag words here to build the sentence…</span>
        </div>
    </div>

    <div id="wordBank"></div>

    <div class="controls">
        <button class="us-btn us-btn-show" type="button" onclick="usShowAnswer()">Show Answer</button>
        <button class="us-btn us-btn-next" type="button" onclick="usNextSentence()">Next</button>
    </div>

    <div id="feedback"></div>

    <div id="us-completed" class="us-completed-screen">
        <div class="us-completed-icon">✅</div>
        <h2 class="us-completed-title">Completed!</h2>
        <p class="us-completed-text" id="us-completed-text"></p>
        <p class="us-completed-text" id="us-score-text" style="font-weight:700;font-size:18px;color:#5b21b6;"></p>
        <button type="button" class="us-completed-button" id="us-restart" onclick="usRestartActivity()">Restart</button>
    </div>
</div>

<audio id="winSound" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="loseSound" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<audio id="doneSound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
const usSentences = <?= json_encode($sentences, JSON_UNESCAPED_UNICODE) ?>;
const usActivityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const US_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
const US_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;

// Shuffle a portion of sentences if there are more than 1
const usBlocks = usSentences.length > 1
    ? usSentences.slice().sort(function () { return Math.random() - 0.5; }).slice(0, Math.max(1, Math.ceil(usSentences.length * 0.75)))
    : usSentences.slice();

let usIndex = 0;
let usDragged = null;
let usCurrentSentence = '';
let usCurrentWords = [];
let usListenEnabled = true;
let usIsSpeaking = false;
let usIsPaused = false;
let usUtter = null;
let usSpeechOffset = 0;
let usSpeechSourceText = '';
let usSpeechSegmentStart = 0;
let usFinished = false;
let usBlockFinished = false;
let usCorrectCount = 0;
let usTotalCount = 0;
let usCheckedBlocks = {};
let usAttemptsByBlock = {};
let usScoredByBlock = {};

const usBuildArea = document.getElementById('buildArea');
const usWordBank = document.getElementById('wordBank');
const usFeedback = document.getElementById('feedback');
const usListenBtn = document.getElementById('listenBtn');
const usWinSound = document.getElementById('winSound');
const usLoseSound = document.getElementById('loseSound');
const usDoneSound = document.getElementById('doneSound');
const usSentenceBox = document.getElementById('sentenceBox');
const usControls = document.querySelector('.controls');
const usCompletedEl = document.getElementById('us-completed');
const usCompletedTextEl = document.getElementById('us-completed-text');
const usScoreTextEl = document.getElementById('us-score-text');
const usBuildPlaceholder = document.getElementById('buildPlaceholder');

function usPlaySound(audio) {
    try {
        audio.pause();
        audio.currentTime = 0;
        audio.play();
    } catch (e) {}
}

function usPersistScoreSilently(targetUrl) {
    if (!targetUrl) {
        return Promise.resolve(false);
    }
    return fetch(targetUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
    }).then(function (response) {
        return !!(response && response.ok);
    }).catch(function () {
        return false;
    });
}

function usNavigateToReturn(targetUrl) {
    if (!targetUrl) return;
    try {
        if (window.top && window.top !== window.self) {
            window.top.location.href = targetUrl;
            return;
        }
    } catch (e) {}
    window.location.href = targetUrl;
}

function usSetListenVisible(visible) {
    if (visible) {
        usListenBtn.classList.remove('hidden');
    } else {
        usListenBtn.classList.add('hidden');
        speechSynthesis.cancel();
        usIsSpeaking = false;
        usIsPaused = false;
        usSpeechOffset = 0;
        usSpeechSourceText = '';
        usSpeechSegmentStart = 0;
    }
}

function usShuffle(list) {
    return list.slice().sort(function () { return Math.random() - 0.5; });
}

function usGetBuiltWords() {
    const chips = Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip'));
    return chips.map(function (chip) { return chip.dataset.word || ''; });
}

function usUpdatePlaceholder() {
    if (usBuildPlaceholder) {
        const hasChips = usBuildArea.querySelectorAll('.built-chip').length > 0;
        usBuildPlaceholder.style.display = hasChips ? 'none' : 'inline';
    }
}

function usCreateBankChip(word) {
    const chip = document.createElement('span');
    chip.textContent = word;
    chip.className = 'us-chip bank-chip';
    chip.draggable = true;
    chip.dataset.word = word;

    chip.addEventListener('dragstart', function () {
        usDragged = chip;
        usDragged.dataset.source = 'bank';
    });

    chip.addEventListener('click', function () {
        if (usFinished || usBlockFinished) return;
        usMoveToBuildArea(chip);
        setTimeout(usAutoCheck, 40);
    });

    return chip;
}

function usCreateBuiltChip(word) {
    const chip = document.createElement('span');
    chip.textContent = word;
    chip.className = 'us-chip built-chip';
    chip.draggable = true;
    chip.dataset.word = word;

    chip.addEventListener('dragstart', function () {
        usDragged = chip;
        usDragged.dataset.source = 'build';
    });

    chip.addEventListener('click', function () {
        if (usFinished || usBlockFinished) return;
        usMoveToBank(chip);
        usFeedback.textContent = '';
        usFeedback.className = '';
    });

    return chip;
}

function usMoveToBuildArea(chip) {
    const builtChip = usCreateBuiltChip(chip.dataset.word);
    usBuildArea.appendChild(builtChip);
    chip.remove();
    usUpdatePlaceholder();
}

function usMoveToBank(chip) {
    const bankChip = usCreateBankChip(chip.dataset.word);
    usWordBank.appendChild(bankChip);
    chip.remove();
    usUpdatePlaceholder();
}

// Build area drop support
usBuildArea.addEventListener('dragover', function (e) {
    e.preventDefault();
    usBuildArea.classList.add('drag-over');
});

usBuildArea.addEventListener('dragleave', function () {
    usBuildArea.classList.remove('drag-over');
});

usBuildArea.addEventListener('drop', function (e) {
    e.preventDefault();
    usBuildArea.classList.remove('drag-over');
    if (!usDragged || usFinished || usBlockFinished) return;

    const source = usDragged.dataset.source || 'bank';
    const word = usDragged.dataset.word || '';

    if (source === 'bank') {
        const builtChip = usCreateBuiltChip(word);
        usBuildArea.appendChild(builtChip);
        usDragged.remove();
    }
    // If dragging a built-chip to build area, it moves to the end
    if (source === 'build') {
        usBuildArea.appendChild(usDragged);
    }

    usDragged = null;
    usUpdatePlaceholder();
    setTimeout(usAutoCheck, 40);
});

// Word bank drop support (return chips)
usWordBank.addEventListener('dragover', function (e) { e.preventDefault(); });

usWordBank.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!usDragged || usFinished || usBlockFinished) return;

    const source = usDragged.dataset.source || 'bank';

    if (source === 'build') {
        const bankChip = usCreateBankChip(usDragged.dataset.word);
        usWordBank.appendChild(bankChip);
        usDragged.remove();
        usUpdatePlaceholder();
        usFeedback.textContent = '';
        usFeedback.className = '';
    }

    usDragged = null;
});

function usLoadSentence() {
    speechSynthesis.cancel();
    usIsSpeaking = false;
    usIsPaused = false;
    usSpeechOffset = 0;
    usSpeechSourceText = '';
    usSpeechSegmentStart = 0;
    usDragged = null;
    usFinished = false;
    usBlockFinished = false;

    if (usCompletedEl) usCompletedEl.classList.remove('active');
    if (usSentenceBox) usSentenceBox.style.display = 'block';
    if (usWordBank) usWordBank.style.display = 'flex';
    if (usControls) usControls.style.display = 'block';

    usFeedback.textContent = '';
    usFeedback.className = '';

    // Clear build area (keep placeholder)
    Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip, .correct-chip, .incorrect-chip')).forEach(function (c) { c.remove(); });
    usUpdatePlaceholder();
    usWordBank.innerHTML = '';

    const block = usBlocks[usIndex] || {};
    usCurrentSentence = typeof block.sentence === 'string' ? block.sentence.trim() : '';
    usSpeechSourceText = usCurrentSentence;
    usListenEnabled = !!block.listen_enabled;
    usSetListenVisible(usListenEnabled);

    if (!usCurrentSentence) {
        usFeedback.textContent = 'Empty sentence';
        usFeedback.className = 'bad';
        return;
    }

    // Split sentence into words (preserve punctuation attached to words)
    usCurrentWords = usCurrentSentence.split(/\s+/).filter(function (w) { return w.length > 0; });

    // Shuffle all words into bank
    usShuffle(usCurrentWords).forEach(function (word) {
        usWordBank.appendChild(usCreateBankChip(word));
    });
}

async function usShowCompleted() {
    usFinished = true;
    usBlockFinished = true;
    speechSynthesis.cancel();
    usIsSpeaking = false;
    usIsPaused = false;
    usFeedback.textContent = '';
    usFeedback.className = '';

    if (usSentenceBox) usSentenceBox.style.display = 'none';
    if (usWordBank) usWordBank.style.display = 'none';
    if (usControls) usControls.style.display = 'none';
    if (usCompletedEl) usCompletedEl.classList.add('active');

    usPlaySound(usDoneSound);

    const pct = usTotalCount > 0 ? Math.round((usCorrectCount / usTotalCount) * 100) : 0;
    const errors = Math.max(0, usTotalCount - usCorrectCount);

    if (usCompletedTextEl) {
        usCompletedTextEl.textContent = "You've completed " + (usActivityTitle || 'this activity') + '. Great job!';
    }

    if (usScoreTextEl) {
        usScoreTextEl.textContent = 'Score: ' + usCorrectCount + ' / ' + usTotalCount + ' (' + pct + '%)';
    }

    if (US_ACTIVITY_ID && US_RETURN_TO) {
        const joiner = US_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        const saveUrl = US_RETURN_TO
            + joiner + 'activity_percent=' + pct
            + '&activity_errors=' + errors
            + '&activity_total=' + usTotalCount
            + '&activity_id=' + encodeURIComponent(US_ACTIVITY_ID)
            + '&activity_type=unscramble';

        const ok = await usPersistScoreSilently(saveUrl);
        if (!ok) {
            usNavigateToReturn(saveUrl);
        }
    }
}

function usRegisterSentenceScore(builtWords) {
    if (Object.prototype.hasOwnProperty.call(usScoredByBlock, usIndex)) {
        return;
    }

    const built = Array.isArray(builtWords) ? builtWords : usGetBuiltWords();
    const isCorrect = JSON.stringify(built) === JSON.stringify(usCurrentWords);
    const score = isCorrect ? 1 : 0;
    usScoredByBlock[usIndex] = score;
    usCorrectCount += score;
}

function usCheckSentence() {
    if (usFinished || usBlockFinished || usCheckedBlocks[usIndex]) return;

    const built = usGetBuiltWords();

    if (built.length < usCurrentWords.length) {
        usFeedback.textContent = 'Place all words first.';
        usFeedback.className = 'bad';
        return;
    }

    const currentAttempts = (usAttemptsByBlock[usIndex] || 0) + 1;
    usAttemptsByBlock[usIndex] = currentAttempts;

    const isCorrect = JSON.stringify(built) === JSON.stringify(usCurrentWords);

    if (isCorrect) {
        usFeedback.textContent = '✔ Correct!';
        usFeedback.className = 'good';
        usPlaySound(usWinSound);
        usCheckedBlocks[usIndex] = true;
        usRegisterSentenceScore(built);
        usBlockFinished = true;
        // Mark chips green
        usBuildArea.querySelectorAll('.built-chip').forEach(function (chip) {
            chip.className = 'us-chip correct-chip';
            chip.draggable = false;
        });
    } else {
        if (currentAttempts >= 2) {
            usFeedback.textContent = '✘ Incorrect (2/2)';
            usFeedback.className = 'bad';
            usPlaySound(usLoseSound);
            usCheckedBlocks[usIndex] = true;
            usRegisterSentenceScore(built);
            usBlockFinished = true;
            // Mark chips red/green by position
            const chips = Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip'));
            chips.forEach(function (chip, i) {
                if ((built[i] || '') === (usCurrentWords[i] || '')) {
                    chip.className = 'us-chip correct-chip';
                } else {
                    chip.className = 'us-chip incorrect-chip';
                }
                chip.draggable = false;
            });
        } else {
            usFeedback.textContent = '✘ Incorrect (1/2) — try again';
            usFeedback.className = 'bad';
            usPlaySound(usLoseSound);
        }
    }
}

function usAutoCheck() {
    if (usFinished || usBlockFinished || usCheckedBlocks[usIndex]) return;

    const built = usGetBuiltWords();
    if (built.length < usCurrentWords.length) return;

    usCheckSentence();
}

function usShowAnswer() {
    const built = usGetBuiltWords();
    usRegisterSentenceScore(built);
    usCheckedBlocks[usIndex] = true;

    // Clear build area
    Array.prototype.slice.call(usBuildArea.querySelectorAll('.built-chip, .incorrect-chip, .correct-chip')).forEach(function (c) { c.remove(); });
    usWordBank.innerHTML = '';

    // Place correct words in order
    usCurrentWords.forEach(function (word) {
        const chip = document.createElement('span');
        chip.textContent = word;
        chip.className = 'us-chip correct-chip';
        chip.draggable = false;
        chip.dataset.word = word;
        usBuildArea.appendChild(chip);
    });

    usUpdatePlaceholder();
    usFeedback.textContent = 'Correct order shown';
    usFeedback.className = 'good';
    usBlockFinished = true;
}

function usNextSentence() {
    if (usFinished) return;

    usAutoCheck();

    if (!usBlockFinished && !usCheckedBlocks[usIndex]) {
        return;
    }

    usRegisterSentenceScore();

    if (usIndex >= usBlocks.length - 1) {
        usShowCompleted();
        return;
    }

    usIndex += 1;
    usLoadSentence();
}

function usRestartActivity() {
    usIndex = 0;
    usCorrectCount = 0;
    usTotalCount = usBlocks.length;
    usCheckedBlocks = {};
    usAttemptsByBlock = {};
    usScoredByBlock = {};
    usLoadSentence();
}

function usSpeak() {
    if (!usListenEnabled) return;
    if (!usCurrentSentence || String(usCurrentSentence).trim() === '') return;

    if (speechSynthesis.paused || usIsPaused) {
        speechSynthesis.resume();
        usIsSpeaking = true;
        usIsPaused = false;
        setTimeout(function () {
            if (!speechSynthesis.speaking && usSpeechOffset < usSpeechSourceText.length) {
                usStartSpeechFromOffset();
            }
        }, 80);
        return;
    }

    if (speechSynthesis.speaking && !speechSynthesis.paused) {
        speechSynthesis.pause();
        usIsSpeaking = true;
        usIsPaused = true;
        return;
    }

    speechSynthesis.cancel();
    usSpeechSourceText = usCurrentSentence || '';
    usSpeechOffset = 0;
    usStartSpeechFromOffset();
}

function usStartSpeechFromOffset() {
    const source = usSpeechSourceText || usCurrentSentence || '';
    if (!source) return;

    const safeOffset = Math.max(0, Math.min(usSpeechOffset, source.length));
    const remaining = source.slice(safeOffset);
    if (!remaining.trim()) {
        usIsSpeaking = false;
        usIsPaused = false;
        usSpeechOffset = 0;
        return;
    }

    speechSynthesis.cancel();
    usSpeechSegmentStart = safeOffset;
    usUtter = new SpeechSynthesisUtterance(remaining);
    usUtter.lang = 'en-US';
    usUtter.rate = 0.92;
    usUtter.pitch = 1;
    if (selectedVoice) usUtter.voice = selectedVoice;
    speechSynthesis.speak(usUtter);

// Voice dropdown logic
function populateVoiceList() {
    const voiceSelect = document.getElementById('voiceSelect');
    if (!voiceSelect || !window.speechSynthesis) return;
    const voices = window.speechSynthesis.getVoices();
    voiceSelect.innerHTML = '';
    voices.forEach((voice, i) => {
        const option = document.createElement('option');
        option.value = i;
        option.textContent = `${voice.name} (${voice.lang})${voice.default ? ' [default]' : ''}`;
        voiceSelect.appendChild(option);
    });
    voiceSelect.onchange = function() {
        selectedVoice = voices[this.value];
    };
    // Set default
    if (voices.length) {
        selectedVoice = voices[voiceSelect.value];
    }
}

if (typeof speechSynthesis !== 'undefined') {
    speechSynthesis.onvoiceschanged = populateVoiceList;
    populateVoiceList();
}

    usUtter.onstart = function () { usIsSpeaking = true; usIsPaused = false; };
    usUtter.onpause = function () { usIsPaused = true; usIsSpeaking = true; };
    usUtter.onresume = function () { usIsPaused = false; usIsSpeaking = true; };
    usUtter.onboundary = function (event) {
        if (typeof event.charIndex === 'number') {
            usSpeechOffset = Math.max(usSpeechSegmentStart, Math.min(source.length, usSpeechSegmentStart + event.charIndex));
        }
    };
    usUtter.onend = function () {
        if (usIsPaused) return;
        usIsSpeaking = false;
        usIsPaused = false;
        usSpeechOffset = 0;
    };

    speechSynthesis.speak(usUtter);
}

// Init
usTotalCount = usBlocks.length;
usLoadSentence();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔀', $content);

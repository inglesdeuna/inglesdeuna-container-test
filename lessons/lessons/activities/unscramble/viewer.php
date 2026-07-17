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

    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us_default_title_view(): string
{
    return 'Unscramble the Sentence';
}

function us_parse_bool($raw, bool $default = true): bool
{
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_numeric($raw)) {
        return (int) $raw === 1;
    }
    if (is_string($raw)) {
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    return $default;
}

function us_words_from_sentence(string $sentence): array
{
    $sentence = trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence);
    if ($sentence === '') {
        return [];
    }
    return array_values(array_filter(preg_split('/\s+/u', $sentence) ?: [], static fn ($w): bool => $w !== ''));
}

function us_normalize_payload_view($rawData): array
{
    $default = [
        'title' => us_default_title_view(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
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
    $voiceId = trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub';

    $sentencesSource = [];
    if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        $sentencesSource = $decoded['sentences'];
    } elseif (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $sentencesSource = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $sentencesSource = $decoded['items'];
    }

    $sentences = [];
    foreach ($sentencesSource as $item) {
        if (is_string($item)) {
            $sentence = trim($item);
            $listenEnabled = true;
        } elseif (is_array($item)) {
            $sentence = '';
            if (isset($item['sentence']) && is_string($item['sentence'])) {
                $sentence = trim($item['sentence']);
            } elseif (isset($item['text']) && is_string($item['text'])) {
                $sentence = trim($item['text']);
            } elseif (isset($item['answer']) && is_string($item['answer'])) {
                $sentence = trim($item['answer']);
            } elseif (isset($item['correct']) && is_array($item['correct'])) {
                $sentence = trim(implode(' ', array_map('strval', $item['correct'])));
            }

            $listenEnabled = array_key_exists('listen_enabled', $item)
                ? us_parse_bool($item['listen_enabled'])
                : (array_key_exists('listen', $item) ? us_parse_bool($item['listen']) : true);
        } else {
            continue;
        }

        $words = us_words_from_sentence($sentence);
        if ($sentence === '' || empty($words)) {
            continue;
        }

        $sentences[] = [
            'sentence' => $sentence,
            'words' => $words,
            'listen_enabled' => $listenEnabled,
        ];
    }

    return [
        'title' => $title !== '' ? $title : us_default_title_view(),
        'voice_id' => $voiceId,
        'sentences' => $sentences,
    ];
}

function us_load_activity_view(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => us_default_title_view(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'sentences' => [],
    ];

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'unscramble' ORDER BY id ASC LIMIT 1");
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
        'voice_id' => (string) ($payload['voice_id'] ?? 'nzFihrBIvB34imQBuxub'),
        'sentences' => is_array($payload['sentences'] ?? null) ? $payload['sentences'] : [],
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = us_resolve_unit_from_activity_view($pdo, $activityId);
}

$activity = us_load_activity_view($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? us_default_title_view());
$activityVoiceId = (string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub');
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
.us2-page{
    width:100%;
    min-height:100%;
    flex:1 1 auto;
    display:flex;
    justify-content:center;
    align-items:flex-start;
    overflow:auto;
    background:#fff;
    padding:clamp(14px,2.5vw,30px);
    box-sizing:border-box;
    font-family:'Nunito','Segoe UI',Arial,sans-serif;
}
.us2-app{width:min(900px,100%);margin:0 auto;color:#18213a;}
.us2-top{text-align:center;margin-bottom:14px;}
.us2-kicker{display:inline-flex;align-items:center;gap:8px;background:#FFF0E6;color:#C2580A;border:1px solid #FCDDBF;border-radius:999px;padding:7px 14px;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;}
.us2-title{margin:10px 0 4px;font-family:'Fredoka','Trebuchet MS',Arial,sans-serif;font-size:clamp(28px,4.4vw,48px);line-height:1.05;color:#F97316;font-weight:800;}
.us2-subtitle{margin:0;color:#8b82b7;font-size:clamp(13px,1.6vw,16px);font-weight:800;}
.us2-card{background:#fff;border:1px solid #F0EEF8;border-radius:30px;padding:clamp(18px,2.6vw,26px);box-shadow:0 12px 38px rgba(127,119,221,.14);}
.us2-listen-row{text-align:center;margin-bottom:14px;}
.us2-build{min-height:92px;border:2px dashed #EDE9FA;background:#fff;border-radius:22px;padding:16px;display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:12px;transition:border-color .15s,background .15s,box-shadow .15s;}
.us2-build.is-drag{border-color:#7F77DD;background:#FAFAFE;box-shadow:0 8px 24px rgba(127,119,221,.14);}
.us2-placeholder{color:#9B94BE;font-weight:900;font-size:15px;text-align:center;}
.us2-bank{min-height:70px;margin:20px 0 0;display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:12px;}
.us2-chip{display:inline-flex;align-items:center;justify-content:center;min-height:44px;min-width:64px;padding:11px 17px;border:2px solid #BDB5EE;border-bottom-color:#7F77DD;border-radius:12px;background:#fff;color:#4A3FC2;font-size:clamp(16px,2vw,20px);font-weight:900;line-height:1;cursor:pointer;user-select:none;box-shadow:0 4px 0 rgba(127,119,221,.42);transition:transform .12s,box-shadow .12s,filter .12s;}
.us2-chip:hover{transform:translateY(-1px);box-shadow:0 7px 16px rgba(127,119,221,.2),0 4px 0 rgba(127,119,221,.42);}
.us2-chip:active{transform:translateY(1px);box-shadow:0 2px 0 rgba(127,119,221,.42);}
.us2-chip.us2-built{background:#F8F7FF;color:#4338CA;}
.us2-chip.us2-correct{background:#f0fdf4;border-color:#16a34a;color:#166534;box-shadow:none;cursor:default;}
.us2-chip.us2-wrong{background:#fef2f2;border-color:#ef4444;color:#991b1b;box-shadow:none;cursor:default;}
.us2-controls{border-top:1px solid #F0EEF8;margin-top:18px;padding-top:16px;display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;}
.us2-btn{display:inline-flex;align-items:center;justify-content:center;min-width:126px;padding:12px 18px;border:0;border-radius:10px;color:#fff;font-size:14px;font-weight:900;font-family:'Nunito','Segoe UI',Arial,sans-serif;cursor:pointer;box-shadow:0 8px 18px rgba(127,119,221,.2);transition:filter .12s,transform .12s;}
.us2-btn:hover{filter:brightness(1.06);transform:translateY(-1px);}
.us2-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;filter:none;}
.us2-purple{background:#7F77DD;}.us2-orange{background:#F97316;box-shadow:0 8px 18px rgba(249,115,22,.24);}.us2-green{background:#16a34a;}
.us2-feedback{min-height:24px;margin-top:12px;text-align:center;font-size:14px;font-weight:900;color:#534AB7;}
.us2-feedback.good{color:#166534;}.us2-feedback.bad{color:#991b1b;}
.us2-score{display:none;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px;}
.us2-score.is-visible{display:grid;}
.us2-score-card{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:16px;padding:12px;text-align:center;}
.us2-score-num{font-family:'Fredoka','Trebuchet MS',Arial,sans-serif;font-size:26px;font-weight:800;line-height:1;color:#7F77DD;}
.us2-score-label{margin-top:4px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:900;color:#9B94BE;}
.us2-completed{display:none;text-align:center;padding:28px 12px;}
.us2-completed.is-visible{display:block;}
.us2-completed-icon{font-size:34px;line-height:1;margin-bottom:8px;}
.us2-completed-title{margin:0 0 8px;font-family:'Fredoka','Trebuchet MS',Arial,sans-serif;color:#F97316;font-size:34px;font-weight:800;}
.us2-completed-text{margin:0 0 8px;color:#8b82b7;font-size:15px;font-weight:800;}
.us2-hidden{display:none!important;}
body.fullscreen-embedded .us2-page{height:100vh;padding:16px;align-items:center;}
body.fullscreen-embedded .us2-app{width:min(1040px,100%);}
body.fullscreen-embedded .us2-title{font-size:clamp(34px,5vw,58px);}
body.fullscreen-embedded .us2-card{max-height:calc(100vh - 130px);overflow:auto;}
@media(max-width:720px){.us2-page{padding:12px}.us2-card{border-radius:22px}.us2-score{grid-template-columns:1fr}.us2-btn{width:100%;max-width:280px}.us2-controls{gap:8px}.us2-chip{font-size:16px;padding:10px 14px}}
</style>

<div class="us2-page" id="us2Page">
    <div class="us2-app" id="us2App">
        <div class="us2-top">
            <div class="us2-kicker">Unscramble <span id="us2Counter">1 / <?= count($sentences) ?></span></div>
            <h1 class="us2-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="us2-subtitle">Unscramble the words to form the correct sentence.</p>
        </div>

        <div class="us2-card" id="us2Card" data-az-zoom>
            <div id="us2PlayArea">
                <div class="us2-listen-row">
                    <button id="us2Listen" class="us2-btn us2-purple" type="button">Listen</button>
                </div>

                <div class="us2-build" id="us2Build" aria-label="Answer area">
                    <span class="us2-placeholder" id="us2Placeholder">Drag or click words here to build the sentence...</span>
                </div>

                <div class="us2-bank" id="us2Bank" aria-label="Word bank"></div>

                <div class="us2-controls">
                    <button id="us2Show" class="us2-btn us2-purple" type="button">Show Answer</button>
                    <button id="us2Next" class="us2-btn us2-orange" type="button">Next</button>
                </div>

                <div class="us2-feedback" id="us2Feedback"></div>

                <div class="us2-score" id="us2Score">
                    <div class="us2-score-card"><div class="us2-score-num" id="us2Correct">0</div><div class="us2-score-label">Correct</div></div>
                    <div class="us2-score-card"><div class="us2-score-num" id="us2Wrong">0</div><div class="us2-score-label">Wrong</div></div>
                    <div class="us2-score-card"><div class="us2-score-num" id="us2Pct">0%</div><div class="us2-score-label">Score</div></div>
                </div>
            </div>

            <div class="us2-completed" id="us2Completed">
                <div class="us2-completed-icon">✅</div>
                <h2 class="us2-completed-title">Completed!</h2>
                <p class="us2-completed-text" id="us2CompletedText"></p>
                <p class="us2-completed-text" id="us2ScoreText"></p>
                <button id="us2Restart" class="us2-btn us2-purple" type="button">Restart</button>
            </div>
        </div>
    </div>
</div>

<audio id="us2Win" src="../../hangman/assets/win.mp3" preload="auto"></audio>
<audio id="us2Lose" src="../../hangman/assets/lose.mp3" preload="auto"></audio>
<audio id="us2Done" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>
<audio id="us2TtsAudio" preload="none"></audio>

<script>
(function(){
'use strict';

const sentences = <?= json_encode($sentences, JSON_UNESCAPED_UNICODE) ?>;
const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const activityId = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
const returnTo = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const voiceId = <?= json_encode($activityVoiceId, JSON_UNESCAPED_UNICODE) ?>;
const ttsUrl = 'tts.php';

let index = 0;
let currentWords = [];
let currentSentence = '';
let listenEnabled = true;
let dragged = null;
let locked = false;
let scoredThisSentence = false;
let attemptsThisSentence = 0;
let correct = 0;
let wrong = 0;
let audioUrl = '';
let currentAudio = null;
let isSpeaking = false;
let isPaused = false;

const playArea = document.getElementById('us2PlayArea');
const completed = document.getElementById('us2Completed');
const counter = document.getElementById('us2Counter');
const build = document.getElementById('us2Build');
const bank = document.getElementById('us2Bank');
const placeholder = document.getElementById('us2Placeholder');
const listenBtn = document.getElementById('us2Listen');
const showBtn = document.getElementById('us2Show');
const nextBtn = document.getElementById('us2Next');
const restartBtn = document.getElementById('us2Restart');
const feedback = document.getElementById('us2Feedback');
const score = document.getElementById('us2Score');
const correctEl = document.getElementById('us2Correct');
const wrongEl = document.getElementById('us2Wrong');
const pctEl = document.getElementById('us2Pct');
const completedText = document.getElementById('us2CompletedText');
const scoreText = document.getElementById('us2ScoreText');
const winSound = document.getElementById('us2Win');
const loseSound = document.getElementById('us2Lose');
const doneSound = document.getElementById('us2Done');
const ttsAudio = document.getElementById('us2TtsAudio');

function shuffle(items){
    const arr = items.slice();
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        const tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    }
    return arr;
}

function playSound(el){
    if (!el) return;
    try { el.currentTime = 0; el.play().catch(function(){}); } catch(e) {}
}

function stopAudio(){
    if (currentAudio) {
        try { currentAudio.pause(); currentAudio.currentTime = 0; } catch(e) {}
        currentAudio = null;
    }
    if (audioUrl) {
        try { URL.revokeObjectURL(audioUrl); } catch(e) {}
        audioUrl = '';
    }
    if (window.speechSynthesis) {
        try { window.speechSynthesis.cancel(); } catch(e) {}
    }
    isSpeaking = false;
    isPaused = false;
    setListenLabel();
}

function percent(){
    const total = sentences.length || 1;
    return Math.round((correct / total) * 100);
}

function updateScore(show){
    if (score) score.classList.toggle('is-visible', !!show);
    if (correctEl) correctEl.textContent = String(correct);
    if (wrongEl) wrongEl.textContent = String(wrong);
    if (pctEl) pctEl.textContent = percent() + '%';
}

function updatePlaceholder(){
    const hasWords = build.querySelectorAll('.us2-chip').length > 0;
    if (placeholder) placeholder.classList.toggle('us2-hidden', hasWords);
}

function builtWords(){
    return Array.from(build.querySelectorAll('.us2-built,.us2-correct,.us2-wrong')).map(function(chip){ return chip.dataset.word || ''; });
}

function createChip(word, place){
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = place === 'build' ? 'us2-chip us2-built' : 'us2-chip';
    chip.textContent = word;
    chip.dataset.word = word;
    chip.draggable = true;

    chip.addEventListener('dragstart', function(e){
        if (locked) return;
        dragged = chip;
        chip.dataset.source = place;
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', word);
        }
    });

    chip.addEventListener('click', function(){
        if (locked) return;
        if (place === 'bank') moveToBuild(chip);
        else moveToBank(chip);
    });

    return chip;
}

function moveToBuild(chip){
    build.appendChild(createChip(chip.dataset.word || chip.textContent || '', 'build'));
    chip.remove();
    updatePlaceholder();
    window.setTimeout(autoCheck, 40);
}

function moveToBank(chip){
    bank.appendChild(createChip(chip.dataset.word || chip.textContent || '', 'bank'));
    chip.remove();
    feedback.textContent = '';
    feedback.className = 'us2-feedback';
    updatePlaceholder();
}

function lockChips(clsByIndex){
    Array.from(build.querySelectorAll('.us2-built')).forEach(function(chip, i){
        chip.className = 'us2-chip ' + (typeof clsByIndex === 'function' ? clsByIndex(chip, i) : clsByIndex);
        chip.draggable = false;
        chip.disabled = true;
    });
    Array.from(bank.querySelectorAll('.us2-chip')).forEach(function(chip){ chip.disabled = true; chip.draggable = false; });
}

function markScore(isCorrect){
    if (scoredThisSentence) return;
    scoredThisSentence = true;
    if (isCorrect) correct += 1;
    else wrong += 1;
    updateScore(true);
}

function loadSentence(){
    stopAudio();
    locked = false;
    scoredThisSentence = false;
    attemptsThisSentence = 0;
    dragged = null;
    build.querySelectorAll('.us2-chip').forEach(function(el){ el.remove(); });
    bank.innerHTML = '';
    feedback.textContent = '';
    feedback.className = 'us2-feedback';

    const item = sentences[index] || {};
    currentSentence = String(item.sentence || '').trim();
    currentWords = Array.isArray(item.words) && item.words.length ? item.words.slice() : currentSentence.split(/\s+/).filter(Boolean);
    listenEnabled = item.listen_enabled !== false;

    if (counter) counter.textContent = (index + 1) + ' / ' + sentences.length;
    if (listenBtn) listenBtn.classList.toggle('us2-hidden', !listenEnabled);
    if (nextBtn) nextBtn.textContent = index >= sentences.length - 1 ? 'Finish' : 'Next';

    shuffle(currentWords).forEach(function(word){ bank.appendChild(createChip(word, 'bank')); });
    updatePlaceholder();
}

function check(){
    if (locked) return false;
    const built = builtWords();
    if (built.length < currentWords.length) {
        feedback.textContent = 'Place all words first.';
        feedback.className = 'us2-feedback bad';
        return false;
    }

    attemptsThisSentence += 1;
    const ok = JSON.stringify(built) === JSON.stringify(currentWords);

    if (ok) {
        locked = true;
        feedback.textContent = 'Correct!';
        feedback.className = 'us2-feedback good';
        markScore(true);
        lockChips('us2-correct');
        playSound(winSound);
        return true;
    }

    playSound(loseSound);
    if (attemptsThisSentence >= 2) {
        locked = true;
        feedback.textContent = 'Incorrect. Review the order and continue.';
        feedback.className = 'us2-feedback bad';
        markScore(false);
        lockChips(function(chip, i){ return (built[i] || '') === (currentWords[i] || '') ? 'us2-correct' : 'us2-wrong'; });
        return true;
    }

    feedback.textContent = 'Incorrect. Try again.';
    feedback.className = 'us2-feedback bad';
    return false;
}

function autoCheck(){
    if (locked) return;
    if (builtWords().length >= currentWords.length) check();
}

function showAnswer(){
    if (locked) return;
    markScore(false);
    locked = true;
    build.querySelectorAll('.us2-chip').forEach(function(el){ el.remove(); });
    bank.innerHTML = '';
    currentWords.forEach(function(word){
        const chip = createChip(word, 'build');
        chip.className = 'us2-chip us2-correct';
        chip.draggable = false;
        chip.disabled = true;
        build.appendChild(chip);
    });
    updatePlaceholder();
    feedback.textContent = 'Correct order shown.';
    feedback.className = 'us2-feedback good';
}

async function persistScore(){
    if (!activityId || !returnTo) return;
    const joiner = returnTo.indexOf('?') !== -1 ? '&' : '?';
    const url = returnTo + joiner
        + 'activity_percent=' + encodeURIComponent(String(percent()))
        + '&activity_errors=' + encodeURIComponent(String(wrong))
        + '&activity_total=' + encodeURIComponent(String(sentences.length))
        + '&activity_id=' + encodeURIComponent(activityId)
        + '&activity_type=unscramble';
    try {
        await fetch(url, { method:'GET', credentials:'same-origin', cache:'no-store' });
    } catch(e) {}
}

function finish(){
    stopAudio();
    playArea.classList.add('us2-hidden');
    completed.classList.add('is-visible');
    playSound(doneSound);
    if (completedText) completedText.textContent = "You've completed " + (activityTitle || 'this activity') + '.';
    if (scoreText) scoreText.textContent = correct + ' correct · ' + wrong + ' wrong · ' + percent() + '%';
    persistScore();
}

function next(){
    if (!locked) {
        const attempted = check();
        if (!attempted) return;
    }
    if (index >= sentences.length - 1) {
        finish();
        return;
    }
    index += 1;
    loadSentence();
}

function restart(){
    stopAudio();
    index = 0;
    correct = 0;
    wrong = 0;
    playArea.classList.remove('us2-hidden');
    completed.classList.remove('is-visible');
    updateScore(false);
    loadSentence();
}

function setListenLabel(){
    if (!listenBtn) return;
    if (isPaused) listenBtn.textContent = 'Resume';
    else if (isSpeaking) listenBtn.textContent = 'Pause';
    else listenBtn.textContent = 'Listen';
}

function browserSpeak(text){
    if (!window.speechSynthesis) return;
    try { window.speechSynthesis.cancel(); } catch(e) {}
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'en-US';
    u.rate = 0.9;
    u.pitch = 1.02;
    u.onend = function(){ isSpeaking = false; isPaused = false; setListenLabel(); };
    isSpeaking = true;
    isPaused = false;
    setListenLabel();
    window.speechSynthesis.speak(u);
}

function speak(){
    if (!listenEnabled || !currentSentence) return;

    if (currentAudio) {
        if (!currentAudio.paused) {
            currentAudio.pause();
            isSpeaking = true;
            isPaused = true;
            setListenLabel();
        } else {
            currentAudio.play().then(function(){ isSpeaking = true; isPaused = false; setListenLabel(); }).catch(function(){});
        }
        return;
    }

    isSpeaking = true;
    isPaused = false;
    setListenLabel();
    listenBtn.disabled = true;

    const fd = new FormData();
    fd.append('text', currentSentence);
    fd.append('voice_id', voiceId || 'nzFihrBIvB34imQBuxub');

    fetch(ttsUrl, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(res){ if (!res.ok) throw new Error('TTS ' + res.status); return res.blob(); })
        .then(function(blob){
            audioUrl = URL.createObjectURL(blob);
            currentAudio = ttsAudio || new Audio();
            currentAudio.src = audioUrl;
            currentAudio.onended = function(){ stopAudio(); };
            currentAudio.onpause = function(){ if (currentAudio && currentAudio.currentTime < (currentAudio.duration || Infinity)) { isSpeaking = true; isPaused = true; setListenLabel(); } };
            return currentAudio.play();
        })
        .catch(function(){ browserSpeak(currentSentence); })
        .finally(function(){ listenBtn.disabled = false; });
}

build.addEventListener('dragover', function(e){ e.preventDefault(); build.classList.add('is-drag'); });
build.addEventListener('dragleave', function(){ build.classList.remove('is-drag'); });
build.addEventListener('drop', function(e){
    e.preventDefault();
    build.classList.remove('is-drag');
    if (!dragged || locked) return;
    const source = dragged.dataset.source || 'bank';
    if (source === 'bank') moveToBuild(dragged);
    else build.appendChild(dragged);
    dragged = null;
    updatePlaceholder();
    window.setTimeout(autoCheck, 40);
});

bank.addEventListener('dragover', function(e){ e.preventDefault(); });
bank.addEventListener('drop', function(e){
    e.preventDefault();
    if (!dragged || locked) return;
    if ((dragged.dataset.source || '') === 'build') moveToBank(dragged);
    dragged = null;
});

listenBtn.addEventListener('click', speak);
showBtn.addEventListener('click', showAnswer);
nextBtn.addEventListener('click', next);
restartBtn.addEventListener('click', restart);

updateScore(false);
loadSentence();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔀', $content);
?>

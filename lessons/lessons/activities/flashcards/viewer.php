<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function activities_columns(PDO $pdo): array {
    static $cache = null;

    if (is_array($cache)) return $cache;

    $cache = [];

    $stmt = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
        AND table_name = 'activities'
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string)$row['column_name'];
        }
    }

    return $cache;
}

function load_flashcards(PDO $pdo, string $activityId): array {

    $stmt = $pdo->prepare("
        SELECT *
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $activityId
    ]);

    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        return [];
    }

    $raw = $activity['data'] ?? $activity['content_json'] ?? '[]';

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return [];
    }

    if (isset($decoded['cards']) && is_array($decoded['cards'])) {
        return $decoded['cards'];
    }

    return $decoded;
}

$data = load_flashcards($pdo, $activityId);

if (!$data || !count($data)) {
    die('No flashcards found');
}

$title = 'Flashcards';

?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@500;600;700;800&display=swap" rel="stylesheet">

<style>

:root{
    --orange:#F97316;
    --purple:#7F77DD;
    --purple-dark:#534AB7;
    --muted:#9B94BE;
    --soft:#F4F2FD;
    --card:#FFFFFF;
    --border:#ECE9FA;
}

*{
    box-sizing:border-box;
}

html,
body{
    width:100%;
    height:100%;
    min-height:100%;
    margin:0;
    padding:0;
    background:#fff;
    font-family:'Nunito',sans-serif;
    overflow:hidden;
}

.fc-shell{
    width:100%;
    flex:1;
    height:100%;
    min-height:0;
    overflow:hidden;
    padding:clamp(14px,2.5vw,34px);
    background:#fff;
    display:flex;
    flex-direction:column;
    align-items:center;
}

.fc-app{
    width:min(760px,100%);
    margin:0 auto;
    height:100%;
    min-height:0;
    display:grid;
    grid-template-rows:auto minmax(0,1fr) auto;
    gap:16px;
}

.fc-header{
    text-align:center;
    flex-shrink:0;
}

.fc-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 14px;
    border-radius:999px;
    background:#FFF0E6;
    border:1px solid #FCDDBF;
    color:#C2580A;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:10px;
}

.fc-title{
    margin:0;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5vw,54px);
    line-height:1;
    color:var(--orange);
    font-weight:600;
}

.fc-subtitle{
    margin-top:10px;
    color:var(--muted);
    font-size:14px;
    font-weight:700;
}

.fc-board{
    height:100%;
    min-height:0;
    background:#fff;
    border:1px solid var(--border);
    border-radius:32px;
    padding:18px;
    box-shadow:0 8px 40px rgba(127,119,221,.12);
    display:grid;
    grid-template-rows:auto minmax(0,1fr) auto;
    gap:14px;
    overflow:hidden;
}

.fc-progress{
    display:flex;
    align-items:center;
    gap:10px;
    flex-shrink:0;
}

.fc-track{
    flex:1;
    height:12px;
    background:var(--soft);
    border-radius:999px;
    overflow:hidden;
}

.fc-fill{
    height:100%;
    width:0%;
    background:linear-gradient(90deg,var(--orange),var(--purple));
    border-radius:999px;
    transition:.35s;
}

.fc-count{
    min-width:74px;
    text-align:center;
    padding:7px 10px;
    border-radius:999px;
    background:var(--purple);
    color:#fff;
    font-size:12px;
    font-weight:900;
}

.fc-card{
    position:relative;
    border-radius:30px;
    perspective:1000px;
    height:100%;
    min-height:0;
    max-height:none;
    min-width:0;
}

.fc-inner{
    position:relative;
    width:100%;
    height:100%;
    transform-style:preserve-3d;
    transition:transform .45s ease;
}

.fc-card.flipped .fc-inner{
    transform:rotateY(180deg);
}

.fc-face{
    position:absolute;
    inset:0;
    backface-visibility:hidden;
    border-radius:30px;
    overflow:hidden;
    min-height:0;
}

.fc-front{
    position:relative;
    background:#fff;
    border:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:clamp(16px,2.4vw,28px);
    text-align:center;
    cursor:pointer;
}

.fc-back{
    background:#fff;
    border:1px solid var(--border);
    transform:rotateY(180deg);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:clamp(16px,2.2vw,24px);
    text-align:center;
    gap:14px;
    cursor:pointer;
}

.fc-image-wrap{
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:0;
}

.fc-image{
    width:100%;
    height:100%;
    max-width:100%;
    max-height:100%;
    object-fit:contain;
    border-radius:24px;
}

.fc-placeholder{
    width:100%;
    height:100%;
    border-radius:24px;
    background:#FAFAFD;
    border:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#D5D0F0;
}

.fc-placeholder svg{
    width:84px;
    height:84px;
}

.fc-flip-hint{
    position:absolute;
    bottom:18px;
    left:50%;
    transform:translateX(-50%);
    color:#C4BEF0;
    font-size:12px;
    font-weight:800;
    letter-spacing:.02em;
    pointer-events:none;
}

.fc-card.flipped .fc-flip-hint{
    display:none;
}

.fc-word{
    color:var(--purple);
    font-size:clamp(30px,5vw,52px);
    font-weight:700;
    line-height:1.1;
}

.fc-translation{
    margin-top:14px;
    color:var(--muted);
    font-size:18px;
    font-weight:700;
    line-height:1.5;
}

.fc-actions{
    display:flex;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
    flex-shrink:0;
}

.fc-btn{
    border:0;
    border-radius:999px;
    padding:13px 20px;
    min-width:120px;
    color:#fff;
    cursor:pointer;
    font-family:'Nunito',sans-serif;
    font-size:14px;
    font-weight:900;
    transition:.18s;
    box-shadow:0 6px 18px rgba(127,119,221,.15);
}

.fc-btn:hover{
    transform:translateY(-1px);
}

.fc-btn-purple{
    background:var(--purple);
}

.fc-btn-orange{
    background:var(--orange);
}

.fc-completed{
    display:none;
    text-align:center;
    padding:42px 20px;
}

.fc-size-warning{
    position:fixed;
    right:16px;
    bottom:16px;
    width:min(92vw,360px);
    background:#fff;
    border:1px solid #FCDDBF;
    border-left:6px solid var(--orange);
    border-radius:14px;
    box-shadow:0 12px 34px rgba(0,0,0,.16);
    padding:12px 12px 10px;
    z-index:9999;
}

.fc-size-warning[hidden]{
    display:none;
}

.fc-size-warning-title{
    margin:0 0 4px;
    color:#C2580A;
    font-weight:900;
    font-size:13px;
    letter-spacing:.02em;
}

.fc-size-warning-text{
    margin:0;
    color:#5f5a80;
    font-size:13px;
    line-height:1.4;
    font-weight:700;
}

.fc-size-warning-close{
    position:absolute;
    top:6px;
    right:8px;
    border:0;
    background:transparent;
    color:#9B94BE;
    cursor:pointer;
    font-size:18px;
    line-height:1;
}

.fc-completed.active{
    display:block;
}

.fc-check{
    width:72px;
    height:72px;
    margin:0 auto 18px;
    border-radius:999px;
    background:#F4F2FD;
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--purple);
    font-size:34px;
    font-weight:900;
}

.fc-done-title{
    margin:0;
    color:var(--orange);
    font-family:'Fredoka',sans-serif;
    font-size:42px;
}

.fc-done-text{
    margin-top:12px;
    color:var(--muted);
    font-size:16px;
    font-weight:700;
}

@media(max-width:640px){

    .fc-shell{
        padding:12px;
    }

    .fc-app{
        gap:12px;
    }

    .fc-board{
        border-radius:24px;
        padding:14px;
        gap:12px;
    }

    .fc-inner{
        height:100%;
    }

    .fc-actions{
        display:grid;
        grid-template-columns:1fr;
    }

    .fc-btn{
        width:100%;
    }

    .fc-size-warning{
        left:10px;
        right:10px;
        width:auto;
        bottom:10px;
    }

}

</style>

<div class="fc-shell">

    <div class="fc-app">

        <div class="fc-header">
            <div class="fc-kicker">
                Flashcards
            </div>

            <h1 class="fc-title">
                <?php echo htmlspecialchars($title); ?>
            </h1>

            <div class="fc-subtitle">
                Listen carefully and repeat naturally
            </div>
        </div>

        <div class="fc-board" id="fc-board">

            <div class="fc-progress">
                <div class="fc-track">
                    <div class="fc-fill" id="fc-fill"></div>
                </div>

                <div class="fc-count" id="fc-count">
                    1 / <?php echo count($data); ?>
                </div>
            </div>

            <div class="fc-card" id="fc-card">

                <div class="fc-inner">

                    <div class="fc-face fc-front" id="fc-front">
                        <div class="fc-image-wrap">

                            <img
                                id="fc-image"
                                class="fc-image"
                                src=""
                                alt=""
                                style="display:none;"
                            >

                            <div class="fc-placeholder" id="fc-placeholder">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4-4 4 4 8-8"/>
                                </svg>
                            </div>

                        </div>
                        <div class="fc-flip-hint" id="fc-flip-hint">Tap to flip</div>
                    </div>

                    <div class="fc-face fc-back">

                        <div class="fc-back-image-wrap" id="fc-back-image-wrap" style="width:100%;height:100%;display:none;align-items:center;justify-content:center;">
                            <img id="fc-back-image" class="fc-image" src="" alt="" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:18px;">
                        </div>

                        <div class="fc-word" id="fc-word"></div>

                        <div class="fc-translation" id="fc-translation"></div>

                    </div>

                </div>

            </div>

            <div class="fc-actions">

                <button class="fc-btn fc-btn-orange" id="fc-repeat">
                    Listen
                </button>

                <button class="fc-btn fc-btn-purple" id="fc-flip">
                    Flip
                </button>

                <button class="fc-btn fc-btn-orange" id="fc-next">
                    Next
                </button>

            </div>

        </div>

        <div class="fc-completed" id="fc-completed">

            <div class="fc-check">
                ✓
            </div>

            <h2 class="fc-done-title">
                Complete
            </h2>

            <div class="fc-done-text">
                Great listening and pronunciation practice.
            </div>

            <div class="fc-actions" style="margin-top:24px;">

                <button class="fc-btn fc-btn-orange" id="fc-restart">
                    Restart
                </button>

            </div>

        </div>

    </div>

</div>

<div class="fc-size-warning" id="fc-size-warning" hidden>
    <button class="fc-size-warning-close" id="fc-size-warning-close" aria-label="Close image size warning">&times;</button>
    <p class="fc-size-warning-title">Image too large</p>
    <p class="fc-size-warning-text" id="fc-size-warning-text"></p>
</div>

<script>

(function(){

'use strict';

var cards = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

var current = 0;
var flipped = false;

var cardEl = document.getElementById('fc-card');
var imageEl = document.getElementById('fc-image');
var placeholderEl = document.getElementById('fc-placeholder');
var backImageWrapEl = document.getElementById('fc-back-image-wrap');
var backImageEl = document.getElementById('fc-back-image');
var wordEl = document.getElementById('fc-word');
var translationEl = document.getElementById('fc-translation');

var fillEl = document.getElementById('fc-fill');
var countEl = document.getElementById('fc-count');
var shellEl = document.querySelector('.fc-shell');
var appEl = document.querySelector('.fc-app');
var headerEl = document.querySelector('.fc-header');
var actionsEl = document.querySelector('.fc-actions');
var progressEl = document.querySelector('.fc-progress');
var sizeWarningEl = document.getElementById('fc-size-warning');
var sizeWarningTextEl = document.getElementById('fc-size-warning-text');
var sizeWarningCloseEl = document.getElementById('fc-size-warning-close');

var completedEl = document.getElementById('fc-completed');
var boardEl = document.getElementById('fc-board');
var warningDismissed = false;

function num(v){
    return parseFloat(v) || 0;
}

function showSizeWarning(message){
    if (warningDismissed || !sizeWarningEl || !sizeWarningTextEl) return;
    sizeWarningTextEl.textContent = message;
    sizeWarningEl.hidden = false;
}

function hideSizeWarning(){
    if (!sizeWarningEl) return;
    sizeWarningEl.hidden = true;
}

function evaluateImageSize(imgEl){
    if (!imgEl || !imgEl.src || !imgEl.naturalWidth || !imgEl.naturalHeight) {
        hideSizeWarning();
        return;
    }

    var cardRect = cardEl.getBoundingClientRect();
    var recommendedWidth = Math.max(900, Math.round(cardRect.width * 2));
    var recommendedHeight = Math.max(900, Math.round(cardRect.height * 2));

    if (imgEl.naturalWidth > recommendedWidth || imgEl.naturalHeight > recommendedHeight) {
        showSizeWarning(
            'Current image: ' + imgEl.naturalWidth + 'x' + imgEl.naturalHeight + ' px. '
            + 'Recommended max: ' + recommendedWidth + 'x' + recommendedHeight + ' px.'
        );
    } else {
        hideSizeWarning();
    }
}

function syncLayout(){
    if (!boardEl || !cardEl || boardEl.style.display === 'none') return;

    var shellStyles = window.getComputedStyle(shellEl);
    var appStyles = window.getComputedStyle(appEl);
    var boardStyles = window.getComputedStyle(boardEl);

    var shellPaddingY = num(shellStyles.paddingTop) + num(shellStyles.paddingBottom);
    var appGap = num(appStyles.gap);
    var boardPaddingY = num(boardStyles.paddingTop) + num(boardStyles.paddingBottom);
    var boardBorderY = num(boardStyles.borderTopWidth) + num(boardStyles.borderBottomWidth);
    var boardGap = num(boardStyles.gap);

    var viewportHeight = window.innerHeight;
    var boardTargetHeight = Math.floor(
        viewportHeight
        - shellPaddingY
        - headerEl.offsetHeight
        - appGap
        - 12
    );

    boardTargetHeight = Math.max(250, boardTargetHeight);
    boardEl.style.height = boardTargetHeight + 'px';

    var actionsHeight = actionsEl ? actionsEl.offsetHeight : 0;
    var progressHeight = progressEl ? progressEl.offsetHeight : 0;
    var cardHeight = Math.floor(
        boardTargetHeight
        - boardPaddingY
        - boardBorderY
        - progressHeight
        - actionsHeight
        - (boardGap * 2)
    );

    cardHeight = Math.max(150, cardHeight);
    cardEl.style.height = cardHeight + 'px';

    if (imageEl && imageEl.style.display !== 'none') {
        evaluateImageSize(imageEl);
    }
}

function voiceProfileFromId(voiceId) {
    var id = String(voiceId || '').trim();
    if (id === 'NoOVOzCQFLOvtsMoNcdT') return 'female';
    if (id === 'Nggzl2QAXh3OijoXD116') return 'child';
    return 'male';
}

function pickVoice(voices, profile) {
    if (!Array.isArray(voices) || voices.length === 0) return null;

    var profileKeywords = {
        male: [' male', 'david', 'guy', 'daniel', 'george', 'matthew'],
        female: [' female', 'zira', 'jenny', 'susan', 'aria', 'sara', 'rachel'],
        child: ['child', 'kid', 'junior', 'young', 'lily']
    };

    var keys = profileKeywords[profile] || profileKeywords.male;
    var best = null;
    var bestScore = -1;

    for (var i = 0; i < voices.length; i++) {
        var v = voices[i];
        var name = String(v.name || '').toLowerCase();
        var lang = String(v.lang || '').toLowerCase();
        var score = 0;

        if (lang.indexOf('en') === 0) score += 4;
        for (var k = 0; k < keys.length; k++) {
            if (name.indexOf(keys[k]) !== -1) score += 6;
        }

        if (profile === 'child' && name.indexOf('female') !== -1) score += 1;
        if (profile === 'male' && name.indexOf('female') !== -1) score -= 3;
        if (profile === 'female' && name.indexOf('male') !== -1) score -= 3;

        if (score > bestScore) {
            best = v;
            bestScore = score;
        }
    }

    return best || voices[0] || null;
}

function getCard(){
    return cards[current] || {};
}

function getWord(card){
    return String(
        card.english_text ||
        card.text ||
        ''
    ).trim();
}

function getAudio(card){
    return String((card && card.audio) || '').trim();
}

function getTranslation(card){
    return String(
        card.spanish_text ||
        ''
    ).trim();
}

function updateProgress(){

    var total = cards.length;
    var pct = ((current + 1) / total) * 100;

    fillEl.style.width = pct + '%';

    countEl.textContent =
        (current + 1) +
        ' / ' +
        total;
}

function renderCard(){

    var card = getCard();

    var word = getWord(card);
    var translation = getTranslation(card);

    wordEl.textContent = word;
    translationEl.textContent = translation;

    if(card.image){

        imageEl.src = card.image;
        imageEl.style.display = 'block';

        placeholderEl.style.display = 'none';

    }else{

        imageEl.style.display = 'none';
        placeholderEl.style.display = 'flex';

    }

    var backImg = String((card && card.back_image) || '').trim();
    if (backImg) {
        backImageEl.src = backImg;
        backImageWrapEl.style.display = 'flex';
        wordEl.style.display = 'none';
        translationEl.style.display = 'none';
    } else {
        backImageEl.src = '';
        backImageWrapEl.style.display = 'none';
        wordEl.style.display = '';
        translationEl.style.display = '';
    }

    flipped = false;
    cardEl.classList.remove('flipped');

    updateProgress();

    window.requestAnimationFrame(syncLayout);
}

function flip(){

    flipped = !flipped;

    if(flipped){
        cardEl.classList.add('flipped');
    }else{
        cardEl.classList.remove('flipped');
    }
}

function next(){

    if(current >= cards.length - 1){

        boardEl.style.display = 'none';
        completedEl.classList.add('active');
        hideSizeWarning();

        return;
    }

    current++;

    renderCard();
}

function restart(){

    current = 0;

    completedEl.classList.remove('active');
    boardEl.style.display = '';

    renderCard();
}

imageEl.addEventListener('load', function(){
    evaluateImageSize(imageEl);
});

if (sizeWarningCloseEl) {
    sizeWarningCloseEl.addEventListener('click', function(){
        warningDismissed = true;
        hideSizeWarning();
    });
}

window.addEventListener('resize', function(){
    window.requestAnimationFrame(syncLayout);
});

document.addEventListener('fullscreen-embedded', function(){
    window.requestAnimationFrame(syncLayout);
});

function speakText(text, profile) {
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);

    const setVoiceAndSpeak = () => {
        const voices = window.speechSynthesis.getVoices();
        const selectedVoice = pickVoice(voices, profile || 'male');

        utterance.voice = selectedVoice;
        utterance.rate = 0.88;
        utterance.pitch = 0.95;
        utterance.volume = 1;
        window.speechSynthesis.speak(utterance);
    };

    if (window.speechSynthesis.getVoices().length === 0) {
        window.speechSynthesis.onvoiceschanged = setVoiceAndSpeak;
    } else {
        setVoiceAndSpeak();
    }
}

function playAudio(){

    var card = getCard();
    var audioUrl = getAudio(card);
    if (audioUrl) {
        if (!playAudio._audio || playAudio._audio.getAttribute('data-src') !== audioUrl) {
            if (playAudio._audio) playAudio._audio.pause();
            playAudio._audio = new Audio(audioUrl);
            playAudio._audio.setAttribute('data-src', audioUrl);
        }
        if (!playAudio._audio.paused) {
            playAudio._audio.pause();
        } else {
            playAudio._audio.play().catch(function(){});
        }
        return;
    }

    var card2 = getCard();
    var text = getWord(card2);
    var perCardProfile = voiceProfileFromId(card2.voice_id);

    if(!text) return;

    if(!window.speechSynthesis) return;

    speakText(text, perCardProfile);
}

document
.getElementById('fc-repeat')
.addEventListener('click', playAudio);

document
.getElementById('fc-flip')
.addEventListener('click', flip);

document
.getElementById('fc-next')
.addEventListener('click', next);

document
.getElementById('fc-restart')
.addEventListener('click', restart);

document
.getElementById('fc-card')
.addEventListener('click', flip);

renderCard();
syncLayout();

})();

</script>

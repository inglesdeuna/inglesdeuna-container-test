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
    if (is_array($cache)) return $cache;

    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = activities_columns($pdo);

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
    $default = array('title' => default_flashcards_title(), 'cards' => array());
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
            'id'           => isset($item['id'])           ? trim((string) $item['id'])           : uniqid('fc_'),
            'english_text' => isset($item['english_text']) ? trim((string) $item['english_text']) : '',
            'spanish_text' => isset($item['spanish_text']) ? trim((string) $item['spanish_text']) : '',
            'text'         => isset($item['text'])         ? trim((string) $item['text'])         : '',
            'image'        => isset($item['image'])        ? trim((string) $item['image'])        : '',
        );
    }

    return array('title' => normalize_flashcards_title($title), 'cards' => $cards);
}

function load_flashcards_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);
    $selectFields = array('id');

    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'flashcards' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'flashcards' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'flashcards' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return array('title' => default_flashcards_title(), 'cards' => array());

    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];

    $payload = normalize_flashcards_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);

    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return array(
        'title' => normalize_flashcards_title((string) $payload['title']),
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') $unit = resolve_unit_from_activity($pdo, $activityId);

$activity = load_flashcards_activity($pdo, $unit, $activityId);
$data = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_flashcards_title();

if (count($data) === 0) die('No flashcards found for this unit');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --li-purple:#7F77DD;
    --li-purple-dark:#534AB7;
    --li-purple-soft:#EEEDFE;
    --li-purple-mid:#AFA9EC;
    --li-blue:#2563EB;
    --li-blue-dark:#1D4ED8;
    --li-pink:#EC4899;
    --li-pink-dark:#BE185D;
    --li-teal:#1D9E75;
    --li-ink:#271B5D;
    --li-muted:#7C739B;
    --li-white:#FFFFFF;
    --li-shadow:0 24px 60px rgba(83,74,183,.20);
}

*{box-sizing:border-box}

.fc-premium-shell{
    width:100%;
    min-height:calc(100vh - 90px);
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:'Nunito','Segoe UI',system-ui,sans-serif;
    background:
    radial-gradient(circle at 18% 18%,rgba(255,255,255,.70) 0 9%,transparent 10%),
    radial-gradient(circle at 84% 16%,rgba(255,255,255,.45) 0 7%,transparent 8%),
    radial-gradient(circle at 74% 82%,rgba(255,255,255,.40) 0 10%,transparent 11%),
    #ffffff;
    border-radius:16px;
    overflow:hidden;
}

.fc-premium-app{
    width:min(980px,100%);
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:clamp(12px,2vw,20px);
}

.fc-premium-hero{
    text-align:center;
    color:var(--li-white);
    text-shadow:0 2px 16px rgba(83,74,183,.24);
}

.fc-premium-kicker{
    display:inline-flex;
    align-items:center;
    gap:7px;
    margin-bottom:8px;
    padding:7px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.24);
    color:#fff;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    backdrop-filter:blur(8px);
}

.fc-premium-title{
    margin:0;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    line-height:1;
    color:#fff;
    font-weight:700;
}

.fc-premium-subtitle{
    margin:8px 0 0;
    color:rgba(255,255,255,.92);
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
}

.fc-premium-board{
    position:relative;
    width:min(760px,100%);
    margin:0 auto;
    background:rgba(255,255,255,.84);
    border:1px solid rgba(255,255,255,.80);
    border-radius:34px;
    padding:clamp(16px,2.6vw,26px);
    box-shadow:var(--li-shadow);
    backdrop-filter:blur(14px);
}

.fc-premium-progress-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
    align-items:center;
    margin-bottom:clamp(14px,2vw,20px);
}

.fc-premium-progress-track{
    height:12px;
    background:#ECEAFB;
    border-radius:999px;
    overflow:hidden;
    border:1px solid rgba(127,119,221,.18);
}

.fc-premium-progress-fill{
    height:100%;
    width:0%;
    background:linear-gradient(90deg,var(--li-purple),#A78BFA,var(--li-pink));
    border-radius:999px;
    transition:width .45s cubic-bezier(.2,.9,.2,1);
}

.fc-premium-progress-count{
    min-width:74px;
    text-align:center;
    padding:7px 11px;
    border-radius:999px;
    background:var(--li-purple-dark);
    color:#fff;
    font-size:13px;
    font-weight:900;
    box-shadow:0 10px 20px rgba(83,74,183,.18);
}

.fc-premium-card-wrap{
    position:relative;
    display:grid;
    grid-template-columns:auto minmax(0,1fr) auto;
    align-items:center;
    gap:clamp(8px,1.6vw,18px);
}

.fc-premium-arrow{
    width:clamp(38px,5vw,54px);
    height:clamp(38px,5vw,54px);
    border-radius:50%;
    border:0;
    background:#fff;
    color:var(--li-purple-dark);
    box-shadow:0 12px 24px rgba(83,74,183,.18);
    font-size:clamp(24px,4vw,34px);
    font-weight:900;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:transform .18s ease, box-shadow .18s ease, background .18s ease;
    z-index:3;
}

.fc-premium-arrow:hover{
    transform:translateY(-2px) scale(1.05);
    background:var(--li-purple-soft);
    box-shadow:0 16px 28px rgba(83,74,183,.24);
}

.fc-premium-card{
    perspective:1200px;
    min-height:clamp(330px,46vh,470px);
    cursor:pointer;
    outline:none;
}

.fc-premium-card-inner{
    position:relative;
    width:100%;
    height:100%;
    min-height:inherit;
    transform-style:preserve-3d;
    transition:transform .65s cubic-bezier(.2,.8,.2,1);
}

.fc-premium-card.is-flipped .fc-premium-card-inner{
    transform:rotateY(180deg);
}

.fc-premium-face{
    position:absolute;
    inset:0;
    border-radius:30px;
    backface-visibility:hidden;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:clamp(22px,4vw,42px);
    border:1px solid rgba(127,119,221,.16);
    box-shadow:0 18px 36px rgba(39,27,93,.13);
}

.fc-premium-front{
    background:
        radial-gradient(circle at 20% 18%,rgba(127,119,221,.12),transparent 26%),
        radial-gradient(circle at 78% 82%,rgba(236,72,153,.10),transparent 28%),
        #ffffff;
}

.fc-premium-back{
    transform:rotateY(180deg);
    background:
        radial-gradient(circle at 20% 20%,rgba(255,255,255,.22),transparent 26%),
        radial-gradient(circle at 80% 80%,rgba(255,255,255,.14),transparent 30%),
        linear-gradient(135deg,var(--li-purple) 0%,var(--li-purple-dark) 58%,#3B2E91 100%);
}

.fc-premium-image-box{
    width:min(270px,64vw);
    height:min(270px,64vw);
    max-height:290px;
    border-radius:28px;
    background:#fff;
    border:1.5px solid rgba(127,119,221,.18);
    box-shadow:0 16px 34px rgba(83,74,183,.14);
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}

.fc-premium-image-box img{
    max-width:82%;
    max-height:82%;
    object-fit:contain;
    display:block;
}

.fc-premium-placeholder{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(76px,16vw,150px);
    color:var(--li-purple);
    font-weight:700;
}

.fc-premium-word{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(46px,9vw,96px);
    color:#fff;
    font-weight:700;
    line-height:1.05;
    text-align:center;
    text-shadow:0 8px 22px rgba(0,0,0,.18);
}

.fc-premium-translation{
    margin-top:12px;
    padding:8px 15px;
    border-radius:999px;
    background:rgba(255,255,255,.18);
    color:#fff;
    font-size:clamp(14px,2vw,20px);
    font-weight:900;
}

.fc-premium-hint{
    position:absolute;
    left:50%;
    bottom:clamp(14px,2vw,22px);
    transform:translateX(-50%);
    color:var(--li-muted);
    font-size:12px;
    font-weight:900;
    letter-spacing:.02em;
    text-align:center;
    pointer-events:none;
}

.fc-premium-back .fc-premium-hint{
    color:rgba(255,255,255,.78);
}

.fc-premium-actions{
    margin-top:clamp(16px,2.4vw,24px);
    display:flex;
    justify-content:center;
    align-items:center;
    gap:clamp(8px,1.4vw,12px);
    flex-wrap:wrap;
}

.fc-premium-btn{
    border:0;
    border-radius:999px;
    min-width:clamp(104px,16vw,146px);
    padding:13px 20px;
    color:#fff;
    font-family:'Nunito',sans-serif;
    font-size:clamp(13px,1.8vw,15px);
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    box-shadow:0 12px 22px rgba(37,99,235,.20);
    transition:transform .18s ease, filter .18s ease, box-shadow .18s ease;
}

.fc-premium-btn:hover{
    transform:translateY(-2px);
    filter:brightness(1.05);
}

.fc-premium-btn-blue{
    background:linear-gradient(135deg,var(--li-blue),var(--li-blue-dark));
}

.fc-premium-btn-pink{
    background:linear-gradient(135deg,var(--li-pink),var(--li-pink-dark));
    box-shadow:0 12px 22px rgba(190,24,93,.20);
}

.fc-premium-btn-purple{
    background:linear-gradient(135deg,var(--li-purple),var(--li-purple-dark));
    box-shadow:0 12px 22px rgba(83,74,183,.22);
}

.fc-premium-completed{
    display:none;
    width:min(680px,100%);
    margin:0 auto;
    text-align:center;
    padding:clamp(28px,5vw,54px);
    border-radius:34px;
    background:rgba(255,255,255,.88);
    border:1px solid rgba(255,255,255,.82);
    box-shadow:var(--li-shadow);
    backdrop-filter:blur(14px);
}

.fc-premium-completed.active{
    display:block;
    animation:fcPop .45s cubic-bezier(.2,.9,.2,1);
}

.fc-premium-done-icon{
    font-size:clamp(66px,12vw,112px);
    margin-bottom:12px;
}

.fc-premium-done-title{
    margin:0 0 10px;
    font-family:'Fredoka',sans-serif;
    font-size:clamp(34px,6vw,62px);
    color:var(--li-purple-dark);
    line-height:1;
}

.fc-premium-done-text{
    margin:0 auto 22px;
    max-width:520px;
    color:var(--li-muted);
    font-size:clamp(14px,2vw,18px);
    font-weight:800;
    line-height:1.5;
}

.fc-premium-done-track{
    height:14px;
    max-width:420px;
    margin:0 auto 18px;
    border-radius:999px;
    background:#ECEAFB;
    overflow:hidden;
}

.fc-premium-done-fill{
    height:100%;
    width:0%;
    border-radius:999px;
    background:linear-gradient(90deg,var(--li-teal),var(--li-purple),var(--li-pink));
    transition:width .8s cubic-bezier(.2,.9,.2,1);
}

.fc-premium-confetti{
    position:fixed;
    width:10px;
    height:14px;
    top:-20px;
    z-index:99999;
    opacity:.95;
    animation:fcFall linear forwards;
    pointer-events:none;
}

@keyframes fcPop{
    from{opacity:0;transform:translateY(12px) scale(.96)}
    to{opacity:1;transform:translateY(0) scale(1)}
}

@keyframes fcFall{
    to{transform:translateY(110vh) rotate(720deg);opacity:1}
}

@media(max-width:640px){
    .fc-premium-shell{min-height:calc(100vh - 70px);padding:12px;border-radius:12px}
    .fc-premium-board{border-radius:26px;padding:14px}
    .fc-premium-card-wrap{grid-template-columns:1fr}
    .fc-premium-arrow{position:absolute;top:50%;transform:translateY(-50%)}
    .fc-premium-arrow-left{left:-4px}
    .fc-premium-arrow-right{right:-4px}
    .fc-premium-card{min-height:360px}
    .fc-premium-actions{display:grid;grid-template-columns:1fr;gap:9px}
    .fc-premium-btn{width:100%}
}
</style>

<?php echo render_activity_header($viewerTitle, 'Tap each card to reveal the word.'); ?>

<div class="fc-premium-shell">
    <div class="fc-premium-app" id="fc-premium-app">
        <div class="fc-premium-hero">
            <div class="fc-premium-kicker">Activity <span id="fc-premium-kicker-count">1 / <?php echo count($data); ?></span></div>
            <h1 class="fc-premium-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="fc-premium-subtitle">Tap the card to reveal the word.</p>
        </div>

        <section class="fc-premium-board" id="fc-premium-board">
            <div class="fc-premium-progress-row">
                <div class="fc-premium-progress-track">
                    <div class="fc-premium-progress-fill" id="fc-premium-progress-fill"></div>
                </div>
                <div class="fc-premium-progress-count" id="fc-premium-progress-count">1 / <?php echo count($data); ?></div>
            </div>

            <div class="fc-premium-card-wrap">
                <button type="button" class="fc-premium-arrow fc-premium-arrow-left" id="fc-premium-prev-arrow" aria-label="Previous card">&#8249;</button>

                <div class="fc-premium-card" id="fc-premium-card" role="button" tabindex="0" aria-label="Tap to reveal word">
                    <div class="fc-premium-card-inner">
                        <div class="fc-premium-face fc-premium-front">
                            <div class="fc-premium-image-box" id="fc-premium-image-box">
                                <img id="fc-premium-img" src="" alt="" style="display:none;">
                                <div class="fc-premium-placeholder" id="fc-premium-placeholder">?</div>
                            </div>
                            <div class="fc-premium-hint" id="fc-premium-front-hint">Tap to reveal word</div>
                        </div>

                        <div class="fc-premium-face fc-premium-back">
                            <div class="fc-premium-word" id="fc-premium-word"></div>
                            <div class="fc-premium-translation" id="fc-premium-translation" style="display:none;"></div>
                            <div class="fc-premium-hint">Tap to see image</div>
                        </div>
                    </div>
                </div>

                <button type="button" class="fc-premium-arrow fc-premium-arrow-right" id="fc-premium-next-arrow" aria-label="Next card">&#8250;</button>
            </div>

            <div class="fc-premium-actions">
                <button type="button" class="fc-premium-btn fc-premium-btn-blue" id="fc-premium-prev">&#9664; Prev</button>
                <button type="button" class="fc-premium-btn fc-premium-btn-pink" id="fc-premium-listen">&#x1F50A; Listen</button>
                <button type="button" class="fc-premium-btn fc-premium-btn-blue" id="fc-premium-next">Next &#9654;</button>
            </div>
        </section>

        <section class="fc-premium-completed" id="fc-premium-completed">
            <div class="fc-premium-done-icon">🎉</div>
            <h2 class="fc-premium-done-title">All Done!</h2>
            <p class="fc-premium-done-text">You reviewed all the cards. Great vocabulary practice!</p>
            <div class="fc-premium-done-track">
                <div class="fc-premium-done-fill" id="fc-premium-done-fill"></div>
            </div>
            <div class="fc-premium-actions">
                <button type="button" class="fc-premium-btn fc-premium-btn-pink" id="fc-premium-restart">&#8635; Review Again</button>
                <button type="button" class="fc-premium-btn fc-premium-btn-purple" onclick="history.back()">&#8592; Back</button>
            </div>
        </section>
    </div>
</div>

<audio id="fc-premium-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
'use strict';

var CARDS = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var TOTAL = CARDS.length;
var idx = 0;
var flipped = false;
var done = false;

var els = {
    board: document.getElementById('fc-premium-board'),
    completed: document.getElementById('fc-premium-completed'),
    card: document.getElementById('fc-premium-card'),
    img: document.getElementById('fc-premium-img'),
    placeholder: document.getElementById('fc-premium-placeholder'),
    word: document.getElementById('fc-premium-word'),
    translation: document.getElementById('fc-premium-translation'),
    progressFill: document.getElementById('fc-premium-progress-fill'),
    progressCount: document.getElementById('fc-premium-progress-count'),
    kickerCount: document.getElementById('fc-premium-kicker-count'),
    doneFill: document.getElementById('fc-premium-done-fill'),
    win: document.getElementById('fc-premium-win')
};

var TTS = (function(){
    var preferred = ['zira','samantha','karen','aria','jenny','emma','ava','siri','google us english','female','woman'];
    var cache = null;
    var attempts = 0;

    function load(cb){
        if (!window.speechSynthesis) return;
        var voices = window.speechSynthesis.getVoices();
        if (voices && voices.length) {
            cache = voices;
            cb(voices);
            return;
        }
        if (window.speechSynthesis.onvoiceschanged !== undefined) {
            window.speechSynthesis.onvoiceschanged = function(){
                cache = window.speechSynthesis.getVoices();
                if (cache.length) cb(cache);
            };
        }
        if (attempts < 12) {
            attempts++;
            setTimeout(function(){ load(cb); }, 150);
        }
    }

    function pick(voices){
        if (!voices || !voices.length) return null;
        var pool = [];
        voices.forEach(function(v){
            var lang = String(v.lang || '').toLowerCase();
            if (lang.indexOf('en') === 0) pool.push(v);
        });
        if (!pool.length) pool = voices;

        for (var i = 0; i < preferred.length; i++) {
            for (var j = 0; j < pool.length; j++) {
                var label = (String(pool[j].name || '') + ' ' + String(pool[j].voiceURI || '')).toLowerCase();
                if (label.indexOf(preferred[i]) !== -1) return pool[j];
            }
        }
        return pool[0] || null;
    }

    function speak(text){
        text = String(text || '').trim();
        if (!text || !window.speechSynthesis) return;

        window.speechSynthesis.cancel();

        function run(voices){
            var utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-US';
            utterance.rate = 0.84;
            utterance.pitch = 1.08;
            utterance.volume = 1;
            var voice = pick(voices);
            if (voice) utterance.voice = voice;
            window.speechSynthesis.speak(utterance);
        }

        if (cache && cache.length) run(cache);
        else load(run);
    }

    if (window.speechSynthesis) load(function(){});
    return { speak: speak };
})();

function getWord(card){
    return String((card && (card.english_text || card.text)) || '').trim();
}

function getSpanish(card){
    return String((card && card.spanish_text) || '').trim();
}

function getPlaceholder(word){
    if (!word) return '?';
    var first = word.charAt(0).toUpperCase();
    return first || '?';
}

function setFlipped(value){
    flipped = !!value;
    if (flipped) els.card.classList.add('is-flipped');
    else els.card.classList.remove('is-flipped');
}

function loadCard(){
    if (!TOTAL) return;

    var card = CARDS[idx] || {};
    var word = getWord(card) || 'No text';
    var spanish = getSpanish(card);
    var image = String(card.image || '').trim();

    els.word.textContent = word;

    if (spanish) {
        els.translation.textContent = spanish;
        els.translation.style.display = '';
    } else {
        els.translation.textContent = '';
        els.translation.style.display = 'none';
    }

    if (image) {
        els.img.src = image;
        els.img.alt = word;
        els.img.style.display = '';
        els.placeholder.style.display = 'none';
    } else {
        els.img.removeAttribute('src');
        els.img.alt = '';
        els.img.style.display = 'none';
        els.placeholder.textContent = getPlaceholder(word);
        els.placeholder.style.display = '';
    }

    setFlipped(false);

    var countText = (idx + 1) + ' / ' + TOTAL;
    var pct = Math.max(1, Math.round(((idx + 1) / TOTAL) * 100));
    els.progressFill.style.width = pct + '%';
    els.progressCount.textContent = countText;
    els.kickerCount.textContent = countText;
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
    els.board.style.display = 'none';
    els.completed.classList.add('active');
    setTimeout(function(){ els.doneFill.style.width = '100%'; }, 120);
    launchConfetti();
    try {
        els.win.pause();
        els.win.currentTime = 0;
        els.win.play();
    } catch(e) {}
}

function restart(){
    done = false;
    idx = 0;
    els.doneFill.style.width = '0%';
    els.completed.classList.remove('active');
    els.board.style.display = '';
    loadCard();
}

function launchConfetti(){
    var colors = ['#7F77DD','#EC4899','#2563EB','#1D9E75','#F59E0B','#FFFFFF'];
    var amount = 80;

    for (var i = 0; i < amount; i++) {
        (function(n){
            setTimeout(function(){
                var piece = document.createElement('span');
                piece.className = 'fc-premium-confetti';
                piece.style.left = Math.random() * 100 + 'vw';
                piece.style.background = colors[Math.floor(Math.random() * colors.length)];
                piece.style.animationDuration = (2.2 + Math.random() * 1.8) + 's';
                piece.style.transform = 'rotate(' + (Math.random() * 180) + 'deg)';
                piece.style.borderRadius = Math.random() > .5 ? '999px' : '3px';
                document.body.appendChild(piece);
                setTimeout(function(){ piece.remove(); }, 4500);
            }, n * 10);
        })(i);
    }
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

bind('fc-premium-prev-arrow', 'click', prevCard);
bind('fc-premium-next-arrow', 'click', nextCard);
bind('fc-premium-prev', 'click', prevCard);
bind('fc-premium-next', 'click', nextCard);
bind('fc-premium-restart', 'click', restart);
bind('fc-premium-listen', 'click', function(){
    TTS.speak(getWord(CARDS[idx] || {}));
});

document.addEventListener('keydown', function(e){
    if (e.key === 'ArrowRight') nextCard();
    if (e.key === 'ArrowLeft') prevCard();
});

loadCard();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-clone', $content);

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function wp_columns(PDO $pdo): array
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

function wp_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = wp_columns($pdo);
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

function wp_default_title(): string { return 'Writing Practice'; }

function wp_normalize_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : wp_default_title();
}

function wp_normalize_payload($rawData): array
{
    $default = array('title' => wp_default_title(), 'items' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title = '';
    $itemsSource = $decoded;
    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['items']) && is_array($decoded['items'])) $itemsSource = $decoded['items'];

    $items = array();
    foreach ($itemsSource as $item) {
        if (!is_array($item)) continue;
        $items[] = array(
            'id'           => isset($item['id'])           ? trim((string) $item['id'])           : uniqid('wp_'),
            'instruction'  => isset($item['instruction'])  ? trim((string) $item['instruction'])  : '',
            'prompt_text'  => isset($item['prompt_text'])  ? trim((string) $item['prompt_text'])  : '',
            'answer'       => isset($item['answer'])       ? trim((string) $item['answer'])       : '',
        );
    }
    return array('title' => wp_normalize_title($title), 'items' => $items);
}

function wp_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = wp_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'writing_practice' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return array('title' => wp_default_title(), 'items' => array());

    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];

    $payload = wp_normalize_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return array(
        'title' => wp_normalize_title((string) $payload['title']),
        'items' => isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array(),
    );
}

if ($unit === '' && $activityId !== '') $unit = wp_resolve_unit($pdo, $activityId);

$activity = wp_load_activity($pdo, $unit, $activityId);
$items    = isset($activity['items']) && is_array($activity['items']) ? $activity['items'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : wp_default_title();

if (count($items) === 0) die('No writing prompts found for this activity');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --wp-orange:#F97316;
    --wp-orange-dark:#C2580A;
    --wp-orange-soft:#FFF0E6;
    --wp-purple:#7F77DD;
    --wp-purple-dark:#534AB7;
    --wp-purple-soft:#EEEDFE;
    --wp-lila:rgba(127,119,221,.13);
    --wp-lila-md:rgba(127,119,221,.18);
    --wp-ink:#271B5D;
    --wp-muted:#9B94BE;
    --wp-green:#1D9E75;
    --wp-green-soft:#E6F9F2;
    --wp-green-border:#9FE1CB;
    --wp-red:#E24B4A;
    --wp-red-soft:#FCEBEB;
    --wp-red-border:#F7C1C1;
}

*{box-sizing:border-box}

.wp-shell{
    width:100%;
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    font-family:'Nunito','Segoe UI',system-ui,sans-serif;
    background:#ffffff;
    border-radius:16px;
}

.wp-app{
    width:min(860px,100%);
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:clamp(12px,2vw,20px);
}

.wp-hero{text-align:center;}

.wp-kicker{
    display:inline-flex;align-items:center;gap:7px;
    margin-bottom:8px;padding:7px 14px;border-radius:999px;
    background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;
    font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;
}

.wp-title{
    margin:0;font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);line-height:1;
    color:#F97316;font-weight:700;
}

.wp-subtitle{
    margin:8px 0 0;color:#9B94BE;
    font-size:clamp(13px,1.8vw,17px);font-weight:800;
}

.wp-board{
    background:#ffffff;border:1px solid #F0EEF8;
    border-radius:28px;padding:clamp(16px,2.6vw,26px);
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    overflow:visible;
}

.wp-progress-row{
    display:grid;grid-template-columns:1fr auto;
    gap:12px;align-items:center;margin-bottom:20px;
}

.wp-progress-track{
    height:10px;background:#F4F2FD;border-radius:999px;
    overflow:hidden;border:1px solid #E4E1F8;
}

.wp-progress-fill{
    height:100%;width:0%;
    background:linear-gradient(90deg,#F97316,#7F77DD);
    border-radius:999px;transition:width .45s cubic-bezier(.2,.9,.2,1);
}

.wp-progress-count{
    min-width:74px;text-align:center;padding:7px 11px;
    border-radius:999px;background:#7F77DD;color:#fff;
    font-size:13px;font-weight:900;
}

.wp-section-label{
    font-size:11px;font-weight:900;letter-spacing:.1em;
    text-transform:uppercase;color:#9B94BE;margin-bottom:8px;
}

.wp-prompt-box{
    background:#FAFAFE;border:1px solid #EDE9FA;
    border-radius:16px;padding:16px 18px;margin-bottom:18px;
}

.wp-instruction-badge{
    display:inline-block;font-size:12px;font-weight:900;
    color:#C2580A;background:#FFF0E6;border:1px solid #FCDDBF;
    border-radius:999px;padding:4px 12px;margin-bottom:10px;
}

.wp-prompt-text{
    font-size:15px;font-weight:700;color:#271B5D;line-height:1.7;
}

.wp-writing-wrap{position:relative;margin-bottom:16px;}

.wp-textarea{
    width:100%;min-height:120px;
    border:1.5px solid #EDE9FA;border-radius:16px;
    padding:14px 16px 32px;
    font-family:'Nunito',sans-serif;font-size:15px;
    font-weight:700;color:#271B5D;resize:vertical;
    outline:none;background:#fff;
    transition:border-color .18s,box-shadow .18s;
}

.wp-textarea:focus{
    border-color:#7F77DD;
    box-shadow:0 0 0 3px rgba(127,119,221,.10);
}

.wp-wordcount{
    position:absolute;bottom:10px;right:14px;
    font-size:11px;font-weight:900;color:#9B94BE;
}

.wp-actions{
    display:flex;justify-content:center;align-items:center;
    gap:clamp(8px,1.4vw,12px);flex-wrap:wrap;
    margin-bottom:20px;padding-bottom:4px;flex-shrink:0;
}

.wp-btn{
    border:0;border-radius:999px;
    min-width:clamp(100px,14vw,136px);
    padding:13px 20px;color:#fff;
    font-family:'Nunito',sans-serif;
    font-size:clamp(13px,1.8vw,15px);font-weight:900;
    cursor:pointer;display:inline-flex;align-items:center;
    justify-content:center;gap:7px;
    transition:transform .18s ease,filter .18s ease;
}

.wp-btn:hover{transform:translateY(-2px);filter:brightness(1.05);}

.wp-btn-orange{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22);}
.wp-btn-purple{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);}

.wp-btn-outline{
    background:#fff;color:#534AB7;
    border:1.5px solid #EDE9FA;
    box-shadow:0 4px 12px rgba(127,119,221,.10);
}
.wp-btn-outline:hover{background:#EEEDFE;}

.wp-answer-reveal{
    display:none;background:#FAFAFE;
    border:1.5px solid #9FE1CB;border-radius:16px;
    padding:14px 18px;margin-bottom:16px;
}
.wp-answer-reveal.show{display:block;}
.wp-answer-title{
    font-size:11px;font-weight:900;letter-spacing:.1em;
    text-transform:uppercase;color:#1D9E75;margin-bottom:8px;
}
.wp-answer-text{
    font-size:14px;font-weight:700;color:#271B5D;line-height:1.7;
}

.wp-result{display:none;margin-top:4px;}
.wp-result.show{display:block;}

.wp-score-row{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:12px;margin-bottom:18px;
}

.wp-score-card{
    background:#FAFAFE;border:1px solid #EDE9FA;
    border-radius:16px;padding:14px;text-align:center;
}

.wp-score-num{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(28px,5vw,38px);font-weight:700;line-height:1;
}

.wp-score-num.green{color:#1D9E75;}
.wp-score-num.orange{color:#F97316;}
.wp-score-num.purple{color:#7F77DD;}
.wp-score-lbl{
    font-size:11px;font-weight:900;color:#9B94BE;
    text-transform:uppercase;letter-spacing:.06em;margin-top:4px;
}

.wp-legend{
    display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;
}
.wp-leg{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:900;color:#9B94BE;}
.wp-leg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.wp-leg-dot.match{background:#1D9E75;}
.wp-leg-dot.miss{background:#E24B4A;}
.wp-leg-dot.extra{background:#F97316;}

.wp-comparison{
    background:#FAFAFE;border:1px solid #EDE9FA;
    border-radius:16px;padding:14px 18px;margin-bottom:14px;
}
.wp-comp-label{
    font-size:11px;font-weight:900;letter-spacing:.08em;
    text-transform:uppercase;margin-bottom:10px;
}
.wp-comp-label.green{color:#1D9E75;}
.wp-comp-label.purple{color:#534AB7;}

.wp-tokens{display:flex;flex-wrap:wrap;gap:6px;}

.wp-token{
    display:inline-flex;align-items:center;
    padding:4px 10px;border-radius:999px;
    font-size:13px;font-weight:900;
}
.wp-token.match{
    background:#E6F9F2;color:#0F6E56;
    border:1px solid #9FE1CB;
}
.wp-token.miss{
    background:#FCEBEB;color:#A32D2D;
    border:1px solid #F7C1C1;
    text-decoration:line-through;
}
.wp-token.extra{
    background:#FFF0E6;color:#C2580A;
    border:1px solid #FCDDBF;
}
.wp-token.neutral{
    background:#EEEDFE;color:#534AB7;
    border:1px solid #CECBF6;
}

.wp-nav-row{
    display:flex;justify-content:space-between;
    align-items:center;margin-top:8px;
    padding-top:16px;border-top:1px solid #F0EEF8;
}
.wp-nav-info{
    font-size:13px;font-weight:900;color:#9B94BE;
}

.wp-confetti{
    position:fixed;width:10px;height:14px;top:-20px;
    z-index:99999;opacity:.95;
    animation:wpFall linear forwards;pointer-events:none;
}

@keyframes wpFall{
    to{transform:translateY(110vh) rotate(720deg);opacity:1}
}

@media(max-width:640px){
    .wp-shell{padding:12px;border-radius:12px;}
    .wp-board{border-radius:22px;padding:14px;}
    .wp-score-row{grid-template-columns:1fr 1fr;}
    .wp-score-row .wp-score-card:last-child{grid-column:span 2;}
    .wp-actions{display:grid;grid-template-columns:1fr;gap:9px;}
    .wp-btn{width:100%;}
}

body.embedded-mode .wp-shell,
body.fullscreen-embedded .wp-shell,
body.presentation-mode .wp-shell{
    position:absolute!important;inset:0!important;
    max-width:none!important;margin:0!important;
    padding:10px 12px!important;border-radius:0!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:flex-start!important;
    overflow-y:auto!important;overflow-x:hidden!important;
}

body.embedded-mode .wp-app,
body.fullscreen-embedded .wp-app,
body.presentation-mode .wp-app{
    width:min(860px,100%)!important;
    margin:0 auto!important;
}
body.embedded-mode .wp-board,
body.fullscreen-embedded .wp-board,
body.presentation-mode .wp-board{
    overflow:visible!important;
}
body.embedded-mode .wp-actions,
body.fullscreen-embedded .wp-actions,
body.presentation-mode .wp-actions{
    flex-shrink:0!important;padding-bottom:12px!important;
}
</style>

<div class="wp-shell">
<div class="wp-app" id="wp-app">

    <div class="wp-hero">
        <div class="wp-kicker">Activity <span id="wp-kicker-count">1 / <?php echo count($items); ?></span></div>
        <h1 class="wp-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="wp-subtitle">Read the text and write your translation in English.</p>
    </div>

    <div class="wp-board" id="wp-board">

        <div class="wp-progress-row">
            <div class="wp-progress-track">
                <div class="wp-progress-fill" id="wp-progress-fill"></div>
            </div>
            <div class="wp-progress-count" id="wp-progress-count">1 / <?php echo count($items); ?></div>
        </div>

        <div class="wp-section-label">Prompt</div>
        <div class="wp-prompt-box">
            <div class="wp-instruction-badge" id="wp-instruction"></div>
            <p class="wp-prompt-text" id="wp-prompt-text"></p>
        </div>

        <div class="wp-section-label">Your answer</div>
        <div class="wp-writing-wrap">
            <textarea class="wp-textarea" id="wp-textarea" placeholder="Write your answer here..."></textarea>
            <div class="wp-wordcount" id="wp-wordcount">0 words</div>
        </div>

        <div class="wp-actions">
            <button type="button" class="wp-btn wp-btn-outline" id="wp-btn-reset">&#8635; Reset</button>
            <button type="button" class="wp-btn wp-btn-purple" id="wp-btn-show">Show Answer</button>
            <button type="button" class="wp-btn wp-btn-orange" id="wp-btn-check">Check &#10003;</button>
        </div>

        <div class="wp-answer-reveal" id="wp-answer-reveal">
            <div class="wp-answer-title">Model answer</div>
            <p class="wp-answer-text" id="wp-answer-text"></p>
        </div>

        <div class="wp-result" id="wp-result">
            <div class="wp-score-row">
                <div class="wp-score-card">
                    <div class="wp-score-num green" id="wp-s-correct">0</div>
                    <div class="wp-score-lbl">correct words</div>
                </div>
                <div class="wp-score-card">
                    <div class="wp-score-num orange" id="wp-s-total">0</div>
                    <div class="wp-score-lbl">words written</div>
                </div>
                <div class="wp-score-card">
                    <div class="wp-score-num purple" id="wp-s-score">0%</div>
                    <div class="wp-score-lbl">score</div>
                </div>
            </div>

            <div class="wp-legend">
                <div class="wp-leg"><div class="wp-leg-dot match"></div>Correct word</div>
                <div class="wp-leg"><div class="wp-leg-dot miss"></div>Missing / wrong</div>
                <div class="wp-leg"><div class="wp-leg-dot extra"></div>Extra word</div>
            </div>

            <div class="wp-comparison">
                <div class="wp-comp-label green">Your answer — word by word</div>
                <div class="wp-tokens" id="wp-student-tokens"></div>
            </div>
            <div class="wp-comparison">
                <div class="wp-comp-label purple">Model answer — word by word</div>
                <div class="wp-tokens" id="wp-answer-tokens"></div>
            </div>
        </div>

        <div class="wp-nav-row">
            <button type="button" class="wp-btn wp-btn-outline" id="wp-btn-prev" style="min-width:100px;">&#9664; Prev</button>
            <span class="wp-nav-info" id="wp-nav-info">1 / <?php echo count($items); ?></span>
            <button type="button" class="wp-btn wp-btn-orange" id="wp-btn-next" style="min-width:100px;">Next &#9654;</button>
        </div>

    </div>
</div>
</div>

<audio id="wp-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){
'use strict';

var ITEMS = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var TOTAL = ITEMS.length;
var idx = 0;

function normalize(str){
    return String(str||'').toLowerCase().replace(/[^a-z0-9\s]/g,'').trim().split(/\s+/).filter(Boolean);
}

function el(id){ return document.getElementById(id); }

function loadItem(){
    var item = ITEMS[idx] || {};
    el('wp-instruction').textContent = item.instruction || 'Translate to English';
    el('wp-prompt-text').textContent = item.prompt_text || '';
    el('wp-textarea').value = '';
    el('wp-wordcount').textContent = '0 words';
    el('wp-answer-reveal').classList.remove('show');
    el('wp-answer-text').textContent = item.answer || '';
    el('wp-result').classList.remove('show');
    el('wp-student-tokens').innerHTML = '';
    el('wp-answer-tokens').innerHTML = '';

    var pct = Math.max(1, Math.round(((idx+1)/TOTAL)*100));
    el('wp-progress-fill').style.width = pct + '%';
    var countText = (idx+1) + ' / ' + TOTAL;
    el('wp-progress-count').textContent = countText;
    el('wp-kicker-count').textContent = countText;
    el('wp-nav-info').textContent = countText;
    el('wp-btn-prev').style.visibility = idx === 0 ? 'hidden' : 'visible';
    el('wp-btn-next').textContent = idx >= TOTAL-1 ? 'Finish \u2713' : 'Next \u25BA';
}

function updateWC(){
    var words = el('wp-textarea').value.trim().split(/\s+/).filter(Boolean).length;
    el('wp-wordcount').textContent = words + ' word' + (words!==1?'s':'');
}

function checkAnswer(){
    var item = ITEMS[idx] || {};
    var modelWords = normalize(item.answer || '');
    var studentWords = normalize(el('wp-textarea').value);

    var modelFreq = {};
    modelWords.forEach(function(w){ modelFreq[w] = (modelFreq[w]||0)+1; });
    var studentFreq = {};
    studentWords.forEach(function(w){ studentFreq[w] = (studentFreq[w]||0)+1; });

    var correct = 0;
    var usedCount = {};
    studentWords.forEach(function(w){
        usedCount[w] = (usedCount[w]||0)+1;
        if(modelFreq[w] && usedCount[w] <= modelFreq[w]) correct++;
    });

    var pct = modelWords.length > 0 ? Math.round((correct / modelWords.length)*100) : 0;
    el('wp-s-correct').textContent = correct;
    el('wp-s-total').textContent = studentWords.length;
    el('wp-s-score').textContent = pct + '%';

    var sTok = el('wp-student-tokens');
    sTok.innerHTML = '';
    var usedC2 = {};
    studentWords.forEach(function(w){
        usedC2[w] = (usedC2[w]||0)+1;
        var isMatch = modelFreq[w] && usedC2[w] <= modelFreq[w];
        var span = document.createElement('span');
        span.className = 'wp-token ' + (isMatch ? 'match' : 'extra');
        span.textContent = w;
        sTok.appendChild(span);
    });

    var aTok = el('wp-answer-tokens');
    aTok.innerHTML = '';
    var usedM = {};
    modelWords.forEach(function(w){
        usedM[w] = (usedM[w]||0)+1;
        var found = studentFreq[w] && usedM[w] <= studentFreq[w];
        var span = document.createElement('span');
        span.className = 'wp-token ' + (found ? 'neutral' : 'miss');
        span.textContent = w;
        aTok.appendChild(span);
    });

    el('wp-result').classList.add('show');
    el('wp-answer-reveal').classList.remove('show');

    if(pct >= 80) launchConfetti();
}

function launchConfetti(){
    var colors = ['#F97316','#7F77DD','#534AB7','#1D9E75','#FCDDBF','#EEEDFE'];
    for(var i=0;i<60;i++){
        (function(n){
            setTimeout(function(){
                var p = document.createElement('span');
                p.className = 'wp-confetti';
                p.style.left = Math.random()*100+'vw';
                p.style.background = colors[Math.floor(Math.random()*colors.length)];
                p.style.animationDuration = (2.2+Math.random()*1.8)+'s';
                p.style.transform = 'rotate('+(Math.random()*180)+'deg)';
                p.style.borderRadius = Math.random()>.5?'999px':'3px';
                document.body.appendChild(p);
                setTimeout(function(){ p.remove(); },4500);
            },n*12);
        })(i);
    }
    try{
        var win = document.getElementById('wp-win');
        win.pause(); win.currentTime=0; win.play();
    }catch(e){}
}

el('wp-textarea').addEventListener('input', updateWC);
el('wp-btn-reset').addEventListener('click', function(){
    el('wp-textarea').value='';
    el('wp-wordcount').textContent='0 words';
    el('wp-result').classList.remove('show');
    el('wp-answer-reveal').classList.remove('show');
});
el('wp-btn-show').addEventListener('click', function(){
    el('wp-answer-reveal').classList.toggle('show');
});
el('wp-btn-check').addEventListener('click', checkAnswer);
el('wp-btn-prev').addEventListener('click', function(){
    if(idx>0){ idx--; loadItem(); }
});
el('wp-btn-next').addEventListener('click', function(){
    if(idx<TOTAL-1){ idx++; loadItem(); }
});

loadItem();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-pen-to-square', $content);

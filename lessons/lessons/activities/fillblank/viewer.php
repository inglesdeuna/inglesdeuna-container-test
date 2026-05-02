<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function fb_load(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = array(
        'id'           => '',
        'instructions' => 'Write the missing words in the blanks.',
        'blocks'       => array(),
        'wordbank'     => '',
        'media_type'   => 'none',
        'media_url'    => '',
        'tts_text'     => '',
    );
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $data = json_decode($row['data'] ?? '', true);

    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = array(array(
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
            'image'   => '',
        ));
    } else {
        $blocks = isset($data['blocks']) ? $data['blocks'] : array();
    }

    return array(
        'id'           => (string)($row['id'] ?? ''),
        'instructions' => isset($data['instructions']) ? $data['instructions'] : $fallback['instructions'],
        'blocks'       => $blocks,
        'wordbank'     => isset($data['wordbank'])   ? $data['wordbank']   : '',
        'media_type'   => isset($data['media_type']) ? $data['media_type'] : 'none',
        'media_url'    => isset($data['media_url'])  ? $data['media_url']  : '',
        'tts_text'     => isset($data['tts_text'])   ? $data['tts_text']   : '',
    );
}

$activity = fb_load($pdo, $unit, $activityId);
$blocks   = $activity['blocks'];

if (empty($blocks)) {
    die('No activity blocks found.');
}

$renderedBlocks = array();
foreach ($blocks as $bIdx => $block) {
    $text    = isset($block['text'])  ? $block['text']  : '';
    $image   = isset($block['image']) ? trim((string)$block['image']) : '';
    $answers = isset($block['answers']) && is_array($block['answers']) ? $block['answers'] : array();
    $blankN  = 0;

    $rendered = preg_replace_callback('/___+/', function ($m) use (&$blankN, $bIdx) {
        $blankN++;
        $sizerId = 's' . $bIdx . '_' . $blankN;
        $inputId = 'i' . $bIdx . '_' . $blankN;
        return
            '<span class="fb-blank-wrap">' .
                '<span class="fb-blank-sizer" id="' . $sizerId . '">...</span>' .
                '<input class="fb-blank"' .
                    ' id="' . $inputId . '"' .
                    ' data-sizer="' . $sizerId . '"' .
                    ' data-block="' . $bIdx . '"' .
                    ' data-n="' . $blankN . '"' .
                    ' placeholder="..."' .
                    ' autocomplete="off"' .
                    ' spellcheck="false">' .
            '</span>';
    }, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

    $renderedBlocks[] = array(
        'rendered'   => $rendered,
        'image'      => $image,
        'answers'    => $answers,
        'blankCount' => $blankN,
    );
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --t50:#E1F5EE; --t100:#9FE1CB; --t200:#5DCAA5;
    --t400:#1D9E75; --t600:#0F6E56; --t800:#085041; --t900:#04342C;
    --purple:#7F77DD; --purple-d:#534AB7; --purple-l:#EEEDFE; --purple-b:#AFA9EC;
    --green:#16a34a; --red:#dc2626;
}
body { margin:0 !important; padding:0 !important; background:#f0faf6 !important;
    font-family:'Nunito','Segoe UI',sans-serif !important; }
.activity-wrapper { max-width:100% !important; margin:0 !important; padding:0 !important;
    min-height:100vh; display:flex !important; flex-direction:column !important; background:transparent !important; }
.top-row { display:none !important; }
.viewer-content { flex:1 !important; display:flex !important; flex-direction:column !important;
    padding:0 !important; margin:0 !important; background:transparent !important;
    border:none !important; box-shadow:none !important; border-radius:0 !important; }
.fb-page { display:flex; flex-direction:column; width:100vw; min-height:100vh; background:#f0faf6; }
.fb-topbar { flex-shrink:0; height:38px; background:var(--t50); border-bottom:1px solid var(--t100);
    display:flex; align-items:center; padding:0 16px; gap:12px; }
.fb-back-btn { background:rgba(15,110,86,.12); border:1px solid var(--t100); color:var(--t800);
    font-size:12px; font-weight:800; font-family:'Nunito',sans-serif; border-radius:7px;
    padding:4px 12px; cursor:pointer; transition:background .15s; }
.fb-back-btn:hover { background:var(--t100); }
body.presentation-mode .fb-back-btn, body.embedded-mode .fb-back-btn { display:none; }
.fb-topbar-title { font-family:'Nunito',sans-serif; font-size:12px; font-weight:800;
    color:var(--t600); letter-spacing:.1em; text-transform:uppercase; margin:0 auto; }
.fb-bottombar { flex-shrink:0; height:40px; background:var(--t50); border-top:1px solid var(--t100); }
.fb-card { flex:1; margin:8px 12px; background:#fff; border-radius:14px; border:1px solid var(--t100);
    display:flex; flex-direction:column; overflow:hidden; min-height:0; position:relative;
    box-shadow:0 2px 16px rgba(29,158,117,.08); }
.fb-card-hd { flex-shrink:0; background:var(--t50); border-bottom:1px solid var(--t100); padding:11px 20px 9px; }
.fb-card-hd h2 { font-family:'Fredoka',sans-serif; font-size:clamp(15px,2vw,20px); font-weight:600;
    color:var(--t800); margin:0 0 2px; line-height:1.2; }
.fb-card-hd p { font-size:12px; font-weight:600; color:var(--t600); margin:0; }
.fb-tts-bar { flex-shrink:0; background:var(--t50); border-bottom:1px solid var(--t100);
    padding:8px 16px; display:flex; align-items:center; gap:10px; }
.fb-tts-btn { display:inline-flex; align-items:center; gap:6px; background:var(--purple); color:#fff;
    border:none; border-radius:20px; padding:7px 16px; font-size:12px; font-weight:800;
    font-family:'Nunito',sans-serif; cursor:pointer; box-shadow:0 2px 8px rgba(127,119,221,.3);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s; }
.fb-tts-btn:hover { transform:translateY(-2px) scale(1.05); box-shadow:0 6px 16px rgba(127,119,221,.4); }
.fb-wordbank { flex-shrink:0; background:var(--purple-l); border-bottom:1px solid var(--purple-b);
    padding:7px 16px; font-size:12px; font-weight:600; color:var(--purple-d); line-height:1.7; }
.fb-wordbank b { font-weight:800; color:var(--purple); margin-right:3px; }
.fb-prog-wrap { flex-shrink:0; padding:8px 16px 0; display:flex; align-items:center; gap:10px; }
.fb-prog-track { flex:1; height:5px; background:var(--purple-l); border-radius:3px;
    border:1px solid var(--purple-b); overflow:hidden; }
.fb-prog-fill { height:100%; background:var(--purple); border-radius:3px; transition:width .35s ease; }
.fb-prog-label { font-size:11px; font-weight:800; color:var(--purple); white-space:nowrap; font-family:'Nunito',sans-serif; }
.fb-block-area { flex:1; overflow-y:auto; padding:16px 20px 8px; }
.fb-block { display:none; }
.fb-block.active { display:block; animation:fbFadeIn .2s ease; }
@keyframes fbFadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
.fb-block-img { display:block; max-width:240px; max-height:140px; object-fit:contain;
    border-radius:10px; margin:0 auto 16px; border:1px solid var(--t100); }
.fb-text { font-family:'Nunito',sans-serif; font-size:clamp(14px,1.8vw,16px);
    font-weight:600; color:#1e293b; line-height:2.6; }
.fb-blank-wrap { display:inline-block; position:relative; vertical-align:baseline; margin:0 2px; min-width:60px; }
.fb-blank-sizer { position:absolute; inset:0; visibility:hidden; white-space:pre;
    font-size:14px; font-weight:800; font-family:'Nunito',sans-serif;
    padding:0 10px; pointer-events:none; border-bottom:2.5px solid transparent; }
.fb-blank { display:inline-block; width:100%; min-width:60px; border:none;
    border-bottom:2.5px solid var(--purple-b); background:transparent; padding:1px 8px;
    font-size:14px; font-weight:800; font-family:'Nunito',sans-serif; color:var(--purple-d);
    outline:none; text-align:center; vertical-align:baseline;
    transition:border-color .15s,box-shadow .15s; }
.fb-blank:hover { border-bottom-color:var(--purple); }
.fb-blank:focus { border-bottom-color:var(--purple); box-shadow:0 3px 0 rgba(127,119,221,.18); }
.fb-blank.correct { border-bottom-color:var(--green); border-bottom-width:3px; color:var(--green); }
.fb-blank.wrong { border-bottom-color:var(--red); border-bottom-width:3px; color:var(--red); animation:fbShake .3s ease; }
.fb-blank.revealed { border-bottom-color:var(--purple); color:var(--purple-d);
    background:var(--purple-l); border-radius:4px; padding:1px 10px; }
@keyframes fbShake { 0%,100% { transform:translateX(0); } 25% { transform:translateX(-5px); } 75% { transform:translateX(5px); } }
.fb-controls { flex-shrink:0; border-top:1px solid var(--t100); padding:8px 14px;
    display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:center;
    position:relative; z-index:5; background:#fff; }
.fb-btn { display:inline-flex; align-items:center; justify-content:center; padding:9px 18px;
    border:none; border-radius:20px; font-family:'Nunito',sans-serif; font-size:13px;
    font-weight:800; color:#fff; cursor:pointer; white-space:nowrap; background:var(--purple);
    box-shadow:0 3px 10px rgba(127,119,221,.30); pointer-events:auto;
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s,filter .15s; }
.fb-btn:hover { transform:translateY(-3px) scale(1.05); box-shadow:0 8px 22px rgba(127,119,221,.45); filter:brightness(1.08); }
.fb-btn:active { transform:scale(.97); box-shadow:none; }
.fb-btn:disabled { opacity:.45; cursor:default; transform:none; filter:none; box-shadow:none; }
#fb-feedback { font-family:'Nunito',sans-serif; font-size:13px; font-weight:800;
    text-align:center; min-height:18px; width:100%; }
#fb-feedback.good { color:var(--green); }
#fb-feedback.bad  { color:var(--red); }
.fb-completed { display:none; position:absolute; inset:0; background:#fff; border-radius:14px;
    flex-direction:column; align-items:center; justify-content:center; text-align:center;
    padding:40px 24px; gap:12px; z-index:20; }
.fb-completed.active { display:flex; }
.fb-completed-icon { font-size:60px; line-height:1; margin-bottom:4px; }
.fb-completed-title { font-family:'Fredoka',sans-serif; font-size:28px; font-weight:700; color:var(--t800); margin:0; }
.fb-completed-msg { font-size:14px; font-weight:600; color:#5a7a6a; margin:0; }
.fb-score-ring { width:88px; height:88px; border-radius:50%; background:var(--purple-l);
    border:3px solid var(--purple-b); display:flex; flex-direction:column;
    align-items:center; justify-content:center; }
.fb-score-pct { font-family:'Fredoka',sans-serif; font-size:24px; font-weight:700; color:var(--purple-d); line-height:1; }
.fb-score-lbl { font-size:10px; font-weight:800; color:var(--purple); letter-spacing:.04em; }
.fb-score-frac { font-size:15px; font-weight:800; color:var(--purple-d); }
.fb-restart-btn { background:var(--purple); color:#fff; border:none; border-radius:20px;
    padding:11px 28px; font-family:'Nunito',sans-serif; font-size:14px; font-weight:800;
    cursor:pointer; box-shadow:0 4px 14px rgba(127,119,221,.3);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s; }
.fb-restart-btn:hover { transform:translateY(-2px) scale(1.04); box-shadow:0 8px 22px rgba(127,119,221,.4); }
@media (max-width:600px) {
    .fb-card { margin:6px 8px; border-radius:10px; }
    .fb-topbar { height:34px; }
    .fb-bottombar { height:32px; }
    .fb-block-area { padding:12px 14px 6px; }
    .fb-btn { padding:8px 14px; font-size:12px; }
    .fb-text { font-size:14px; line-height:2.2; }
}
</style>

<div class="fb-page">
    <div class="fb-topbar">
        <button class="fb-back-btn" onclick="history.back()">&#8592; Back</button>
        <span class="fb-topbar-title">Activity</span>
    </div>

    <div class="fb-card">
        <div class="fb-card-hd">
            <h2><?php echo htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p>Fill in the blanks with the correct words.</p>
        </div>

        <?php if ($activity['media_type'] === 'tts' && !empty($activity['tts_text'])): ?>
        <div class="fb-tts-bar">
            <button type="button" id="fb-tts-btn" class="fb-tts-btn">&#x1F50A; Listen</button>
        </div>
        <?php elseif ($activity['media_type'] === 'audio' && !empty($activity['media_url'])): ?>
        <div class="fb-tts-bar">
            <audio controls src="<?php echo htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;border-radius:8px"></audio>
        </div>
        <?php endif; ?>

        <?php if (!empty($activity['wordbank'])): ?>
        <div class="fb-wordbank">
            <b>Word bank:</b> <?php echo htmlspecialchars($activity['wordbank'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <div class="fb-prog-wrap">
            <div class="fb-prog-track">
                <div class="fb-prog-fill" id="fb-prog-fill" style="width:<?php echo count($renderedBlocks) > 0 ? round(1/count($renderedBlocks)*100) : 100; ?>%"></div>
            </div>
            <span class="fb-prog-label" id="fb-prog-label">1 / <?php echo count($renderedBlocks); ?></span>
        </div>

        <div class="fb-block-area">
            <?php foreach ($renderedBlocks as $bIdx => $block): ?>
            <div class="fb-block <?php echo $bIdx === 0 ? 'active' : ''; ?>"
                 id="fb-block-<?php echo $bIdx; ?>"
                 data-answers="<?php echo htmlspecialchars(json_encode($block['answers']), ENT_QUOTES, 'UTF-8'); ?>">
                <?php if (!empty($block['image'])): ?>
                <img class="fb-block-img" src="<?php echo htmlspecialchars($block['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php endif; ?>
                <div class="fb-text"><?php echo $block['rendered']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="fb-controls">
            <button type="button" class="fb-btn" id="fb-prev" style="display:none">&#9664; Prev</button>
            <button type="button" class="fb-btn" id="fb-check">&#10004; Check</button>
            <button type="button" class="fb-btn" id="fb-show">Show Answer</button>
            <button type="button" class="fb-btn" id="fb-next">Next &#9654;</button>
            <div id="fb-feedback"></div>
        </div>

        <div class="fb-completed" id="fb-completed">
            <div class="fb-completed-icon">&#x2705;</div>
            <h2 class="fb-completed-title">Fill-in-the-Blank</h2>
            <p class="fb-completed-msg">Activity completed. Great job!</p>
            <div class="fb-score-ring">
                <span class="fb-score-pct" id="fb-score-pct">&#8212;</span>
                <span class="fb-score-lbl">SCORE</span>
            </div>
            <div class="fb-score-frac" id="fb-score-frac"></div>
            <button type="button" class="fb-restart-btn" onclick="fbRestart()">&#8635; Try Again</button>
        </div>
    </div>

    <div class="fb-bottombar"></div>
</div>

<audio id="fb-win-sound"  src="../../hangman/assets/win.mp3"     preload="auto"></audio>
<audio id="fb-lose-sound" src="../../hangman/assets/lose.mp3"    preload="auto"></audio>
<audio id="fb-done-sound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
(function () {

var BLOCKS      = <?php echo json_encode(array_map(function($b) { return $b['answers']; }, $renderedBlocks), JSON_UNESCAPED_UNICODE); ?>;
var RETURN_TO   = <?php echo json_encode($returnTo,   JSON_UNESCAPED_UNICODE); ?>;
var ACTIVITY_ID = <?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
var TOTAL       = BLOCKS.length;

var cur       = 0;
var done      = false;
/* Track per-blank whether student answered it or it was revealed */
var revealed  = {};

var winSound  = document.getElementById('fb-win-sound');
var losSound  = document.getElementById('fb-lose-sound');
var doneSound = document.getElementById('fb-done-sound');

var btnPrev  = document.getElementById('fb-prev');
var btnCheck = document.getElementById('fb-check');
var btnShow  = document.getElementById('fb-show');
var btnNext  = document.getElementById('fb-next');

btnPrev.addEventListener('click',  function() { fbPrev(); });
btnCheck.addEventListener('click', function() { fbCheck(); });
btnShow.addEventListener('click',  function() { fbShow(); });
btnNext.addEventListener('click',  function() { fbNext(); });

function playSound(el) { try { el.pause(); el.currentTime = 0; el.play(); } catch(e) {} }

function resizeInput(inp) {
    var sizer = document.getElementById(inp.getAttribute('data-sizer'));
    if (!sizer) return;
    sizer.textContent = inp.value || inp.getAttribute('placeholder') || '...';
}

function initResizers() {
    var blanks = document.querySelectorAll('.fb-blank');
    for (var i = 0; i < blanks.length; i++) {
        (function(inp) {
            resizeInput(inp);
            inp.addEventListener('input', function() { resizeInput(inp); });
        })(blanks[i]);
    }
}

function blockEl(b) { return document.getElementById('fb-block-' + b); }

function blanksOf(b) {
    var el = blockEl(b);
    if (!el) return [];
    return Array.prototype.slice.call(el.querySelectorAll('.fb-blank'));
}

function answersOf(b) {
    var el = blockEl(b);
    if (!el) return [];
    try { return JSON.parse(el.getAttribute('data-answers') || '[]'); } catch(e) { return []; }
}

function blankKey(b, i) { return b + '_' + i; }

function setFb(msg, cls) {
    var f = document.getElementById('fb-feedback');
    f.textContent = msg;
    f.className = cls || '';
}
function clearFb() { setFb('', ''); }

function setProgress() {
    var pct = Math.round((cur + 1) / TOTAL * 100);
    document.getElementById('fb-prog-fill').style.width = pct + '%';
    document.getElementById('fb-prog-label').textContent = (cur + 1) + ' / ' + TOTAL;
    btnPrev.style.display = cur > 0 ? '' : 'none';
}

function clearColors() {
    var allBlanks = document.querySelectorAll('.fb-blank');
    for (var i = 0; i < allBlanks.length; i++) {
        allBlanks[i].classList.remove('correct', 'wrong', 'revealed');
    }
}

function fbCheck() {
    if (done) return;
    var ans  = answersOf(cur);
    var inps = blanksOf(cur);
    var ok   = 0;
    for (var i = 0; i < inps.length; i++) {
        inps[i].classList.remove('correct', 'wrong', 'revealed');
        var v = inps[i].value.trim().toLowerCase();
        var a = (ans[i] || '').trim().toLowerCase();
        if (v === a) { inps[i].classList.add('correct'); ok++; }
        else           inps[i].classList.add('wrong');
    }
    if (ok === inps.length) {
        setFb('&#x2705; Correct! Well done!', 'good');
        playSound(winSound);
    } else {
        setFb('&#x274C; ' + ok + ' / ' + inps.length + ' correct &#8212; try again!', 'bad');
        playSound(losSound);
    }
}

function fbShow() {
    if (done) return;
    var ans  = answersOf(cur);
    var inps = blanksOf(cur);
    for (var i = 0; i < inps.length; i++) {
        inps[i].value = ans[i] || '';
        resizeInput(inps[i]);
        inps[i].classList.remove('correct', 'wrong');
        inps[i].classList.add('revealed');
        /* Mark this blank as revealed so score counts it as wrong */
        revealed[blankKey(cur, i)] = true;
    }
    setFb('Answers shown.', 'good');
    btnCheck.disabled = true;
    btnShow.disabled  = true;
}

function fbNext() {
    if (cur < TOTAL - 1) {
        blockEl(cur).classList.remove('active');
        cur++;
        blockEl(cur).classList.add('active');
        clearFb();
        clearColors();
        btnCheck.disabled = false;
        btnShow.disabled  = false;
        setProgress();
        var first = blanksOf(cur)[0];
        if (first) setTimeout(function() { first.focus(); }, 80);
    } else {
        fbFinish();
    }
}

function fbPrev() {
    if (cur > 0) {
        blockEl(cur).classList.remove('active');
        cur--;
        blockEl(cur).classList.add('active');
        clearFb();
        btnCheck.disabled = false;
        btnShow.disabled  = false;
        setProgress();
    }
}

function fbFinish() {
    done = true;
    var totalBlanks   = 0;
    var correctBlanks = 0;

    for (var b = 0; b < TOTAL; b++) {
        var ans  = answersOf(b);
        var inps = blanksOf(b);
        for (var i = 0; i < inps.length; i++) {
            totalBlanks++;
            /* Only count as correct if student typed it (not revealed by Show Answer) */
            if (!revealed[blankKey(b, i)]) {
                var v = inps[i].value.trim().toLowerCase();
                var a = (ans[i] || '').trim().toLowerCase();
                if (v === a) correctBlanks++;
            }
        }
    }

    var pct    = totalBlanks > 0 ? Math.round(correctBlanks / totalBlanks * 100) : 0;
    var errors = Math.max(0, totalBlanks - correctBlanks);

    document.getElementById('fb-score-pct').textContent  = pct + '%';
    document.getElementById('fb-score-frac').textContent = correctBlanks + ' / ' + totalBlanks + ' correct';
    document.getElementById('fb-completed').classList.add('active');
    playSound(doneSound);

    if (RETURN_TO && ACTIVITY_ID) {
        var sep = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var url = RETURN_TO + sep +
            'activity_percent=' + pct +
            '&activity_errors=' + errors +
            '&activity_total='  + totalBlanks +
            '&activity_id='     + encodeURIComponent(ACTIVITY_ID) +
            '&activity_type=fillblank';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.withCredentials = true;
        xhr.onload = function() {
            if (xhr.status < 200 || xhr.status >= 300) navigate(url);
        };
        xhr.onerror = function() { navigate(url); };
        xhr.send();
    }
}

function navigate(url) {
    try {
        if (window.top && window.top !== window.self) { window.top.location.href = url; return; }
    } catch(e) {}
    window.location.href = url;
}

window.fbRestart = function() {
    done     = false;
    cur      = 0;
    revealed = {};
    document.getElementById('fb-completed').classList.remove('active');
    btnCheck.disabled = false;
    btnShow.disabled  = false;
    clearFb();
    for (var b = 0; b < TOTAL; b++) {
        blockEl(b).classList.toggle('active', b === 0);
        var inps = blanksOf(b);
        for (var i = 0; i < inps.length; i++) {
            inps[i].value = '';
            inps[i].classList.remove('correct', 'wrong', 'revealed');
            resizeInput(inps[i]);
        }
    }
    setProgress();
    var first = blanksOf(0)[0];
    if (first) setTimeout(function() { first.focus(); }, 80);
};

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    var active = document.activeElement;
    if (!active || !active.classList.contains('fb-blank')) return;
    e.preventDefault();
    var all = blanksOf(cur);
    var idx = all.indexOf(active);
    if (idx !== -1 && idx < all.length - 1) {
        all[idx + 1].focus();
    } else {
        fbCheck();
    }
});

<?php if ($activity['media_type'] === 'tts' && !empty($activity['tts_text'])): ?>
var TTS_TEXT    = <?php echo json_encode($activity['tts_text'], JSON_UNESCAPED_UNICODE); ?>;
var ttsBtn      = document.getElementById('fb-tts-btn');
var ttsSpeaking = false;
var ttsPaused   = false;
var ttsOffset   = 0;
var ttsSegStart = 0;
var ttsUtter    = null;

function ttsVoice(lang) {
    var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
    if (!voices.length) return null;
    var pre = lang.split('-')[0].toLowerCase();
    var m = [];
    for (var i = 0; i < voices.length; i++) {
        var vl = String(voices[i].lang || '').toLowerCase();
        if (vl === lang.toLowerCase() || vl.indexOf(pre + '-') === 0 || vl.indexOf(pre + '_') === 0) {
            m.push(voices[i]);
        }
    }
    if (!m.length) return voices[0] || null;
    var hints = ['female','woman','zira','samantha','karen','aria','jenny','emma'];
    for (var j = 0; j < m.length; j++) {
        var label = (String(m[j].name || '') + ' ' + String(m[j].voiceURI || '')).toLowerCase();
        for (var k = 0; k < hints.length; k++) {
            if (label.indexOf(hints[k]) !== -1) return m[j];
        }
    }
    return m[0];
}

function ttsStart() {
    var rem = TTS_TEXT.slice(Math.max(0, ttsOffset));
    if (!rem.trim()) { ttsSpeaking = false; ttsPaused = false; ttsOffset = 0; return; }
    speechSynthesis.cancel();
    ttsSegStart = ttsOffset;
    ttsUtter    = new SpeechSynthesisUtterance(rem);
    ttsUtter.lang   = 'en-US';
    ttsUtter.rate   = 0.7;
    ttsUtter.pitch  = 1;
    ttsUtter.volume = 1;
    var pref = ttsVoice('en-US');
    if (pref) ttsUtter.voice = pref;
    ttsUtter.onstart    = function() { ttsSpeaking = true;  ttsPaused = false; };
    ttsUtter.onpause    = function() { ttsPaused   = true;  ttsSpeaking = true; };
    ttsUtter.onresume   = function() { ttsPaused   = false; ttsSpeaking = true; };
    ttsUtter.onboundary = function(ev) {
        if (typeof ev.charIndex === 'number')
            ttsOffset = Math.max(ttsSegStart, Math.min(TTS_TEXT.length, ttsSegStart + ev.charIndex));
    };
    ttsUtter.onend   = function() { if (!ttsPaused) { ttsSpeaking = false; ttsPaused = false; ttsOffset = 0; } };
    ttsUtter.onerror = function() { ttsSpeaking = false; ttsPaused = false; ttsOffset = 0; };
    speechSynthesis.speak(ttsUtter);
}

if (ttsBtn) {
    ttsBtn.addEventListener('click', function() {
        if (!TTS_TEXT.trim()) return;
        if (speechSynthesis.paused || ttsPaused) {
            speechSynthesis.resume(); ttsSpeaking = true; ttsPaused = false;
            setTimeout(function() { if (!speechSynthesis.speaking && ttsOffset < TTS_TEXT.length) ttsStart(); }, 80);
            return;
        }
        if (speechSynthesis.speaking && !speechSynthesis.paused) {
            speechSynthesis.pause(); ttsSpeaking = true; ttsPaused = true; return;
        }
        speechSynthesis.cancel(); ttsOffset = 0; ttsStart();
    });
}
<?php endif; ?>

initResizers();
setProgress();

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Fill-in-the-Blank', 'fa-solid fa-pen-to-square', $content);

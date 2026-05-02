<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])   ? trim((string) $_GET['id'])   : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function qa_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function qa_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $cols = qa_columns($pdo);
    $col  = in_array('unit_id', $cols, true) ? 'unit_id' : (in_array('unit', $cols, true) ? 'unit' : '');
    if ($col === '') return '';
    $stmt = $pdo->prepare("SELECT {$col} FROM activities WHERE id=:id LIMIT 1");
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row[$col] ?? '') : '';
}

function qa_load(PDO $pdo, string $unit, string $activityId): array
{
    $empty = array('title' => 'Questions & Answers', 'cards' => array());
    $cols  = qa_columns($pdo);

    $fields = array('id');
    if (in_array('data',         $cols, true)) $fields[] = 'data';
    if (in_array('content_json', $cols, true)) $fields[] = 'content_json';
    if (in_array('title',        $cols, true)) $fields[] = 'title';
    if (in_array('name',         $cols, true)) $fields[] = 'name';
    $sel = implode(', ', $fields);

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE id=:id AND type='question_answer' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $cols, true)) {
        $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE unit_id=:unit AND type='question_answer' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $empty;

    $raw  = isset($row['data']) ? $row['data'] : (isset($row['content_json']) ? $row['content_json'] : null);
    $data = is_string($raw) ? json_decode($raw, true) : array();
    if (!is_array($data)) $data = array();

    $cardsRaw = isset($data['cards']) && is_array($data['cards']) ? $data['cards'] : $data;
    $cards    = array();
    foreach ($cardsRaw as $item) {
        if (!is_array($item)) continue;
        $cards[] = array(
            'question' => isset($item['question']) ? trim((string)$item['question']) : '',
            'answer'   => isset($item['answer'])   ? trim((string)$item['answer'])   : '',
        );
    }

    $title = '';
    if (isset($row['title']) && trim((string)$row['title']) !== '') $title = trim((string)$row['title']);
    if ($title === '' && isset($row['name']) && trim((string)$row['name']) !== '') $title = trim((string)$row['name']);
    if ($title === '' && isset($data['title'])) $title = trim((string)$data['title']);
    if ($title === '') $title = 'Questions & Answers';

    return array('title' => $title, 'cards' => $cards);
}

if ($unit === '' && $activityId !== '') $unit = qa_resolve_unit($pdo, $activityId);
$activity    = qa_load($pdo, $unit, $activityId);
$cards       = $activity['cards'];
$viewerTitle = $activity['title'];

if (empty($cards)) die('No questions found for this activity');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --p:#7F77DD; --pd:#534AB7; --pl:#EEEDFE; --pb:#AFA9EC;
    --t50:#E1F5EE; --t100:#9FE1CB; --t400:#1D9E75; --t600:#0F6E56; --t800:#085041;
    --green:#16a34a;
}

/* ── template reset ── */
body { margin:0 !important; padding:0 !important; background:#f0faf6 !important;
    font-family:'Nunito','Segoe UI',sans-serif !important; }
.activity-wrapper { max-width:100% !important; margin:0 !important; padding:0 !important;
    height:100vh; display:flex !important; flex-direction:column !important; background:transparent !important; overflow:hidden !important; }
.top-row { display:none !important; }
.viewer-content { flex:1 !important; display:flex !important; flex-direction:column !important;
    padding:0 !important; margin:0 !important; background:transparent !important;
    border:none !important; box-shadow:none !important; border-radius:0 !important; }

/* ── page shell ── */
.qa-page { display:flex; flex-direction:column; width:100vw; height:100vh; background:#f0faf6; overflow:hidden; }

/* topbar — lavender */
.qa-topbar { flex-shrink:0; height:42px; background:var(--pl); border-bottom:1.5px solid var(--pb);
    display:flex; align-items:center; padding:0 16px; gap:12px; }
.qa-topbar-title { font-size:12px; font-weight:800; color:var(--pd);
    letter-spacing:.1em; text-transform:uppercase; margin:0 auto; font-family:'Nunito',sans-serif; }
.qa-bottombar { flex-shrink:0; height:36px; background:var(--pl); border-top:1.5px solid var(--pb); }

/* shared button */
.act-btn { display:inline-flex; align-items:center; justify-content:center; gap:5px;
    border:none; border-radius:999px; font-family:'Nunito',sans-serif; font-weight:800;
    color:#fff; cursor:pointer; white-space:nowrap; background:var(--p);
    box-shadow:0 3px 10px rgba(127,119,221,.30); line-height:1; text-decoration:none;
    transition:transform .18s cubic-bezier(.34,1.4,.64,1), box-shadow .15s, filter .15s; }
.act-btn:hover { transform:translateY(-2px) scale(1.04); box-shadow:0 7px 18px rgba(127,119,221,.42); filter:brightness(1.08); }
.act-btn.teal { background:var(--t400); box-shadow:0 3px 10px rgba(29,158,117,.28); }
.act-btn.teal:hover { box-shadow:0 7px 18px rgba(29,158,117,.38); }

/* ── body ── */
.qa-body { flex:1; display:flex; flex-direction:column; align-items:center; padding:10px 14px 8px; gap:8px; min-height:0; }

/* card wrap — white with purple border */
.qa-card-area { width:100%; max-width:900px; display:flex; align-items:center; gap:10px; flex:1; min-height:0; }
.qa-arrow-btn { flex-shrink:0; width:38px; height:38px; border-radius:50%;
    background:var(--pl); border:1.5px solid var(--pb); color:var(--pd);
    font-size:18px; font-weight:800; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:background .15s,transform .15s; }
.qa-arrow-btn:hover { background:var(--pb); transform:scale(1.08); }
.qa-card-wrap { flex:1; height:100%; background:#fff;
    border-radius:20px; border:1.5px solid var(--pb);
    overflow:hidden; box-shadow:0 4px 20px rgba(127,119,221,.10);
    display:flex; flex-direction:column; min-height:0; position:relative; }

/* progress */
.qa-prog-row { display:flex; align-items:center; gap:10px; flex-shrink:0; width:100%; max-width:900px; }
.qa-prog-track { flex:1; height:5px; background:var(--pl); border-radius:3px;
    border:1px solid var(--pb); overflow:hidden; }
.qa-prog-fill { height:100%; background:var(--p); border-radius:3px; transition:width .35s ease; }
.qa-prog-lbl { font-size:11px; font-weight:800; color:var(--p); white-space:nowrap; font-family:'Nunito',sans-serif; }

/* flip area */
.qa-flip-area { flex:1; min-height:200px; perspective:1200px; padding:14px 18px; cursor:pointer; display:flex; align-items:stretch; }
.qa-card { width:100%; flex:1; min-height:200px; position:relative;
    transform-style:preserve-3d; transition:transform .55s ease; border-radius:14px; }
.qa-card.flipped { transform:rotateY(180deg); }
.qa-side { position:absolute; inset:0; backface-visibility:hidden; border-radius:14px;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:20px; gap:10px; }

/* question side — lavender */
.qa-front { background:var(--pl); border:1.5px solid var(--pb); }
/* answer side — purple dark */
.qa-back  { background:var(--pd); border:1.5px solid rgba(255,255,255,.15); transform:rotateY(180deg); }

.qa-side-label { font-size:10px; font-weight:800; letter-spacing:.1em;
    text-transform:uppercase; font-family:'Nunito',sans-serif; opacity:.7; }
.qa-front .qa-side-label { color:var(--pd); }
.qa-back  .qa-side-label { color:rgba(255,255,255,.7); }

.qa-side-text { font-family:'Fredoka',sans-serif; font-size:clamp(18px,3.5vw,30px);
    font-weight:600; text-align:center; line-height:1.3; }
.qa-front .qa-side-text { color:var(--pd); }
.qa-back  .qa-side-text { color:#fff; }

/* hint + divider */
.qa-hint { font-size:10px; font-weight:600; color:var(--pb); text-align:center;
    font-family:'Nunito',sans-serif; position:absolute; bottom:8px; width:100%; left:0; }
.qa-divider { display:none; }

/* controls */
.qa-controls { display:flex; gap:8px; justify-content:center; flex-wrap:wrap;
    padding:8px 14px; flex-shrink:0; width:100%; max-width:900px; }

/* completed */
.qa-completed { display:none; position:absolute; inset:0; background:#f0faf6;
    border-radius:20px; flex-direction:column; align-items:center;
    justify-content:center; padding:20px 16px; z-index:20; }
.qa-completed.active { display:flex; }
.done-card { background:#fff; border-radius:20px; border:1.5px solid var(--pb);
    box-shadow:0 4px 24px rgba(127,119,221,.10);
    width:100%; max-width:500px;
    display:flex; flex-direction:column; align-items:center;
    padding:28px 24px; gap:14px; text-align:center; }
.done-confetti { width:100%; height:5px; border-radius:3px;
    background:linear-gradient(90deg,var(--p) 0%,var(--t400) 35%,#f59e0b 65%,var(--p) 100%);
    margin-bottom:2px; }
.done-icon  { font-size:58px; line-height:1; }
.done-title { font-family:'Fredoka',sans-serif; font-size:26px; font-weight:700; color:var(--t800); margin:0; }
.done-sub   { font-size:13px; font-weight:600; color:#64748b; max-width:300px; line-height:1.5; margin:0; }
.done-bar-row  { width:100%; display:flex; flex-direction:column; gap:5px; }
.done-bar-hd   { display:flex; justify-content:space-between; align-items:center; }
.done-bar-lbl  { font-size:12px; font-weight:800; color:var(--pd); }
.done-bar-val  { font-size:12px; font-weight:800; color:var(--p); }
.done-bar-track { width:100%; height:10px; background:var(--pl); border-radius:6px;
    border:1px solid var(--pb); overflow:hidden; }
.done-bar-fill  { height:100%; border-radius:6px;
    background:linear-gradient(90deg,var(--t400),var(--p)); width:0;
    transition:width .8s cubic-bezier(.34,1,.64,1); }
.done-stat-box { background:var(--pl); border-radius:16px; border:1.5px solid var(--pb);
    padding:14px 20px; width:100%; display:flex; align-items:center;
    justify-content:center; gap:12px; }
.done-stat-count { font-family:'Fredoka',sans-serif; font-size:24px; font-weight:700; color:var(--pd); }
.done-stat-lbl   { font-size:11px; font-weight:800; color:var(--p);
    text-transform:uppercase; letter-spacing:.06em; }
.done-btns { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
.done-btn  { display:inline-flex; align-items:center; gap:5px; border:none;
    border-radius:999px; font-family:'Nunito',sans-serif; font-weight:800;
    font-size:13px; color:#fff; cursor:pointer; padding:9px 20px; line-height:1;
    background:var(--p); box-shadow:0 3px 10px rgba(127,119,221,.28);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s,filter .15s; }
.done-btn:hover { transform:translateY(-2px) scale(1.04); filter:brightness(1.08); }
.done-btn.teal  { background:var(--t400); box-shadow:0 3px 10px rgba(29,158,117,.28); }

/* fullscreen scaling */
body.fullscreen-embedded .qa-flip-area,
body.presentation-mode   .qa-flip-area  { min-height:300px; }
body.fullscreen-embedded .qa-card,
body.presentation-mode   .qa-card       { height:280px; }
body.fullscreen-embedded .qa-side-text,
body.presentation-mode   .qa-side-text  { font-size:clamp(22px,4vw,38px) !important; }
body.fullscreen-embedded .act-btn,
body.presentation-mode   .act-btn       { padding:10px 22px !important; font-size:14px !important; }
body.fullscreen-embedded .qa-topbar,
body.presentation-mode   .qa-topbar     { height:46px !important; }

body.presentation-mode .qa-topbar,
body.embedded-mode     .qa-topbar { display:none; }

@media (max-width:600px) {
    .qa-card-wrap { border-radius:14px; }
    .qa-flip-area { min-height:180px; padding:10px 12px; }
    .qa-card { height:160px; }
    .act-btn { padding:7px 14px; font-size:12px; }
}
</style>

<div class="qa-page">

    <div class="qa-topbar">
        <a class="act-btn" style="padding:6px 14px;font-size:12px"
           href="<?php echo htmlspecialchars(
               (isset($_GET['return_to']) && $_GET['return_to'] !== '') ? $_GET['return_to'] :
               (isset($_GET['assignment']) && $_GET['assignment'] !== ''
                   ? '../../academic/teacher_unit.php?assignment='.urlencode($_GET['assignment']).'&unit='.urlencode($unit)
                   : '../../academic/unit_view.php?unit='.urlencode($unit)),
           ENT_QUOTES, 'UTF-8'); ?>">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M6.5 1.5L3 5l3.5 3.5" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back
        </a>
        <span class="qa-topbar-title">Questions &amp; Answers</span>
    </div>

    <div class="qa-body">

        <!-- progress row -->
        <div class="qa-prog-row">
            <div class="qa-prog-track">
                <div class="qa-prog-fill" id="qa-prog-fill"
                     style="width:<?php echo count($cards) > 0 ? round(1/count($cards)*100) : 100; ?>%"></div>
            </div>
            <span class="qa-prog-lbl" id="qa-prog-lbl">1 / <?php echo count($cards); ?></span>
        </div>

        <!-- card area with side arrows -->
        <div class="qa-card-area" id="qa-wrap">
            <button type="button" class="qa-arrow-btn" id="qa-prev-arrow">&#8249;</button>

            <div class="qa-card-wrap">
                <div class="qa-flip-area" id="qa-flip-area">
                    <div class="qa-card" id="qa-card" tabindex="0">
                        <div class="qa-side qa-front">
                            <div class="qa-side-text" id="qa-q-text"></div>
                            <span class="qa-hint">Tap to reveal answer</span>
                        </div>
                        <div class="qa-side qa-back">
                            <div class="qa-side-text" id="qa-a-text"></div>
                        </div>
                    </div>
                </div>

                <!-- completed overlay inside card-wrap -->
                <div class="qa-completed" id="qa-completed">
                    <div class="done-card">
                        <div class="done-confetti"></div>
                        <div class="done-icon">&#x2705;</div>
                        <h2 class="done-title">All Done!</h2>
                        <p class="done-sub">You reviewed all questions. Excellent fluency practice!</p>
                        <div class="done-bar-row">
                            <div class="done-bar-hd">
                                <span class="done-bar-lbl">Questions reviewed</span>
                                <span class="done-bar-val" id="qa-done-count">0 / 0</span>
                            </div>
                            <div class="done-bar-track">
                                <div class="done-bar-fill" id="qa-done-bar"></div>
                            </div>
                        </div>
                        <div class="done-stat-box">
                            <span style="font-size:38px">&#x1F4AC;</span>
                            <div style="text-align:left">
                                <div class="done-stat-count" id="qa-done-stat">0 questions</div>
                                <div class="done-stat-lbl">practised today</div>
                            </div>
                        </div>
                        <div class="done-btns">
                            <button type="button" class="done-btn teal" id="qa-restart">&#8635; Review Again</button>
                            <button type="button" class="done-btn" onclick="history.back()">Next Activity &#8594;</button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="qa-arrow-btn" id="qa-next-arrow">&#8250;</button>
        </div>

        <!-- controls -->
        <div class="qa-controls">
            <button type="button" class="act-btn" style="padding:8px 16px;font-size:13px" id="qa-prev">&#9664; Prev</button>
            <button type="button" class="act-btn teal" style="padding:8px 16px;font-size:13px" id="qa-listen">&#x1F50A; Listen</button>
            <button type="button" class="act-btn" style="padding:8px 16px;font-size:13px" id="qa-next">Next &#9654;</button>
        </div>


    </div>

    <div class="qa-bottombar"></div>
</div>

<audio id="qa-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function () {

var CARDS = <?php echo json_encode($cards, JSON_UNESCAPED_UNICODE); ?>;
var total = CARDS.length;
var idx   = 0;
var done  = false;

var cardEl   = document.getElementById('qa-card');
var qText    = document.getElementById('qa-q-text');
var aText    = document.getElementById('qa-a-text');
var progFill = document.getElementById('qa-prog-fill');
var progLbl  = document.getElementById('qa-prog-lbl');
var wrap     = document.getElementById('qa-wrap');
var comp     = document.getElementById('qa-completed');
var winSnd   = document.getElementById('qa-win');

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function loadCard() {
    var card = CARDS[idx] || {};
    qText.textContent = card.question || 'No question';
    aText.textContent = card.answer   || 'No answer';
    cardEl.classList.remove('flipped');
    var pct = Math.round((idx + 1) / total * 100);
    progFill.style.width = pct + '%';
    progLbl.textContent  = (idx + 1) + ' / ' + total;
}

/* ═══════════════════════════════════════════════════════════
   UNIFIED TTS ENGINE — inglesdeuna v2
   ttsSpeak(text, { gender: 'female'|'male', lang: 'en-US', rate: 0.82 })
   ═══════════════════════════════════════════════════════════ */
var TTS = (function () {
    var FEMALE_HINTS = [
        'zira','samantha','karen','aria','jenny','emma','ava','siri',
        'google us english','microsoft zira','microsoft aria','microsoft jenny',
        'paulina','sabina','monica','conchita','esperanza','female','woman'
    ];
    var MALE_HINTS = [
        'guy','ryan','daniel','liam','google uk english male',
        'microsoft guy','microsoft ryan','microsoft david',
        'jorge','diego','carlos','miguel','male','man'
    ];
    var _cache = null;
    var _tries = 0;

    function _load(cb) {
        if (!window.speechSynthesis) return;
        var v = window.speechSynthesis.getVoices();
        if (v && v.length) { _cache = v; cb(v); return; }
        if (window.speechSynthesis.onvoiceschanged !== undefined) {
            window.speechSynthesis.onvoiceschanged = function () {
                _cache = window.speechSynthesis.getVoices();
                if (_cache.length) cb(_cache);
            };
        }
        if (_tries < 12) { _tries++; setTimeout(function () { _load(cb); }, 150); }
    }

    function _pick(voices, lang, gender) {
        if (!voices || !voices.length) return null;
        var pre = lang.split('-')[0].toLowerCase();
        var pool = [];
        for (var i = 0; i < voices.length; i++) {
            var vl = String(voices[i].lang || '').toLowerCase();
            if (vl === lang.toLowerCase() || vl.indexOf(pre+'-') === 0 || vl.indexOf(pre+'_') === 0) pool.push(voices[i]);
        }
        if (!pool.length) pool = voices;
        var hints = gender === 'male' ? MALE_HINTS : FEMALE_HINTS;
        var quality = ['neural','premium','enhanced','natural'];
        /* Pass 1: quality + gender */
        for (var q = 0; q < quality.length; q++) {
            for (var h = 0; h < hints.length; h++) {
                for (var v = 0; v < pool.length; v++) {
                    var lbl = (String(pool[v].name||'')+' '+String(pool[v].voiceURI||'')).toLowerCase();
                    if (lbl.indexOf(quality[q]) !== -1 && lbl.indexOf(hints[h]) !== -1) return pool[v];
                }
            }
        }
        /* Pass 2: gender only */
        for (var h2 = 0; h2 < hints.length; h2++) {
            for (var v2 = 0; v2 < pool.length; v2++) {
                var lbl2 = (String(pool[v2].name||'')+' '+String(pool[v2].voiceURI||'')).toLowerCase();
                if (lbl2.indexOf(hints[h2]) !== -1) return pool[v2];
            }
        }
        return pool[0] || null;
    }

    function speak(text, opts) {
        if (!text || !window.speechSynthesis) return;
        opts = opts || {};
        var lang   = opts.lang   || 'en-US';
        var gender = opts.gender || 'female';
        var rate   = typeof opts.rate  !== 'undefined' ? opts.rate  : 0.82;
        var pitch  = typeof opts.pitch !== 'undefined' ? opts.pitch : 1.0;

        window.speechSynthesis.cancel();

        function _do(voices) {
            var u = new SpeechSynthesisUtterance(text);
            u.lang = lang; u.rate = rate; u.pitch = pitch; u.volume = 1;
            var voice = _pick(voices, lang, gender);
            if (voice) u.voice = voice;
            window.speechSynthesis.speak(u);
        }

        if (_cache && _cache.length) { _do(_cache); }
        else { _load(function (v) { _do(v); }); }
    }

    if (window.speechSynthesis) _load(function () {});
    return { speak: speak };
})();

function speak(text) { TTS.speak(text, { gender: 'male', rate: 0.82 }); }

function showCompleted() {
    done = true;
    comp.classList.add('active');
    var countEl = document.getElementById('qa-done-count');
    var barEl   = document.getElementById('qa-done-bar');
    var statEl  = document.getElementById('qa-done-stat');
    if (countEl) countEl.textContent = total + ' / ' + total;
    if (statEl)  statEl.textContent  = total + ' question' + (total !== 1 ? 's' : '');
    setTimeout(function() { if (barEl) barEl.style.width = '100%'; }, 100);
    try { winSnd.pause(); winSnd.currentTime = 0; winSnd.play(); } catch(e) {}
}

document.getElementById('qa-prev').addEventListener('click', function() {
    if (done) return;
    cardEl.classList.remove('flipped');
    idx = (idx - 1 + total) % total;
    loadCard();
});

document.getElementById('qa-next').addEventListener('click', function() {
    if (done) return;
    cardEl.classList.remove('flipped');
    if (idx >= total - 1) { showCompleted(); return; }
    idx++;
    loadCard();
});

document.getElementById('qa-listen').addEventListener('click', function() {
    var card = CARDS[idx] || {};
    var text = cardEl.classList.contains('flipped') ? card.answer : card.question;
    speak(text || '');
});

document.getElementById('qa-flip-area').addEventListener('click', function() {
    if (!done) cardEl.classList.toggle('flipped');
});

cardEl.addEventListener('keydown', function(e) {
    if (done) return;
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cardEl.classList.toggle('flipped'); }
    if (e.key === 'ArrowRight') { document.getElementById('qa-next').click(); }
    if (e.key === 'ArrowLeft')  { document.getElementById('qa-prev').click(); }
});

document.getElementById('qa-restart').addEventListener('click', function() {
    done = false; idx = 0;
    comp.classList.remove('active');
    var barEl = document.getElementById('qa-done-bar');
    if (barEl) barEl.style.width = '0%';
    loadCard();
});

/* arrow buttons = same as prev/next */
var arrowPrev = document.getElementById('qa-prev-arrow');
var arrowNext = document.getElementById('qa-next-arrow');
if (arrowPrev) arrowPrev.addEventListener('click', function() { document.getElementById('qa-prev').click(); });
if (arrowNext) arrowNext.addEventListener('click', function() { document.getElementById('qa-next').click(); });

loadCard();

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-circle-question', $content);

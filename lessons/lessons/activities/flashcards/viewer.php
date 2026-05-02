<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])   ? trim((string) $_GET['id'])   : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function fc_columns(PDO $pdo): array
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

function fc_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $cols = fc_columns($pdo);
    $col  = in_array('unit_id', $cols, true) ? 'unit_id' : (in_array('unit', $cols, true) ? 'unit' : '');
    if ($col === '') return '';
    $stmt = $pdo->prepare("SELECT {$col} FROM activities WHERE id=:id LIMIT 1");
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row[$col] ?? '') : '';
}

function fc_load(PDO $pdo, string $unit, string $activityId): array
{
    $empty = array('title' => 'Flashcards', 'cards' => array());
    $cols  = fc_columns($pdo);

    $fields = array('id');
    if (in_array('data',         $cols, true)) $fields[] = 'data';
    if (in_array('content_json', $cols, true)) $fields[] = 'content_json';
    if (in_array('title',        $cols, true)) $fields[] = 'title';
    if (in_array('name',         $cols, true)) $fields[] = 'name';
    $sel = implode(', ', $fields);

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE id=:id AND type='flashcards' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $cols, true)) {
        $stmt = $pdo->prepare("SELECT {$sel} FROM activities WHERE unit_id=:unit AND type='flashcards' ORDER BY id ASC LIMIT 1");
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
            'text'         => isset($item['text'])         ? trim((string)$item['text'])         : '',
            'english_text' => isset($item['english_text']) ? trim((string)$item['english_text']) : '',
            'spanish_text' => isset($item['spanish_text']) ? trim((string)$item['spanish_text']) : '',
            'image'        => isset($item['image'])        ? trim((string)$item['image'])        : '',
        );
    }

    $title = '';
    if (isset($row['title'])  && trim((string)$row['title'])  !== '') $title = trim((string)$row['title']);
    if ($title === '' && isset($row['name']) && trim((string)$row['name']) !== '') $title = trim((string)$row['name']);
    if ($title === '' && isset($data['title'])) $title = trim((string)$data['title']);
    if ($title === '') $title = 'Flashcards';

    return array('title' => $title, 'cards' => $cards);
}

if ($unit === '' && $activityId !== '') $unit = fc_resolve_unit($pdo, $activityId);
$activity    = fc_load($pdo, $unit, $activityId);
$cards       = $activity['cards'];
$viewerTitle = $activity['title'];

if (empty($cards)) die('No flashcards found for this unit');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --p:#7F77DD; --pd:#534AB7; --pl:#EEEDFE; --pb:#AFA9EC;
    --t50:#E1F5EE; --t100:#9FE1CB; --t200:#5DCAA5; --t400:#1D9E75; --t600:#0F6E56; --t800:#085041;
    --green:#16a34a;
}

/* ── template reset ── */
body { margin:0 !important; padding:0 !important; background:#f0faf6 !important;
    font-family:'Nunito','Segoe UI',sans-serif !important; }
.activity-wrapper { max-width:100% !important; margin:0 !important; padding:0 !important;
    min-height:100vh; display:flex !important; flex-direction:column !important; background:transparent !important; }
.top-row { display:none !important; }
.viewer-content { flex:1 !important; display:flex !important; flex-direction:column !important;
    padding:0 !important; margin:0 !important; background:transparent !important;
    border:none !important; box-shadow:none !important; border-radius:0 !important; }

/* ── page shell ── */
.fc-page { display:flex; flex-direction:column; width:100vw; min-height:100vh; background:#f0faf6; }

/* topbar — lavender */
.fc-topbar { flex-shrink:0; height:42px; background:var(--pl); border-bottom:1.5px solid var(--pb);
    display:flex; align-items:center; padding:0 16px; gap:12px; }
.fc-topbar-title { font-size:12px; font-weight:800; color:var(--pd);
    letter-spacing:.1em; text-transform:uppercase; margin:0 auto; font-family:'Nunito',sans-serif; }
.fc-bottombar { flex-shrink:0; height:36px; background:var(--pl); border-top:1.5px solid var(--pb); }

/* shared activity button — purple pill */
.act-btn { display:inline-flex; align-items:center; justify-content:center; gap:5px;
    border:none; border-radius:999px; font-family:'Nunito',sans-serif; font-weight:800;
    color:#fff; cursor:pointer; white-space:nowrap; background:var(--p);
    box-shadow:0 3px 10px rgba(127,119,221,.30); line-height:1; text-decoration:none;
    transition:transform .18s cubic-bezier(.34,1.4,.64,1), box-shadow .15s, filter .15s; }
.act-btn:hover { transform:translateY(-2px) scale(1.04); box-shadow:0 7px 18px rgba(127,119,221,.42); filter:brightness(1.08); }
.act-btn:active { transform:scale(.97); }
.act-btn.teal { background:var(--t400); box-shadow:0 3px 10px rgba(29,158,117,.28); }
.act-btn.teal:hover { box-shadow:0 7px 18px rgba(29,158,117,.38); }

/* ── body area ── */
.fc-body { flex:1; display:flex; flex-direction:column; align-items:center;
    padding:16px 14px 10px; gap:12px; }

/* card container — white with purple soft border */
.fc-card-wrap { width:100%; max-width:720px; background:#fff;
    border-radius:20px; border:1.5px solid var(--pb);
    overflow:hidden; box-shadow:0 4px 20px rgba(127,119,221,.10); }

/* progress bar */
.fc-prog-row { display:flex; align-items:center; gap:10px; padding:10px 18px 0; }
.fc-prog-track { flex:1; height:5px; background:var(--pl); border-radius:3px;
    border:1px solid var(--pb); overflow:hidden; }
.fc-prog-fill { height:100%; background:var(--p); border-radius:3px;
    transition:width .35s ease; }
.fc-prog-lbl { font-size:11px; font-weight:800; color:var(--p); white-space:nowrap; font-family:'Nunito',sans-serif; }

/* card flip area */
.fc-flip-area { min-height:240px; perspective:1200px; padding:14px 18px; cursor:pointer; }
.fc-card { width:100%; height:220px; position:relative;
    transform-style:preserve-3d; transition:transform .55s ease; border-radius:14px; }
.fc-card.flipped { transform:rotateY(180deg); }
.fc-side { position:absolute; inset:0; backface-visibility:hidden; border-radius:14px;
    display:flex; flex-direction:column; align-items:center; justify-content:center; padding:20px; }
.fc-front { background:var(--pl); border:1.5px solid var(--pb); }
.fc-back  { background:var(--pd); border:1.5px solid rgba(255,255,255,.15);
    transform:rotateY(180deg); }

.fc-side-label { font-size:10px; font-weight:800; letter-spacing:.1em;
    text-transform:uppercase; font-family:'Nunito',sans-serif;
    margin-bottom:10px; opacity:.7; }
.fc-front .fc-side-label { color:var(--pd); }
.fc-back  .fc-side-label { color:rgba(255,255,255,.7); }

.fc-side-text { font-family:'Fredoka',sans-serif; font-size:clamp(22px,4vw,36px);
    font-weight:600; text-align:center; line-height:1.2; }
.fc-front .fc-side-text { color:var(--pd); }
.fc-back  .fc-side-text { color:#fff; }

.fc-side-img { max-width:100%; max-height:160px; object-fit:contain;
    border-radius:10px; border:1px solid var(--pb); }

/* hint */
.fc-hint { font-size:11px; font-weight:600; color:var(--pb); text-align:center;
    font-family:'Nunito',sans-serif; padding:0 0 4px; }

/* divider */
.fc-divider { height:1.5px; background:var(--pb); margin:0 18px; opacity:.4; }

/* controls */
.fc-controls { display:flex; gap:8px; justify-content:center; flex-wrap:wrap;
    padding:10px 14px; border-top:1px solid var(--pl); background:#fff; }

/* completed overlay */
.fc-completed { display:none; flex-direction:column; align-items:center;
    justify-content:center; text-align:center; padding:40px 24px; gap:12px; }
.fc-completed.active { display:flex; }
.fc-done-icon { font-size:56px; line-height:1; }
.fc-done-title { font-family:'Fredoka',sans-serif; font-size:26px; font-weight:700; color:var(--t800); margin:0; }
.fc-done-msg { font-size:13px; font-weight:600; color:#5a7a6a; margin:0; }

/* fullscreen / presentation scaling */
body.fullscreen-embedded .fc-flip-area,
body.presentation-mode   .fc-flip-area   { min-height:300px; }
body.fullscreen-embedded .fc-card,
body.presentation-mode   .fc-card        { height:280px; }
body.fullscreen-embedded .fc-side-text,
body.presentation-mode   .fc-side-text   { font-size:clamp(26px,4.5vw,44px) !important; }
body.fullscreen-embedded .act-btn,
body.presentation-mode   .act-btn        { padding:10px 22px !important; font-size:14px !important; }
body.fullscreen-embedded .fc-topbar,
body.presentation-mode   .fc-topbar      { height:46px !important; }

body.presentation-mode .fc-topbar,
body.embedded-mode     .fc-topbar { display:none; }

@media (max-width:600px) {
    .fc-card-wrap { border-radius:14px; }
    .fc-flip-area { min-height:180px; padding:10px 12px; }
    .fc-card { height:160px; }
    .act-btn { padding:7px 14px; font-size:12px; }
}
</style>

<div class="fc-page">

    <div class="fc-topbar">
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
        <span class="fc-topbar-title">Flashcards</span>
    </div>

    <div class="fc-body">

        <div class="fc-card-wrap" id="fc-wrap">

            <div class="fc-prog-row">
                <div class="fc-prog-track">
                    <div class="fc-prog-fill" id="fc-prog-fill" style="width:<?php echo count($cards) > 0 ? round(1/count($cards)*100) : 100; ?>%"></div>
                </div>
                <span class="fc-prog-lbl" id="fc-prog-lbl">1 / <?php echo count($cards); ?></span>
            </div>

            <div class="fc-flip-area" id="fc-flip-area">
                <div class="fc-card" id="fc-card">
                    <div class="fc-side fc-front" id="fc-front">
                        <span class="fc-side-label">English</span>
                        <div class="fc-side-text" id="fc-front-text"></div>
                    </div>
                    <div class="fc-side fc-back" id="fc-back">
                        <span class="fc-side-label">Translation</span>
                        <div class="fc-side-text" id="fc-back-text"></div>
                    </div>
                </div>
            </div>

            <div class="fc-hint">Tap card to flip &nbsp;·&nbsp; Enter or Space to flip</div>
            <div class="fc-divider"></div>

            <div class="fc-controls">
                <button type="button" class="act-btn" style="padding:8px 16px;font-size:13px" id="fc-prev">&#9664; Prev</button>
                <button type="button" class="act-btn teal" style="padding:8px 16px;font-size:13px" id="fc-listen">&#x1F50A; Listen</button>
                <button type="button" class="act-btn" style="padding:8px 16px;font-size:13px" id="fc-next">Next &#9654;</button>
            </div>

        </div>

        <div class="fc-completed" id="fc-completed">
            <div class="fc-done-icon">&#x2705;</div>
            <h2 class="fc-done-title">All cards reviewed!</h2>
            <p class="fc-done-msg">Great job practising your vocabulary.</p>
            <button type="button" class="act-btn" style="padding:10px 24px;font-size:14px" id="fc-restart">&#8635; Start over</button>
        </div>

    </div>

    <div class="fc-bottombar"></div>
</div>

<audio id="fc-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function () {

var CARDS = <?php echo json_encode($cards, JSON_UNESCAPED_UNICODE); ?>;
var total = CARDS.length;
var idx   = 0;
var done  = false;

var cardEl    = document.getElementById('fc-card');
var frontText = document.getElementById('fc-front-text');
var backText  = document.getElementById('fc-back-text');
var progFill  = document.getElementById('fc-prog-fill');
var progLbl   = document.getElementById('fc-prog-lbl');
var wrap      = document.getElementById('fc-wrap');
var completed = document.getElementById('fc-completed');
var winSnd    = document.getElementById('fc-win');

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function getEnglish(card) {
    return card.english_text || card.text || '';
}
function getTranslation(card) {
    return card.spanish_text || card.text || '';
}

function loadCard() {
    var card = CARDS[idx] || {};
    var eng  = getEnglish(card);
    var tr   = getTranslation(card);

    if (card.image) {
        frontText.innerHTML = '<img class="fc-side-img" src="' + esc(card.image) + '" alt="">';
    } else {
        frontText.textContent = eng || 'No text';
    }
    backText.textContent = tr || eng || 'No text';

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

function speak(text) { TTS.speak(text, { gender: 'female', rate: 0.82 }); }

function showCompleted() {
    done = true;
    wrap.style.display = 'none';
    completed.classList.add('active');
    try { winSnd.pause(); winSnd.currentTime = 0; winSnd.play(); } catch(e) {}
}

document.getElementById('fc-prev').addEventListener('click', function() {
    if (done) return;
    cardEl.classList.remove('flipped');
    idx = (idx - 1 + total) % total;
    loadCard();
});

document.getElementById('fc-next').addEventListener('click', function() {
    if (done) return;
    cardEl.classList.remove('flipped');
    if (idx >= total - 1) { showCompleted(); return; }
    idx++;
    loadCard();
});

document.getElementById('fc-listen').addEventListener('click', function() {
    var card = CARDS[idx] || {};
    var text = cardEl.classList.contains('flipped')
        ? (getTranslation(card) || getEnglish(card))
        : getEnglish(card);
    speak(text);
});

document.getElementById('fc-flip-area').addEventListener('click', function() {
    if (!done) cardEl.classList.toggle('flipped');
});

cardEl.addEventListener('keydown', function(e) {
    if (done) return;
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cardEl.classList.toggle('flipped'); }
    if (e.key === 'ArrowRight') { document.getElementById('fc-next').click(); }
    if (e.key === 'ArrowLeft')  { document.getElementById('fc-prev').click(); }
});
cardEl.setAttribute('tabindex', '0');

document.getElementById('fc-restart').addEventListener('click', function() {
    done = false; idx = 0;
    wrap.style.display = '';
    completed.classList.remove('active');
    loadCard();
});

loadCard();

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-clone', $content);

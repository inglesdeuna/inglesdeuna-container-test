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
/* ── variables ── */
/* ── Override template viewer-content background in all modes ── */
.viewer-content {
    background: #f0faf6 !important;
    overflow: hidden !important;
}
body.presentation-mode .viewer-content {
    background: #f0faf6 !important;
    overflow: hidden !important;
}
/* Make our page fill viewer-content completely */
.qa-page {
    flex: 1 !important;
    min-height: 0 !important;
    height: 100% !important;
    width: 100% !important;
    overflow: hidden !important;
    background: #f0faf6 !important;
}
:root{
    --p:#7F77DD; --pd:#534AB7; --pl:#EEEDFE; --pb:#AFA9EC;
    --t50:#E1F5EE; --t100:#9FE1CB; --t400:#1D9E75; --t600:#0F6E56; --t800:#085041;
    --green:#16a34a; --gold:#f59e0b;
}

/* ── wipe template chrome completely ── */
*,*::before,*::after { box-sizing:border-box; }
html,body { margin:0 !important; padding:0 !important;
    background:#f0faf6 !important; font-family:'Nunito','Segoe UI',sans-serif !important; }
.activity-wrapper { width:100% !important; min-height:100vh;
    margin:0 !important; padding:0 !important; max-width:100% !important;
    display:flex !important; flex-direction:column !important;
    background:transparent !important; overflow:hidden !important; }
.top-row { display:none !important; }
.viewer-content { flex:1 !important; min-height:0 !important;
    display:flex !important; flex-direction:column !important;
    padding:0 !important; margin:0 !important; background:#f0faf6 !important;
    border:none !important; box-shadow:none !important; border-radius:0 !important; }

/* ══════════════════════════════
   PAGE SHELL
   ══════════════════════════════ */
.qa-page { display:flex; flex-direction:column; width:100%; flex:1;
    min-height:0; background:#f0faf6; overflow:hidden; }

/* topbar — lavender */
.qa-topbar { flex-shrink:0; height:42px; background:var(--pl);
    border-bottom:1.5px solid var(--pb);
    display:flex; align-items:center; padding:0 16px; gap:12px; }
.qa-topbar-title { font-size:12px; font-weight:800; color:var(--pd);
    letter-spacing:.1em; text-transform:uppercase; margin:0 auto;
    font-family:'Nunito',sans-serif; }
body.presentation-mode .qa-topbar,
body.embedded-mode     .qa-topbar { display:none !important; }

/* bottombar — lavender */
.qa-btmbar { flex-shrink:0; height:36px; background:var(--pl);
    border-top:1.5px solid var(--pb); }

/* back button — purple pill */
.qa-back-btn { display:inline-flex; align-items:center; gap:5px;
    border:none; border-radius:999px; font-family:'Nunito',sans-serif;
    font-weight:800; font-size:12px; color:#fff; cursor:pointer;
    padding:6px 14px; line-height:1; text-decoration:none;
    background:var(--p); box-shadow:0 3px 8px rgba(127,119,221,.28);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s; }
.qa-back-btn:hover { transform:translateY(-2px) scale(1.04);
    box-shadow:0 6px 16px rgba(127,119,221,.4); }

/* ══════════════════════════════
   MAIN BODY
   ══════════════════════════════ */
.qa-body { flex:1; min-height:0; display:flex; flex-direction:column;
    align-items:center; padding:10px 14px 8px; gap:8px; overflow:hidden; }

/* progress row */
.qa-prog-row { flex-shrink:0; width:100%; max-width:940px;
    display:flex; align-items:center; gap:10px; }
.qa-prog-track { flex:1; height:5px; background:var(--pl); border-radius:3px;
    border:1px solid var(--pb); overflow:hidden; }
.qa-prog-fill { height:100%; background:var(--p); border-radius:3px;
    transition:width .35s ease; }
.qa-prog-lbl { font-size:11px; font-weight:800; color:var(--p);
    white-space:nowrap; font-family:'Nunito',sans-serif; }

/* card area — arrow | card | arrow */
.qa-card-area { flex:1; min-height:0; width:100%; max-width:940px;
    display:flex; align-items:center; gap:10px; }

/* side arrow buttons */
.qa-arrow { flex-shrink:0; width:40px; height:40px; border-radius:50%;
    background:var(--pl); border:1.5px solid var(--pb); color:var(--pd);
    font-size:20px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:background .15s, transform .15s; line-height:1; }
.qa-arrow:hover { background:var(--pb); transform:scale(1.1); }

/* white card with purple border */
.qa-card-shell { flex:1; height:100%; min-height:0; background:#fff;
    border-radius:20px; border:1.5px solid var(--pb);
    box-shadow:0 4px 20px rgba(127,119,221,.10);
    position:relative; overflow:hidden;
    max-height:calc(100vh - 170px); }

/* flip container fills shell */
.qa-flip-wrap { width:100%; height:100%; perspective:1400px; cursor:pointer; }
.qa-card { width:100%; height:100%; position:relative;
    transform-style:preserve-3d; transition:transform .55s ease; }
.qa-card.flipped { transform:rotateY(180deg); }

/* card faces */
.qa-face { position:absolute; inset:0; backface-visibility:hidden;
    border-radius:19px; display:flex; flex-direction:column;
    align-items:center; justify-content:center; padding:28px 40px; gap:10px; }
.qa-face-front { background:var(--pl); }
.qa-face-back  { background:var(--pd); transform:rotateY(180deg); }

.qa-face-text { font-family:'Fredoka',sans-serif;
    font-size:clamp(24px,5vw,56px); font-weight:600;
    text-align:center; line-height:1.15; }
.qa-face-front .qa-face-text { color:var(--pd); }
.qa-face-back  .qa-face-text { color:#fff; }

.qa-tap-hint { position:absolute; bottom:10px; font-size:10px;
    font-weight:600; color:var(--pb); font-family:'Nunito',sans-serif; }

/* controls bar */
.qa-controls { flex-shrink:0; width:100%; max-width:940px;
    display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
.qa-btn { display:inline-flex; align-items:center; gap:4px; border:none;
    border-radius:999px; font-family:'Nunito',sans-serif; font-weight:800;
    font-size:13px; color:#fff; cursor:pointer; padding:8px 18px; line-height:1;
    background:var(--p); box-shadow:0 3px 10px rgba(127,119,221,.28);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s,filter .15s; }
.qa-btn:hover { transform:translateY(-2px) scale(1.04);
    box-shadow:0 7px 18px rgba(127,119,221,.42); filter:brightness(1.08); }
.qa-btn.teal { background:var(--t400); box-shadow:0 3px 10px rgba(29,158,117,.28); }
.qa-btn.teal:hover { box-shadow:0 7px 18px rgba(29,158,117,.38); }

/* ══════════════════════════════
   COMPLETED OVERLAY
   ══════════════════════════════ */
.qa-completed { display:none; position:absolute; inset:0;
    background:#f0faf6; border-radius:19px;
    flex-direction:column; align-items:center;
    justify-content:center; padding:20px 16px; z-index:20; }
.qa-completed.active { display:flex; }

.done-card { background:#fff; border-radius:20px; border:1.5px solid var(--pb);
    box-shadow:0 4px 24px rgba(127,119,221,.10);
    width:100%; max-width:480px;
    display:flex; flex-direction:column; align-items:center;
    padding:26px 24px; gap:12px; text-align:center; }
.done-confetti { width:100%; height:5px; border-radius:3px;
    background:linear-gradient(90deg,var(--p) 0%,var(--t400) 35%,#f59e0b 65%,var(--p) 100%); }
.done-icon  { font-size:54px; line-height:1; }
.done-title { font-family:'Fredoka',sans-serif; font-size:26px;
    font-weight:700; color:var(--t800); margin:0; }
.done-sub   { font-size:13px; font-weight:600; color:#64748b;
    max-width:300px; line-height:1.5; margin:0; }
.done-bar-row   { width:100%; display:flex; flex-direction:column; gap:5px; }
.done-bar-hd    { display:flex; justify-content:space-between; align-items:center; }
.done-bar-lbl   { font-size:12px; font-weight:800; color:var(--pd); }
.done-bar-val   { font-size:12px; font-weight:800; color:var(--p); }
.done-bar-track { width:100%; height:10px; background:var(--pl);
    border-radius:6px; border:1px solid var(--pb); overflow:hidden; }
.done-bar-fill  { height:100%; border-radius:6px; width:0%;
    background:linear-gradient(90deg,var(--t400),var(--p));
    transition:width .8s cubic-bezier(.34,1,.64,1); }
.done-stat-box  { background:var(--pl); border-radius:16px;
    border:1.5px solid var(--pb); padding:14px 20px; width:100%;
    display:flex; align-items:center; justify-content:center; gap:12px; }
.done-stat-num  { font-family:'Fredoka',sans-serif; font-size:24px;
    font-weight:700; color:var(--pd); }
.done-stat-lbl  { font-size:11px; font-weight:800; color:var(--p);
    text-transform:uppercase; letter-spacing:.06em; }
.done-btns { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
.done-btn  { display:inline-flex; align-items:center; gap:4px; border:none;
    border-radius:999px; font-family:'Nunito',sans-serif; font-weight:800;
    font-size:13px; color:#fff; cursor:pointer; padding:9px 20px; line-height:1;
    background:var(--p); box-shadow:0 3px 10px rgba(127,119,221,.28);
    transition:transform .18s cubic-bezier(.34,1.4,.64,1),box-shadow .15s; }
.done-btn:hover { transform:translateY(-2px) scale(1.04); }
.done-btn.teal  { background:var(--t400); }

/* responsive */
@media (max-width:600px) {
    .qa-arrow { width:32px; height:32px; font-size:16px; }
    .qa-face  { padding:16px 20px; }
    .qa-btn   { padding:7px 14px; font-size:12px; }
}
</style>

<div class="qa-page">

    <div class="qa-topbar">
        <a class="qa-back-btn"
           href="<?php echo htmlspecialchars(
               (isset($_GET['return_to']) && $_GET['return_to'] !== '') ? $_GET['return_to'] :
               (isset($_GET['assignment']) && $_GET['assignment'] !== ''
                   ? '../../academic/teacher_unit.php?assignment='.urlencode($_GET['assignment']).'&unit='.urlencode($unit)
                   : '../../academic/unit_view.php?unit='.urlencode($unit)),
           ENT_QUOTES, 'UTF-8'); ?>">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                <path d="M6.5 1.5L3 5l3.5 3.5" stroke="#fff" stroke-width="1.7"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Back
        </a>
        <span class="qa-topbar-title">Questions &amp; Answers</span>
    </div>

    <div class="qa-body">

        <!-- progress -->
        <div class="qa-prog-row">
            <div class="qa-prog-track">
                <div class="qa-prog-fill" id="qa-prog"
                     style="width:<?php echo count($cards)>0 ? round(1/count($cards)*100) : 100; ?>%"></div>
            </div>
            <span class="qa-prog-lbl" id="qa-prog-lbl">1 / <?php echo count($cards); ?></span>
        </div>

        <!-- card area -->
        <div class="qa-card-area">
            <button type="button" class="qa-arrow" id="qa-arrow-prev">&#8249;</button>

            <div class="qa-card-shell">
                <div class="qa-flip-wrap" id="qa-flip-wrap">
                    <div class="qa-card" id="qa-card" tabindex="0">

                        <div class="qa-face qa-face-front">
                            <div class="qa-face-text" id="qa-q-text"></div>
                            <span class="qa-tap-hint">Tap to reveal answer</span>
                        </div>

                        <div class="qa-face qa-face-back">
                            <div class="qa-face-text" id="qa-a-text"></div>
                        </div>

                    </div>
                </div>

                <!-- completed overlay -->
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
                            <span style="font-size:36px">&#x1F4AC;</span>
                            <div style="text-align:left">
                                <div class="done-stat-num" id="qa-done-num">0</div>
                                <div class="done-stat-lbl">questions practised today</div>
                            </div>
                        </div>
                        <div class="done-btns">
                            <button type="button" class="done-btn teal" id="qa-restart">&#8635; Review Again</button>
                            <button type="button" class="done-btn" onclick="history.back()">Next Activity &#8594;</button>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="qa-arrow" id="qa-arrow-next">&#8250;</button>
        </div>

        <!-- controls -->
        <div class="qa-controls">
            <button type="button" class="qa-btn" id="qa-prev">&#9664; Prev</button>
            <button type="button" class="qa-btn teal" id="qa-listen">&#x1F50A; Listen</button>
            <button type="button" class="qa-btn" id="qa-next">Next &#9654;</button>
        </div>

    </div>

    <div class="qa-btmbar"></div>
</div>

<audio id="qa-win" src="../../hangman/assets/win.mp3" preload="auto"></audio>

<script>
(function(){

var CARDS = <?php echo json_encode($cards, JSON_UNESCAPED_UNICODE); ?>;
var TOTAL = CARDS.length;
var idx   = 0;
var done  = false;

var cardEl   = document.getElementById('qa-card');
var qText    = document.getElementById('qa-q-text');
var aText    = document.getElementById('qa-a-text');
var progFill = document.getElementById('qa-prog');
var progLbl  = document.getElementById('qa-prog-lbl');
var comp     = document.getElementById('qa-completed');
var winSnd   = document.getElementById('qa-win');

function loadCard() {
    var card = CARDS[idx] || {};
    qText.textContent = card.question || 'No question';
    aText.textContent = card.answer   || 'No answer';
    cardEl.classList.remove('flipped');
    progFill.style.width = Math.round((idx+1)/TOTAL*100) + '%';
    progLbl.textContent  = (idx+1) + ' / ' + TOTAL;
}



function speak(text) { TTS.speak(text, { gender: 'male', rate: 0.82 }); }

function showCompleted() {
    done = true;
    comp.classList.add('active');
    var cEl = document.getElementById('qa-done-count');
    var bEl = document.getElementById('qa-done-bar');
    var nEl = document.getElementById('qa-done-num');
    if (cEl) cEl.textContent = TOTAL + ' / ' + TOTAL;
    if (nEl) nEl.textContent = TOTAL;
    setTimeout(function(){ if (bEl) bEl.style.width = '100%'; }, 100);
    try { winSnd.pause(); winSnd.currentTime=0; winSnd.play(); } catch(e){}
}

function goPrev() {
    if (done) return;
    cardEl.classList.remove('flipped');
    idx = (idx - 1 + TOTAL) % TOTAL;
    loadCard();
}

function goNext() {
    if (done) return;
    cardEl.classList.remove('flipped');
    if (idx >= TOTAL - 1) { showCompleted(); return; }
    idx++;
    loadCard();
}

document.getElementById('qa-prev').addEventListener('click', goPrev);
document.getElementById('qa-next').addEventListener('click', goNext);
document.getElementById('qa-arrow-prev').addEventListener('click', goPrev);
document.getElementById('qa-arrow-next').addEventListener('click', goNext);

document.getElementById('qa-listen').addEventListener('click', function() {
    var card = CARDS[idx] || {};
    speak(cardEl.classList.contains('flipped') ? card.answer : card.question);
});

document.getElementById('qa-flip-wrap').addEventListener('click', function() {
    if (!done) cardEl.classList.toggle('flipped');
});

cardEl.addEventListener('keydown', function(e) {
    if (done) return;
    if (e.key==='Enter'||e.key===' ') { e.preventDefault(); cardEl.classList.toggle('flipped'); }
    if (e.key==='ArrowRight') goNext();
    if (e.key==='ArrowLeft')  goPrev();
});

document.getElementById('qa-restart').addEventListener('click', function() {
    done = false; idx = 0;
    comp.classList.remove('active');
    var bEl = document.getElementById('qa-done-bar');
    if (bEl) bEl.style.width = '0%';
    loadCard();
});

loadCard();

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-circle-question', $content);

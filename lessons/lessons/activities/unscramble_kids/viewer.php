<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])     ? trim((string) $_GET['id'])      : '';
$unit       = isset($_GET['unit'])   ? trim((string) $_GET['unit'])    : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

/* ── helpers ─────────────────────────────────────────────────────────── */
function usk_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function usk_default_title(): string { return 'Spell the Word'; }

function usk_normalize_payload($raw): array
{
    $default = ['title' => usk_default_title(), 'voice_id' => 'Nggzl2QAXh3OijoXD116', 'words' => []];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $words = [];
    foreach (($d['words'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $word = strtoupper(trim((string) ($item['word'] ?? '')));
        if ($word === '') continue;
        $words[] = [
            'word'       => $word,
            'emoji'      => trim((string) ($item['emoji']      ?? '')),
            'hint'       => trim((string) ($item['hint']       ?? '')),
            'audio'      => trim((string) ($item['audio']      ?? '')),
            'voice_id'   => trim((string) ($item['voice_id']   ?? '')),
        ];
    }

    $title   = trim((string) ($d['title']    ?? ''));
    $voiceId = trim((string) ($d['voice_id'] ?? 'Nggzl2QAXh3OijoXD116'));
    if ($voiceId === '') $voiceId = 'Nggzl2QAXh3OijoXD116';

    return [
        'title'    => $title !== '' ? $title : usk_default_title(),
        'voice_id' => $voiceId,
        'words'    => $words,
    ];
}

function usk_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['id' => '', 'title' => usk_default_title(), 'voice_id' => 'Nggzl2QAXh3OijoXD116', 'words' => []];
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble_kids' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'unscramble_kids' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $p = usk_normalize_payload($row['data'] ?? null);
    return [
        'id'       => (string) ($row['id'] ?? ''),
        'title'    => $p['title'],
        'voice_id' => $p['voice_id'],
        'words'    => $p['words'],
    ];
}

/* ── load data ───────────────────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') {
    $unit = usk_resolve_unit($pdo, $activityId);
}

$activity       = usk_load($pdo, $activityId, $unit);
$viewerTitle    = $activity['title'];
$activityVoiceId = $activity['voice_id'];
$words          = $activity['words'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

if (count($words) === 0) {
    die('No words found for this activity');
}

/* ── render ──────────────────────────────────────────────────────────── */
ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --us-orange:#F97316;--us-purple:#7F77DD;--us-purple-dark:#534AB7;
    --us-purple-soft:#EEEDFE;--us-lila:#EDE9FA;--us-muted:#9B94BE;
    --us-green:#16a34a;--us-green-soft:#f0fdf4;--us-green-dark:#15803d;
    --us-red:#ef4444;--us-red-soft:#fef2f2;--us-red-dark:#b91c1c;
}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

/* ── page shell ── */
.usk-page{width:100%;padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#fff;box-sizing:border-box}
.usk-app{width:min(760px,100%);margin:0 auto}

.usk-topbar{height:36px;display:flex;align-items:center;justify-content:center;margin-bottom:8px}
.usk-topbar-title{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}

/* ── hero ── */
.usk-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}
.usk-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.usk-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(30px,5.5vw,52px);font-weight:700;color:var(--us-orange);margin:0;line-height:1.03}
.usk-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.8vw,16px);font-weight:800;color:var(--us-muted);margin:8px 0 0}

/* ── progress ── */
.usk-progress{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.usk-prog-label{font-size:12px;font-weight:900;color:var(--us-muted);min-width:44px}
.usk-track{flex:1;height:10px;background:var(--us-purple-soft);border-radius:999px;overflow:hidden}
.usk-fill{height:100%;background:linear-gradient(90deg,var(--us-orange),var(--us-purple));border-radius:999px;transition:width .35s}
.usk-badge{min-width:72px;text-align:center;padding:6px 10px;border-radius:999px;background:var(--us-purple);color:#fff;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900}

/* ── stage card ── */
.usk-stage{background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13);width:100%;box-sizing:border-box}

/* ── inner card (emoji + hint + listen + drop zone) ── */
.usk-card-inner{padding:clamp(18px,3vw,24px);background:#fff;border:1px solid var(--us-lila);border-radius:28px;margin-bottom:16px;display:flex;flex-direction:column;align-items:center;gap:14px;box-shadow:0 12px 36px rgba(127,119,221,.08)}

.usk-img-box{width:120px;height:108px;border-radius:20px;background:#FFF0E6;border:1.5px solid #FCDDBF;display:flex;align-items:center;justify-content:center;font-size:62px;line-height:1;flex-shrink:0}
.usk-word-hint{font-family:'Fredoka',sans-serif;font-size:17px;font-weight:600;color:var(--us-purple-dark);text-align:center}

/* ── listen button ── */
.usk-btn-listen{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:10px;background:var(--us-purple);border:none;cursor:pointer;font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s}
.usk-btn-listen:hover{filter:brightness(1.07);transform:translateY(-1px)}
.usk-btn-listen:active{transform:scale(.98)}

/* ── answer drop zone ── */
.usk-answer-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center;min-height:78px;padding:14px 12px;border-radius:22px;border:2px dashed var(--us-lila);width:100%;background:#fff;box-sizing:border-box;transition:border-color .15s,background .15s}
.usk-answer-row.drag-over{border-color:var(--us-purple);background:#FAFAFE}

/* ── slots ── */
.usk-slot{width:54px;height:64px;border-radius:12px;border:1.5px solid #CDC7F3;border-bottom-width:4px;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-family:'Fredoka',sans-serif;font-size:28px;font-weight:600;color:#4A3FC2;cursor:default;transition:border-color .12s,background .12s}
.usk-slot.empty{border-style:dashed;color:#CDC7F3;font-size:22px}
.usk-slot.filled{background:#F8F7FF;border-color:var(--us-purple);border-bottom-color:var(--us-purple-dark);cursor:pointer}
.usk-slot.correct{background:var(--us-green-soft);border-color:var(--us-green);border-bottom-color:var(--us-green-dark);color:var(--us-green-dark);cursor:default}
.usk-slot.wrong{background:var(--us-red-soft);border-color:var(--us-red);border-bottom-color:var(--us-red-dark);color:var(--us-red-dark);cursor:default}

/* ── bank ── */
.usk-bank-label{font-family:'Nunito',sans-serif;font-size:11px;font-weight:900;color:var(--us-muted);text-transform:uppercase;letter-spacing:.08em;align-self:flex-start}
.usk-bank{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;min-height:64px;width:100%;margin-top:4px}

/* ── letter chips ── */
.usk-chip{width:54px;height:64px;border-radius:12px;border:1.5px solid #CDC7F3;border-bottom-width:4px;background:#fff;display:inline-flex;align-items:center;justify-content:center;font-family:'Fredoka',sans-serif;font-size:28px;font-weight:600;color:#4A3FC2;cursor:grab;user-select:none;transition:transform .13s,box-shadow .13s,border-color .13s}
.usk-chip:hover{transform:translateY(-2px) scale(1.07);border-color:#AFA9EC;border-bottom-color:#7F77DD;box-shadow:0 6px 16px rgba(127,119,221,.18)}
.usk-chip:active{cursor:grabbing;transform:scale(.97)}
.usk-chip.used{opacity:0;pointer-events:none;width:0;height:0;border:none;margin:0;padding:0;overflow:hidden;flex-basis:0}

/* ── feedback ── */
#uskFeedback{text-align:center;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;min-height:18px;margin-top:6px;color:var(--us-purple-dark)}
#uskFeedback.good{color:var(--us-green-dark)}
#uskFeedback.bad{color:var(--us-red-dark)}

/* ── score grid ── */
.usk-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px}
.usk-score-grid.visible{display:grid}
.usk-score-card{background:#FAFAFE;border:1px solid var(--us-lila);border-radius:14px;padding:12px;text-align:center}
.usk-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:26px;line-height:1}
.usk-score-num.c{color:var(--us-green)}.usk-score-num.w{color:var(--us-red)}.usk-score-num.p{color:var(--us-purple)}
.usk-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:var(--us-muted);text-transform:uppercase;letter-spacing:.08em}

/* ── controls ── */
.usk-controls{border-top:1px solid #F0EEF8;margin-top:16px;padding-top:16px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
.usk-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;border:none;border-radius:8px;color:#fff;cursor:pointer;min-width:clamp(100px,14vw,130px);font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:13px;line-height:1;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s}
.usk-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.usk-btn:active{transform:scale(.98)}
.usk-btn-hint{background:var(--us-purple)}
.usk-btn-clear{background:var(--us-purple)}
.usk-btn-next{background:var(--us-orange);box-shadow:0 6px 18px rgba(249,115,22,.22)}

/* ── completed screen ── */
.usk-completed-screen{display:none;text-align:center;padding:24px 12px;max-width:480px;margin:0 auto}
.usk-completed-screen.active{display:block}
.usk-completed-icon{font-size:28px;line-height:1;margin-bottom:6px}
.usk-completed-title{margin:0;color:var(--us-orange);font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:32px;font-weight:700}
.usk-completed-text{color:var(--us-muted);font-size:14px;font-weight:800;line-height:1.5;margin:4px 0 0}
.usk-score-text{color:#534AB7!important;font-family:'Nunito',sans-serif!important;font-size:14px!important;font-weight:900!important;margin:4px 0 12px}
.usk-completed-button{display:inline-flex;align-items:center;justify-content:center;padding:11px 20px;border-radius:8px;background:var(--us-purple);color:#fff;border:none;cursor:pointer;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:filter .12s,transform .12s;min-width:128px}
.usk-completed-button:hover{filter:brightness(1.07);transform:translateY(-1px)}

/* ── responsive ── */
@media(max-width:640px){
    .usk-page{padding:12px}
    .usk-stage{border-radius:26px;padding:14px}
    .usk-card-inner{border-radius:22px;padding:16px}
  2 .usk-slot,.usk-chip{width:44px;height:54px;font-size:22px}
    .usk-controls{display:grid;grid-template-columns:1fr;gap:9px}
    .usk-btn{width:100%}
    .usk-score-grid{grid-template-columns:1fr}
}
</style>

<div class="usk-page" id="uskPage">
<div class="usk-app" id="uskApp">

    <div class="usk-topbar">
        <span class="usk-topbar-title">Spelling</span>
    </div>

    <div class="usk-hero">
        <div class="usk-kicker">Activity</div>
        <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Look at the picture and spell the word!</p>
    </div>

    <div class="usk-progress">
        <span class="usk-prog-label" id="uskProgLabel">1 of <?php echo count($words); ?></span>
        <div class="usk-track"><div class="usk-fill" id="uskFill" style="width:0%"></div></div>
        <div class="usk-badge" id="uskBadge">Word 1</div>
    </div>

    <div class="usk-stage" id="uskStage">

        <div class="usk-card-inner" id="uskCardInner">
            <div class="usk-img-box" id="uskEmoji"></div>
            <div class="usk-word-hint" id="uskHint"></div>
            <button class="usk-btn-listen" id="uskListenBtn" type="button" onclick="uskSpeak()">🔊 Listen</button>
            <div class="usk-answer-row" id="uskAnswer"
                ondragover="event.preventDefault();document.getElementById('uskAnswer').classList.add('drag-over')"
                ondragleave="document.getElementById('uskAnswer').classList.remove('drag-over')"
                ondrop="uskDropOnAnswer(event)"></div>
        </div>

        <div class="usk-bank-label">Tap or drag the letters</div>
        <div class="usk-bank" id="uskBank"
            ondragover="event.preventDefault()"
            ondrop="uskDragging=null"></div>

        <div id="uskFeedback"></div>

        <div class="usk-score-grid" id="uskScoreGrid">
            <div class="usk-score-card"><div class="usk-score-num c" id="uskSC">0</div><div class="usk-score-lbl">Correct</div></div>
            <div class="usk-score-card"><div class="usk-score-num w" id="uskSW">0</div><div class="usk-score-lbl">Wrong</div></div>
            <div class="usk-score-card"><div class="usk-score-num p" id="uskSP">0%</div><div class="usk-score-lbl">Score</div></div>
        </div>

        <div id="uskCompleted" class="usk-completed-screen">
            <div class="usk-completed-icon">✅</div>
            <h2 class="usk-completed-title">Completed!</h2>
            <p class="usk-completed-text" id="uskCompText"></p>
            <p class="usk-score-text" id="uskScoreText"></p>
            <button type="button" class="usk-completed-button" onclick="uskRestart()">Try Again</button>
        </div>

        <div class="usk-controls" id="uskControls">
            <button class="usk-btn usk-btn-hint"  type="button" onclick="uskHint()">Hint</button>
            <button class="usk-btn usk-btn-clear" type="button" onclick="uskClear()">Clear</button>
            <button class="usk-btn usk-btn-next"  type="button" onclick="uskNext()">Next →</button>
        </div>

    </div><!-- /usk-stage -->

</div><!-- /usk-app -->
</div><!-- /usk-page -->

<audio id="uskWinAudio"  src="../../hangman/assets/win.mp3"      preload="auto"></audio>
<audio id="uskLoseAudio" src="../../hangman/assets/lose.mp3"     preload="auto"></audio>
<audio id="uskDoneAudio" src="../../hangman/assets/win (1).mp3"  preload="auto"></audio>
<audio id="uskTtsAudio"  preload="none"></audio>

<script>
/* ── data from PHP ── */
const USK_WORDS      = <?php echo json_encode($words, JSON_UNESCAPED_UNICODE); ?>;
const USK_TITLE      = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
const USK_ACTIVITY_ID= <?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
const USK_RETURN_TO  = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
const USK_VOICE_ID   = <?php echo json_encode($activityVoiceId, JSON_UNESCAPED_UNICODE); ?>;
const USK_TTS_URL    = 'tts.php';

/* ── state ── */
let uskIdx = 0;
let uskPlaced   = [];   // uskPlaced[slotIndex] = bankIndex | null
let uskOrder    = [];   // shuffled [{l, origIdx}]
let uskUsed     = [];   // uskUsed[bankIndex] = bool
let uskBlocked  = false;
let uskAttempts = 0;
let uskCorrect  = 0;
let uskWrong    = 0;
let uskScoreShown = false;
let uskDragging = null;
let uskCurrentAudio = null;
let uskCurrentAudioUrl = '';
let uskIsSpeaking = false;

/* ── utils ── */
function uskShuffle(a){ return a.slice().sort(()=>Math.random()-.5); }
function uskPlay(el){ try{ el.pause(); el.currentTime=0; el.play(); }catch(e){} }

/* ── progress bar ── */
function uskUpdateProgress(){
    const n = USK_WORDS.length;
    const pct = Math.round(uskIdx / n * 100);
    document.getElementById('uskFill').style.width = Math.max(pct, 4) + '%';
    document.getElementById('uskProgLabel').textContent = (uskIdx+1) + ' of ' + n;
    document.getElementById('uskBadge').textContent = 'Word ' + (uskIdx+1);
}

/* ── render word ── */
function uskRender(){
    uskBlocked = false; uskAttempts = 0; uskDragging = null;
    uskStopAudio();

    const w = USK_WORDS[uskIdx];

    document.getElementById('uskEmoji').textContent = w.emoji || '📞';
    document.getElementById('uskHint').textContent  = w.hint  || '';
    document.getElementById('uskFeedback').textContent = '';
    document.getElementById('uskFeedback').className = '';
    document.getElementById('uskCompleted').classList.remove('active');
    document.getElementById('uskCardInner').style.display = 'flex';
    document.getElementById('uskBank').style.display      = 'flex';
    document.getElementById('uskControls').style.display  = 'flex';
    uskUpdateProgress();

    const letters = w.word.split('');
    uskPlaced = new Array(letters.length).fill(null);
    uskOrder  = uskShuffle(letters.map((l, i) => ({ l, i })));
    uskUsed   = new Array(uskOrder.length).fill(false);

    /* answer slots */
    const ans = document.getElementById('uskAnswer');
    ans.innerHTML = '';
    letters.forEach((_, si) => {
        const s = document.createElement('div');
        s.className = 'usk-slot empty';
        s.dataset.slot = si;
        s.textContent = '·';
        s.onclick = () => uskSlotClick(si);
        ans.appendChild(s);
    });

    /* letter bank */
    const bank = document.getElementById('uskBank');
    bank.innerHTML = '';
    uskOrder.forEach((item, bi) => {
        const c = document.createElement('div');
        c.className  = 'usk-chip';
        c.textContent = item.l;
        c.dataset.bi  = bi;
        c.draggable   = true;
        c.ondragstart = e => {
            if (uskBlocked || uskUsed[bi]) { e.preventDefault(); return; }
            uskDragging = { bi };
            e.dataTransfer.effectAllowed = 'move';
        };
        c.onclick = () => uskChipClick(bi);
        bank.appendChild(c);
    });
}

function uskGetSlots(){ return document.querySelectorAll('#uskAnswer .usk-slot'); }
function uskGetChip(bi){ return document.querySelector(`[data-bi="${bi}"]`); }

/* ── place / remove ── */
function uskPlace(bi, si){
    if (uskUsed[bi]) return;
    const slots = uskGetSlots();
    const slot  = slots[si];
    const chip  = uskGetChip(bi);
    if (!slot || !chip) return;
    uskUsed[bi] = true;
    uskPlaced[si] = bi;
    slot.textContent = uskOrder[bi].l;
    slot.className   = 'usk-slot filled';
    slot.dataset.bi  = bi;
    chip.classList.add('used');
    setTimeout(uskAutoCheck, 60);
}

function uskRemove(si, bi){
    const slots = uskGetSlots();
    const slot  = slots[si];
    if (!slot) return;
    uskUsed[bi]   = false;
    uskPlaced[si] = null;
    slot.textContent = '·';
    slot.className   = 'usk-slot empty';
    slot.dataset.bi  = '';
    const chip = uskGetChip(bi);
    if (chip) chip.classList.remove('used');
}

/* ── click interactions ── */
function uskChipClick(bi){
    if (uskBlocked || uskUsed[bi]) return;
    const slots = [...uskGetSlots()];
    const si = slots.findIndex(s => s.classList.contains('empty'));
    if (si === -1) return;
    uskPlace(bi, si);
}

function uskSlotClick(si){
    if (uskBlocked) return;
    const slots = uskGetSlots();
    const slot  = slots[si];
    if (!slot || slot.classList.contains('empty') || slot.classList.contains('correct')) return;
    const bi = (slot.dataset.bi !== undefined && slot.dataset.bi !== '') ? +slot.dataset.bi : null;
    if (bi === null) return;
    uskRemove(si, bi);
    document.getElementById('uskFeedback').textContent = '';
    document.getElementById('uskFeedback').className = '';
}

/* ── drag & drop ── */
function uskDropOnAnswer(e){
    e.preventDefault();
    document.getElementById('uskAnswer').classList.remove('drag-over');
    if (!uskDragging || uskBlocked) return;
    const target = e.target.closest('.usk-slot');
    if (!target) return;
    const si = +target.dataset.slot;
    if (uskPlaced[si] !== null) return;
    uskPlace(uskDragging.bi, si);
    uskDragging = null;
}

/* ── auto-check when all slots filled ── */
function uskAutoCheck(){
    if (uskBlocked) return;
    if (uskPlaced.some(v => v === null)) return;
    uskAttempts++;

    const word  = USK_WORDS[uskIdx].word;
    const built = uskPlaced.map(bi => uskOrder[bi].l).join('');
    const slots = uskGetSlots();
    const fb    = document.getElementById('uskFeedback');

    if (built === word) {
        uskBlocked = true;
        uskCorrect++;
        slots.forEach(s => s.className = 'usk-slot correct');
        fb.textContent = '✔ Correct!';
        fb.className   = 'good';
        uskPlay(document.getElementById('uskWinAudio'));
        uskShowScore();
    } else {
        uskWrong++;
        slots.forEach((s, i) => {
            const bi = (s.dataset.bi !== undefined && s.dataset.bi !== '') ? +s.dataset.bi : null;
            const l  = bi !== null ? uskOrder[bi].l : '';
            s.className = 'usk-slot ' + (l === word[i] ? 'correct' : 'wrong');
        });
        fb.textContent = '✘ Try again!';
        fb.className   = 'bad';
        uskPlay(document.getElementById('uskLoseAudio'));
        uskShowScore();

        /* remove only wrong slots after short delay */
        setTimeout(() => {
            if (uskBlocked) return;
            const slots2 = uskGetSlots();
            slots2.forEach((s, i) => {
                if (s.classList.contains('wrong')) {
                    const bi2 = (s.dataset.bi !== undefined && s.dataset.bi !== '') ? +s.dataset.bi : null;
                    if (bi2 !== null) uskRemove(i, bi2);
                }
            });
            fb.textContent = '';
            fb.className   = '';
        }, 900);
    }
}

/* ── score grid ── */
function uskShowScore(){
    if (!uskScoreShown) uskScoreShown = true;
    const tot = uskCorrect + uskWrong;
    const pct = tot > 0 ? Math.round(uskCorrect / tot * 100) : 0;
    document.getElementById('uskSC').textContent = uskCorrect;
    document.getElementById('uskSW').textContent = uskWrong;
    document.getElementById('uskSP').textContent = pct + '%';
    document.getElementById('uskScoreGrid').classList.add('visible');
}

/* ── hint: place first missing correct letter ── */
function uskHint(){
    if (uskBlocked) return;
    const word = USK_WORDS[uskIdx].word;
    for (let si = 0; si < uskPlaced.length; si++) {
        if (uskPlaced[si] === null) {
            for (let bi = 0; bi < uskOrder.length; bi++) {
                if (!uskUsed[bi] && uskOrder[bi].l === word[si]) {
                    uskPlace(bi, si);
                    return;
                }
            }
            return;
        }
    }
}

/* ── clear wrong / unfilled slots ── */
function uskClear(){
    if (uskBlocked) return;
    const slots = uskGetSlots();
    [...slots].forEach((s, i) => {
        if (!s.classList.contains('empty') && !s.classList.contains('correct')) {
            const bi = (s.dataset.bi !== undefined && s.dataset.bi !== '') ? +s.dataset.bi : null;
            if (bi !== null) uskRemove(i, bi);
        }
    });
    document.getElementById('uskFeedback').textContent = '';
    document.getElementById('uskFeedback').className   = '';
}

/* ── next word / finish ── */
function uskNext(){
    if (uskIdx >= USK_WORDS.length - 1) {
        uskShowCompleted(); return;
    }
    uskIdx++;
    uskRender();
}

async function uskShowCompleted(){
    uskBlocked = true;
    uskStopAudio();
    document.getElementById('uskCardInner').style.display = 'none';
    document.getElementById('uskBank').style.display      = 'none';
    document.getElementById('uskControls').style.display  = 'none';
    document.getElementById('uskFeedback').textContent    = '';

    uskPlay(document.getElementById('uskDoneAudio'));

    const tot  = uskCorrect + uskWrong;
    const pct  = tot > 0 ? Math.round(uskCorrect / tot * 100) : 0;
    document.getElementById('uskCompText').textContent  = "You've completed " + USK_TITLE + ". Great job!";
    document.getElementById('uskScoreText').textContent = uskCorrect + ' correct · ' + uskWrong + ' wrong · ' + pct + '%';
    document.getElementById('uskCompleted').classList.add('active');

    document.getElementById('uskFill').style.width = '100%';
    document.getElementById('uskProgLabel').textContent = USK_WORDS.length + ' of ' + USK_WORDS.length;

    /* persist score */
    if (USK_ACTIVITY_ID && USK_RETURN_TO) {
        const joiner = USK_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        const saveUrl = USK_RETURN_TO + joiner
            + 'activity_percent=' + pct
            + '&activity_errors='  + uskWrong
            + '&activity_total='   + USK_WORDS.length
            + '&activity_id='      + encodeURIComponent(USK_ACTIVITY_ID)
            + '&activity_type=unscramble_kids';
        try {
            const ok = await fetch(saveUrl, { method:'GET', credentials:'same-origin', cache:'no-store' }).then(r => r.ok);
            if (!ok) uskNavigate(saveUrl);
        } catch(e) { uskNavigate(saveUrl); }
    }
}

function uskNavigate(url){
    try { if (window.top && window.top !== window.self) { window.top.location.href = url; return; } } catch(e){}
    window.location.href = url;
}

function uskRestart(){
    uskIdx = 0; uskCorrect = 0; uskWrong = 0; uskScoreShown = false;
    document.getElementById('uskScoreGrid').classList.remove('visible');
    uskRender();
}

/* ── TTS / listen ── */
function uskStopAudio(){
    if (uskCurrentAudio) { uskCurrentAudio.pause(); uskCurrentAudio.currentTime = 0; uskCurrentAudio = null; }
    if (uskCurrentAudioUrl) { try { URL.revokeObjectURL(uskCurrentAudioUrl); } catch(e){} uskCurrentAudioUrl = ''; }
    uskIsSpeaking = false;
}

function uskSpeak(){
    const w = USK_WORDS[uskIdx];
    const text = w.word.toLowerCase();

    /* try Cloudinary pre-generated audio first */
    if (w.audio) {
        uskStopAudio();
        const a = document.getElementById('uskTtsAudio');
        a.src = w.audio;
        a.play().catch(() => uskBrowserTTS(text));
        return;
    }

    /* ElevenLabs via tts.php */
    const voiceId = w.voice_id || USK_VOICE_ID;
    const btn = document.getElementById('uskListenBtn');
    btn.textContent = '…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('text', text);
    fd.append('voice_id', voiceId);

    fetch(USK_TTS_URL, { method:'POST', body:fd, credentials:'same-origin' })
        .then(r => { if (!r.ok) throw new Error('TTS ' + r.status); return r.blob(); })
        .then(blob => {
            uskCurrentAudioUrl = URL.createObjectURL(blob);
            uskCurrentAudio = document.getElementById('uskTtsAudio');
            uskCurrentAudio.src = uskCurrentAudioUrl;
            uskCurrentAudio.onended = () => uskStopAudio();
            uskCurrentAudio.play();
        })
        .catch(() => uskBrowserTTS(text))
        .finally(() => { btn.textContent = '🔊 Listen'; btn.disabled = false; });
}

function uskBrowserTTS(text){
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.rate = 0.75; u.pitch = 1.05; u.lang = 'en-US';
    const voices = window.speechSynthesis.getVoices();
    const child  = voices.find(v => /child|kid|girl|boy|candy/i.test(v.name));
    if (child) u.voice = child;
    window.speechSynthesis.speak(u);
}

/* ── init ── */
uskRender();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

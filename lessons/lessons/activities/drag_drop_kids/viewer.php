<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

function ddk_default_title(): string { return 'Drag & Drop'; }

function ddk_normalize_payload($raw): array
{
    $default = [
        'title'            => ddk_default_title(),
        'instructions'     => '',
        'background_image' => '',
        'pairs'            => [],
    ];

    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $pairs = [];
    foreach ($d['pairs'] ?? [] as $p) {
        if (!is_array($p)) continue;
        $id    = (int)($p['id'] ?? 0);
        $label = trim((string)($p['label'] ?? ''));
        if ($id <= 0 || $label === '') continue;
        $pairs[] = [
            'id'    => $id,
            'label' => $label,
            'x'     => (float)($p['x'] ?? 10),
            'y'     => (float)($p['y'] ?? 10),
            'w'     => (float)($p['w'] ?? 12),
            'h'     => (float)($p['h'] ?? 8),
        ];
    }

    return [
        'title'            => trim((string)($d['title'] ?? '')) ?: ddk_default_title(),
        'instructions'     => trim((string)($d['instructions'] ?? '')),
        'background_image' => trim((string)($d['background_image'] ?? '')),
        'pairs'            => $pairs,
    ];
}

function ddk_resolve_unit(PDO $pdo, string $activityId): string
{
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row['unit_id'] ?? '') : '';
}

function ddk_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id'               => '',
        'title'            => ddk_default_title(),
        'instructions'     => '',
        'background_image' => '',
        'pairs'            => [],
    ];

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'drag_drop_kids' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'drag_drop_kids' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $payload = ddk_normalize_payload($row['data'] ?? null);
    return array_merge($payload, ['id' => (string)($row['id'] ?? '')]);
}

if ($unit === '' && $activityId !== '') {
    $unit = ddk_resolve_unit($pdo, $activityId);
}

$activity     = ddk_load($pdo, $activityId, $unit);
$title        = $activity['title'];
$pairs        = $activity['pairs'];
$bgImage      = $activity['background_image'];
$instructions = $activity['instructions'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

if (empty($pairs) || $bgImage === '') {
    die('Activity not configured yet.');
}

ob_start();
?>
<style>
/* ── Title header: 25% smaller, centred with stage ─ */
.act-header {
    max-width: 900px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    margin-bottom: 10px !important;
    padding: 12px 18px !important;
    border-radius: 16px !important;
}
.act-header h2 { font-size: clamp(18px, 2.6vw, 26px) !important; margin: 0 0 4px !important; }
.act-header p  { font-size: 13px !important; }

/* ── drag drop kids (ddk) ───────────────────────── */
.ddk-stage {
    max-width: 900px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
}

/* Canvas: shrinks to image size so %-zones align perfectly */
.ddk-canvas-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    margin-bottom: 10px;
    line-height: 0;
}
.ddk-canvas {
    position: relative;
    display: inline-block;
    max-width: 100%;
}
.ddk-bg {
    display: block;
    max-width: 100%;
    /* Constrain height so it fits in the viewport without scrolling */
    max-height: calc(100vh - 230px);
    width: auto;
    height: auto;
    border-radius: 16px;
    user-select: none;
    pointer-events: none;
    box-shadow: 0 10px 28px rgba(15,23,42,.13);
}

/* Drop zones – teal, solid, equal padding, centred text */
.ddk-zone {
    position: absolute;
    border: 2.5px solid #14b8a6;
    border-radius: 10px;
    background: rgba(204,251,241,.6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(10px, 1.2vw, 16px);
    font-weight: 700;
    color: #134e4a;
    cursor: pointer;
    transition: background .18s, border-color .18s, transform .15s;
    box-sizing: border-box;
    overflow: hidden;
    text-align: center;
    padding: 4px;
    line-height: 1.2;
}
.ddk-zone.drag-over {
    background: rgba(153,246,228,.75);
    border-color: #0d9488;
    transform: scale(1.06);
}
.ddk-zone.filled {
    border-color: #16a34a;
    background: rgba(220,252,231,.9);
    color: #14532d;
    cursor: default;
}
.ddk-zone.wrong {
    border-color: #dc2626;
    background: rgba(254,226,226,.8);
    animation: ddk-shake .4s ease;
}
@keyframes ddk-shake {
    0%, 100% { transform: translateX(0); }
    20%       { transform: translateX(-6px); }
    60%       { transform: translateX(6px); }
    80%       { transform: translateX(-3px); }
}

/* Word bank */
.ddk-bank {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    margin: 8px 0;
    min-height: 40px;
}
.ddk-chip {
    padding: 8px 16px;
    border-radius: 999px;
    color: #4c1d95;
    font-weight: 800;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(12px, 1.4vw, 15px);
    cursor: grab;
    background: #ede9fe;
    border: 2px solid #7c3aed;
    box-shadow: 0 4px 12px rgba(124,58,237,.18);
    user-select: none;
    touch-action: manipulation;
    transition: filter .15s, transform .15s;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}
.ddk-chip:hover { filter: brightness(1.06); transform: scale(1.04); }
.ddk-chip.dragging { opacity: .4; cursor: grabbing; }
.ddk-chip.selected-touch {
    outline: 3px solid #7c3aed;
    outline-offset: 2px;
    filter: brightness(1.05);
}

.ddk-touch-hint {
    text-align: center;
    color: #7c2d12;
    font-size: 12px;
    font-weight: 700;
    margin: 0 0 4px;
}
.ddk-touch-hint.hidden { display: none; }

/* Buttons */
.ddk-controls { text-align: center; margin: 6px 0 4px; }
.ddk-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 18px;
    border: none;
    border-radius: 999px;
    color: #fff;
    cursor: pointer;
    min-width: 130px;
    font-weight: 800;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 13px;
    box-shadow: 0 8px 18px rgba(15,23,42,.12);
    transition: transform .15s, filter .15s;
    line-height: 1;
}
.ddk-btn:hover { filter: brightness(1.05); transform: translateY(-1px); }
.ddk-btn-show { background: linear-gradient(180deg, #d8b4fe 0%, #a855f7 100%); }

/* Feedback */
#ddkFeedback {
    text-align: center;
    font-size: 16px;
    font-weight: 800;
    min-height: 24px;
    margin: 4px 0;
}
.good { color: #15803d; }
.bad  { color: #dc2626; }

/* Completion */
.ddk-completed { display: none; text-align: center; padding: 28px 20px; }
.ddk-completed.active { display: block; }
.ddk-completed-icon  { font-size: 60px; margin-bottom: 10px; }
.ddk-completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 30px;
    font-weight: 700;
    color: #9a3412;
    margin: 0 0 8px;
}
.ddk-completed-text  { font-size: 14px; color: #6b4f3a; line-height: 1.5; margin: 0 0 4px; }
.ddk-completed-score { font-size: 16px; font-weight: 800; color: #9a3412; margin: 0 0 20px; }
.ddk-completed-btn {
    display: inline-block;
    padding: 10px 24px;
    border: none;
    border-radius: 999px;
    background: linear-gradient(180deg, #fb923c 0%, #f97316 100%);
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(0,0,0,.13);
    transition: transform .18s, filter .18s;
}
.ddk-completed-btn:hover { transform: scale(1.05); filter: brightness(1.07); }

@media (max-width: 640px) {
    .ddk-bg { max-height: calc(100vh - 200px); }
    .ddk-chip { padding: 7px 12px; }
    .ddk-bank { gap: 6px; }
    .ddk-controls { display: flex; flex-direction: column; align-items: center; }
    .ddk-btn { width: 100%; max-width: 280px; }
}

/* Presentation / fullscreen mode */
body.presentation-mode .ddk-bg,
body.fullscreen-embedded .ddk-bg {
    max-height: calc(100vh - 160px);
}
body.presentation-mode .act-header,
body.fullscreen-embedded .act-header {
    padding: 8px 14px !important;
    margin-bottom: 6px !important;
}
body.presentation-mode .act-header h2,
body.fullscreen-embedded .act-header h2 {
    font-size: clamp(16px, 2vw, 22px) !important;
}
</style>

<?= render_activity_header(
    $title,
    $instructions !== '' ? $instructions : 'Drag each word to the correct place on the image.'
) ?>

<div class="ddk-stage" id="ddkStage">

    <div class="ddk-canvas-wrap">
        <div class="ddk-canvas" id="ddkCanvas">
            <img id="ddkBg" class="ddk-bg"
                 src="<?= htmlspecialchars($bgImage, ENT_QUOTES, 'UTF-8') ?>"
                 alt="Activity image">
            <?php foreach ($pairs as $p): ?>
            <div class="ddk-zone"
                 id="zone-<?= (int)$p['id'] ?>"
                 data-id="<?= (int)$p['id'] ?>"
                 style="left:<?= round((float)$p['x'], 4) ?>%;top:<?= round((float)$p['y'], 4) ?>%;width:<?= round((float)$p['w'], 4) ?>%;height:<?= round((float)$p['h'], 4) ?>%"
            ></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="ddkTouchHint" class="ddk-touch-hint hidden">
        Tap a word, then tap a zone on the image.
    </div>
    <div class="ddk-bank" id="ddkBank"></div>

    <div class="ddk-controls" id="ddkControls">
        <button class="ddk-btn ddk-btn-show" type="button" onclick="showAnswers()">Show Answer</button>
    </div>

    <div id="ddkFeedback"></div>

    <div id="ddkCompleted" class="ddk-completed">
        <div class="ddk-completed-icon">🎉</div>
        <h2 class="ddk-completed-title">Great job!</h2>
        <p class="ddk-completed-text">
            You completed <strong><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></strong>.
        </p>
        <p class="ddk-completed-score" id="ddkScore"></p>
        <button class="ddk-completed-btn" type="button" onclick="restartActivity()">Play Again</button>
    </div>
</div>

<audio id="winSnd"  src="../../hangman/assets/win.mp3"       preload="auto"></audio>
<audio id="loseSnd" src="../../hangman/assets/lose.mp3"      preload="auto"></audio>
<audio id="doneSnd" src="../../hangman/assets/win (1).mp3"   preload="auto"></audio>

<script>
const DDK_PAIRS       = <?= json_encode($pairs, JSON_UNESCAPED_UNICODE) ?>;
const DDK_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
const DDK_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const DDK_TITLE       = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;

const winSnd      = document.getElementById('winSnd');
const loseSnd     = document.getElementById('loseSnd');
const doneSnd     = document.getElementById('doneSnd');
const bank        = document.getElementById('ddkBank');
const feedbackEl  = document.getElementById('ddkFeedback');
const completedEl = document.getElementById('ddkCompleted');
const scoreEl     = document.getElementById('ddkScore');
const touchHint   = document.getElementById('ddkTouchHint');
const controls    = document.getElementById('ddkControls');

const isTouchLike = (window.matchMedia && window.matchMedia('(pointer:coarse)').matches)
    || ('ontouchstart' in window)
    || navigator.maxTouchPoints > 0;

let dragged       = null;
let selectedChip  = null;
let correctCount  = 0;
let done          = false;

function playSound(a) {
    try { a.pause(); a.currentTime = 0; a.play(); } catch (e) {}
}

function shuffle(arr) {
    return arr.slice().sort(() => Math.random() - 0.5);
}

function setFeedback(msg, cls) {
    feedbackEl.textContent = msg;
    feedbackEl.className   = cls || '';
}

/* ── Word bank ─────────────────────────────────── */
function buildBank() {
    bank.innerHTML = '';
    shuffle(DDK_PAIRS).forEach(function (p) {
        const chip = document.createElement('span');
        chip.className  = 'ddk-chip';
        chip.textContent = p.label;
        chip.draggable  = true;
        chip.dataset.id = p.id;

        chip.addEventListener('dragstart', function () {
            dragged = chip;
            chip.classList.add('dragging');
        });
        chip.addEventListener('dragend', function () {
            chip.classList.remove('dragging');
            dragged = null;
        });
        chip.addEventListener('click', function () {
            if (!isTouchLike || done) return;
            toggleChip(chip);
        });

        bank.appendChild(chip);
    });
}

function toggleChip(chip) {
    if (selectedChip === chip) { clearChip(); return; }
    if (selectedChip) selectedChip.classList.remove('selected-touch');
    selectedChip = chip;
    chip.classList.add('selected-touch');
}

function clearChip() {
    if (selectedChip) selectedChip.classList.remove('selected-touch');
    selectedChip = null;
}

/* ── Zones ─────────────────────────────────────── */
function setupZones() {
    document.querySelectorAll('.ddk-zone').forEach(function (zone) {
        zone.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!zone.classList.contains('filled')) {
                zone.classList.add('drag-over');
            }
        });
        zone.addEventListener('dragleave', function () {
            zone.classList.remove('drag-over');
        });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('drag-over');
            if (!dragged || done) return;
            handleDrop(zone, dragged.dataset.id, dragged);
        });
        zone.addEventListener('click', function () {
            if (!isTouchLike || done) return;
            if (!selectedChip) return;
            handleDrop(zone, selectedChip.dataset.id, selectedChip);
            clearChip();
        });
    });
}

function handleDrop(zone, chipId, chipEl) {
    if (zone.classList.contains('filled')) return;
    const zoneId = zone.dataset.id;

    if (String(chipId) === String(zoneId)) {
        zone.classList.add('filled');
        zone.classList.remove('wrong');
        zone.textContent = chipEl.textContent;
        chipEl.remove();
        correctCount++;
        playSound(winSnd);
        setFeedback('✔ Correct!', 'good');
        checkAllDone();
    } else {
        zone.classList.add('wrong');
        playSound(loseSnd);
        setFeedback('✘ Try a different one.', 'bad');
        setTimeout(function () { zone.classList.remove('wrong'); }, 500);
    }
}

function checkAllDone() {
    const allFilled = Array.from(document.querySelectorAll('.ddk-zone'))
        .every(function (z) { return z.classList.contains('filled'); });
    if (allFilled) setTimeout(showCompleted, 700);
}

/* ── Completion ────────────────────────────────── */
async function showCompleted() {
    done = true;
    playSound(doneSnd);
    if (controls) controls.style.display = 'none';
    setFeedback('', '');

    const total = DDK_PAIRS.length;
    const pct   = total > 0 ? Math.round((correctCount / total) * 100) : 0;
    const errors = Math.max(0, total - correctCount);
    if (scoreEl) scoreEl.textContent = 'Score: ' + correctCount + ' / ' + total + ' (' + pct + '%)';
    if (completedEl) completedEl.classList.add('active');

    if (DDK_ACTIVITY_ID && DDK_RETURN_TO) {
        const joiner  = DDK_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        const saveUrl = DDK_RETURN_TO + joiner
            + 'activity_percent=' + pct
            + '&activity_errors=' + errors
            + '&activity_total='  + total
            + '&activity_id='     + encodeURIComponent(DDK_ACTIVITY_ID)
            + '&activity_type=drag_drop_kids';
        try {
            const res = await fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) throw new Error();
        } catch (e) {
            try {
                if (window.top && window.top !== window.self) window.top.location.href = saveUrl;
                else window.location.href = saveUrl;
            } catch (ee) { window.location.href = saveUrl; }
        }
    }
}

/* ── Show answers ──────────────────────────────── */
function showAnswers() {
    if (done) return;
    document.querySelectorAll('.ddk-zone').forEach(function (zone) {
        if (zone.classList.contains('filled')) return;
        const id   = zone.dataset.id;
        const pair = DDK_PAIRS.find(function (p) { return String(p.id) === String(id); });
        if (pair) {
            zone.classList.add('filled');
            zone.textContent = pair.label;
        }
    });
    bank.innerHTML = '';
    setFeedback('Answers shown', 'good');
    correctCount = DDK_PAIRS.length;
    setTimeout(showCompleted, 700);
}

/* ── Restart ───────────────────────────────────── */
function restartActivity() {
    done         = false;
    correctCount = 0;
    clearChip();
    if (completedEl) completedEl.classList.remove('active');
    if (controls)    controls.style.display = '';
    setFeedback('', '');
    document.querySelectorAll('.ddk-zone').forEach(function (z) {
        z.classList.remove('filled', 'wrong');
        z.textContent = '';
    });
    buildBank();
}

/* ── Boot ──────────────────────────────────────── */
if (isTouchLike && touchHint) touchHint.classList.remove('hidden');
buildBank();
setupZones();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🖼️', $content);

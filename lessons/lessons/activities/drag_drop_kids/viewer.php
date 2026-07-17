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
/* White background — override all containers including presentation mode */
body,
.activity-wrapper,
.viewer-content,
body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content { background: #fff !important; }

/* ── Title header: no box, matches Tracing/other activity style ─ */
.act-header {
    max-width: 960px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    margin-bottom: 10px !important;
    padding: 8px 18px !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    text-align: center !important;
}
.act-header h2 { font-size: clamp(18px, 2.8vw, 34px) !important; margin: 0 0 4px !important; color: #F97316 !important; text-align: center !important; }
.act-header p  { font-size: clamp(12px, 1.2vw, 15px) !important; color: #9B94BE !important; text-align: center !important; }

/* ── drag drop kids (ddk) ───────────────────────── */
.ddk-stage {
    max-width: 960px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
}

/* Canvas wrap: zoom-aware overflow container */
.ddk-canvas-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    margin-bottom: 10px;
    line-height: 0;
    overflow: hidden;
    border-radius: 16px;
    touch-action: none; /* allow pointer events for pinch/drag */
}
/* Inner canvas: transform origin top-center for zoom */
.ddk-canvas {
    position: relative;
    display: inline-block;
    max-width: 100%;
    transform-origin: top center;
    transition: transform .15s ease;
}
.ddk-bg {
    display: block;
    max-width: 100%;
    /* Constrain height so it fits in the viewport without scrolling */
    max-height: calc(100vh - 250px);
    width: auto;
    height: auto;
    border-radius: 16px;
    user-select: none;
    pointer-events: none;
    box-shadow: 0 10px 28px rgba(15,23,42,.13);
}

/* Drop zones – lilac/purple */
.ddk-zone {
    position: absolute;
    border: 2.5px solid #7F77DD;
    border-radius: 6px;
    background: rgba(127,119,221,.30);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(10px, 1.4vw, 18px);
    font-weight: 700;
    color: #4c1d95;
    cursor: pointer;
    transition: background .18s, border-color .18s, transform .15s;
    box-sizing: border-box;
    overflow: hidden;
    text-align: center;
    padding: 4px;
    line-height: 1.2;
    touch-action: none;
}
.ddk-zone.drag-over {
    background: rgba(127,119,221,.55);
    border-color: #5b52d1;
    transform: scale(1.06);
}
.ddk-zone.filled {
    border-color: #7c3aed !important;
    background: #fff !important;
    color: #4c1d95 !important;
    box-shadow: none !important;
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
    gap: 10px;
    margin: 8px 0;
    min-height: 48px;
}
.ddk-chip {
    padding: 10px 20px;
    border-radius: 8px;
    color: #4c1d95 !important;
    font-weight: 800;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(16px, 2vw, 22px);
    cursor: grab;
    background: #fff !important;
    border: 2.5px solid #7c3aed;
    box-shadow: 0 2px 8px rgba(127,119,221,.15) !important;
    user-select: none;
    touch-action: none;   /* must be none so pointermove fires during drag */
    transition: filter .12s, box-shadow .12s;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    will-change: transform;
}
.ddk-chip:hover { filter: brightness(1.06); box-shadow: 0 4px 14px rgba(127,119,221,.28) !important; }
.ddk-chip.dragging { opacity: .35; cursor: grabbing; }
.ddk-chip.selected-touch {
    outline: 3px solid #F97316;
    outline-offset: 3px;
    background: #FFF0E6 !important;
    border-color: #F97316;
}

/* Floating drag clone (appended to body during drag) */
.ddk-drag-clone {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    opacity: .92;
    transform: scale(1.12) rotate(-2deg);
    box-shadow: 0 10px 28px rgba(127,119,221,.45) !important;
    transition: none !important;
}

/* Touch / zoom hint bar */
.ddk-hint-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0 0 6px;
    flex-wrap: wrap;
}
.ddk-touch-hint {
    color: #5b516f;
    font-size: 12px;
    font-weight: 700;
}
.ddk-touch-hint.hidden { display: none; }

/* Zoom controls */
.ddk-zoom-bar {
    display: flex;
    align-items: center;
    gap: 4px;
}
.ddk-zoom-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid #7F77DD;
    background: #fff;
    color: #7F77DD;
    font-size: 18px;
    font-weight: 900;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    transition: background .12s, color .12s;
    padding: 0;
}
.ddk-zoom-btn:hover { background: #7F77DD; color: #fff; }
.ddk-zoom-label {
    font-size: 11px;
    font-weight: 700;
    color: #9B94BE;
    min-width: 32px;
    text-align: center;
    font-family: 'Nunito', sans-serif;
}

/* Buttons */
.ddk-controls { text-align: center; margin: 8px 0 4px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.ddk-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 22px;
    border: none;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    min-width: 140px;
    font-weight: 800;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: clamp(13px, 1.4vw, 16px);
    box-shadow: 0 6px 18px rgba(127,119,221,.28);
    transition: transform .15s, filter .15s;
    line-height: 1;
}
.ddk-btn:hover { filter: brightness(1.07); transform: translateY(-2px); }
.ddk-btn-show { background: #7F77DD; }

/* Feedback */
#ddkFeedback {
    text-align: center;
    font-size: clamp(14px, 1.5vw, 18px);
    font-weight: 800;
    min-height: 26px;
    margin: 4px 0;
}
.good { color: #15803d; }
.bad  { color: #dc2626; }

/* Completion */
.ddk-completed { display: none; padding: 8px 0; }
.ddk-completed.active { display: block; }

/* ── Small phones ───────────────────────────────── */
@media (max-width: 480px) {
    .ddk-bg { max-height: calc(100vh - 220px); }
    .ddk-chip { padding: 9px 14px; font-size: 15px; }
    .ddk-bank { gap: 7px; }
    .ddk-btn { min-width: 120px; }
}

/* ── Large screens / TV (≥1280 px) ─────────────── */
@media (min-width: 1280px) {
    .ddk-stage { max-width: 1100px; }
    .act-header { max-width: 1100px !important; }
    .ddk-chip  { font-size: clamp(20px, 2.2vw, 30px) !important; padding: 12px 26px !important; }
    .ddk-zone  { font-size: clamp(14px, 1.8vw, 24px) !important; }
    .ddk-btn   { font-size: clamp(15px, 1.5vw, 18px) !important; padding: 13px 28px !important; min-width: 160px !important; }
    #ddkFeedback { font-size: clamp(16px, 1.6vw, 20px); }
    .ddk-zoom-btn { width: 38px; height: 38px; font-size: 20px; }
}

/* ── Presentation / fullscreen ──────────────────── */
body.presentation-mode .activity-wrapper,
body.fullscreen-embedded .activity-wrapper {
    padding: 8mm !important;
    box-sizing: border-box !important;
}
body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content {
    border-radius: 14px !important;
    overflow: hidden !important;
}
body.presentation-mode .ddk-bg,
body.fullscreen-embedded .ddk-bg {
    max-height: calc(100vh - 16mm - 200px);
}
body.presentation-mode .act-header,
body.fullscreen-embedded .act-header {
    padding: 6px 14px !important;
    margin-bottom: 6px !important;
}
body.presentation-mode .act-header h2,
body.fullscreen-embedded .act-header h2 {
    font-size: clamp(20px, 2.4vw, 32px) !important;
}
body.presentation-mode .act-header p,
body.fullscreen-embedded .act-header p {
    font-size: clamp(13px, 1.3vw, 17px) !important;
}
body.presentation-mode .ddk-chip,
body.fullscreen-embedded .ddk-chip {
    font-size: clamp(20px, 2.4vw, 32px) !important;
    padding: 13px 26px !important;
    border-width: 3px !important;
}
body.presentation-mode .ddk-zone,
body.fullscreen-embedded .ddk-zone {
    font-size: clamp(13px, 1.8vw, 26px) !important;
    border-width: 3px !important;
}
body.presentation-mode .ddk-btn,
body.fullscreen-embedded .ddk-btn {
    font-size: clamp(15px, 1.6vw, 20px) !important;
    padding: 14px 30px !important;
    min-width: 170px !important;
}
body.presentation-mode #ddkFeedback,
body.fullscreen-embedded #ddkFeedback {
    font-size: clamp(16px, 1.8vw, 22px) !important;
}
body.presentation-mode .ddk-bank,
body.fullscreen-embedded .ddk-bank {
    gap: 12px !important;
}
body.presentation-mode .ddk-zoom-btn,
body.fullscreen-embedded .ddk-zoom-btn {
    width: 44px !important; height: 44px !important; font-size: 22px !important;
}
</style>

<?= render_activity_header(
    $title,
    $instructions !== '' ? $instructions : 'Drag each word to the correct place on the image.'
) ?>

<div class="ddk-stage" id="ddkStage">

    <div class="ddk-canvas-wrap" id="ddkCanvasWrap">
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

    <div class="ddk-hint-bar">
        <span id="ddkTouchHint" class="ddk-touch-hint hidden">
            👆 Drag words onto the image — or tap a word, then tap a spot.
        </span>
        <div class="ddk-zoom-bar" id="ddkZoomBar" title="Zoom image">
            <button class="ddk-zoom-btn" type="button" id="ddkZoomOut" aria-label="Zoom out">−</button>
            <span class="ddk-zoom-label" id="ddkZoomLabel">100%</span>
            <button class="ddk-zoom-btn" type="button" id="ddkZoomIn" aria-label="Zoom in">+</button>
        </div>
    </div>

    <div class="ddk-bank" id="ddkBank"></div>

    <div class="ddk-controls" id="ddkControls">
        <button class="ddk-btn ddk-btn-show" type="button" onclick="showAnswers()">Show Answer</button>
    </div>

    <div id="ddkFeedback"></div>

    <div id="ddkCompleted" class="ddk-completed"></div>
</div>

<audio id="winSnd"  src="../../hangman/assets/win.mp3"       preload="auto"></audio>
<audio id="loseSnd" src="../../hangman/assets/lose.mp3"      preload="auto"></audio>
<audio id="doneSnd" src="../../hangman/assets/win (1).mp3"   preload="auto"></audio>

<script src="../../core/_activity_feedback.js"></script>
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
const touchHint   = document.getElementById('ddkTouchHint');
const controls    = document.getElementById('ddkControls');
const canvasEl    = document.getElementById('ddkCanvas');
const canvasWrap  = document.getElementById('ddkCanvasWrap');

const isTouchLike = (window.matchMedia && window.matchMedia('(pointer:coarse)').matches)
    || ('ontouchstart' in window)
    || navigator.maxTouchPoints > 0;

let selectedChip  = null;
let correctCount  = 0;
let done          = false;

function playSound(a) {
    try { a.pause(); a.currentTime = 0; a.play(); } catch (e) {}
}
function shuffle(arr) { return arr.slice().sort(() => Math.random() - 0.5); }
function setFeedback(msg, cls) {
    feedbackEl.textContent = msg;
    feedbackEl.className   = cls || '';
}

/* ── Zoom ──────────────────────────────────────────────── */
let zoomScale = 1;
const ZOOM_STEP = 0.2, ZOOM_MIN = 0.6, ZOOM_MAX = 3.0;

function applyZoom() {
    canvasEl.style.transform = zoomScale === 1 ? '' : `scale(${zoomScale})`;
    // Expand the wrap height to prevent clipping
    if (zoomScale > 1) {
        const img = document.getElementById('ddkBg');
        canvasWrap.style.minHeight = (img.offsetHeight * zoomScale) + 'px';
    } else {
        canvasWrap.style.minHeight = '';
    }
    document.getElementById('ddkZoomLabel').textContent = Math.round(zoomScale * 100) + '%';
}
document.getElementById('ddkZoomIn').addEventListener('click', function() {
    zoomScale = Math.min(ZOOM_MAX, parseFloat((zoomScale + ZOOM_STEP).toFixed(2)));
    applyZoom();
});
document.getElementById('ddkZoomOut').addEventListener('click', function() {
    zoomScale = Math.max(ZOOM_MIN, parseFloat((zoomScale - ZOOM_STEP).toFixed(2)));
    applyZoom();
});

/* ── Pinch-to-zoom ─────────────────────────────────────── */
(function() {
    let pinchActive = false, pinchStartDist = 0, pinchStartScale = 1;
    function pinchDist(e) {
        const dx = e.touches[0].clientX - e.touches[1].clientX;
        const dy = e.touches[0].clientY - e.touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }
    canvasWrap.addEventListener('touchstart', function(e) {
        if (e.touches.length === 2) {
            pinchActive = true;
            pinchStartDist = pinchDist(e);
            pinchStartScale = zoomScale;
            e.preventDefault();
        }
    }, { passive: false });
    canvasWrap.addEventListener('touchmove', function(e) {
        if (!pinchActive || e.touches.length !== 2) return;
        const ratio = pinchDist(e) / pinchStartDist;
        zoomScale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, parseFloat((pinchStartScale * ratio).toFixed(2))));
        applyZoom();
        e.preventDefault();
    }, { passive: false });
    canvasWrap.addEventListener('touchend', function(e) {
        if (e.touches.length < 2) pinchActive = false;
    }, { passive: true });
    /* Double-tap to reset zoom */
    let lastTap = 0;
    canvasWrap.addEventListener('touchend', function(e) {
        if (e.touches.length > 0) return;
        const now = Date.now();
        if (now - lastTap < 300) {
            zoomScale = 1;
            applyZoom();
        }
        lastTap = now;
    }, { passive: true });
})();

/* ── Pointer-events drag ───────────────────────────────── */
// Works on both mouse and touch — chips visually follow the pointer/finger
let dragClone = null;
let dragChip  = null;

function addChipDrag(chip) {
    let startX = 0, startY = 0;
    let isDragging = false;
    let captureId = -1;

    chip.addEventListener('pointerdown', function(e) {
        if (done) return;
        if (e.button !== 0 && e.pointerType === 'mouse') return;
        startX = e.clientX;
        startY = e.clientY;
        isDragging = false;
        captureId = e.pointerId;
        chip.setPointerCapture(e.pointerId);
        // Don't call preventDefault yet — wait to see if it's a drag
    }, { passive: true });

    chip.addEventListener('pointermove', function(e) {
        if (e.pointerId !== captureId || done) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;

        if (!isDragging && Math.sqrt(dx * dx + dy * dy) > 8) {
            isDragging = true;
            dragChip = chip;
            chip.classList.add('dragging');
            // Clear any tap selection
            if (selectedChip) { selectedChip.classList.remove('selected-touch'); selectedChip = null; }

            // Build floating clone
            const rect = chip.getBoundingClientRect();
            dragClone = chip.cloneNode(true);
            dragClone.classList.remove('dragging', 'selected-touch');
            dragClone.classList.add('ddk-drag-clone');
            dragClone.style.width  = rect.width + 'px';
            dragClone.style.left   = (e.clientX - rect.width  / 2) + 'px';
            dragClone.style.top    = (e.clientY - rect.height / 2) + 'px';
            document.body.appendChild(dragClone);
        }

        if (isDragging && dragClone) {
            const w = dragClone.offsetWidth;
            const h = dragClone.offsetHeight;
            dragClone.style.left = (e.clientX - w / 2) + 'px';
            dragClone.style.top  = (e.clientY - h / 2) + 'px';

            // Highlight zone under pointer
            const below = getZoneAt(e.clientX, e.clientY);
            document.querySelectorAll('.ddk-zone').forEach(function(z) {
                if (z !== below) z.classList.remove('drag-over');
            });
            if (below && !below.classList.contains('filled')) below.classList.add('drag-over');
            e.preventDefault();
        }
    }, { passive: false });

    chip.addEventListener('pointerup', function(e) {
        if (e.pointerId !== captureId) return;
        captureId = -1;

        if (isDragging) {
            // Drag ended — drop onto zone
            if (dragClone) { dragClone.remove(); dragClone = null; }
            chip.classList.remove('dragging');
            document.querySelectorAll('.ddk-zone').forEach(function(z) { z.classList.remove('drag-over'); });

            const zone = getZoneAt(e.clientX, e.clientY);
            if (zone && dragChip) {
                handleDrop(zone, dragChip.dataset.id, dragChip);
            }
            dragChip  = null;
            isDragging = false;
        } else {
            // It was a tap — toggle chip selection
            isDragging = false;
            toggleChip(chip);
        }
    });

    chip.addEventListener('pointercancel', function(e) {
        if (e.pointerId !== captureId) return;
        captureId = -1;
        if (dragClone) { dragClone.remove(); dragClone = null; }
        chip.classList.remove('dragging');
        document.querySelectorAll('.ddk-zone').forEach(function(z) { z.classList.remove('drag-over'); });
        dragChip  = null;
        isDragging = false;
    });
}

/* Return the .ddk-zone element visually under (cx, cy), or null */
function getZoneAt(cx, cy) {
    // Temporarily hide clone so it doesn't intercept
    if (dragClone) dragClone.style.display = 'none';
    const el = document.elementFromPoint(cx, cy);
    if (dragClone) dragClone.style.display = '';
    if (!el) return null;
    if (el.classList.contains('ddk-zone')) return el;
    return el.closest ? el.closest('.ddk-zone') : null;
}

/* ── Tap-to-select / tap-to-place ─────────────────────── */
function toggleChip(chip) {
    if (done) return;
    if (selectedChip === chip) { clearChip(); return; }
    if (selectedChip) selectedChip.classList.remove('selected-touch');
    selectedChip = chip;
    chip.classList.add('selected-touch');
}
function clearChip() {
    if (selectedChip) selectedChip.classList.remove('selected-touch');
    selectedChip = null;
}

/* ── Word bank ─────────────────────────────────────────── */
function buildBank() {
    bank.innerHTML = '';
    shuffle(DDK_PAIRS).forEach(function(p) {
        const chip = document.createElement('span');
        chip.className   = 'ddk-chip';
        chip.textContent = p.label;
        chip.dataset.id  = p.id;
        addChipDrag(chip);   // pointer-events drag (mouse + touch)
        bank.appendChild(chip);
    });
}

/* ── Zones ─────────────────────────────────────────────── */
function setupZones() {
    document.querySelectorAll('.ddk-zone').forEach(function(zone) {
        // Tap-to-place (fires when no pointer drag is active)
        zone.addEventListener('pointerup', function(e) {
            if (done || !selectedChip) return;
            // Only act on tap (not end of a chip-drag, which is handled in chip pointerup)
            if (dragChip) return;
            handleDrop(zone, selectedChip.dataset.id, selectedChip);
            clearChip();
            e.stopPropagation();
        });
        // Visual hover on desktop (mouse pointermove)
        zone.addEventListener('pointerenter', function() {
            if (dragChip && !zone.classList.contains('filled')) zone.classList.add('drag-over');
        });
        zone.addEventListener('pointerleave', function() {
            zone.classList.remove('drag-over');
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
        setTimeout(function() { zone.classList.remove('wrong'); }, 500);
    }
}

function checkAllDone() {
    const allFilled = Array.from(document.querySelectorAll('.ddk-zone'))
        .every(function(z) { return z.classList.contains('filled'); });
    if (allFilled) setTimeout(showCompleted, 700);
}

/* ── Completion ────────────────────────────────────────── */
async function showCompleted() {
    done = true;
    playSound(doneSnd);
    if (controls) controls.style.display = 'none';
    setFeedback('', '');

    const total  = DDK_PAIRS.length;
    const pct    = total > 0 ? Math.round((correctCount / total) * 100) : 0;
    const errors = Math.max(0, total - correctCount);

    var scores = [];
    for (var i = 0; i < total; i++) scores.push(i < correctCount ? 1 : 0);

    completedEl.classList.add('active');
    completedEl.innerHTML = '';
    window.ActivityFeedback.showCompleted({
        target:        completedEl,
        scores:        scores,
        title:         DDK_TITLE,
        activityType:  'Drag & Drop',
        questionCount: total,
        onRetry:       restartActivity
    });

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

/* ── Show answers ──────────────────────────────────────── */
function showAnswers() {
    if (done) return;
    document.querySelectorAll('.ddk-zone').forEach(function(zone) {
        if (zone.classList.contains('filled')) return;
        const id   = zone.dataset.id;
        const pair = DDK_PAIRS.find(function(p) { return String(p.id) === String(id); });
        if (pair) { zone.classList.add('filled'); zone.textContent = pair.label; }
    });
    bank.innerHTML = '';
    setFeedback('Answers shown', 'good');
    correctCount = DDK_PAIRS.length;
    setTimeout(showCompleted, 700);
}

/* ── Restart ───────────────────────────────────────────── */
function restartActivity() {
    done         = false;
    correctCount = 0;
    clearChip();
    if (completedEl) { completedEl.classList.remove('active'); completedEl.innerHTML = ''; }
    if (controls) controls.style.display = '';
    setFeedback('', '');
    document.querySelectorAll('.ddk-zone').forEach(function(z) {
        z.classList.remove('filled', 'wrong');
        z.textContent = '';
    });
    buildBank();
}

/* ── Boot ──────────────────────────────────────────────── */
if (isTouchLike && touchHint) touchHint.classList.remove('hidden');
buildBank();
setupZones();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🖼️', $content);

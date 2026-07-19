<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

function ddp_default_title(): string { return 'Drag & Drop Picture'; }

function ddp_normalize_payload($raw): array
{
    $default = [
        'title'            => ddp_default_title(),
        'instructions'     => '',
        'background_image' => '',
        'items'            => [],
    ];

    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $items = [];
    foreach ($d['items'] ?? [] as $item) {
        if (!is_array($item)) continue;
        $id      = (int)($item['id'] ?? 0);
        $pic_url = trim((string)($item['pic_url'] ?? ''));
        if ($id <= 0 || $pic_url === '') continue;
        $items[] = [
            'id'      => $id,
            'pic_url' => $pic_url,
            'label'   => trim((string)($item['label'] ?? '')),
            'x'       => (float)($item['x'] ?? 10),
            'y'       => (float)($item['y'] ?? 10),
            'w'       => (float)($item['w'] ?? 12),
            'h'       => (float)($item['h'] ?? 15),
            'rot'     => (int)($item['rot'] ?? 0),
            'flipH'   => !empty($item['flipH']),
        ];
    }

    return [
        'title'            => trim((string)($d['title'] ?? '')) ?: ddp_default_title(),
        'instructions'     => trim((string)($d['instructions'] ?? '')),
        'background_image' => trim((string)($d['background_image'] ?? '')),
        'items'            => $items,
    ];
}

function ddp_resolve_unit(PDO $pdo, string $activityId): string
{
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row['unit_id'] ?? '') : '';
}

function ddp_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id'               => '',
        'title'            => ddp_default_title(),
        'instructions'     => '',
        'background_image' => '',
        'items'            => [],
    ];

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'dragdrop_pic' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'dragdrop_pic' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $payload = ddp_normalize_payload($row['data'] ?? null);
    return array_merge($payload, ['id' => (string)($row['id'] ?? '')]);
}

if ($unit === '' && $activityId !== '') {
    $unit = ddp_resolve_unit($pdo, $activityId);
}

$activity     = ddp_load($pdo, $activityId, $unit);
$title        = $activity['title'];
$items        = $activity['items'];
$bgImage      = $activity['background_image'];
$instructions = $activity['instructions'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

if (empty($items) || $bgImage === '') {
    die('Activity not configured yet.');
}

function ddp_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

ob_start();
?>
<style>
/* Base overrides */
body,
.activity-wrapper,
.viewer-content,
body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content { background: #fff !important; }

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
.act-header h2 { font-size: clamp(18px,2.8vw,34px) !important; margin:0 0 4px !important; color:#F97316 !important; text-align:center !important; }
.act-header p  { font-size: clamp(12px,1.2vw,15px) !important; color:#9B94BE !important; text-align:center !important; }

/* ── Stage ─────────────────────────────── */
.ddp-stage {
    max-width: 960px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
}

/* Canvas wrapper */
.ddp-canvas-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    margin-bottom: 10px;
    line-height: 0;
    overflow: hidden;
    border-radius: 16px;
    touch-action: none;
}
.ddp-zoom-target {
    transform-origin: top center;
    transition: transform .15s ease;
}
.ddp-canvas {
    position: relative;
    display: inline-block;
    max-width: 100%;
}
.ddp-bg {
    display: block;
    max-width: 100%;
    max-height: calc(100vh - 260px);
    width: auto;
    height: auto;
    border-radius: 16px;
    user-select: none;
    pointer-events: none;
    box-shadow: 0 10px 28px rgba(15,23,42,.13);
}

/* Drop zones — invisible hit targets, no visual overlay on background */
.ddp-zone {
    position: absolute;
    box-sizing: border-box;
    border-radius: 8px;
    cursor: pointer;
    transition: background .18s, transform .15s, box-shadow .18s;
    touch-action: none;
}
.ddp-zone.drag-over {
    background: rgba(249,115,22,.18);
    outline: 3px solid #F97316;
    outline-offset: -2px;
    transform: scale(1.03);
    box-shadow: 0 0 0 4px rgba(249,115,22,.25);
}
.ddp-zone.filled {
    cursor: default;
    pointer-events: none;
}
.ddp-zone.wrong {
    outline: 3px solid #dc2626 !important;
    background: rgba(254,226,226,.3) !important;
    animation: ddp-shake .4s ease;
}
@keyframes ddp-shake {
    0%,100% { transform: translateX(0); }
    20%      { transform: translateX(-6px); }
    60%      { transform: translateX(6px); }
    80%      { transform: translateX(-3px); }
}

/* Images placed on canvas — layered above background, non-blocking */
.ddp-placed-img {
    position: absolute;
    object-fit: cover;
    display: block;
    border-radius: 6px;
    pointer-events: none;
    box-sizing: border-box;
}

/* Picture bank */
.ddp-bank {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
    margin: 8px 0;
    min-height: 80px;
    padding: 4px;
}
/* Floating wiggle animation on idle chips */
@keyframes ddp-chip-wiggle {
    0%, 100% { transform: rotate(-4deg) translateY(0); }
    50%       { transform: rotate( 4deg) translateY(-6px); }
}
.ddp-chip {
    cursor: grab;
    border-radius: 10px;
    border: 2.5px solid #7c3aed;
    background: #fff;
    box-shadow: 0 2px 8px rgba(127,119,221,.18);
    overflow: hidden;
    user-select: none;
    touch-action: none;
    transition: filter .12s, box-shadow .12s;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    will-change: transform;
    flex-shrink: 0;
    animation: ddp-chip-wiggle 2.2s ease-in-out infinite;
}
.ddp-chip:nth-child(2) { animation-delay: -0.55s; }
.ddp-chip:nth-child(3) { animation-delay: -1.10s; }
.ddp-chip:nth-child(4) { animation-delay: -1.65s; }
.ddp-chip:nth-child(5) { animation-delay: -0.30s; }
.ddp-chip:nth-child(6) { animation-delay: -0.85s; }
.ddp-chip:nth-child(7) { animation-delay: -1.40s; }
.ddp-chip:hover { filter: brightness(1.06); box-shadow: 0 4px 14px rgba(127,119,221,.30); animation-play-state: paused; }
.ddp-chip.dragging { opacity: .35; cursor: grabbing; animation: none; }
.ddp-chip.selected-touch {
    outline: 3px solid #F97316;
    outline-offset: 3px;
    border-color: #F97316;
    box-shadow: 0 0 0 3px rgba(249,115,22,.25);
    animation-play-state: paused;
}
.ddp-chip-img {
    display: block;
    width: 80px;
    height: 80px;
    object-fit: cover;
    pointer-events: none;
    user-select: none;
}

/* Floating drag clone */
@keyframes ddp-clone-rock {
    0%, 100% { transform: scale(1.12) rotate(-5deg); }
    50%       { transform: scale(1.12) rotate( 5deg); }
}
.ddp-drag-clone {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    opacity: .92;
    animation: ddp-clone-rock .45s ease-in-out infinite;
    box-shadow: 0 10px 28px rgba(127,119,221,.45);
    border-radius: 10px;
    overflow: hidden;
    transition: none !important;
}

/* Snap animation element */
.ddp-snap-el {
    position: fixed;
    z-index: 9998;
    pointer-events: none;
    object-fit: cover;
    border-radius: 8px;
    transition: left .32s cubic-bezier(0.2,1.0,0.3,1.0),
                top  .32s cubic-bezier(0.2,1.0,0.3,1.0),
                width .32s cubic-bezier(0.2,1.0,0.3,1.0),
                height .32s cubic-bezier(0.2,1.0,0.3,1.0),
                opacity .32s;
}

/* Hint / zoom bar */
.ddp-hint-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0 0 6px;
    flex-wrap: wrap;
}
.ddp-touch-hint { color:#5b516f; font-size:12px; font-weight:700; }
.ddp-touch-hint.hidden { display: none; }
.ddp-zoom-bar  { display:flex; align-items:center; gap:4px; }
.ddp-zoom-btn  {
    width:32px; height:32px; border-radius:50%;
    border:2px solid #7F77DD; background:#fff; color:#7F77DD;
    font-size:18px; font-weight:900; cursor:pointer;
    display:flex; align-items:center; justify-content:center; line-height:1;
    transition: background .12s, color .12s; padding:0;
}
.ddp-zoom-btn:hover { background:#7F77DD; color:#fff; }
.ddp-zoom-label { font-size:11px; font-weight:700; color:#9B94BE; min-width:32px; text-align:center; font-family:'Nunito',sans-serif; }

/* Controls / buttons */
.ddp-controls { text-align:center; margin:8px 0 4px; display:flex; flex-wrap:wrap; gap:8px; justify-content:center; }
.ddp-btn {
    display:inline-flex; align-items:center; justify-content:center;
    padding:11px 22px; border:none; border-radius:8px; color:#fff; cursor:pointer;
    min-width:140px; font-weight:800; font-family:'Nunito','Segoe UI',sans-serif;
    font-size:clamp(13px,1.4vw,16px); box-shadow:0 6px 18px rgba(127,119,221,.28);
    transition:transform .15s,filter .15s; line-height:1;
}
.ddp-btn:hover { filter:brightness(1.07); transform:translateY(-2px); }
.ddp-btn-show { background:#7F77DD; }

/* Feedback */
#ddpFeedback {
    text-align:center; font-size:clamp(14px,1.5vw,18px);
    font-weight:800; min-height:26px; margin:4px 0;
}
.good { color:#15803d; }
.bad  { color:#dc2626; }

.ddp-completed { display:none; padding:8px 0; }
.ddp-completed.active { display:block; }

/* Responsive */
@media (max-width: 480px) {
    .ddp-bg { max-height: calc(100vh - 240px); }
    .ddp-chip-img { width:64px; height:64px; }
    .ddp-bank { gap:7px; }
    .ddp-btn { min-width:120px; }
}
@media (min-width: 1280px) {
    .ddp-stage { max-width:1100px; }
    .act-header { max-width:1100px !important; }
    .ddp-chip-img { width:100px; height:100px; }
    .ddp-btn { font-size:clamp(15px,1.5vw,18px) !important; padding:13px 28px !important; min-width:160px !important; }
}

/* Presentation / fullscreen */
body.presentation-mode .activity-wrapper,
body.fullscreen-embedded .activity-wrapper { padding:8mm !important; box-sizing:border-box !important; }
body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content { border-radius:14px !important; overflow:hidden !important; }
body.presentation-mode .ddp-bg,
body.fullscreen-embedded .ddp-bg { max-height: calc(100vh - 16mm - 210px); }
</style>

<?= render_activity_header(
    $title,
    $instructions !== '' ? $instructions : 'Drag each picture to the correct place on the scene.'
) ?>

<div class="ddp-stage" id="ddpStage">

    <div class="ddp-hint-bar">
        <span id="ddpTouchHint" class="ddp-touch-hint hidden">
            👆 Drag pictures onto the scene — or tap a picture, then tap its spot.
        </span>
        <div class="ddp-zoom-bar" id="ddpZoomBar">
            <button class="ddp-zoom-btn" type="button" id="ddpZoomOut" aria-label="Zoom out">−</button>
            <span class="ddp-zoom-label" id="ddpZoomLabel">100%</span>
            <button class="ddp-zoom-btn" type="button" id="ddpZoomIn" aria-label="Zoom in">+</button>
        </div>
    </div>

    <div id="ddpZoomTarget" class="ddp-zoom-target">

        <div class="ddp-canvas-wrap" id="ddpCanvasWrap">
            <div class="ddp-canvas" id="ddpCanvas">
                <img id="ddpBg" class="ddp-bg"
                     src="<?= ddp_h($bgImage) ?>"
                     alt="Scene">
                <?php foreach ($items as $it): ?>
                <div class="ddp-zone"
                     id="zone-<?= (int)$it['id'] ?>"
                     data-id="<?= (int)$it['id'] ?>"
                     style="left:<?= round((float)$it['x'],4) ?>%;top:<?= round((float)$it['y'],4) ?>%;width:<?= round((float)$it['w'],4) ?>%;height:<?= round((float)$it['h'],4) ?>%"
                ></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ddp-bank" id="ddpBank"></div>

        <div class="ddp-controls" id="ddpControls">
            <button class="ddp-btn ddp-btn-show" type="button" onclick="showAnswers()">Show Answer</button>
        </div>

        <div id="ddpFeedback"></div>
        <div id="ddpCompleted" class="ddp-completed"></div>

    </div>

</div>

<audio id="winSnd"  src="../../hangman/assets/win.mp3"       preload="auto"></audio>
<audio id="loseSnd" src="../../hangman/assets/lose.mp3"      preload="auto"></audio>
<audio id="doneSnd" src="../../hangman/assets/win (1).mp3"   preload="auto"></audio>

<script src="../../core/_activity_feedback.js"></script>
<script>
const DDP_ITEMS       = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
const DDP_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
const DDP_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const DDP_TITLE       = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;

/* ── Transform helper ──────────────────── */
function getChipTransform(it) {
    var r = it.rot  || 0;
    var f = it.flipH ? -1 : 1;
    if (!r && f === 1) return '';
    return 'rotate(' + r + 'deg) scaleX(' + f + ')';
}

const winSnd      = document.getElementById('winSnd');
const loseSnd     = document.getElementById('loseSnd');
const doneSnd     = document.getElementById('doneSnd');
const bank        = document.getElementById('ddpBank');
const feedbackEl  = document.getElementById('ddpFeedback');
const completedEl = document.getElementById('ddpCompleted');
const touchHint   = document.getElementById('ddpTouchHint');
const controls    = document.getElementById('ddpControls');
const canvasEl    = document.getElementById('ddpCanvas');
const canvasWrap  = document.getElementById('ddpCanvasWrap');
const zoomTarget  = document.getElementById('ddpZoomTarget');

const isTouchLike = (window.matchMedia && window.matchMedia('(pointer:coarse)').matches)
    || ('ontouchstart' in window)
    || navigator.maxTouchPoints > 0;

let selectedChip = null;
let correctCount = 0;
let done         = false;

function playSound(a) { try { a.pause(); a.currentTime = 0; a.play(); } catch(e) {} }
function shuffle(arr) { return arr.slice().sort(() => Math.random() - 0.5); }
function setFeedback(msg, cls) { feedbackEl.textContent = msg; feedbackEl.className = cls || ''; }

/* ── Zoom ─────────────────────────────── */
let zoomScale = 1;
const ZOOM_STEP = 0.2, ZOOM_MIN = 0.6, ZOOM_MAX = 3.0;

function applyZoom() {
    zoomTarget.style.transform = zoomScale === 1 ? '' : 'scale(' + zoomScale + ')';
    if (zoomScale > 1) {
        zoomTarget.style.marginBottom = (zoomTarget.offsetHeight * (zoomScale - 1)) + 'px';
    } else {
        zoomTarget.style.marginBottom = '';
    }
    document.getElementById('ddpZoomLabel').textContent = Math.round(zoomScale * 100) + '%';
}
document.getElementById('ddpZoomIn').addEventListener('click', function() {
    zoomScale = Math.min(ZOOM_MAX, parseFloat((zoomScale + ZOOM_STEP).toFixed(2)));
    applyZoom();
});
document.getElementById('ddpZoomOut').addEventListener('click', function() {
    zoomScale = Math.max(ZOOM_MIN, parseFloat((zoomScale - ZOOM_STEP).toFixed(2)));
    applyZoom();
});

/* ── Pinch-to-zoom ─────────────────────── */
(function() {
    let pinchActive = false, pinchStartDist = 0, pinchStartScale = 1;
    function pinchDist(e) {
        var dx = e.touches[0].clientX - e.touches[1].clientX;
        var dy = e.touches[0].clientY - e.touches[1].clientY;
        return Math.sqrt(dx*dx + dy*dy);
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
        var ratio = pinchDist(e) / pinchStartDist;
        zoomScale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, parseFloat((pinchStartScale * ratio).toFixed(2))));
        applyZoom();
        e.preventDefault();
    }, { passive: false });
    canvasWrap.addEventListener('touchend', function(e) {
        if (e.touches.length < 2) pinchActive = false;
    }, { passive: true });
    var lastTap = 0;
    canvasWrap.addEventListener('touchend', function(e) {
        if (e.touches.length > 0) return;
        var now = Date.now();
        if (now - lastTap < 300) { zoomScale = 1; applyZoom(); }
        lastTap = now;
    }, { passive: true });
})();

/* ── Pointer drag ─────────────────────── */
var dragClone = null;
var dragChip  = null;

function addChipDrag(chip) {
    var startX = 0, startY = 0;
    var isDragging = false;
    var captureId = -1;

    chip.addEventListener('pointerdown', function(e) {
        if (done) return;
        if (e.button !== 0 && e.pointerType === 'mouse') return;
        startX = e.clientX;
        startY = e.clientY;
        isDragging = false;
        captureId = e.pointerId;
        chip.setPointerCapture(e.pointerId);
    }, { passive: true });

    chip.addEventListener('pointermove', function(e) {
        if (e.pointerId !== captureId || done) return;
        var dx = e.clientX - startX;
        var dy = e.clientY - startY;

        if (!isDragging && Math.sqrt(dx*dx + dy*dy) > 8) {
            isDragging = true;
            dragChip = chip;
            chip.classList.add('dragging');
            if (selectedChip) { selectedChip.classList.remove('selected-touch'); selectedChip = null; }

            var rect = chip.getBoundingClientRect();
            dragClone = chip.cloneNode(true);
            dragClone.classList.remove('dragging', 'selected-touch');
            dragClone.classList.add('ddp-drag-clone');
            dragClone.style.width  = rect.width  + 'px';
            dragClone.style.height = rect.height + 'px';
            dragClone.style.left   = (e.clientX - rect.width  / 2) + 'px';
            dragClone.style.top    = (e.clientY - rect.height / 2) + 'px';
            document.body.appendChild(dragClone);
        }

        if (isDragging && dragClone) {
            var w = dragClone.offsetWidth;
            var h = dragClone.offsetHeight;
            dragClone.style.left = (e.clientX - w / 2) + 'px';
            dragClone.style.top  = (e.clientY - h / 2) + 'px';

            /* highlight zone under pointer */
            var below = getZoneAt(e.clientX, e.clientY);
            document.querySelectorAll('.ddp-zone').forEach(function(z) {
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
            /* record drag clone position for snap animation */
            var cloneRect = dragClone ? dragClone.getBoundingClientRect() : null;

            if (dragClone) { dragClone.remove(); dragClone = null; }
            chip.classList.remove('dragging');
            document.querySelectorAll('.ddp-zone').forEach(function(z) { z.classList.remove('drag-over'); });

            var zone = getZoneAt(e.clientX, e.clientY);
            if (zone && dragChip) {
                handleDrop(zone, dragChip.dataset.id, dragChip, cloneRect);
            }
            dragChip  = null;
            isDragging = false;
        } else {
            isDragging = false;
            toggleChip(chip);
        }
    });

    chip.addEventListener('pointercancel', function(e) {
        if (e.pointerId !== captureId) return;
        captureId = -1;
        if (dragClone) { dragClone.remove(); dragClone = null; }
        chip.classList.remove('dragging');
        document.querySelectorAll('.ddp-zone').forEach(function(z) { z.classList.remove('drag-over'); });
        dragChip  = null;
        isDragging = false;
    });
}

function getZoneAt(cx, cy) {
    if (dragClone) dragClone.style.display = 'none';
    var el = document.elementFromPoint(cx, cy);
    if (dragClone) dragClone.style.display = '';
    if (!el) return null;
    if (el.classList.contains('ddp-zone')) return el;
    return el.closest ? el.closest('.ddp-zone') : null;
}

/* ── Tap-to-select / tap-to-place ─────── */
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

/* ── Magnet snap animation ─────────────── */
function snapAnimate(fromRect, toRect, picUrl, onComplete) {
    var el = document.createElement('img');
    el.src = picUrl;
    el.className = 'ddp-snap-el';
    el.style.left   = fromRect.left   + 'px';
    el.style.top    = fromRect.top    + 'px';
    el.style.width  = fromRect.width  + 'px';
    el.style.height = fromRect.height + 'px';
    document.body.appendChild(el);

    /* force reflow so transition fires */
    el.getBoundingClientRect();

    el.style.left   = toRect.left   + 'px';
    el.style.top    = toRect.top    + 'px';
    el.style.width  = toRect.width  + 'px';
    el.style.height = toRect.height + 'px';

    var done = false;
    function finish() {
        if (done) return;
        done = true;
        el.remove();
        onComplete();
    }
    el.addEventListener('transitionend', finish, { once: true });
    /* safety fallback */
    setTimeout(finish, 600);
}

/* ── Drop handler ─────────────────────── */
function handleDrop(zone, chipId, chipEl, fromRect) {
    if (zone.classList.contains('filled')) return;
    var zoneId = zone.dataset.id;
    var item   = DDP_ITEMS.find(function(it) { return String(it.id) === String(zoneId); });

    if (String(chipId) === String(zoneId)) {
        /* correct — animate snap */
        zone.classList.remove('drag-over');
        var zoneRect = zone.getBoundingClientRect();
        var animFrom = fromRect || chipEl.getBoundingClientRect();

        /* dim the chip immediately so bank feels responsive */
        chipEl.style.opacity = '0';

        playSound(winSnd);
        setFeedback('✔ Correct!', 'good');

        snapAnimate(animFrom, zoneRect, item ? item.pic_url : '', function() {
            /* place image directly on canvas, above the background */
            zone.classList.add('filled');
            var img = document.createElement('img');
            img.src = item ? item.pic_url : '';
            img.className = 'ddp-placed-img';
            img.alt = item ? (item.label || '') : '';
            img.dataset.zoneId = zoneId;
            img.style.left   = zone.style.left;
            img.style.top    = zone.style.top;
            img.style.width  = zone.style.width;
            img.style.height = zone.style.height;
            if (item) {
                var t = getChipTransform(item);
                if (t) img.style.transform = t;
            }
            canvasEl.appendChild(img);
            chipEl.remove();
            correctCount++;
            checkAllDone();
        });
    } else {
        /* wrong — shake zone, chip stays in bank */
        zone.classList.add('wrong');
        zone.classList.remove('drag-over');
        playSound(loseSnd);
        setFeedback('✘ Try a different spot.', 'bad');
        setTimeout(function() { zone.classList.remove('wrong'); }, 500);
    }
}

function checkAllDone() {
    var allFilled = Array.from(document.querySelectorAll('.ddp-zone'))
        .every(function(z) { return z.classList.contains('filled'); });
    if (allFilled) setTimeout(showCompleted, 700);
}

/* ── Picture bank ─────────────────────── */
function buildBank() {
    bank.innerHTML = '';
    shuffle(DDP_ITEMS).forEach(function(it) {
        var chip = document.createElement('div');
        chip.className   = 'ddp-chip';
        chip.dataset.id  = it.id;

        var img = document.createElement('img');
        img.src       = it.pic_url;
        img.alt       = it.label || '';
        img.className = 'ddp-chip-img';
        var t = getChipTransform(it);
        if (t) img.style.transform = t;
        chip.appendChild(img);

        addChipDrag(chip);
        bank.appendChild(chip);
    });
}

/* ── Zones: tap-to-place ──────────────── */
function setupZones() {
    document.querySelectorAll('.ddp-zone').forEach(function(zone) {
        zone.addEventListener('pointerup', function(e) {
            if (done || !selectedChip) return;
            if (dragChip) return;
            var chipRect = selectedChip.getBoundingClientRect();
            handleDrop(zone, selectedChip.dataset.id, selectedChip, chipRect);
            clearChip();
            e.stopPropagation();
        });
        zone.addEventListener('pointerenter', function() {
            if (dragChip && !zone.classList.contains('filled')) zone.classList.add('drag-over');
        });
        zone.addEventListener('pointerleave', function() {
            zone.classList.remove('drag-over');
        });
    });
}

/* ── Completion ───────────────────────── */
async function showCompleted() {
    done = true;
    playSound(doneSnd);
    if (controls) controls.style.display = 'none';
    setFeedback('', '');

    var total  = DDP_ITEMS.length;
    var pct    = total > 0 ? Math.round((correctCount / total) * 100) : 0;
    var errors = Math.max(0, total - correctCount);
    var scores = [];
    for (var i = 0; i < total; i++) scores.push(i < correctCount ? 1 : 0);

    completedEl.classList.add('active');
    completedEl.innerHTML = '';
    window.ActivityFeedback.showCompleted({
        target:        completedEl,
        scores:        scores,
        title:         DDP_TITLE,
        activityType:  'Drag & Drop Picture',
        questionCount: total,
        onRetry:       restartActivity
    });

    if (DDP_ACTIVITY_ID && DDP_RETURN_TO) {
        var joiner  = DDP_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var saveUrl = DDP_RETURN_TO + joiner
            + 'activity_percent=' + pct
            + '&activity_errors=' + errors
            + '&activity_total='  + total
            + '&activity_id='     + encodeURIComponent(DDP_ACTIVITY_ID)
            + '&activity_type=dragdrop_pic';
        try {
            var res = await fetch(saveUrl, { method:'GET', credentials:'same-origin', cache:'no-store' });
            if (!res.ok) throw new Error();
        } catch(e) {
            try {
                if (window.top && window.top !== window.self) window.top.location.href = saveUrl;
                else window.location.href = saveUrl;
            } catch(ee) { window.location.href = saveUrl; }
        }
    }
}

/* ── Show answers ─────────────────────── */
function showAnswers() {
    if (done) return;
    document.querySelectorAll('.ddp-zone').forEach(function(zone) {
        if (zone.classList.contains('filled')) return;
        var id   = zone.dataset.id;
        var item = DDP_ITEMS.find(function(it) { return String(it.id) === String(id); });
        if (!item) return;
        zone.classList.add('filled');
        var img = document.createElement('img');
        img.src = item.pic_url;
        img.className = 'ddp-placed-img';
        img.alt = item.label || '';
        img.dataset.zoneId = id;
        img.style.left   = zone.style.left;
        img.style.top    = zone.style.top;
        img.style.width  = zone.style.width;
        img.style.height = zone.style.height;
        var t = getChipTransform(item);
        if (t) img.style.transform = t;
        canvasEl.appendChild(img);
    });
    bank.innerHTML = '';
    setFeedback('Answers shown', 'good');
    correctCount = DDP_ITEMS.length;
    setTimeout(showCompleted, 700);
}

/* ── Restart ──────────────────────────── */
function restartActivity() {
    done         = false;
    correctCount = 0;
    clearChip();
    if (completedEl) { completedEl.classList.remove('active'); completedEl.innerHTML = ''; }
    if (controls) controls.style.display = '';
    setFeedback('', '');
    document.querySelectorAll('.ddp-placed-img').forEach(function(img) { img.remove(); });
    document.querySelectorAll('.ddp-zone').forEach(function(z) {
        z.classList.remove('filled', 'wrong');
    });
    buildBank();
}

/* ── Boot ─────────────────────────────── */
if (isTouchLike && touchHint) touchHint.classList.remove('hidden');
buildBank();
setupZones();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🖼️', $content);

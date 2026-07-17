<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

/* ── helpers ────────────────────────────────────────────────── */
function rm_resolve_unit(PDO $pdo, string $id): string
{
    if ($id === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row['unit_id'] ?? '') : '';
}

function rm_normalize(mixed $raw): array
{
    $default = [
        'title'      => 'Review Match',
        'instructions' => '',
        'cefr'       => 'A1',
        'categories' => [],
        'items'      => [],
    ];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;
    return array_merge($default, $d);
}

function rm_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = array_merge(rm_normalize(null), ['id' => '']);
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT id,data FROM activities WHERE id=:id AND type IN ('review_match','lets_classify') LIMIT 1"
        );
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare(
            "SELECT id,data FROM activities WHERE unit_id=:u AND type IN ('review_match','lets_classify') ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute(['u' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $p = rm_normalize($row['data'] ?? null);
    $p['id'] = (string)($row['id'] ?? '');
    return $p;
}

/* ── load ───────────────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') {
    $unit = rm_resolve_unit($pdo, $activityId);
}

$activity = rm_load($pdo, $activityId, $unit);
if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

$viewerTitle  = (string)($activity['title']        ?? 'Review Match');
$instructions = (string)($activity['instructions'] ?? '');
$categories   = is_array($activity['categories'])  ? array_values($activity['categories']) : [];
$items        = is_array($activity['items'])        ? array_values($activity['items'])      : [];

if (count($categories) < 2) die('No categories found for this activity');
if (count($items) < 2)      die('No items found for this activity');

shuffle($items);

$catsJson  = json_encode($categories, JSON_UNESCAPED_UNICODE);
$itemsJson = json_encode($items,      JSON_UNESCAPED_UNICODE);

// Palette for categories without images
$catPalette = [
    'linear-gradient(135deg,#7F77DD,#534AB7)',
    'linear-gradient(135deg,#F97316,#ea580c)',
    'linear-gradient(135deg,#16a34a,#15803d)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)',
    'linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#f59e0b,#d97706)',
];

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --rm-orange:#F97316;--rm-purple:#7F77DD;--rm-purple-dark:#534AB7;
    --rm-lila:#EDE9FA;--rm-muted:#9B94BE;
    --rm-green:#16a34a;--rm-green-soft:#f0fdf4;--rm-green-dark:#15803d;
    --rm-red:#ef4444;--rm-red-soft:#fef2f2;--rm-red-dark:#b91c1c;
}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:0;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;min-height:0!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

/* page scaffold */
.rm-page{width:100%;flex:1;overflow-y:auto;padding:clamp(14px,2.5vw,34px);box-sizing:border-box;background:#fff}
.rm-app{width:min(1120px,100%);margin:0 auto}

/* topbar */
.rm-topbar{height:36px;display:flex;align-items:center;justify-content:center;margin-bottom:6px}
.rm-topbar-title{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}

/* hero */
.rm-hero{text-align:center;margin-bottom:clamp(12px,2vw,22px)}
.rm-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.rm-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(26px,4.5vw,48px);font-weight:700;color:var(--rm-orange);margin:0;line-height:1.05}
.rm-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.7vw,16px);font-weight:800;color:#9B94BE;margin:8px 0 0}

/* stage card */
.rm-stage{background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(14px,2vw,24px);box-shadow:0 8px 40px rgba(127,119,221,.12);box-sizing:border-box}

/* ── TWO-COLUMN LAYOUT ── */
.rm-layout{display:flex;gap:18px;align-items:flex-start}
.rm-col-cats{flex:0 0 62%;min-width:0;display:flex;flex-direction:column;gap:14px}
.rm-col-pool{flex:1;min-width:0;position:sticky;top:20px;display:flex;flex-direction:column;gap:10px}

/* ── CATEGORY CARDS ── */
.rm-cat-card{
    position:relative;border-radius:20px;overflow:hidden;
    min-height:clamp(160px,22vw,250px);
    background:linear-gradient(135deg,#7F77DD,#534AB7);
    box-shadow:0 6px 28px rgba(0,0,0,.16);
    transition:box-shadow .15s,outline-color .1s;
    cursor:default;user-select:none
}
.rm-cat-card.drag-over{
    box-shadow:0 10px 40px rgba(127,119,221,.55);
    outline:3px solid var(--rm-purple);outline-offset:2px
}
.rm-cat-bg-img{
    position:absolute;inset:0;width:100%;height:100%;
    object-fit:cover;display:block;pointer-events:none
}
.rm-cat-gradient{
    position:absolute;inset:0;
    background:linear-gradient(to bottom,rgba(0,0,0,.06) 0%,rgba(0,0,0,.55) 100%);
    pointer-events:none
}
.rm-cat-name-badge{
    position:absolute;bottom:0;left:0;right:0;
    padding:36px 16px 14px;z-index:3;
    color:#fff;font-family:'Fredoka',sans-serif;
    font-size:clamp(16px,2.2vw,24px);font-weight:700;
    text-align:center;text-shadow:0 2px 10px rgba(0,0,0,.55);
    pointer-events:none
}
/* error count badge on category */
.rm-cat-error-badge{
    position:absolute;top:10px;right:10px;z-index:5;
    background:rgba(239,68,68,.85);color:#fff;
    font-family:'Nunito',sans-serif;font-size:11px;font-weight:900;
    border-radius:999px;padding:4px 8px;display:none;
    backdrop-filter:blur(4px)
}
.rm-cat-error-badge.visible{display:block}

/* chips stickered absolutely on the category card */
.rm-cat-chips-area{
    position:absolute;inset:0;z-index:4;pointer-events:none
}
.rm-chip-sticker{
    position:absolute;
    pointer-events:none;
    transform:translate(-50%,-50%);
    width:68px;height:68px;
    border-radius:11px;overflow:hidden;
    border:3px solid var(--rm-green);
    box-shadow:0 4px 14px rgba(22,163,74,.4);
    background:#fff
}
.rm-chip-sticker img{width:100%;height:100%;object-fit:cover;display:block}
/* bounce-back animation */
@keyframes rm-shake{
    0%{transform:translate(-50%,-50%) scale(1.15)}
    40%{transform:translate(-50%,-50%) scale(.9)}
    70%{transform:translate(-50%,-50%) scale(1.05)}
    100%{transform:translate(-50%,-50%) scale(1)}
}
.rm-chip-sticker.rm-correct-anim{animation:rm-shake .35s ease}

/* ── POOL COLUMN ── */
.rm-pool-label{font-family:'Nunito',sans-serif;font-size:11px;font-weight:900;color:#9B94BE;letter-spacing:.08em;text-transform:uppercase}
/* live error counter */
.rm-error-bar{
    display:flex;align-items:center;gap:8px;
    padding:8px 12px;background:#FAFAFE;
    border:1px solid #EDE9FA;border-radius:10px;
    font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;color:#9B94BE
}
.rm-error-bar span{font-family:'Fredoka',sans-serif;font-size:18px;font-weight:700;color:var(--rm-red)}

.rm-pool{
    display:flex;flex-direction:column;gap:8px;
    max-height:66vh;overflow-y:auto;
    padding:4px 2px;
}
.rm-pool-empty-hint{color:#9B94BE;font-size:13px;font-weight:700;padding:18px 0;text-align:center}
/* pool chip — image only */
.rm-pool-chip{
    width:100%;aspect-ratio:1/1;max-height:90px;
    border-radius:14px;overflow:hidden;
    border:3px solid #EDE9FA;
    cursor:grab;user-select:none;
    box-shadow:0 3px 10px rgba(0,0,0,.12);
    transition:transform .12s,box-shadow .12s,border-color .12s;
    background:#FAFAFE;position:relative
}
.rm-pool-chip:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.18);border-color:var(--rm-purple)}
.rm-pool-chip:active{cursor:grabbing;transform:translateY(1px)}
.rm-pool-chip.dragging{opacity:.4;transform:scale(.95)}
.rm-pool-chip img{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
/* shake animation for wrong drop */
@keyframes rm-wrong-shake{
    0%,100%{transform:translateX(0)}
    20%{transform:translateX(-8px)}
    40%{transform:translateX(8px)}
    60%{transform:translateX(-5px)}
    80%{transform:translateX(5px)}
}
.rm-pool-chip.rm-wrong-anim{animation:rm-wrong-shake .4s ease}

/* score grid */
.rm-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}
.rm-score-grid.visible{display:grid}
.rm-score-card{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}
.rm-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:26px;line-height:1}
.rm-score-num.c{color:var(--rm-green)}
.rm-score-num.w{color:var(--rm-red)}
.rm-score-num.p{color:var(--rm-purple)}
.rm-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:#9B94BE;text-transform:uppercase;letter-spacing:.08em}

/* completed screen */
.rm-completed{display:none;text-align:center;padding:28px 12px;max-width:520px;margin:0 auto}
.rm-completed.active{display:block}
.rm-completed-icon{font-size:34px;line-height:1;margin-bottom:8px}
.rm-completed-title{margin:0 0 6px;color:var(--rm-orange);font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:34px;font-weight:700}
.rm-completed-text{color:#9B94BE;font-size:14px;font-weight:800;line-height:1.5;margin:0}
.rm-completed-score{font-family:'Nunito',sans-serif;font-size:15px;font-weight:900;color:var(--rm-purple-dark);margin:6px 0 14px}
.rm-completed .rm-score-grid{margin-bottom:16px}
.rm-completed-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.rm-completed-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;border:none;border-radius:8px;cursor:pointer;min-width:120px;font-weight:700;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;line-height:1;transition:transform .12s,filter .12s;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.rm-completed-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.rm-completed-btn.restart{background:var(--rm-purple);color:#fff}
.rm-completed-btn.back{background:#F0EEF8;color:var(--rm-purple-dark)}

/* ghost */
#rm-ghost{position:fixed;pointer-events:none;z-index:9999;display:none;width:68px;height:68px;border-radius:11px;box-shadow:0 8px 28px rgba(127,119,221,.3);opacity:.92;overflow:hidden;background:#fff}

@media(max-width:660px){
    .rm-layout{flex-direction:column}
    .rm-col-cats{flex:unset;width:100%}
    .rm-col-pool{position:static;width:100%}
    .rm-pool{flex-direction:row;flex-wrap:wrap;max-height:unset;overflow-y:visible}
    .rm-pool-chip{flex:0 0 calc(25% - 6px);width:calc(25% - 6px);max-height:unset}
    .rm-score-grid{grid-template-columns:1fr}
}
</style>

<div class="rm-page" id="rm-page">
<div class="rm-app" id="rm-app">

    <div class="rm-topbar"><span class="rm-topbar-title">Review Match</span></div>

    <div class="rm-hero">
        <div class="rm-kicker">Activity</div>
        <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($instructions !== ''): ?>
            <p><?php echo htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div class="rm-stage" id="rm-stage" data-az-zoom>
        <div class="rm-layout" id="rm-layout">

            <!-- LEFT: category cards -->
            <div class="rm-col-cats" id="rm-col-cats">
                <?php foreach ($categories as $idx => $cat):
                    $catId   = (int)($cat['id']    ?? 0);
                    $catName = htmlspecialchars((string)($cat['name']  ?? ''), ENT_QUOTES, 'UTF-8');
                    $catImg  = htmlspecialchars((string)($cat['image'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $hasImg  = $catImg !== '';
                    $bgStyle = !$hasImg ? 'style="background:' . $catPalette[$idx % count($catPalette)] . '"' : '';
                ?>
                <div class="rm-cat-card" id="rm-cat-<?php echo $catId; ?>" data-cat="<?php echo $catId; ?>" <?php echo $bgStyle; ?>>
                    <?php if ($hasImg): ?>
                        <img class="rm-cat-bg-img" src="<?php echo $catImg; ?>" alt="<?php echo $catName; ?>">
                        <div class="rm-cat-gradient"></div>
                    <?php endif; ?>
                    <div class="rm-cat-name-badge"><?php echo $catName; ?></div>
                    <div class="rm-cat-error-badge" id="rm-cat-err-<?php echo $catId; ?>"></div>
                    <div class="rm-cat-chips-area" id="rm-chips-<?php echo $catId; ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- RIGHT: items pool (images only) -->
            <div class="rm-col-pool" id="rm-col-pool">
                <div class="rm-pool-label">Match these images</div>
                <div class="rm-error-bar" id="rm-error-bar">
                    ❌ Errors: <span id="rm-errors-count">0</span>
                </div>
                <div class="rm-pool" id="rm-pool"></div>
            </div>
        </div>

        <!-- score grid (shown on complete) -->
        <div id="rm-score-grid" class="rm-score-grid">
            <div class="rm-score-card"><div class="rm-score-num c" id="rm-s-correct">0</div><div class="rm-score-lbl">Correct</div></div>
            <div class="rm-score-card"><div class="rm-score-num w" id="rm-s-wrong">0</div><div class="rm-score-lbl">Errors</div></div>
            <div class="rm-score-card"><div class="rm-score-num p" id="rm-s-pct">0%</div><div class="rm-score-lbl">Score</div></div>
        </div>

        <!-- completed screen -->
        <div class="rm-completed" id="rm-completed">
            <div class="rm-completed-icon">✅</div>
            <h2 class="rm-completed-title" id="rm-completed-title">Completed!</h2>
            <div id="rm-completed-score-grid" class="rm-score-grid" style="max-width:360px;margin:0 auto 12px">
                <div class="rm-score-card"><div class="rm-score-num c" id="rm-cs-correct">0</div><div class="rm-score-lbl">Correct</div></div>
                <div class="rm-score-card"><div class="rm-score-num w" id="rm-cs-wrong">0</div><div class="rm-score-lbl">Errors</div></div>
                <div class="rm-score-card"><div class="rm-score-num p" id="rm-cs-pct">0%</div><div class="rm-score-lbl">Score</div></div>
            </div>
            <p class="rm-completed-text" id="rm-completed-text"></p>
            <p class="rm-completed-score" id="rm-completed-score-line"></p>
            <div class="rm-completed-actions">
                <button type="button" class="rm-completed-btn restart" id="rm-restart-btn">Restart</button>
                <?php if ($returnTo !== ''): ?>
                <button type="button" class="rm-completed-btn back" id="rm-back-btn">Back</button>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /rm-stage -->
</div><!-- /rm-app -->
</div><!-- /rm-page -->

<div id="rm-ghost"></div>
<audio id="rm-correct-sound" src="../../hangman/assets/win.mp3"     preload="auto"></audio>
<audio id="rm-done-sound"    src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
(function () {
'use strict';

/* ── data ── */
const RM_CATS      = <?= $catsJson ?>;
const RM_ITEMS_SRC = <?= $itemsJson ?>;
const RM_TITLE     = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const RM_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const RM_ACT_ID    = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

/* ── state ── */
// correctlyPlaced[itemId] = { catId, x, y }  (only correctly-placed items)
let correctlyPlaced = {};
let errors = 0;
let finished = false;

/* ── DOM refs ── */
const poolEl       = document.getElementById('rm-pool');
const scoreGridEl  = document.getElementById('rm-score-grid');
const sCorrectEl   = document.getElementById('rm-s-correct');
const sWrongEl     = document.getElementById('rm-s-wrong');
const sPctEl       = document.getElementById('rm-s-pct');
const completedEl  = document.getElementById('rm-completed');
const compTitleEl  = document.getElementById('rm-completed-title');
const compTextEl   = document.getElementById('rm-completed-text');
const compScoreEl  = document.getElementById('rm-completed-score-line');
const csgridEl     = document.getElementById('rm-completed-score-grid');
const csCorrectEl  = document.getElementById('rm-cs-correct');
const csWrongEl    = document.getElementById('rm-cs-wrong');
const csPctEl      = document.getElementById('rm-cs-pct');
const restartBtn   = document.getElementById('rm-restart-btn');
const backBtn      = document.getElementById('rm-back-btn');
const ghostEl      = document.getElementById('rm-ghost');
const correctSound = document.getElementById('rm-correct-sound');
const doneSound    = document.getElementById('rm-done-sound');
const errCountEl   = document.getElementById('rm-errors-count');

const CHIP_HALF = 34;

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function playSound(el) {
    try { el.currentTime = 0; el.play(); } catch(e) {}
}

function initState() {
    correctlyPlaced = {};
    errors = 0;
    finished = false;
}

function calcDropPos(catEl, clientX, clientY) {
    const rect = catEl.getBoundingClientRect();
    const x = Math.max(CHIP_HALF, Math.min(rect.width  - CHIP_HALF, clientX - rect.left));
    const y = Math.max(CHIP_HALF, Math.min(rect.height - CHIP_HALF, clientY - rect.top));
    return { catId: parseInt(catEl.dataset.cat, 10), x: (x / rect.width) * 100, y: (y / rect.height) * 100 };
}

/* ── Pool chip ── */
function buildPoolChip(item) {
    const el = document.createElement('div');
    el.className = 'rm-pool-chip';
    el.dataset.itemId = item.id;
    el.dataset.catId  = item.category_id;
    el.draggable = true;
    if (item.image) {
        el.innerHTML = `<img src="${escHtml(item.image)}" alt="${escHtml(item.name)}" loading="lazy">`;
    }
    el.addEventListener('dragstart', onDragStart);
    el.addEventListener('dragend',   onDragEnd);
    el.addEventListener('touchstart', onTouchStart, {passive: false});
    return el;
}

/* ── Sticker chip ── */
function buildStickerChip(item, pos) {
    const el = document.createElement('div');
    el.className = 'rm-chip-sticker rm-correct-anim';
    el.style.left = pos.x + '%';
    el.style.top  = pos.y + '%';
    if (item.image) {
        el.innerHTML = `<img src="${escHtml(item.image)}" alt="${escHtml(item.name)}" loading="lazy">`;
    }
    return el;
}

function render() {
    /* pool — only unplaced items */
    poolEl.innerHTML = '';
    const remaining = RM_ITEMS_SRC.filter(i => !correctlyPlaced[i.id]);
    if (remaining.length === 0) {
        poolEl.innerHTML = '<span class="rm-pool-empty-hint">All matched ✓</span>';
    } else {
        remaining.forEach(item => poolEl.appendChild(buildPoolChip(item)));
    }

    /* category sticker areas */
    RM_CATS.forEach(cat => {
        const area = document.getElementById('rm-chips-' + cat.id);
        if (!area) return;
        area.innerHTML = '';
        RM_ITEMS_SRC.filter(i => correctlyPlaced[i.id]?.catId === cat.id)
            .forEach(item => area.appendChild(buildStickerChip(item, correctlyPlaced[item.id])));
    });

    /* error counter */
    if (errCountEl) errCountEl.textContent = errors;

    setupDropZones();
}

/* ── Immediate feedback on drop ── */
function handleDrop(itemId, catEl, clientX, clientY) {
    if (finished) return;
    const item = RM_ITEMS_SRC.find(i => i.id === itemId);
    if (!item) return;
    const pos = calcDropPos(catEl, clientX, clientY);

    if (pos.catId === item.category_id) {
        /* CORRECT */
        correctlyPlaced[item.id] = pos;
        render();
        checkComplete();
    } else {
        /* WRONG — bounce back + error */
        errors++;
        if (errCountEl) errCountEl.textContent = errors;

        /* show shake on the pool chip */
        const chipEl = poolEl.querySelector(`[data-item-id="${itemId}"]`);
        if (chipEl) {
            chipEl.classList.remove('rm-wrong-anim');
            void chipEl.offsetWidth; // reflow
            chipEl.classList.add('rm-wrong-anim');
        }

        /* flash error badge on category */
        const errBadge = document.getElementById('rm-cat-err-' + pos.catId);
        if (errBadge) {
            const catErrors = RM_ITEMS_SRC.filter(i => {
                // count distinct wrong drops on this category — just show current total errors
            });
            errBadge.textContent = 'Wrong!';
            errBadge.classList.add('visible');
            setTimeout(() => errBadge.classList.remove('visible'), 1200);
        }
    }
}

function checkComplete() {
    if (Object.keys(correctlyPlaced).length === RM_ITEMS_SRC.length) {
        finished = true;
        setTimeout(showCompleted, 500);
    }
}

/* ── Drop zones ── */
function setupDropZones() {
    RM_CATS.forEach(cat => {
        const catEl = document.getElementById('rm-cat-' + cat.id);
        if (!catEl) return;
        catEl.ondragover  = e => { e.preventDefault(); catEl.classList.add('drag-over'); };
        catEl.ondragleave = e => { if (!catEl.contains(e.relatedTarget)) catEl.classList.remove('drag-over'); };
        catEl.ondrop = e => {
            e.preventDefault();
            catEl.classList.remove('drag-over');
            if (finished) return;
            const itemId = parseInt(e.dataTransfer.getData('text/plain'), 10);
            if (!isNaN(itemId)) handleDrop(itemId, catEl, e.clientX, e.clientY);
        };
    });
}

/* ── Drag ── */
let dragItemId = null;
function onDragStart(e) {
    if (finished) { e.preventDefault(); return; }
    dragItemId = parseInt(e.currentTarget.dataset.itemId, 10);
    e.dataTransfer.setData('text/plain', dragItemId);
    e.dataTransfer.effectAllowed = 'move';
    setTimeout(() => e.currentTarget.classList.add('dragging'), 0);
}
function onDragEnd(e) {
    e.currentTarget.classList.remove('dragging');
    dragItemId = null;
}

/* ── Touch drag ── */
let touchItemId = null;
let touchItemEl = null;

function onTouchStart(e) {
    if (finished || e.touches.length !== 1) return;
    e.preventDefault();
    const el = e.currentTarget;
    touchItemId = parseInt(el.dataset.itemId, 10);
    touchItemEl = el;
    ghostEl.innerHTML = el.innerHTML;
    ghostEl.style.display = 'block';
    moveGhost(e.touches[0]);
    el.classList.add('dragging');
    document.addEventListener('touchmove', onTouchMove, {passive: false});
    document.addEventListener('touchend',  onTouchEnd,  {passive: false});
}
function moveGhost(t) {
    ghostEl.style.left = (t.clientX - CHIP_HALF) + 'px';
    ghostEl.style.top  = (t.clientY - CHIP_HALF) + 'px';
}
function onTouchMove(e) {
    if (!touchItemEl) return;
    e.preventDefault();
    moveGhost(e.touches[0]);
    document.querySelectorAll('.rm-cat-card.drag-over')
        .forEach(el => el.classList.remove('drag-over'));
    const catEl = catZoneFromPoint(e.touches[0].clientX, e.touches[0].clientY);
    if (catEl) catEl.classList.add('drag-over');
}
function onTouchEnd(e) {
    document.removeEventListener('touchmove', onTouchMove);
    document.removeEventListener('touchend',  onTouchEnd);
    ghostEl.style.display = 'none';
    document.querySelectorAll('.rm-cat-card.drag-over')
        .forEach(el => el.classList.remove('drag-over'));
    if (!touchItemEl) return;
    touchItemEl.classList.remove('dragging');
    const t = e.changedTouches[0];
    const catEl = catZoneFromPoint(t.clientX, t.clientY);
    if (catEl && !finished) handleDrop(touchItemId, catEl, t.clientX, t.clientY);
    touchItemEl = null;
    touchItemId = null;
}
function catZoneFromPoint(x, y) {
    return [...document.querySelectorAll('.rm-cat-card')].find(z => {
        const r = z.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }) || null;
}

/* ── Completed ── */
async function showCompleted() {
    const total = RM_ITEMS_SRC.length;
    const correct = total; // all placed = all correct
    const pct = total > 0 ? Math.round((total / (total + errors)) * 100) : 0;

    document.getElementById('rm-layout').style.display = 'none';
    scoreGridEl.style.display = 'none';

    if (sCorrectEl) sCorrectEl.textContent = correct;
    if (sWrongEl)   sWrongEl.textContent   = errors;
    if (sPctEl)     sPctEl.textContent     = pct + '%';
    scoreGridEl.classList.add('visible');

    if (compTitleEl) compTitleEl.textContent = RM_TITLE || 'Review Match';
    if (compTextEl)  compTextEl.textContent  = "You've completed this activity. Great job!";
    if (compScoreEl) compScoreEl.textContent = correct + ' correct · ' + errors + ' errors · ' + pct + '%';
    if (csCorrectEl) csCorrectEl.textContent = correct;
    if (csWrongEl)   csWrongEl.textContent   = errors;
    if (csPctEl)     csPctEl.textContent     = pct + '%';
    if (csgridEl)    csgridEl.style.display  = 'grid';

    completedEl.classList.add('active');
    playSound(doneSound);

    /* persist score */
    if (RM_ACT_ID && RM_RETURN_TO) {
        const sep = RM_RETURN_TO.includes('?') ? '&' : '?';
        const url = RM_RETURN_TO + sep
            + 'activity_percent=' + pct
            + '&activity_errors=' + errors
            + '&activity_total='  + total
            + '&activity_id='     + encodeURIComponent(RM_ACT_ID)
            + '&activity_type=review_match';
        try {
            const r = await fetch(url, {method:'GET', credentials:'same-origin', cache:'no-store'});
            if (!r.ok) window.location.href = url;
        } catch(e) {
            window.location.href = url;
        }
    }
}

/* ── restart ── */
function restartActivity() {
    initState();
    document.getElementById('rm-layout').style.display = '';
    scoreGridEl.classList.remove('visible');
    scoreGridEl.style.display = '';
    completedEl.classList.remove('active');
    render();
}

/* ── wire ── */
if (restartBtn) restartBtn.addEventListener('click', restartActivity);
if (backBtn) {
    backBtn.addEventListener('click', () => {
        try {
            if (window.top && window.top !== window.self) {
                window.top.location.href = RM_RETURN_TO;
                return;
            }
        } catch(e) {}
        window.location.href = RM_RETURN_TO;
    });
}

/* ── boot ── */
initState();
render();

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🖼️', $content);

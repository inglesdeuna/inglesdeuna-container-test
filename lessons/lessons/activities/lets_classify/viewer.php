<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$returnTo   = isset($_GET['return_to'])  ? trim((string) $_GET['return_to'])  : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

/* ── helpers ────────────────────────────────────────────────── */
function lc_resolve_unit(PDO $pdo, string $id): string
{
    if ($id === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row['unit_id'] ?? '') : '';
}

function lc_normalize(mixed $raw): array
{
    $default = [
        'title'        => "Let's Classify",
        'instructions' => '',
        'cefr'         => 'A1',
        'label_mode'   => 'both',
        'categories'   => [],
        'items'        => [],
    ];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;
    return array_merge($default, $d);
}

function lc_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = array_merge(lc_normalize(null), ['id' => '']);
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='lets_classify' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:u AND type='lets_classify' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['u' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $p = lc_normalize($row['data'] ?? null);
    $p['id'] = (string)($row['id'] ?? '');
    return $p;
}

/* ── load ───────────────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') {
    $unit = lc_resolve_unit($pdo, $activityId);
}

$activity = lc_load($pdo, $activityId, $unit);
if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

$viewerTitle  = (string)($activity['title']        ?? "Let's Classify");
$instructions = (string)($activity['instructions'] ?? '');
$labelMode    = (string)($activity['label_mode']   ?? 'both');   // image | name | both
$categories   = is_array($activity['categories'])  ? array_values($activity['categories']) : [];
$items        = is_array($activity['items'])        ? array_values($activity['items'])      : [];

if (count($categories) < 2) die('No categories found for this activity');
if (count($items) < 2)      die('No items found for this activity');

// Shuffle items for the student pool
shuffle($items);

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --lc-orange:#F97316;--lc-purple:#7F77DD;--lc-purple-dark:#534AB7;
    --lc-purple-soft:#EEEDFE;--lc-lila:#EDE9FA;--lc-muted:#9B94BE;
    --lc-green:#16a34a;--lc-green-soft:#f0fdf4;--lc-green-dark:#15803d;
    --lc-red:#ef4444;--lc-red-soft:#fef2f2;--lc-red-dark:#b91c1c;
}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:0;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;min-height:0!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

/* page scaffold */
.lc-page{width:100%;flex:1;overflow-y:auto;padding:clamp(14px,2.5vw,34px);box-sizing:border-box;background:#fff}
.lc-app{width:min(1060px,100%);margin:0 auto}
.lc-page.is-completed{align-items:flex-start}

/* topbar */
.lc-topbar{height:36px;display:flex;align-items:center;justify-content:center;margin-bottom:6px}
.lc-topbar-title{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}

/* hero */
.lc-hero{text-align:center;margin-bottom:clamp(12px,2vw,22px)}
.lc-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.lc-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(26px,4.5vw,48px);font-weight:700;color:var(--lc-orange);margin:0;line-height:1.05}
.lc-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.7vw,16px);font-weight:800;color:#9B94BE;margin:8px 0 0}

/* stage card */
.lc-stage{background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(14px,2vw,24px);box-shadow:0 8px 40px rgba(127,119,221,.12);box-sizing:border-box}

/* categories grid */
.lc-cats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px}
.lc-cat-zone{position:relative;border:2px dashed #CDC7F3;border-radius:18px;min-height:180px;padding:12px;box-sizing:border-box;background:#FAFAFE;transition:border-color .15s,background .15s,box-shadow .15s;display:flex;flex-direction:column}
.lc-cat-zone.drag-over{border-color:var(--lc-purple);background:#F3F2FF;box-shadow:0 4px 18px rgba(127,119,221,.15)}
.lc-cat-bg{position:absolute;inset:0;border-radius:16px;background-size:cover;background-position:center;opacity:.18;pointer-events:none}
.lc-cat-header{position:relative;z-index:1;display:flex;align-items:center;gap:8px;margin-bottom:8px}
.lc-cat-img-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;flex-shrink:0;border:1.5px solid #EDE9FA}
.lc-cat-name{font-family:'Nunito',sans-serif;font-size:14px;font-weight:900;color:#1E1B3A}
.lc-cat-drop-area{position:relative;z-index:1;flex:1;display:flex;flex-wrap:wrap;gap:8px;align-content:flex-start;min-height:100px}
.lc-cat-empty-hint{color:#9B94BE;font-size:12px;font-weight:700;font-style:normal;pointer-events:none;align-self:center;width:100%;text-align:center;padding:10px 0}

/* item pool */
.lc-pool-label{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.lc-pool{display:flex;flex-wrap:wrap;gap:10px;min-height:64px;padding:14px;border:2px dashed #EDE9FA;border-radius:16px;background:#fff;transition:border-color .15s,background .15s}
.lc-pool.drag-over{border-color:var(--lc-purple);background:#F9F9FF}
.lc-pool-empty-hint{color:#9B94BE;font-size:13px;font-weight:700;font-style:normal;align-self:center}

/* item card */
.lc-item{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:4px;padding:8px 10px;background:#fff;border:1.5px solid #CDC7F3;border-bottom-width:4px;border-radius:12px;cursor:grab;user-select:none;min-width:80px;max-width:110px;box-shadow:0 2px 0 rgba(127,119,221,.14);transition:transform .12s,box-shadow .12s,border-color .12s,opacity .12s}
.lc-item:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(127,119,221,.18);border-color:#AFA6EA}
.lc-item:active{cursor:grabbing;transform:translateY(1px)}
.lc-item.dragging{opacity:.45;transform:scale(.96)}
.lc-item img{width:60px;height:60px;object-fit:contain;border-radius:8px;pointer-events:none}
.lc-item .lc-item-lbl{font-family:'Nunito',sans-serif;font-size:11px;font-weight:900;color:#1E1B3A;text-align:center;word-break:break-word;max-width:90px;line-height:1.3;pointer-events:none}
.lc-item.is-correct{border-color:var(--lc-green);border-bottom-color:var(--lc-green);background:var(--lc-green-soft);cursor:default}
.lc-item.is-correct .lc-item-lbl{color:var(--lc-green-dark)}
.lc-item.is-wrong{border-color:var(--lc-red);border-bottom-color:var(--lc-red);background:var(--lc-red-soft);cursor:default}
.lc-item.is-wrong .lc-item-lbl{color:var(--lc-red-dark)}
.lc-item .lc-result-icon{font-size:16px;line-height:1;display:none}
.lc-item.is-correct .lc-result-icon,.lc-item.is-wrong .lc-result-icon{display:block}

/* score grid */
.lc-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.lc-score-grid.visible{display:grid}
.lc-score-card{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}
.lc-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:26px;line-height:1}
.lc-score-num.c{color:var(--lc-green)}
.lc-score-num.w{color:var(--lc-red)}
.lc-score-num.p{color:var(--lc-purple)}
.lc-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:#9B94BE;text-transform:uppercase;letter-spacing:.08em}

/* controls */
.lc-controls{border-top:1px solid #F0EEF8;margin-top:16px;padding-top:16px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
.lc-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 26px;border:none;border-radius:8px;color:#fff;cursor:pointer;font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:13px;line-height:1;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s,box-shadow .12s}
.lc-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.lc-btn:active{transform:scale(.98)}
.lc-btn-check{background:var(--lc-orange);box-shadow:0 6px 18px rgba(249,115,22,.22)}
.lc-btn-restart{background:var(--lc-purple)}
.lc-btn:disabled{opacity:.5;pointer-events:none}

/* completed screen */
.lc-completed{display:none;text-align:center;padding:28px 12px;max-width:520px;margin:0 auto}
.lc-completed.active{display:block}
.lc-completed-icon{font-size:34px;line-height:1;margin-bottom:8px}
.lc-completed-title{margin:0 0 6px;color:var(--lc-orange);font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:34px;font-weight:700}
.lc-completed-text{color:#9B94BE;font-size:14px;font-weight:800;line-height:1.5;margin:0}
.lc-completed-score{font-family:'Nunito',sans-serif;font-size:15px;font-weight:900;color:var(--lc-purple-dark);margin:6px 0 14px}
.lc-completed .lc-score-grid{margin-bottom:16px}
.lc-completed-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.lc-completed-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;border:none;border-radius:8px;cursor:pointer;min-width:120px;font-weight:700;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;line-height:1;transition:transform .12s,filter .12s;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.lc-completed-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.lc-completed-btn.restart{background:var(--lc-purple);color:#fff}
.lc-completed-btn.back{background:#F0EEF8;color:var(--lc-purple-dark)}

/* ghost for touch drag */
#lc-ghost{position:fixed;pointer-events:none;z-index:9999;display:none;border-radius:12px;box-shadow:0 8px 28px rgba(127,119,221,.3);opacity:.9}

@media(max-width:640px){
    .lc-cats-grid{grid-template-columns:1fr 1fr}
    .lc-item{min-width:68px;max-width:90px;padding:6px 8px}
    .lc-item img{width:44px;height:44px}
    .lc-controls{flex-direction:column;gap:8px}
    .lc-btn{width:100%}
    .lc-score-grid{grid-template-columns:1fr}
}
</style>

<?php
$catsJson  = json_encode($categories,  JSON_UNESCAPED_UNICODE);
$itemsJson = json_encode($items,        JSON_UNESCAPED_UNICODE);
$colCount  = min(4, max(2, count($categories)));
?>

<div class="lc-page" id="lc-page">
<div class="lc-app" id="lc-app">

    <div class="lc-topbar"><span class="lc-topbar-title">Let's Classify</span></div>

    <div class="lc-hero">
        <div class="lc-kicker">Activity</div>
        <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($instructions !== ''): ?>
            <p><?php echo htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div class="lc-stage" id="lc-stage">

        <!-- categories drop zones -->
        <div class="lc-cats-grid" id="lc-cats-grid" style="grid-template-columns:repeat(<?php echo $colCount; ?>,1fr)">
            <?php foreach ($categories as $cat):
                $catId   = (int)($cat['id']    ?? 0);
                $catName = htmlspecialchars((string)($cat['name']  ?? ''), ENT_QUOTES, 'UTF-8');
                $catImg  = htmlspecialchars((string)($cat['image'] ?? ''), ENT_QUOTES, 'UTF-8');
                $showImg  = in_array($labelMode, ['image', 'both'], true);
                $showName = in_array($labelMode, ['name',  'both'], true);
            ?>
            <div class="lc-cat-zone" id="lc-cat-<?php echo $catId; ?>" data-cat="<?php echo $catId; ?>">
                <?php if ($catImg !== ''): ?>
                    <div class="lc-cat-bg" style="background-image:url('<?php echo $catImg; ?>')"></div>
                <?php endif; ?>
                <div class="lc-cat-header">
                    <?php if ($showImg && $catImg !== ''): ?>
                        <img class="lc-cat-img-thumb" src="<?php echo $catImg; ?>" alt="<?php echo $catName; ?>">
                    <?php endif; ?>
                    <?php if ($showName): ?>
                        <span class="lc-cat-name"><?php echo $catName; ?></span>
                    <?php endif; ?>
                </div>
                <div class="lc-cat-drop-area" id="lc-drop-<?php echo $catId; ?>">
                    <span class="lc-cat-empty-hint">Drop here</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- item pool -->
        <div class="lc-pool-label">Items to classify</div>
        <div class="lc-pool" id="lc-pool">
            <!-- items rendered by JS -->
        </div>

        <!-- score grid (shown after Check) -->
        <div id="lc-score-grid" class="lc-score-grid">
            <div class="lc-score-card"><div class="lc-score-num c" id="lc-s-correct">0</div><div class="lc-score-lbl">Correct</div></div>
            <div class="lc-score-card"><div class="lc-score-num w" id="lc-s-wrong">0</div><div class="lc-score-lbl">Wrong</div></div>
            <div class="lc-score-card"><div class="lc-score-num p" id="lc-s-pct">0%</div><div class="lc-score-lbl">Score</div></div>
        </div>

        <!-- controls -->
        <div class="lc-controls" id="lc-controls">
            <button type="button" class="lc-btn lc-btn-check" id="lc-check-btn">Check</button>
        </div>

        <!-- completed screen (inside stage) -->
        <div class="lc-completed" id="lc-completed">
            <div class="lc-completed-icon">✅</div>
            <h2 class="lc-completed-title" id="lc-completed-title">Completed!</h2>
            <div id="lc-completed-score-grid" class="lc-score-grid" style="max-width:360px;margin:0 auto 12px">
                <div class="lc-score-card"><div class="lc-score-num c" id="lc-cs-correct">0</div><div class="lc-score-lbl">Correct</div></div>
                <div class="lc-score-card"><div class="lc-score-num w" id="lc-cs-wrong">0</div><div class="lc-score-lbl">Wrong</div></div>
                <div class="lc-score-card"><div class="lc-score-num p" id="lc-cs-pct">0%</div><div class="lc-score-lbl">Score</div></div>
            </div>
            <p class="lc-completed-text" id="lc-completed-text"></p>
            <p class="lc-completed-score" id="lc-completed-score-line"></p>
            <div class="lc-completed-actions">
                <button type="button" class="lc-completed-btn restart" id="lc-restart-btn">Restart</button>
                <?php if ($returnTo !== ''): ?>
                <button type="button" class="lc-completed-btn back" id="lc-back-btn">Back</button>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /lc-stage -->
</div><!-- /lc-app -->
</div><!-- /lc-page -->

<!-- ghost element for touch drag -->
<div id="lc-ghost" class="lc-item"></div>

<audio id="lc-win-sound"  src="../../hangman/assets/win.mp3"       preload="auto"></audio>
<audio id="lc-done-sound" src="../../hangman/assets/win (1).mp3"   preload="auto"></audio>
<audio id="lc-item-audio" preload="none"></audio>

<script>
(function () {
'use strict';

/* ── data ── */
const LC_CATS      = <?= $catsJson ?>;
const LC_ITEMS_SRC = <?= $itemsJson ?>;
const LC_LABEL_MODE = <?= json_encode($labelMode, JSON_UNESCAPED_UNICODE) ?>;
const LC_TITLE      = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const LC_RETURN_TO  = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const LC_ACT_ID     = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

/* ── state ── */
// placement[itemId] = categoryId | null (null = pool)
let placement = {};
let checked   = false;
let correct   = 0;
let wrong     = 0;

/* ── DOM refs ── */
const poolEl       = document.getElementById('lc-pool');
const scoreGridEl  = document.getElementById('lc-score-grid');
const sCorrectEl   = document.getElementById('lc-s-correct');
const sWrongEl     = document.getElementById('lc-s-wrong');
const sPctEl       = document.getElementById('lc-s-pct');
const checkBtn     = document.getElementById('lc-check-btn');
const completedEl  = document.getElementById('lc-completed');
const compTitleEl  = document.getElementById('lc-completed-title');
const compTextEl   = document.getElementById('lc-completed-text');
const compScoreEl  = document.getElementById('lc-completed-score-line');
const csgridEl     = document.getElementById('lc-completed-score-grid');
const csCorrectEl  = document.getElementById('lc-cs-correct');
const csWrongEl    = document.getElementById('lc-cs-wrong');
const csPctEl      = document.getElementById('lc-cs-pct');
const controlsEl   = document.getElementById('lc-controls');
const restartBtn   = document.getElementById('lc-restart-btn');
const backBtn      = document.getElementById('lc-back-btn');
const ghostEl      = document.getElementById('lc-ghost');
const winSound     = document.getElementById('lc-win-sound');
const doneSound    = document.getElementById('lc-done-sound');
const itemAudio    = document.getElementById('lc-item-audio');

/* ── init placement ── */
function initPlacement() {
    placement = {};
    LC_ITEMS_SRC.forEach(item => { placement[item.id] = null; });
}

/* ── render helpers ── */
function buildItemEl(item) {
    const el = document.createElement('div');
    el.className = 'lc-item';
    el.dataset.itemId = item.id;
    el.dataset.catId  = item.category_id;
    el.draggable = true;

    let inner = '';
    if (item.image) {
        inner += `<img src="${item.image}" alt="${escHtml(item.name)}" loading="lazy">`;
    }
    if (LC_LABEL_MODE !== 'image' || !item.image) {
        inner += `<span class="lc-item-lbl">${escHtml(item.name)}</span>`;
    }
    inner += `<span class="lc-result-icon" aria-hidden="true"></span>`;
    el.innerHTML = inner;

    /* desktop drag */
    el.addEventListener('dragstart', onDragStart);
    el.addEventListener('dragend',   onDragEnd);

    /* audio on click */
    if (item.audio) {
        el.addEventListener('click', () => playItemAudio(item.audio));
        el.style.cursor = 'pointer';
        el.title = 'Click to hear';
    }

    /* touch */
    el.addEventListener('touchstart', onTouchStart, {passive: false});

    return el;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function render() {
    /* pool */
    poolEl.innerHTML = '';
    const poolItems = LC_ITEMS_SRC.filter(i => placement[i.id] === null);
    if (poolItems.length === 0) {
        poolEl.innerHTML = '<span class="lc-pool-empty-hint">All items placed ✓</span>';
    } else {
        poolItems.forEach(item => poolEl.appendChild(buildItemEl(item)));
    }

    /* category drop areas */
    LC_CATS.forEach(cat => {
        const dropArea = document.getElementById('lc-drop-' + cat.id);
        if (!dropArea) return;
        dropArea.innerHTML = '';
        const catItems = LC_ITEMS_SRC.filter(i => placement[i.id] === cat.id);
        if (catItems.length === 0) {
            dropArea.innerHTML = '<span class="lc-cat-empty-hint">Drop here</span>';
        } else {
            catItems.forEach(item => {
                const el = buildItemEl(item);
                if (checked) applyFeedback(el, item);
                dropArea.appendChild(el);
            });
        }
    });

    /* drop zone listeners */
    setupDropZones();
}

function applyFeedback(el, item) {
    const isCorrect = placement[item.id] !== null && placement[item.id] === item.category_id;
    el.classList.toggle('is-correct', isCorrect);
    el.classList.toggle('is-wrong',   !isCorrect && placement[item.id] !== null);
    const icon = el.querySelector('.lc-result-icon');
    if (icon) icon.textContent = isCorrect ? '✅' : '❌';
    el.draggable = false;
}

/* ── drop zones ── */
function setupDropZones() {
    /* category zones */
    LC_CATS.forEach(cat => {
        const zone = document.getElementById('lc-cat-' + cat.id);
        if (!zone) return;
        zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('drag-over');
            const itemId = parseInt(e.dataTransfer.getData('text/plain'), 10);
            if (!isNaN(itemId) && !checked) {
                placement[itemId] = cat.id;
                render();
            }
        });
    });
    /* pool zone */
    poolEl.addEventListener('dragover',  e => { e.preventDefault(); poolEl.classList.add('drag-over'); });
    poolEl.addEventListener('dragleave', () => poolEl.classList.remove('drag-over'));
    poolEl.addEventListener('drop', e => {
        e.preventDefault();
        poolEl.classList.remove('drag-over');
        const itemId = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (!isNaN(itemId) && !checked) {
            placement[itemId] = null;
            render();
        }
    });
}

/* ── drag events ── */
let dragItemId = null;
function onDragStart(e) {
    if (checked) { e.preventDefault(); return; }
    dragItemId = parseInt(e.currentTarget.dataset.itemId, 10);
    e.dataTransfer.setData('text/plain', dragItemId);
    e.dataTransfer.effectAllowed = 'move';
    e.currentTarget.classList.add('dragging');
}
function onDragEnd(e) {
    e.currentTarget.classList.remove('dragging');
    dragItemId = null;
}

/* ── touch drag ── */
let touchItemId  = null;
let touchItemEl  = null;

function onTouchStart(e) {
    if (checked || e.touches.length !== 1) return;
    e.preventDefault();
    const el = e.currentTarget;
    touchItemId = parseInt(el.dataset.itemId, 10);
    touchItemEl = el;
    /* ghost */
    ghostEl.innerHTML = el.innerHTML;
    ghostEl.style.width  = el.offsetWidth  + 'px';
    ghostEl.style.height = el.offsetHeight + 'px';
    ghostEl.style.display = 'flex';
    moveGhost(e.touches[0]);
    el.classList.add('dragging');
    document.addEventListener('touchmove', onTouchMove, {passive: false});
    document.addEventListener('touchend',  onTouchEnd,  {passive: false});
}
function moveGhost(t) {
    ghostEl.style.left = (t.clientX - ghostEl.offsetWidth  / 2) + 'px';
    ghostEl.style.top  = (t.clientY - ghostEl.offsetHeight / 2) + 'px';
}
function onTouchMove(e) {
    if (!touchItemEl) return;
    e.preventDefault();
    moveGhost(e.touches[0]);
    clearDragOver();
    const el = zoneFromPoint(e.touches[0].clientX, e.touches[0].clientY);
    if (el) el.classList.add('drag-over');
}
function onTouchEnd(e) {
    document.removeEventListener('touchmove', onTouchMove);
    document.removeEventListener('touchend',  onTouchEnd);
    ghostEl.style.display = 'none';
    clearDragOver();
    if (!touchItemEl) return;
    touchItemEl.classList.remove('dragging');
    const t = e.changedTouches[0];
    const catZone = catZoneFromPoint(t.clientX, t.clientY);
    if (catZone) {
        const catId = parseInt(catZone.dataset.cat, 10);
        if (!isNaN(catId)) placement[touchItemId] = catId;
    } else if (poolFromPoint(t.clientX, t.clientY)) {
        placement[touchItemId] = null;
    }
    touchItemEl = null;
    touchItemId = null;
    render();
}
function clearDragOver() {
    document.querySelectorAll('.lc-cat-zone.drag-over, .lc-pool.drag-over')
        .forEach(el => el.classList.remove('drag-over'));
}
function zoneFromPoint(x, y) {
    return catZoneFromPoint(x, y) || (poolFromPoint(x, y) ? poolEl : null);
}
function catZoneFromPoint(x, y) {
    return [...document.querySelectorAll('.lc-cat-zone')].find(z => {
        const r = z.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }) || null;
}
function poolFromPoint(x, y) {
    const r = poolEl.getBoundingClientRect();
    return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
}

/* ── audio ── */
function playItemAudio(url) {
    itemAudio.src = url;
    itemAudio.currentTime = 0;
    itemAudio.play().catch(() => {});
}
function playSound(el) {
    try { el.currentTime = 0; el.play(); } catch(e) {}
}

/* ── check ── */
function checkAnswers() {
    const placed = LC_ITEMS_SRC.filter(i => placement[i.id] !== null);
    if (placed.length < LC_ITEMS_SRC.length) {
        alert('Place all items before checking!');
        return;
    }
    checked = true;
    correct = LC_ITEMS_SRC.filter(i => placement[i.id] === i.category_id).length;
    wrong   = LC_ITEMS_SRC.length - correct;
    const pct = LC_ITEMS_SRC.length > 0 ? Math.round((correct / LC_ITEMS_SRC.length) * 100) : 0;

    /* update score grid */
    sCorrectEl.textContent = correct;
    sWrongEl.textContent   = wrong;
    sPctEl.textContent     = pct + '%';
    scoreGridEl.classList.add('visible');

    /* render with feedback */
    render();

    /* disable check, add next btn */
    checkBtn.disabled = true;
    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'lc-btn lc-btn-check';
    nextBtn.textContent = 'Finish';
    nextBtn.addEventListener('click', showCompleted);
    controlsEl.appendChild(nextBtn);

    if (wrong === 0) {
        playSound(winSound);
    }
}

/* ── completed ── */
async function showCompleted() {
    const pct = LC_ITEMS_SRC.length > 0 ? Math.round((correct / LC_ITEMS_SRC.length) * 100) : 0;

    /* hide game UI */
    poolEl.style.display       = 'none';
    scoreGridEl.style.display  = 'none';
    controlsEl.style.display   = 'none';
    document.querySelector('.lc-pool-label').style.display = 'none';
    document.querySelector('.lc-cats-grid').style.display  = 'none';

    /* fill completed screen */
    if (compTitleEl) compTitleEl.textContent = LC_TITLE || "Let's Classify";
    if (compTextEl)  compTextEl.textContent  = "You've completed this activity. Great job!";
    if (compScoreEl) compScoreEl.textContent = correct + ' correct · ' + wrong + ' wrong · ' + pct + '%';
    if (csCorrectEl) csCorrectEl.textContent = correct;
    if (csWrongEl)   csWrongEl.textContent   = wrong;
    if (csPctEl)     csPctEl.textContent     = pct + '%';
    if (csgridEl)    csgridEl.style.display  = 'grid';

    completedEl.classList.add('active');
    playSound(doneSound);

    /* persist score */
    if (LC_ACT_ID && LC_RETURN_TO) {
        const sep = LC_RETURN_TO.includes('?') ? '&' : '?';
        const url = LC_RETURN_TO + sep
            + 'activity_percent=' + pct
            + '&activity_errors=' + wrong
            + '&activity_total='  + LC_ITEMS_SRC.length
            + '&activity_id='     + encodeURIComponent(LC_ACT_ID)
            + '&activity_type=lets_classify';
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
    checked = false;
    correct = 0;
    wrong   = 0;
    initPlacement();
    /* restore game UI */
    poolEl.style.display       = '';
    scoreGridEl.classList.remove('visible');
    scoreGridEl.style.display  = '';
    controlsEl.style.display   = '';
    document.querySelector('.lc-pool-label').style.display = '';
    document.querySelector('.lc-cats-grid').style.display  = '';
    completedEl.classList.remove('active');
    checkBtn.disabled = false;
    /* remove extra buttons added during check */
    controlsEl.querySelectorAll('button:not(#lc-check-btn)').forEach(b => b.remove());
    render();
}

/* ── wire buttons ── */
checkBtn.addEventListener('click', checkAnswers);
if (restartBtn) restartBtn.addEventListener('click', restartActivity);
if (backBtn) {
    backBtn.addEventListener('click', () => {
        try {
            if (window.top && window.top !== window.self) {
                window.top.location.href = LC_RETURN_TO;
                return;
            }
        } catch(e) {}
        window.location.href = LC_RETURN_TO;
    });
}

/* ── boot ── */
initPlacement();
render();

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🗂️', $content);

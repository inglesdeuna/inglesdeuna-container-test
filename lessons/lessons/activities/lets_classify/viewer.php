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
$labelMode    = (string)($activity['label_mode']   ?? 'both');
$categories   = is_array($activity['categories'])  ? array_values($activity['categories']) : [];
$items        = is_array($activity['items'])        ? array_values($activity['items'])      : [];

if (count($categories) < 2) die('No categories found for this activity');
if (count($items) < 2)      die('No items found for this activity');

// Shuffle items for the student pool
shuffle($items);

ob_start();

$catsJson  = json_encode($categories,  JSON_UNESCAPED_UNICODE);
$itemsJson = json_encode($items,        JSON_UNESCAPED_UNICODE);

// Palette for categories without images
$catPalette = [
    'linear-gradient(135deg,#7F77DD,#534AB7)',
    'linear-gradient(135deg,#F97316,#ea580c)',
    'linear-gradient(135deg,#16a34a,#15803d)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)',
    'linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#f59e0b,#d97706)',
];
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --lc-orange:#F97316;--lc-purple:#7F77DD;--lc-purple-dark:#534AB7;
    --lc-purple-soft:#EEEDFE;--lc-lila:#EDE9FA;--lc-muted:#9B94BE;
    --lc-green:#16a34a;--lc-green-soft:#f0fdf4;--lc-green-dark:#15803d;
    --lc-red:#ef4444;--lc-red-soft:#fef2f2;--lc-red-dark:#b91c1c;
    --lc-ink:#1E1B3A;
}
html,body{width:100%;height:100%;overflow:hidden}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:0;height:100%;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;min-height:0!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

/* page scaffold */
.lc-page{width:100%;flex:1;min-height:0;overflow:hidden;padding:clamp(8px,1.5vw,20px);box-sizing:border-box;background:#fff;display:flex;flex-direction:column}
.lc-app{width:min(1040px,100%);margin:0 auto;display:flex;flex-direction:column;flex:1;min-height:0}

/* topbar */
.lc-topbar{height:28px;display:flex;align-items:center;justify-content:center;margin-bottom:2px}
.lc-topbar-title{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}

/* hero */
.lc-hero{text-align:center;margin-bottom:clamp(4px,0.8vw,10px)}
.lc-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.lc-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(26px,4.5vw,48px);font-weight:700;color:var(--lc-orange);margin:0;line-height:1.05}
.lc-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.7vw,16px);font-weight:800;color:#9B94BE;margin:8px 0 0}

/* stage card */
.lc-stage{background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(10px,1.5vw,18px);box-shadow:0 8px 40px rgba(127,119,221,.12);box-sizing:border-box;flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column}

/* ── SINGLE GRID LAYOUT ── */
.lc-layout{display:grid;grid-template-columns:minmax(240px,.36fr) minmax(0,1fr);grid-template-areas:"pool cats";gap:16px;align-items:stretch;flex:1;min-height:0;overflow:hidden}
.lc-col-pool{grid-area:pool;min-width:0;display:flex;flex-direction:column;gap:10px;overflow:hidden;background:#FAFAFE;border:2px dashed #E5E1FA;border-radius:22px;padding:12px;box-sizing:border-box}
.lc-col-pool.drag-over,.lc-col-pool:has(.lc-pool.drag-over){border-color:var(--lc-purple);background:#F7F5FF;box-shadow:0 0 0 4px rgba(127,119,221,.10)}
.lc-col-cats{grid-area:cats;min-width:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-content:start;overflow:auto;padding:1px 3px 1px 1px}

/* ── CATEGORY CARDS / DROP ZONES ── */
.lc-cat-card{position:relative;border-radius:22px;overflow:hidden;min-height:clamp(210px,24vw,300px);background:linear-gradient(135deg,#7F77DD,#534AB7);box-shadow:0 7px 26px rgba(0,0,0,.14);transition:box-shadow .15s,outline-color .1s,transform .12s;cursor:default;user-select:none;display:flex;flex-direction:column}
.lc-cat-card.drag-over{box-shadow:0 10px 40px rgba(127,119,221,.46);outline:3px solid var(--lc-purple);outline-offset:2px;transform:translateY(-1px)}
.lc-cat-bg-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}
.lc-cat-gradient{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.10) 0%,rgba(0,0,0,.42) 48%,rgba(0,0,0,.68) 100%);pointer-events:none}
.lc-cat-name-badge{position:relative;z-index:3;margin:10px;align-self:center;max-width:calc(100% - 20px);padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.92);color:var(--lc-purple-dark);font-family:'Fredoka',sans-serif;font-size:clamp(15px,2vw,22px);font-weight:700;text-align:center;box-shadow:0 6px 18px rgba(0,0,0,.18);pointer-events:none}
.lc-cat-chips-area{position:relative;z-index:4;display:flex;align-content:flex-start;align-items:flex-start;justify-content:flex-start;flex-wrap:wrap;gap:6px;margin:8px 10px 10px;padding:8px;min-height:138px;flex:1;border:2px dashed rgba(255,255,255,.62);border-radius:18px;background:rgba(255,255,255,.18);backdrop-filter:blur(2px);box-sizing:border-box;pointer-events:none;overflow-y:auto;overflow-x:hidden}
.lc-cat-chips-area::-webkit-scrollbar{width:6px}.lc-cat-chips-area::-webkit-scrollbar-thumb{background:rgba(255,255,255,.55);border-radius:999px}
.lc-cat-chips-area::before{content:'Drop answers here';position:absolute;inset:auto 0 50%;transform:translateY(50%);text-align:center;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.04em;color:rgba(255,255,255,.78);pointer-events:none}
.lc-cat-chips-area:has(.lc-chip-sticker)::before{display:none}

/* placed chips: aligned, not floating */
.lc-chip-sticker{position:relative;pointer-events:auto;display:flex;align-items:center;justify-content:center;gap:6px;border-radius:12px;overflow:hidden;border:2px solid #fff;box-shadow:0 4px 14px rgba(0,0,0,.22);cursor:grab;background:#fff;transition:box-shadow .12s,border-color .12s,transform .12s;box-sizing:border-box;touch-action:none;max-width:100%}
.lc-chip-sticker:hover{box-shadow:0 7px 20px rgba(0,0,0,.30);transform:translateY(-1px)}
.lc-chip-sticker:active{cursor:grabbing}
.lc-chip-sticker.dragging{opacity:.45;transform:scale(.94)}
.lc-chip-sticker.has-image{width:clamp(76px,8vw,96px);height:clamp(76px,8vw,96px)}
.lc-chip-sticker.text-only{width:100%;min-height:auto;padding:7px 10px;flex:0 0 100%}
.lc-chip-sticker img{width:100%;height:100%;object-fit:contain;display:block;pointer-events:none;background:#fff}
.lc-chip-sticker .lc-chip-lbl{font-family:'Nunito',sans-serif;font-size:clamp(11px,1.08vw,13px);font-weight:900;text-align:center;line-height:1.14;pointer-events:none;color:var(--lc-ink);white-space:normal;overflow-wrap:break-word;word-break:normal;hyphens:auto}
.lc-chip-sticker.has-image .lc-chip-lbl{position:absolute;bottom:0;left:0;right:0;background:rgba(30,27,58,.76);color:#fff;font-size:10px;padding:4px 5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lc-chip-sticker.is-correct{border-color:var(--lc-green);cursor:default}
.lc-chip-sticker.is-wrong{border-color:var(--lc-red)}
.lc-chip-sticker .lc-result-icon{position:absolute;top:4px;right:5px;font-size:14px;line-height:1;display:none;filter:drop-shadow(0 1px 2px rgba(0,0,0,.28))}
.lc-chip-sticker.is-correct .lc-result-icon,.lc-chip-sticker.is-wrong .lc-result-icon{display:block}

/* ── POOL AREA ── */
.lc-pool-label{font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#9B94BE;letter-spacing:.08em;text-transform:uppercase;text-align:center}
.lc-pool{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));grid-auto-flow:dense;gap:8px;align-content:start;overflow:auto;padding:2px;min-height:0;flex:1}
.lc-pool.drag-over{background:#F9F9FF;border-radius:16px;box-shadow:inset 0 0 0 2px var(--lc-purple)}
.lc-pool-empty-hint{color:#9B94BE;font-size:13px;font-weight:800;padding:18px 8px;text-align:center;grid-column:1/-1}

/* individual pool chip */
.lc-pool-chip{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;background:#fff;border:2px solid #EDE9FA;border-bottom-width:4px;border-radius:14px;cursor:grab;user-select:none;min-width:0;box-shadow:0 3px 10px rgba(0,0,0,.09);transition:transform .12s,box-shadow .12s,border-color .12s;box-sizing:border-box;touch-action:none}
.lc-pool-chip:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.16);border-color:var(--lc-purple)}
.lc-pool-chip:active{cursor:grabbing;transform:translateY(1px)}
.lc-pool-chip.dragging{opacity:.4;transform:scale(.95)}
.lc-pool-chip.has-image{aspect-ratio:1;flex-direction:column}
.lc-pool-chip.text-only{grid-column:1/-1;min-height:auto;padding:8px 10px}
.lc-pool-chip img{width:100%;height:100%;min-height:0;object-fit:contain;border-radius:8px;pointer-events:none;background:#fff}
.lc-pool-chip .lc-pool-chip-lbl{font-family:'Nunito',sans-serif;font-size:clamp(11px,1.08vw,13px);font-weight:900;color:var(--lc-ink);pointer-events:none;line-height:1.14;text-align:center;max-width:100%;white-space:normal;overflow-wrap:break-word;hyphens:auto;flex-shrink:0}
.lc-pool-chip.has-image .lc-pool-chip-lbl{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* score grid */
.lc-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:8px}
.lc-score-grid.visible{display:grid}
.lc-score-card{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}
.lc-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:26px;line-height:1}
.lc-score-num.c{color:var(--lc-green)}
.lc-score-num.w{color:var(--lc-red)}
.lc-score-num.p{color:var(--lc-purple)}
.lc-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:#9B94BE;text-transform:uppercase;letter-spacing:.08em}

/* controls */
.lc-controls{border-top:1px solid #F0EEF8;margin-top:8px;padding-top:8px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;flex-shrink:0}
.lc-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 26px;border:none;border-radius:8px;color:#fff;cursor:pointer;font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:13px;line-height:1;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s,box-shadow .12s}
.lc-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.lc-btn:active{transform:scale(.98)}
.lc-btn-check{background:var(--lc-orange);box-shadow:0 6px 18px rgba(249,115,22,.22)}
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
#lc-ghost{position:fixed;pointer-events:none;z-index:9999;display:none;min-width:76px;max-width:220px;min-height:58px;border-radius:14px;box-shadow:0 8px 28px rgba(127,119,221,.3);opacity:.92;overflow:hidden;background:#fff;border:2px solid #fff}
#lc-ghost img{width:100%;height:100%;object-fit:contain;background:#fff}

@media(max-width:900px){
    .lc-layout{grid-template-columns:1fr;grid-template-areas:"pool" "cats";overflow:auto}
    .lc-col-pool{min-height:190px;overflow:visible}
    .lc-pool{grid-template-columns:repeat(auto-fill,minmax(90px,1fr));overflow:visible;max-height:none}
    .lc-col-cats{overflow:visible;grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:660px){
    html,body{overflow:auto}
    .lc-page{overflow:auto}
    .lc-stage{overflow:visible}
    .lc-pool{grid-template-columns:repeat(3,1fr)}
    .lc-pool-chip.text-only{grid-column:1/-1}
    .lc-col-cats{grid-template-columns:1fr}
    .lc-cat-card{min-height:240px}
    .lc-controls{flex-direction:column;gap:8px}
    .lc-btn{width:100%}
    .lc-score-grid{grid-template-columns:1fr}
}
</style>

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
        <div class="lc-layout" id="lc-layout">

            <!-- Items pool inside the same main grid -->
            <div class="lc-col-pool" id="lc-col-pool">
                <div class="lc-pool-label">Sort these items</div>
                <div class="lc-pool" id="lc-pool"></div>
            </div>

            <!-- Category zones inside the same main grid -->
            <div class="lc-col-cats" id="lc-col-cats">
                <?php foreach ($categories as $idx => $cat):
                    $catId    = (int)($cat['id']    ?? 0);
                    $catName  = htmlspecialchars((string)($cat['name']  ?? ''), ENT_QUOTES, 'UTF-8');
                    $catImg   = htmlspecialchars((string)($cat['image'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $showImg  = in_array($labelMode, ['image', 'both'], true);
                    $showName = in_array($labelMode, ['name',  'both'], true);
                    $hasImg   = $showImg && $catImg !== '';
                    $bgStyle  = !$hasImg ? 'style="background:' . $catPalette[$idx % count($catPalette)] . '"' : '';
                ?>
                <div class="lc-cat-card" id="lc-cat-<?php echo $catId; ?>" data-cat="<?php echo $catId; ?>" <?php echo $bgStyle; ?>>
                    <?php if ($hasImg): ?>
                        <img class="lc-cat-bg-img" src="<?php echo $catImg; ?>" alt="<?php echo $catName; ?>">
                        <div class="lc-cat-gradient"></div>
                    <?php endif; ?>
                    <?php if ($showName): ?>
                        <div class="lc-cat-name-badge"><?php echo $catName; ?></div>
                    <?php endif; ?>
                    <div class="lc-cat-chips-area" id="lc-chips-<?php echo $catId; ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
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

        <!-- completed screen -->
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

<!-- ghost for touch drag -->
<div id="lc-ghost"></div>

<audio id="lc-win-sound"  src="../../hangman/assets/win.mp3"       preload="auto"></audio>
<audio id="lc-done-sound" src="../../hangman/assets/win (1).mp3"   preload="auto"></audio>
<audio id="lc-item-audio" preload="none"></audio>

<script>
(function () {
'use strict';

/* ── data ── */
const LC_CATS       = <?= $catsJson ?>;
const LC_ITEMS_SRC  = <?= $itemsJson ?>;
const LC_LABEL_MODE = <?= json_encode($labelMode, JSON_UNESCAPED_UNICODE) ?>;
const LC_TITLE      = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const LC_RETURN_TO  = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const LC_ACT_ID     = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

/* ── state ── */
// placement[itemId] = null (pool) | { catId } (placed in category grid)
let placement = {};
let checked   = false;
let correct   = 0;
let wrong     = 0;

/* ── DOM refs ── */
const poolEl      = document.getElementById('lc-pool');
const poolColEl   = document.getElementById('lc-col-pool');
const scoreGridEl = document.getElementById('lc-score-grid');
const sCorrectEl  = document.getElementById('lc-s-correct');
const sWrongEl    = document.getElementById('lc-s-wrong');
const sPctEl      = document.getElementById('lc-s-pct');
const checkBtn    = document.getElementById('lc-check-btn');
const completedEl = document.getElementById('lc-completed');
const compTitleEl = document.getElementById('lc-completed-title');
const compTextEl  = document.getElementById('lc-completed-text');
const compScoreEl = document.getElementById('lc-completed-score-line');
const csgridEl    = document.getElementById('lc-completed-score-grid');
const csCorrectEl = document.getElementById('lc-cs-correct');
const csWrongEl   = document.getElementById('lc-cs-wrong');
const csPctEl     = document.getElementById('lc-cs-pct');
const controlsEl  = document.getElementById('lc-controls');
const restartBtn  = document.getElementById('lc-restart-btn');
const backBtn     = document.getElementById('lc-back-btn');
const ghostEl     = document.getElementById('lc-ghost');
const winSound    = document.getElementById('lc-win-sound');
const doneSound   = document.getElementById('lc-done-sound');
const itemAudio   = document.getElementById('lc-item-audio');

const GHOST_HALF = 42;

function initPlacement() {
    placement = {};
    LC_ITEMS_SRC.forEach(item => { placement[item.id] = null; });
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function hasVisibleImage(item) {
    return Boolean(item.image && LC_LABEL_MODE !== 'name');
}

function chipTypeClass(item) {
    return hasVisibleImage(item) ? 'has-image' : 'text-only';
}

/* ── Pool chip ── */
function buildPoolChip(item) {
    const el = document.createElement('div');
    el.className = 'lc-pool-chip ' + chipTypeClass(item);
    el.dataset.itemId = item.id;
    el.dataset.catId  = item.category_id;
    el.draggable = true;

    let inner = '';
    if (hasVisibleImage(item)) {
        inner += `<img src="${escHtml(item.image)}" alt="${escHtml(item.name)}" loading="lazy">`;
    }
    if (LC_LABEL_MODE !== 'image' || !item.image) {
        inner += `<span class="lc-pool-chip-lbl">${escHtml(item.name)}</span>`;
    }
    el.innerHTML = inner;

    el.addEventListener('dragstart', onDragStart);
    el.addEventListener('dragend',   onDragEnd);
    el.addEventListener('touchstart', onTouchStart, {passive: false});

    if (item.audio) {
        el.addEventListener('click', () => { if (!dragItemId) playItemAudio(item.audio); });
    }
    return el;
}

/* ── Placed chip ── */
function buildStickerChip(item, pos) {
    const el = document.createElement('div');
    el.className = 'lc-chip-sticker ' + chipTypeClass(item);
    el.dataset.itemId = item.id;
    el.dataset.catId  = item.category_id;

    let inner = '';
    if (hasVisibleImage(item)) {
        inner += `<img src="${escHtml(item.image)}" alt="${escHtml(item.name)}" loading="lazy">`;
    }
    if (LC_LABEL_MODE === 'name' || LC_LABEL_MODE === 'both') {
        inner += `<span class="lc-chip-lbl">${escHtml(item.name)}</span>`;
    }
    inner += `<span class="lc-result-icon" aria-hidden="true"></span>`;
    el.innerHTML = inner;

    if (!checked) {
        el.draggable = true;
        el.addEventListener('dragstart', onDragStart);
        el.addEventListener('dragend',   onDragEnd);
        el.addEventListener('touchstart', onTouchStart, {passive: false});
    } else {
        const isCorrect = pos.catId === item.category_id;
        el.classList.toggle('is-correct', isCorrect);
        el.classList.toggle('is-wrong',   !isCorrect);
        const icon = el.querySelector('.lc-result-icon');
        if (icon) icon.textContent = isCorrect ? '✅' : '❌';
    }
    return el;
}

function render() {
    /* pool */
    poolEl.innerHTML = '';
    const poolItems = LC_ITEMS_SRC.filter(i => placement[i.id] === null);
    if (poolItems.length === 0) {
        poolEl.innerHTML = '<span class="lc-pool-empty-hint">All items placed ✓<br><small>Drag any answer back here to change it.</small></span>';
    } else {
        poolItems.forEach(item => poolEl.appendChild(buildPoolChip(item)));
    }

    /* category chip areas */
    LC_CATS.forEach(cat => {
        const area = document.getElementById('lc-chips-' + cat.id);
        if (!area) return;
        area.innerHTML = '';
        LC_ITEMS_SRC.filter(i => {
            const p = placement[i.id];
            return p !== null && p.catId === cat.id;
        }).forEach(item => area.appendChild(buildStickerChip(item, placement[item.id])));
    });

    setupDropZones();
}

function createPlacement(catEl) {
    return { catId: parseInt(catEl.dataset.cat, 10) };
}

/* ── Drop zones ── */
function setupDropZones() {
    LC_CATS.forEach(cat => {
        const catEl = document.getElementById('lc-cat-' + cat.id);
        if (!catEl) return;
        catEl.ondragover  = e => { e.preventDefault(); catEl.classList.add('drag-over'); };
        catEl.ondragleave = e => { if (!catEl.contains(e.relatedTarget)) catEl.classList.remove('drag-over'); };
        catEl.ondrop = e => {
            e.preventDefault();
            catEl.classList.remove('drag-over');
            if (checked) return;
            const itemId = parseInt(e.dataTransfer.getData('text/plain'), 10);
            if (isNaN(itemId)) return;
            placement[itemId] = createPlacement(catEl);
            render();
        };
    });

    [poolEl, poolColEl].forEach(zone => {
        if (!zone) return;
        zone.ondragover  = e => { e.preventDefault(); poolEl.classList.add('drag-over'); poolColEl.classList.add('drag-over'); };
        zone.ondragleave = e => {
            if (!zone.contains(e.relatedTarget)) {
                poolEl.classList.remove('drag-over');
                poolColEl.classList.remove('drag-over');
            }
        };
        zone.ondrop = e => {
            e.preventDefault();
            poolEl.classList.remove('drag-over');
            poolColEl.classList.remove('drag-over');
            const itemId = parseInt(e.dataTransfer.getData('text/plain'), 10);
            if (!isNaN(itemId) && !checked) { placement[itemId] = null; render(); }
        };
    });
}

/* ── Drag events ── */
let dragItemId = null;
function onDragStart(e) {
    if (checked) { e.preventDefault(); return; }
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
    if (checked || e.touches.length !== 1) return;
    e.preventDefault();
    const el = e.currentTarget;
    touchItemId = parseInt(el.dataset.itemId, 10);
    touchItemEl = el;
    ghostEl.innerHTML = el.innerHTML;
    ghostEl.className = el.className.includes('text-only') ? 'text-only' : 'has-image';
    ghostEl.style.width = el.offsetWidth + 'px';
    ghostEl.style.height = el.offsetHeight + 'px';
    ghostEl.style.display = 'block';
    moveGhost(e.touches[0]);
    el.classList.add('dragging');
    document.addEventListener('touchmove', onTouchMove, {passive: false});
    document.addEventListener('touchend',  onTouchEnd,  {passive: false});
}
function moveGhost(t) {
    const w = ghostEl.offsetWidth || (GHOST_HALF * 2);
    const h = ghostEl.offsetHeight || (GHOST_HALF * 2);
    ghostEl.style.left = (t.clientX - (w / 2)) + 'px';
    ghostEl.style.top  = (t.clientY - (h / 2)) + 'px';
}
function onTouchMove(e) {
    if (!touchItemEl) return;
    e.preventDefault();
    moveGhost(e.touches[0]);
    clearDragOver();
    const zone = zoneFromPoint(e.touches[0].clientX, e.touches[0].clientY);
    if (zone) zone.classList.add('drag-over');
}
function onTouchEnd(e) {
    document.removeEventListener('touchmove', onTouchMove);
    document.removeEventListener('touchend',  onTouchEnd);
    ghostEl.style.display = 'none';
    ghostEl.innerHTML = '';
    ghostEl.className = '';
    clearDragOver();
    if (!touchItemEl) return;
    touchItemEl.classList.remove('dragging');
    const t = e.changedTouches[0];
    const catEl = catZoneFromPoint(t.clientX, t.clientY);
    if (catEl && !checked) {
        placement[touchItemId] = createPlacement(catEl);
    } else if (poolFromPoint(t.clientX, t.clientY) && !checked) {
        placement[touchItemId] = null;
    }
    touchItemEl = null;
    touchItemId = null;
    render();
}
function clearDragOver() {
    document.querySelectorAll('.lc-cat-card.drag-over, .lc-pool.drag-over, .lc-col-pool.drag-over')
        .forEach(el => el.classList.remove('drag-over'));
}
function zoneFromPoint(x, y) {
    return catZoneFromPoint(x, y) || (poolFromPoint(x, y) ? poolColEl : null);
}
function catZoneFromPoint(x, y) {
    return [...document.querySelectorAll('.lc-cat-card')].find(z => {
        const r = z.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }) || null;
}
function poolFromPoint(x, y) {
    const r = poolColEl.getBoundingClientRect();
    return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
}

/* ── Audio ── */
function playItemAudio(url) {
    itemAudio.src = url;
    itemAudio.currentTime = 0;
    itemAudio.play().catch(() => {});
}
function playSound(el) {
    try { el.currentTime = 0; el.play(); } catch(e) {}
}

/* ── Check ── */
function checkAnswers() {
    const placed = LC_ITEMS_SRC.filter(i => placement[i.id] !== null);
    if (placed.length < LC_ITEMS_SRC.length) {
        alert('Place all items before checking!');
        return;
    }
    checked = true;
    correct = LC_ITEMS_SRC.filter(i => {
        const p = placement[i.id];
        return p !== null && p.catId === i.category_id;
    }).length;
    wrong = LC_ITEMS_SRC.length - correct;
    const pct = LC_ITEMS_SRC.length > 0 ? Math.round((correct / LC_ITEMS_SRC.length) * 100) : 0;

    sCorrectEl.textContent = correct;
    sWrongEl.textContent   = wrong;
    sPctEl.textContent     = pct + '%';
    scoreGridEl.classList.add('visible');
    render();

    checkBtn.disabled = true;
    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'lc-btn lc-btn-check';
    nextBtn.textContent = 'Finish';
    nextBtn.addEventListener('click', showCompleted);
    controlsEl.appendChild(nextBtn);

    if (wrong === 0) playSound(winSound);
}

/* ── Completed ── */
async function showCompleted() {
    const pct = LC_ITEMS_SRC.length > 0 ? Math.round((correct / LC_ITEMS_SRC.length) * 100) : 0;

    document.getElementById('lc-layout').style.display = 'none';
    scoreGridEl.style.display = 'none';
    controlsEl.style.display  = 'none';

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
    document.getElementById('lc-layout').style.display = '';
    scoreGridEl.classList.remove('visible');
    scoreGridEl.style.display = '';
    controlsEl.style.display  = '';
    completedEl.classList.remove('active');
    checkBtn.disabled = false;
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

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function match_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=:t");
    $stmt->execute(['t' => $table]);
    $cache[$table] = array_map(static fn($r) => (string)$r['column_name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    return $cache[$table];
}

function match_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $cols = match_table_columns($pdo, 'activities');
    foreach (['unit_id', 'unit'] as $col) {
        if (!in_array($col, $cols, true)) continue;
        $stmt = $pdo->prepare("SELECT {$col} FROM activities WHERE id=:id LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row[$col])) return (string)$row[$col];
    }
    return '';
}

function match_title_default(): string
{
    return 'Match';
}

function match_normalize_payload(mixed $raw): array
{
    $default = ['title' => match_title_default(), 'pairs' => []];
    if ($raw === null || $raw === '') return $default;
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) return $default;

    $title = trim((string)($decoded['title'] ?? ''));
    $source = $decoded['pairs'] ?? $decoded['items'] ?? $decoded['data'] ?? $decoded;
    if (!is_array($source)) $source = [];

    $pairs = [];
    foreach ($source as $item) {
        if (!is_array($item)) continue;
        $legacyText  = trim((string)($item['text'] ?? $item['word'] ?? ''));
        $legacyImage = trim((string)($item['image'] ?? $item['img'] ?? ''));
        $leftText    = trim((string)($item['left_text'] ?? ''));
        $leftImage   = trim((string)($item['left_image'] ?? $legacyImage));
        $rightText   = trim((string)($item['right_text'] ?? $legacyText));
        $rightImage  = trim((string)($item['right_image'] ?? ''));
        if ($leftText === '' && $leftImage === '' && $rightText === '' && $rightImage === '') continue;
        $pairs[] = [
            'id'          => trim((string)($item['id'] ?? '')) !== '' ? trim((string)$item['id']) : uniqid('match_'),
            'left_text'   => $leftText,
            'left_image'  => $leftImage,
            'right_text'  => $rightText,
            'right_image' => $rightImage,
        ];
    }

    return [
        'title' => $title !== '' ? $title : match_title_default(),
        'pairs' => $pairs,
    ];
}

function match_load_activity(PDO $pdo, string $activityId, string $unit): array
{
    $cols = match_table_columns($pdo, 'activities');
    $select = ['id'];
    foreach (['data', 'content_json', 'title', 'name'] as $col) {
        if (in_array($col, $cols, true)) $select[] = $col;
    }

    $fetchBy = function (string $where, array $params) use ($pdo, $select): ?array {
        $sql = "SELECT " . implode(', ', $select) . " FROM activities WHERE {$where} AND type='match' ORDER BY id ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    };

    $row = null;
    if ($activityId !== '') $row = $fetchBy('id=:id', ['id' => $activityId]);
    if (!$row && $unit !== '' && in_array('unit_id', $cols, true)) $row = $fetchBy('unit_id=:unit', ['unit' => $unit]);
    if (!$row && $unit !== '' && in_array('unit', $cols, true)) $row = $fetchBy('unit=:unit', ['unit' => $unit]);

    if (!$row) return ['id' => '', 'title' => match_title_default(), 'pairs' => []];

    $raw = $row['data'] ?? $row['content_json'] ?? null;
    $payload = match_normalize_payload($raw);
    $columnTitle = trim((string)($row['title'] ?? $row['name'] ?? ''));
    if ($columnTitle !== '') $payload['title'] = $columnTitle;
    $payload['id'] = (string)($row['id'] ?? '');
    return $payload;
}

if ($unit === '' && $activityId !== '') {
    $unit = match_resolve_unit($pdo, $activityId);
}

$activity    = match_load_activity($pdo, $activityId, $unit);
$viewerTitle = trim((string)($activity['title'] ?? match_title_default()));
$pairs       = is_array($activity['pairs'] ?? null) ? array_values($activity['pairs']) : [];
$pairsJson   = json_encode($pairs, JSON_UNESCAPED_UNICODE);

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--m-orange:#F97316;--m-orange-soft:#FFF0E6;--m-orange-dark:#C2580A;--m-purple:#7F77DD;--m-purple-dark:#534AB7;--m-purple-soft:#EEEDFE;--m-green:#22c55e;--m-green-soft:#f0fdf4;--m-green-dark:#15803d;--m-red:#ef4444;--m-red-soft:#fef2f2;--m-bg:#F5F3FF;--m-border:#EDE9FA;--m-ink:#271B5D;--m-muted:#9B94BE}
*{box-sizing:border-box}html,body{width:100%;height:100%;min-height:100%}body{margin:0!important;padding:0!important;background:var(--m-bg)!important;font-family:'Nunito','Segoe UI',sans-serif!important}.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;height:100%!important;min-height:0!important;display:flex!important;flex-direction:column!important;background:transparent!important}.top-row,.activity-header,.activity-title,.activity-subtitle,.viewer-header{display:none!important}.viewer-content{flex:1!important;min-height:0!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important;overflow:hidden!important}
.m-page{width:100%;height:100%;min-height:0;padding:clamp(10px,2vw,24px) clamp(12px,2.4vw,28px);display:flex;justify-content:center;align-items:stretch;background:var(--m-bg);overflow:hidden}.m-app{width:min(960px,100%);height:100%;min-height:0;display:grid;grid-template-rows:auto minmax(0,1fr);gap:clamp(10px,1.8vw,16px)}.m-hero{text-align:center;display:grid;gap:6px;justify-items:center}.m-kicker{display:inline-flex;align-items:center;padding:5px 14px;border-radius:999px;background:var(--m-orange-soft);border:1px solid #FCDDBF;color:var(--m-orange-dark);font-size:11px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.m-title{margin:0;font-family:'Fredoka',sans-serif;font-weight:700;font-size:clamp(28px,5vw,52px);color:var(--m-orange);line-height:1.05}.m-subtitle{margin:0;font-size:clamp(12px,1.6vw,15px);font-weight:800;color:var(--m-muted)}
.m-board{height:100%;min-height:0;background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(12px,2vw,20px);box-shadow:0 8px 40px rgba(127,119,221,.12);display:grid;grid-template-rows:auto auto minmax(0,1fr) auto auto;gap:12px;overflow:hidden}.m-page.is-completed{align-items:flex-start;overflow-y:auto}.m-app.is-completed{height:auto;grid-template-rows:auto auto}.m-board.is-completed{height:auto;display:block;overflow:visible}.m-progress-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}.m-progress-track{height:10px;background:#F4F2FD;border:1px solid #E4E1F8;border-radius:999px;overflow:hidden}.m-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--m-orange),var(--m-purple));border-radius:999px;transition:width .4s ease}.m-badge{background:var(--m-purple);color:#fff;border-radius:999px;padding:5px 16px;font-size:12px;font-weight:900;white-space:nowrap}.m-hint-wrap{display:flex;justify-content:center}.m-hint{display:inline-flex;align-items:center;padding:5px 14px;border-radius:999px;background:var(--m-orange-soft);border:1px solid #FCDDBF;color:var(--m-orange-dark);font-size:12px;font-weight:900;transition:background .2s,border-color .2s,color .2s}.m-hint.is-correct,.m-hint.is-complete{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56}.m-hint.is-wrong{background:#FEF2F2;border-color:#FECACA;color:#B91C1C}
.m-match-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(210px,300px);gap:14px;min-height:0;overflow:hidden;align-items:stretch}.m-pairs-wrap{display:grid;grid-template-rows:auto minmax(0,1fr);gap:8px;min-height:0}.m-column-label{font-size:11px;font-weight:900;color:var(--m-muted);text-transform:uppercase;letter-spacing:.08em}.m-column-label.left{text-align:left}.m-column-label.right{text-align:center}.m-pairs{display:grid;gap:10px;min-height:0;overflow:auto;padding-right:4px;align-content:start}.m-pair-row{display:grid;grid-template-columns:minmax(120px,34%) minmax(0,1fr);gap:12px;align-items:center}.m-pair-row.has-images{grid-template-columns:minmax(120px,34%) minmax(0,1fr)}.m-pair-row.has-images .m-prompt,.m-pair-row.has-images .m-slot{min-height:clamp(78px,12vw,112px);padding:8px}.m-prompt{display:flex;align-items:center;justify-content:center;min-height:clamp(64px,10vw,90px);padding:10px 14px;border:2px solid var(--m-border);border-radius:18px;background:#FAFAFE;font-family:'Fredoka',sans-serif;font-size:clamp(15px,2vw,20px);font-weight:600;color:var(--m-ink);text-align:center;gap:8px;user-select:none}.m-prompt img{max-width:min(100%,140px);max-height:100px;object-fit:contain;border-radius:10px}.m-slot{position:relative;display:flex;align-items:center;justify-content:center;min-height:clamp(64px,10vw,90px);padding:6px;border:2px dashed #C5C0F0;border-radius:18px;background:#FAFAFE;transition:border-color .18s,background .18s,box-shadow .18s;cursor:default;overflow:hidden}.m-slot::before{content:'Drop here';position:absolute;font-size:11px;font-weight:900;color:var(--m-muted);letter-spacing:.05em;pointer-events:none}.m-slot:not(:empty)::before{display:none}.m-slot.drag-over{border-color:var(--m-purple);background:var(--m-purple-soft);box-shadow:0 0 0 3px rgba(127,119,221,.18)}.m-slot.is-correct{border-color:var(--m-green)!important;border-style:solid!important;background:var(--m-green-soft)!important}.m-slot.is-wrong{border-color:var(--m-red)!important;border-style:solid!important;background:var(--m-red-soft)!important;animation:m-shake .32s ease}.m-slot.is-answered{border-style:solid;border-color:var(--m-border);cursor:not-allowed}
.m-tile{display:inline-flex;align-items:center;justify-content:center;flex-direction:column;gap:4px;padding:8px 10px;min-height:clamp(52px,8vw,72px);border:2px solid var(--m-border);border-radius:16px;background:#fff;cursor:grab;user-select:none;font-family:'Fredoka',sans-serif;font-size:clamp(13px,1.55vw,17px);font-weight:600;color:var(--m-ink);text-align:center;box-shadow:0 3px 12px rgba(127,119,221,.09);transition:transform .18s,box-shadow .18s,border-color .18s,opacity .18s;touch-action:none;max-width:100%}.m-tile:active,.m-tile.dragging{cursor:grabbing;opacity:.5;transform:scale(.96)}.m-tile:hover:not(.is-placed){transform:translateY(-2px);box-shadow:0 8px 22px rgba(127,119,221,.18);border-color:var(--m-purple)}.m-tile.is-placed{cursor:default;border-color:var(--m-purple-dark);background:var(--m-purple-soft);color:var(--m-purple-dark)}.m-tile.is-correct{border-color:var(--m-green);background:var(--m-green-soft);color:var(--m-green-dark);cursor:default}.m-tile.is-wrong-flash{border-color:var(--m-red);background:var(--m-red-soft);animation:m-shake .32s ease}.m-tile img{max-width:100%;max-height:82px;object-fit:contain;border-radius:8px;pointer-events:none;display:block}.m-tile span{display:block;width:100%;font-family:'Nunito',sans-serif;font-size:clamp(10px,1.05vw,12px);font-weight:900;line-height:1.15;color:inherit;text-align:center;white-space:normal;overflow-wrap:break-word}.m-tile.has-image{width:100%;min-width:0;min-height:0;padding:6px;border-radius:14px;gap:3px}.m-tile.has-image img{width:100%;height:auto;max-width:100%;max-height:96px;object-fit:contain}.m-pool .m-tile.has-image{width:100%;padding:6px 8px}.m-slot .m-tile{width:100%;height:100%;min-height:0}.m-slot .m-tile.has-image{height:auto;max-height:100%;padding:4px}.m-slot .m-tile.has-image img{max-height:70px}.m-slot .m-tile.has-image span{font-size:10px;line-height:1.1}
.m-pool-label{font-size:11px;font-weight:900;color:var(--m-muted);text-transform:uppercase;letter-spacing:.08em;text-align:center;margin-bottom:8px}.m-pool{display:grid;grid-template-columns:1fr;gap:10px;justify-items:stretch;min-height:56px;padding:10px;border:2px dashed #DDD9F8;border-radius:20px;background:#FAFAFE;transition:background .18s,border-color .18s;max-height:none;height:100%;overflow:auto;align-content:flex-start;align-items:flex-start}.m-pool.drag-over{background:var(--m-purple-soft);border-color:var(--m-purple)}.m-pairs::-webkit-scrollbar,.m-pool::-webkit-scrollbar{width:8px;height:8px}.m-pairs::-webkit-scrollbar-thumb,.m-pool::-webkit-scrollbar-thumb{background:#D3CEF3;border-radius:999px}.m-pairs::-webkit-scrollbar-track,.m-pool::-webkit-scrollbar-track{background:transparent}.m-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px}.m-score-grid.visible{display:grid}.m-score-card{background:#FAFAFE;border:1px solid var(--m-border);border-radius:14px;padding:12px;text-align:center}.m-score-num{font-family:'Fredoka',sans-serif;font-size:24px;line-height:1;font-weight:600;color:var(--m-purple)}.m-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:var(--m-muted);text-transform:uppercase;letter-spacing:.08em}.m-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}.m-btn{padding:12px 24px;border-radius:10px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;transition:transform .15s,filter .15s;border:none;min-width:120px}.m-btn:hover{transform:translateY(-2px);filter:brightness(1.07)}.m-btn-check,.m-btn-next{background:var(--m-orange);color:#fff;box-shadow:0 6px 18px rgba(249,115,22,.22)}.m-btn-answer{background:var(--m-purple);color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18)}.m-completed{display:none;text-align:center;padding:24px 12px}.m-completed.active{display:block}.m-completed-icon{font-size:30px;line-height:1;margin-bottom:6px}.m-completed-title{margin:0;color:var(--m-orange);font-family:'Fredoka',sans-serif;font-size:32px;font-weight:700}.m-completed-text{color:var(--m-muted);font-size:14px;font-weight:800}.m-completed-score{color:#666;font-size:14px;font-weight:800}.m-restart-btn{border:none;border-radius:10px;color:#fff;min-width:128px;padding:11px 20px;font-size:14px;font-weight:700;font-family:'Nunito',sans-serif;cursor:pointer;background:var(--m-purple)}#m-ghost{position:fixed;pointer-events:none;z-index:9999;opacity:.82;transform:rotate(-3deg) scale(1.02);border:2px solid var(--m-purple);background:#fff;border-radius:14px;padding:6px;font-family:'Fredoka',sans-serif;font-size:13px;font-weight:600;color:var(--m-ink);text-align:center;box-shadow:0 16px 40px rgba(127,119,221,.22);display:none;max-width:190px;align-items:center;justify-content:center;flex-direction:column;gap:3px}#m-ghost img{max-width:100%;max-height:90px;object-fit:contain;border-radius:8px}#m-ghost span{font-size:10px;font-weight:900}.m-empty{text-align:center;padding:48px 24px;color:var(--m-muted);font-size:17px;font-weight:800}@keyframes m-shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-7px)}40%{transform:translateX(7px)}60%{transform:translateX(-5px)}80%{transform:translateX(5px)}}@keyframes m-pop{from{transform:scale(.88);opacity:0}to{transform:scale(1);opacity:1}}.m-tile.popped{animation:m-pop .22s ease}
@media(max-width:640px){.m-match-layout{grid-template-columns:1fr;overflow:auto}.m-pairs-wrap{min-height:auto}.m-column-label.right{text-align:left}.m-pair-row{grid-template-columns:1fr 1fr}.m-page{padding:10px}.m-board{padding:12px;border-radius:22px;gap:10px}.m-score-grid{grid-template-columns:1fr}.m-restart-btn{width:100%}.m-pool{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:420px){.m-pair-row{grid-template-columns:1fr}.m-slot,.m-prompt{min-height:54px}.m-pool{grid-template-columns:1fr}}
</style>

<?php if (empty($pairs)): ?>
<div class="m-page"><div class="m-app"><div class="m-empty">No match data available.</div></div></div>
<?php else: ?>
<div id="m-ghost"></div>
<div class="m-page" id="m-page"><div class="m-app" id="m-app">
<header class="m-hero"><div class="m-kicker">Match Activity</div><h1 class="m-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1><p class="m-subtitle">Drag each answer to its matching prompt.</p></header>
<main class="m-board" id="m-board">
<div class="m-progress-row"><div class="m-progress-track"><div class="m-progress-fill" id="m-fill"></div></div><div class="m-badge" id="m-badge">0 / <?= count($pairs) ?></div></div>
<div class="m-hint-wrap"><div class="m-hint" id="m-hint">Drag answers from right to left onto matching prompts</div></div>
<div class="m-match-layout"><div class="m-pairs-wrap"><div class="m-column-label left">Drop Targets</div><div class="m-pairs" id="m-pairs"></div></div><div><div class="m-column-label right">Answer Bank (Drag right → left)</div><div class="m-pool" id="m-pool"></div></div></div>
<div class="m-score-grid" id="m-score-grid"><div class="m-score-card"><div class="m-score-num" id="m-s-correct">0</div><div class="m-score-lbl">Correct</div></div><div class="m-score-card"><div class="m-score-num" id="m-s-wrong">0</div><div class="m-score-lbl">Wrong</div></div><div class="m-score-card"><div class="m-score-num" id="m-s-pct">0%</div><div class="m-score-lbl">Score</div></div></div>
<div class="m-actions"><button type="button" class="m-btn m-btn-check" id="m-check">Check</button><button type="button" class="m-btn m-btn-answer" id="m-answer">Show Answer</button><button type="button" class="m-btn m-btn-next" id="m-next">Next</button></div>
<div class="m-completed" id="m-completed"><div class="m-completed-icon">✅</div><h2 class="m-completed-title" id="m-completed-title"></h2><p class="m-completed-text" id="m-completed-text"></p><p class="m-completed-score" id="m-completed-score"></p><button type="button" class="m-restart-btn" id="m-restart">Restart</button></div>
</main></div></div>
<audio id="m-win-sound" src="../../hangman/assets/win.mp3" preload="auto"></audio><audio id="m-lose-sound" src="../../hangman/assets/lose.mp3" preload="auto"></audio><audio id="m-done-sound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>
<script>
(function(){'use strict';
const PAIRS=<?= $pairsJson ?>,RETURN_TO=<?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>,ACT_ID=<?= json_encode((string)($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,TOTAL=PAIRS.length,VIEWER_TITLE=<?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
const esc=s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const byId=id=>PAIRS.find(p=>String(p.id)===String(id));
const leftText=p=>String(p.left_text||p.text||p.word||'').trim(), rightText=p=>String(p.right_text||p.text||p.word||'').trim();
const leftImg=p=>String(p.left_image||'').trim(), rightImg=p=>String(p.right_image||p.image||p.img||'').trim();
function shuffle(arr){const a=[...arr];for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]];}return a;}
const fillEl=document.getElementById('m-fill'),badgeEl=document.getElementById('m-badge'),pageEl=document.getElementById('m-page'),appEl=document.getElementById('m-app'),boardEl=document.getElementById('m-board'),hintEl=document.getElementById('m-hint'),hintWrapEl=document.querySelector('.m-hint-wrap'),progressEl=document.querySelector('.m-progress-row'),pairsEl=document.getElementById('m-pairs'),poolEl=document.getElementById('m-pool'),poolWrapEl=poolEl.parentElement,scoreGrid=document.getElementById('m-score-grid'),sCorrect=document.getElementById('m-s-correct'),sWrong=document.getElementById('m-s-wrong'),sPct=document.getElementById('m-s-pct'),checkBtn=document.getElementById('m-check'),answerBtn=document.getElementById('m-answer'),nextBtn=document.getElementById('m-next'),actionsEl=document.querySelector('.m-actions'),completedEl=document.getElementById('m-completed'),completedTitleEl=document.getElementById('m-completed-title'),completedTextEl=document.getElementById('m-completed-text'),completedScoreEl=document.getElementById('m-completed-score'),restartBtn=document.getElementById('m-restart'),winSound=document.getElementById('m-win-sound'),loseSound=document.getElementById('m-lose-sound'),doneSound=document.getElementById('m-done-sound'),ghost=document.getElementById('m-ghost');
let slots={},wrongs=0,checked=new Set(),answered=false,finished=false,revealedByAnswer=false,preservedScoreSummary=null,preservedPersistenceSummary=null,poolOrder=[],dragTileId=null,dragFromSlot=null,touchTileEl=null,touchFromSlot=null,touchTileId=null;
function playSound(el){try{el.pause();el.currentTime=0;el.play();}catch(e){}}
function tileHTML(p,extraClass){const ri=rightImg(p),rt=esc(rightText(p)),hasImg=ri!=='';const inner=hasImg?`<img src="${esc(ri)}" alt="${rt}" draggable="false">${rt?'<span>'+rt+'</span>':''}`:rt;const cls=['m-tile',hasImg?'has-image':'',extraClass||''].filter(Boolean).join(' ');return `<div class="${cls}" draggable="true" data-tile="${esc(String(p.id))}">${inner}</div>`;}
function promptHTML(p){const li=leftImg(p),lt=esc(leftText(p));let inner='';if(li)inner+=`<img src="${esc(li)}" alt="${lt}">`;if(lt)inner+=`<span>${lt}</span>`;return `<div class="m-prompt">${inner}</div>`;}
function render(){pairsEl.innerHTML=PAIRS.map(p=>{const pid=String(p.id),pairHasImages=leftImg(p)!==''||rightImg(p)!=='',placedId=slots[pid],isCheckedOk=checked.has(pid),slotCls=['m-slot',isCheckedOk?'is-correct':'',answered&&!isCheckedOk&&placedId?'is-correct':''].filter(Boolean).join(' ');let slotContent='';if(placedId!==undefined&&placedId!==null){const tp=byId(placedId);if(tp){const ri=rightImg(tp),rt=esc(rightText(tp)),hasImg=ri!=='';let inner=hasImg?`<img src="${esc(ri)}" alt="${rt}" draggable="false">${rt?'<span>'+rt+'</span>':''}`:rt;const tc=['m-tile',hasImg?'has-image':'',isCheckedOk||answered?'is-correct':'is-placed'].filter(Boolean).join(' ');slotContent=`<div class="${tc}" data-tile="${esc(placedId)}" data-in-slot="${esc(pid)}" draggable="true">${inner}</div>`;}}return `<div class="m-pair-row ${pairHasImages?'has-images':''}" data-pair-row="${esc(pid)}"><div class="${slotCls}" data-slot="${esc(pid)}">${slotContent}</div>${promptHTML(p)}</div>`;}).join('');const placedTiles=new Set(Object.values(slots).filter(v=>v!==null));const pairById=new Map(PAIRS.map(p=>[String(p.id),p]));poolEl.innerHTML=poolOrder.filter(id=>!placedTiles.has(id)).map(id=>pairById.get(id)).filter(Boolean).map(p=>tileHTML(p,'popped')).join('')||'<span style="color:var(--m-muted);font-size:13px;font-weight:800;">All placed ✓</span>';const placed=Object.values(slots).filter(v=>v!==null).length,pct=TOTAL>0?Math.round((placed/TOTAL)*100):0;fillEl.style.width=pct+'%';badgeEl.textContent=placed+' / '+TOTAL;bindDrag();bindTouch();}
function bindDrag(){document.querySelectorAll('.m-tile[draggable]').forEach(el=>{el.addEventListener('dragstart',e=>{dragTileId=el.dataset.tile;dragFromSlot=el.dataset.inSlot||null;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',dragTileId);setTimeout(()=>el.classList.add('dragging'),0);});el.addEventListener('dragend',()=>{dragTileId=null;dragFromSlot=null;document.querySelectorAll('.m-tile.dragging').forEach(t=>t.classList.remove('dragging'));});});document.querySelectorAll('.m-slot').forEach(slot=>{slot.addEventListener('dragover',e=>{if(slot.classList.contains('is-correct'))return;e.preventDefault();slot.classList.add('drag-over');});slot.addEventListener('dragleave',()=>slot.classList.remove('drag-over'));slot.addEventListener('drop',e=>{e.preventDefault();slot.classList.remove('drag-over');const tileId=e.dataTransfer.getData('text/plain')||dragTileId;if(tileId)dropTileOnSlot(tileId,slot.dataset.slot,dragFromSlot);});});poolEl.addEventListener('dragover',e=>{e.preventDefault();poolEl.classList.add('drag-over');});poolEl.addEventListener('dragleave',()=>poolEl.classList.remove('drag-over'));poolEl.addEventListener('drop',e=>{e.preventDefault();poolEl.classList.remove('drag-over');const tileId=e.dataTransfer.getData('text/plain')||dragTileId;if(!tileId||!dragFromSlot)return;if(!checked.has(dragFromSlot)){slots[dragFromSlot]=null;render();}});}
function dropTileOnSlot(tileId,targetPairId,fromSlot){if(checked.has(targetPairId))return;if(slots[targetPairId]!==null&&slots[targetPairId]!==undefined){const displaced=slots[targetPairId];Object.keys(slots).forEach(pid=>{if(slots[pid]===displaced&&pid!==targetPairId)slots[pid]=null;});}if(fromSlot&&fromSlot!==targetPairId)slots[fromSlot]=null;slots[targetPairId]=tileId;render();setHint('Placed on left side. Keep going…',null);}
function bindTouch(){document.querySelectorAll('.m-tile[draggable]').forEach(el=>el.addEventListener('touchstart',onTouchStart,{passive:false}));}
function onTouchStart(e){if(e.touches.length!==1)return;const el=e.currentTarget;if(el.closest('.m-slot')?.classList.contains('is-correct'))return;e.preventDefault();touchTileId=el.dataset.tile;touchFromSlot=el.dataset.inSlot||null;touchTileEl=el;ghost.innerHTML=el.innerHTML;ghost.style.display='flex';ghost.style.width=el.offsetWidth+'px';moveGhost(e.touches[0]);el.classList.add('dragging');document.addEventListener('touchmove',onTouchMove,{passive:false});document.addEventListener('touchend',onTouchEnd,{passive:false});}
function moveGhost(t){ghost.style.left=(t.clientX-ghost.offsetWidth/2)+'px';ghost.style.top=(t.clientY-ghost.offsetHeight/2)+'px';}
function onTouchMove(e){if(!touchTileEl)return;e.preventDefault();moveGhost(e.touches[0]);document.querySelectorAll('.m-slot.drag-over').forEach(s=>s.classList.remove('drag-over'));const slot=slotFromPoint(e.touches[0].clientX,e.touches[0].clientY);if(slot)slot.classList.add('drag-over');poolEl.classList.toggle('drag-over',!!poolFromPoint(e.touches[0].clientX,e.touches[0].clientY));}
function onTouchEnd(e){document.removeEventListener('touchmove',onTouchMove);document.removeEventListener('touchend',onTouchEnd);ghost.style.display='none';document.querySelectorAll('.m-slot.drag-over').forEach(s=>s.classList.remove('drag-over'));poolEl.classList.remove('drag-over');if(!touchTileEl)return;touchTileEl.classList.remove('dragging');const t=e.changedTouches[0],slot=slotFromPoint(t.clientX,t.clientY);if(slot)dropTileOnSlot(touchTileId,slot.dataset.slot,touchFromSlot);else if(poolFromPoint(t.clientX,t.clientY)&&touchFromSlot&&!checked.has(touchFromSlot)){slots[touchFromSlot]=null;render();}touchTileEl=null;touchTileId=null;touchFromSlot=null;}
function slotFromPoint(x,y){return[...document.querySelectorAll('.m-slot')].find(s=>{const r=s.getBoundingClientRect();return x>=r.left&&x<=r.right&&y>=r.top&&y<=r.bottom;})||null;}function poolFromPoint(x,y){const r=poolEl.getBoundingClientRect();return x>=r.left&&x<=r.right&&y>=r.top&&y<=r.bottom;}
function setHint(text,state){hintEl.textContent=text;hintEl.className='m-hint'+(state?' is-'+state:'');}
function scoreSummary(){if(revealedByAnswer&&preservedScoreSummary)return preservedScoreSummary;const attempts=checked.size+wrongs,pct=attempts>0?Math.round((checked.size/attempts)*100):0;return{correct:checked.size,wrong:wrongs,percent:pct};}
function persistenceSummary(){if(revealedByAnswer&&preservedPersistenceSummary)return preservedPersistenceSummary;const correct=checked.size,total=Math.max(0,TOTAL),errors=Math.max(0,total-correct),percent=total>0?Math.round((correct/total)*100):0;return{percent,errors,total};}
function checkAnswers(){if(finished)return;let anyNew=false;PAIRS.forEach(p=>{const pid=String(p.id);if(checked.has(pid))return;const placed=slots[pid];if(!placed)return;anyNew=true;if(String(placed)===String(p.id))checked.add(pid);else{wrongs++;const slotEl=document.querySelector(`[data-slot="${CSS.escape(pid)}"]`);if(slotEl){slotEl.classList.add('is-wrong');setTimeout(()=>{slotEl.classList.remove('is-wrong');slots[pid]=null;render();},500);}else{slots[pid]=null;render();}}});if(!anyNew){setHint('Place all answers first','wrong');playSound(loseSound);return;}updateScores(true);if(checked.size===TOTAL){setHint('🎉 All matched! Press Next.','complete');playSound(winSound);render();finished=true;checkBtn.disabled=true;answerBtn.disabled=true;}else{setHint(checked.size+' correct, keep going…','correct');playSound(loseSound);render();}}
function showAnswers(){if(finished)return;preservedScoreSummary=scoreSummary();preservedPersistenceSummary=persistenceSummary();answered=true;revealedByAnswer=true;PAIRS.forEach(p=>{slots[String(p.id)]=String(p.id);checked.add(String(p.id));});setHint('Answer revealed — this activity does not affect score.',null);playSound(winSound);updateScores(true);render();finished=true;checkBtn.disabled=true;answerBtn.disabled=true;}
function resetGame(){slots={};wrongs=0;checked=new Set();answered=false;finished=false;revealedByAnswer=false;preservedScoreSummary=null;preservedPersistenceSummary=null;poolOrder=shuffle(PAIRS.map(p=>String(p.id)));PAIRS.forEach(p=>{slots[String(p.id)]=null;});[progressEl,hintWrapEl,pairsEl,poolWrapEl,actionsEl].forEach(el=>{if(el)el.style.display='';});[pageEl,appEl,boardEl].forEach(el=>el&&el.classList.remove('is-completed'));completedEl.classList.remove('active');checkBtn.disabled=false;answerBtn.disabled=false;setHint('Drag answers from right to left onto matching prompts');updateScores(false);render();}
function updateScores(show){const s=scoreSummary();sCorrect.textContent=s.correct;sWrong.textContent=s.wrong;sPct.textContent=s.percent+'%';scoreGrid.classList.toggle('visible',!!show);}
async function persistScore(){if(!RETURN_TO||!ACT_ID)return true;const s=persistenceSummary(),sep=RETURN_TO.includes('?')?'&':'?',url=RETURN_TO+sep+'activity_percent='+s.percent+'&activity_errors='+s.errors+'&activity_total='+s.total+'&activity_id='+encodeURIComponent(ACT_ID)+'&activity_type=match';try{const r=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store'});if(!r.ok){window.location.href=url;return false;}return true;}catch(e){window.location.href=url;return false;}}
async function showCompleted(){if(!finished)return;[progressEl,hintWrapEl,pairsEl,poolWrapEl,actionsEl].forEach(el=>{if(el)el.style.display='none';});[pageEl,appEl,boardEl].forEach(el=>el&&el.classList.add('is-completed'));completedEl.classList.add('active');const s=scoreSummary();completedTitleEl.textContent=VIEWER_TITLE||'Match';completedTextEl.textContent="You've completed this activity. Great job practicing.";completedScoreEl.textContent=s.correct+' correct · '+s.wrong+' wrong · '+s.percent+'%';playSound(doneSound);await persistScore();}
checkBtn.addEventListener('click',checkAnswers);answerBtn.addEventListener('click',showAnswers);nextBtn.addEventListener('click',()=>{if(!finished){setHint('Check answers or show answer first.','wrong');playSound(loseSound);return;}showCompleted();});restartBtn.addEventListener('click',resetGame);resetGame();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);

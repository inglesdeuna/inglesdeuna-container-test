<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$returnTo   = isset($_GET['return_to'])  ? trim((string) $_GET['return_to'])  : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_memory_cards_title(): string
{
    return 'Memory Cards';
}

function normalize_memory_side($raw): array
{
    $side = is_array($raw) ? $raw : array();

    $type  = strtolower(trim((string) ($side['type']  ?? 'text')));
    $text  = trim((string) ($side['text']  ?? ''));
    $image = trim((string) ($side['image'] ?? ''));

    if ($type !== 'text' && $type !== 'image') {
        $type = $image !== '' ? 'image' : 'text';
    }

    if ($type === 'text' && $text === '' && $image !== '') {
        $type = 'image';
    }

    if ($type === 'image' && $image === '' && $text !== '') {
        $type = 'text';
    }

    return array(
        'type'  => $type,
        'text'  => $text,
        'image' => $image,
    );
}

function pair_side_is_valid(array $side): bool
{
    $type = strtolower((string) ($side['type'] ?? 'text'));
    if ($type === 'image') {
        return trim((string) ($side['image'] ?? '')) !== '';
    }

    return trim((string) ($side['text'] ?? '')) !== '';
}

function normalize_memory_cards_payload($rawData): array
{
    $default = array(
        'title' => default_memory_cards_title(),
        'pairs' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    if ($title === '') {
        $title = default_memory_cards_title();
    }

    $pairsSource = isset($decoded['pairs']) && is_array($decoded['pairs'])
        ? $decoded['pairs']
        : array();

    $pairs = array();

    foreach ($pairsSource as $index => $pairRaw) {
        if (!is_array($pairRaw)) {
            continue;
        }

        $left  = normalize_memory_side(isset($pairRaw['left'])  ? $pairRaw['left']  : array());
        $right = normalize_memory_side(isset($pairRaw['right']) ? $pairRaw['right'] : array());

        if (!pair_side_is_valid($left) || !pair_side_is_valid($right)) {
            continue;
        }

        $pairId = trim((string) ($pairRaw['id'] ?? ''));
        if ($pairId === '') {
            $pairId = 'pair_' . ($index + 1) . '_' . mt_rand(1000, 9999);
        }

        $pairs[] = array(
            'id'    => $pairId,
            'left'  => $left,
            'right' => $right,
        );
    }

    return array(
        'title' => $title,
        'pairs' => $pairs,
    );
}

function load_memory_cards_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data',         $columns, true)) { $selectFields[] = 'data'; }
    if (in_array('content_json', $columns, true)) { $selectFields[] = 'content_json'; }
    if (in_array('title',        $columns, true)) { $selectFields[] = 'title'; }
    if (in_array('name',         $columns, true)) { $selectFields[] = 'name'; }

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'memory_cards'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'memory_cards'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'memory_cards'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return array(
            'title' => default_memory_cards_title(),
            'pairs' => array(),
        );
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_memory_cards_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return $payload;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity    = load_memory_cards_activity($pdo, $unit, $activityId);
$viewerTitle = (string) ($activity['title'] ?? default_memory_cards_title());
$pairs       = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();

$cards = array();

foreach ($pairs as $pair) {
    if (!is_array($pair)) {
        continue;
    }

    $pairId = trim((string) ($pair['id'] ?? ''));
    if ($pairId === '') {
        continue;
    }

    $left  = normalize_memory_side(isset($pair['left'])  ? $pair['left']  : array());
    $right = normalize_memory_side(isset($pair['right']) ? $pair['right'] : array());

    if (!pair_side_is_valid($left) || !pair_side_is_valid($right)) {
        continue;
    }

    $cards[] = array(
        'id'     => $pairId . '_a',
        'pairId' => $pairId,
        'type'   => $left['type'],
        'text'   => $left['text'],
        'image'  => $left['image'],
    );

    $cards[] = array(
        'id'     => $pairId . '_b',
        'pairId' => $pairId,
        'type'   => $right['type'],
        'text'   => $right['text'],
        'image'  => $right['image'],
    );
}

if (count($cards) > 12) {
    $cards = array_slice($cards, 0, 12);
}

$totalPairs = (int) floor(count($cards) / 2);
$totalCards = count($cards);

$desktopCols = 4;
if ($totalCards > 0) {
    $desktopCols = (int) ceil(sqrt($totalCards));
    if ($desktopCols < 2) { $desktopCols = 2; }
    if ($desktopCols > 6) { $desktopCols = 6; }
}

$tabletCols = min(4, $desktopCols);
$mobileCols = min(3, $desktopCols);

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --mc-orange:#F97316;
    --mc-orange-dark:#C2580A;
    --mc-orange-soft:#FFF0E6;
    --mc-purple:#7F77DD;
    --mc-purple-dark:#534AB7;
    --mc-purple-soft:#EEEDFE;
    --mc-muted:#9B94BE;
    --mc-border:#F0EEF8;
    --mc-cols:<?= (int) $desktopCols ?>;
    --mc-gap:clamp(8px,1.2vw,14px);
}

html,body{width:100%;height:100%;}

body{
    margin:0!important;
    padding:0!important;
    background:#fff!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.activity-wrapper{
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    height:100%!important;
    min-height:0!important;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
}

.top-row,
.viewer-header,
.activity-header,
.activity-title,
.activity-subtitle{display:none!important;}

.viewer-content{
    flex:1!important;
    display:flex!important;
    flex-direction:column!important;
    min-height:0!important;
    padding:0!important;
    margin:0!important;
    background:#ffffff!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.mc-page{
    display:flex;
    flex-direction:column;
    flex:1;
    min-height:0;
    width:100%;
    padding:1cm;
    gap:8px;
    box-sizing:border-box;
    overflow:hidden;
    background:#ffffff;
}

.mc-app{
    width:min(980px,100%);
    height:100%;
    min-height:0;
    margin:0 auto;
    display:grid;
    grid-template-rows:auto minmax(0,1fr);
    gap:clamp(10px,1.5vw,14px);
}

.mc-hero{text-align:center;}

.mc-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 14px;
    border-radius:999px;
    background:var(--mc-orange-soft);
    border:1px solid #FCDDBF;
    color:var(--mc-orange-dark);
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:8px;
}

.mc-hero h1{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(26px,4.5vw,50px);
    font-weight:700;
    color:var(--mc-orange);
    margin:0;
    line-height:1.03;
}

.mc-hero p{
    font-size:clamp(12px,1.6vw,15px);
    font-weight:800;
    color:var(--mc-muted);
    margin:6px 0 0;
}

.mc-stage{
    height:100%;
    min-height:0;
    background:#fff;
    border:1px solid var(--mc-border);
    border-radius:34px;
    padding:clamp(14px,2.2vw,22px);
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    box-sizing:border-box;
    display:flex;
    flex-direction:column;
    overflow:hidden;
}

.mc-viewer{max-width:100%;flex:1;min-height:0;display:flex;flex-direction:column;}

.mc-shell{
    background:#fff;
    border:1px solid #EDE9FA;
    border-radius:30px;
    box-shadow:0 8px 24px rgba(127,119,221,.09);
    padding:18px;
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
    gap:8px;
    overflow:hidden;
    box-sizing:border-box;
}

.mc-stats{display:flex;gap:8px;flex-wrap:wrap;flex-shrink:0;}

.mc-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:var(--mc-purple-soft);
    border:1px solid #d8d3f5;
    color:var(--mc-purple-dark);
    font-weight:800;
    font-size:13px;
    padding:8px 12px;
    border-radius:999px;
}

.mc-board{
    display:grid;
    grid-template-columns:repeat(var(--mc-cols),1fr);
    gap:var(--mc-gap);
    flex:1;
    min-height:0;
    width:100%;
    box-sizing:border-box;
    overflow:hidden;
}

/* KEY FIX: cards use aspect-ratio so they always have visible height */
.mc-card,
.viewer-content .mc-card{
    position:relative;
    width:100%;
    aspect-ratio:16/10;
    cursor:pointer;
    border:none;
    background:transparent;
    padding:0;
    perspective:900px;
    display:block;
}

.mc-card:disabled{cursor:default;}

.mc-card-inner{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    transform-style:preserve-3d;
    transition:transform .45s ease;
}

.mc-card.is-flipped .mc-card-inner{transform:rotateY(180deg);}

.mc-card-face,
.mc-card-back{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    backface-visibility:hidden;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.mc-card-face{border:1px solid #EDE9FA;overflow:hidden;}

.mc-card-front{
    background:linear-gradient(145deg,var(--mc-purple),var(--mc-purple-dark));
    color:#e9e7fb;
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:clamp(22px,4vw,46px);
    box-shadow:0 14px 24px rgba(127,119,221,.35);
}

.mc-card-back{
    transform:rotateY(180deg);
    background:#fff;
    padding:4px;
    box-shadow:0 10px 20px rgba(15,23,42,.08);
}

.mc-card-back p{
    margin:0;
    text-align:center;
    color:#1e293b;
    font-weight:800;
    font-size:clamp(11px,1.8vw,18px);
    line-height:1.35;
    word-break:break-word;
}

.mc-card-back img{
    width:100%;
    height:100%;
    object-fit:contain;
    border-radius:10px;
}

.mc-card.is-matched .mc-card-face{border-color:#34d399;}
.mc-card.is-vanishing{pointer-events:none;}
.mc-card.is-vanishing .mc-card-inner{animation:mcVanish .34s ease forwards;}
.mc-card.is-hidden{opacity:0;visibility:hidden;pointer-events:none;}

.mc-restart-row{
    flex-shrink:0;
    display:flex;
    justify-content:center;
    padding:4px 0 0;
}

.mc-ingame-btn{
    border:none;
    border-radius:10px;
    padding:10px 24px;
    font-family:'Nunito',sans-serif;
    font-weight:900;
    font-size:14px;
    cursor:pointer;
    box-shadow:0 6px 18px rgba(127,119,221,.22);
    background:#7F77DD;
    color:#fff;
    transition:.18s;
}
.mc-ingame-btn:hover{transform:translateY(-2px);}

.mc-empty{text-align:center;padding:28px;font-weight:800;color:#b91c1c;}

.completed-screen{display:none;flex-direction:column;align-items:stretch;justify-content:flex-start;}
.completed-screen.active{display:flex;flex:1;}
.mc-activity.is-hidden{display:none;}

.mc-done{display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:clamp(28px,5vw,54px);gap:12px;}
.mc-done.active{display:flex;animation:mcPop .4s cubic-bezier(.2,.9,.2,1);}
@keyframes mcPop{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}
.mc-done-icon{font-size:clamp(54px,10vw,88px);}
.mc-done-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(30px,5vw,52px);font-weight:700;color:#F97316;line-height:1.05;}
.mc-done-text{margin:0;color:#9B94BE;font-size:clamp(13px,1.8vw,16px);font-weight:800;max-width:420px;}
.mc-done-track{height:12px;width:min(380px,90%);border-radius:999px;background:#F4F2FD;border:1px solid #E4E1F8;overflow:hidden;}
.mc-done-fill{height:100%;width:0%;border-radius:999px;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .8s cubic-bezier(.2,.9,.2,1);}
.mc-done-btns{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:4px;}
.mc-done-btn{border:none;border-radius:999px;padding:13px 28px;min-width:clamp(104px,16vw,140px);font-family:'Nunito',sans-serif;font-size:15px;font-weight:900;color:#fff;cursor:pointer;background:#F97316;transition:.18s;}
.mc-done-btn:hover{transform:translateY(-2px);}

@keyframes mcVanish{
    0%  {opacity:1;transform:scale(1);}
    100%{opacity:0;transform:scale(.7);}
}

@media(max-width:900px){
    :root{--mc-cols:<?= (int) $tabletCols ?>;--mc-gap:10px;}
    .mc-page{padding:0.7cm;}
    .mc-stage{border-radius:26px;padding:12px;}
}
@media(max-width:640px){
    :root{--mc-cols:<?= (int) $mobileCols ?>;--mc-gap:8px;}
    .mc-page{padding:0.5cm;}
}
@media(max-width:420px){
    :root{--mc-cols:2;}
}

/* ── Unified unscored completed screen ── */
.af-unscored__card{background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;padding:28px 32px;width:100%;max-width:100%;box-sizing:border-box;font-family:'Nunito','Segoe UI',sans-serif;}
.af-unscored__prog-label{font-size:11px;color:#9B8FCC;font-weight:700;letter-spacing:.06em;text-align:center;margin-bottom:6px;text-transform:uppercase;}
.af-unscored__prog-track{background:#EDE9FA;border-radius:99px;height:9px;overflow:hidden;margin-bottom:4px;}
.af-unscored__prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .4s ease;}
.af-unscored__prog-nums{display:flex;justify-content:space-between;font-size:11px;color:#9B8FCC;margin-bottom:16px;}
.af-unscored__prog-nums strong{color:#7F77DD;}
.af-unscored__icon{width:48px;height:48px;border-radius:50%;background:#EDE9FA;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.af-unscored__title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:20px;font-weight:600;color:#7F77DD;text-align:center;margin:0 0 3px;}
.af-unscored__sub{font-size:13px;color:#9B8FCC;font-weight:600;text-align:center;margin:0 0 16px;}
.af-unscored__chips{display:grid;gap:8px;margin-bottom:16px;}
.af-unscored__chips--2{grid-template-columns:1fr 1fr;}
.af-unscored__chips--3{grid-template-columns:1fr 1fr 1fr;}
.af-unscored__chip{background:#F9F8FF;border:1.5px solid #EDE9FA;border-radius:12px;padding:10px 6px;text-align:center;}
.af-unscored__chip-val{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#7F77DD;line-height:1;}
.af-unscored__chip-val--orange{color:#F97316;}
.af-unscored__chip-lbl{font-size:10px;color:#9B8FCC;font-weight:700;letter-spacing:.05em;margin-top:2px;text-transform:uppercase;}
.af-unscored__banner{border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.af-unscored__banner--orange{background:#FFF0E6;}
.af-unscored__banner--purple{background:#F5F3FF;}
.af-unscored__banner--green{background:#F0FDF4;}
.af-unscored__banner-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-unscored__banner-icon--orange{background:#F97316;}
.af-unscored__banner-icon--purple{background:#7F77DD;}
.af-unscored__banner-icon--green{background:#22c55e;}
.af-unscored__banner-text{font-size:12px;font-weight:600;}
.af-unscored__banner-text--orange{color:#b85a10;}
.af-unscored__banner-text--purple{color:#5046a6;}
.af-unscored__banner-text--green{color:#166534;}
.af-unscored__banner-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:15px;display:block;}
.af-unscored__btns{display:flex;gap:8px;}
.af-unscored__btn-primary{flex:1;background:#F97316;color:#fff;border:none;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.af-unscored__btn-secondary{flex:1;background:#fff;color:#7F77DD;border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
</style>

<div class="mc-page">
<div class="mc-app">
  <div class="mc-hero">
    <div class="mc-kicker">Activity</div>
    <h1><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Match every pair to complete the memory challenge.</p>
  </div>

  <div class="mc-stage">
    <div class="mc-viewer" id="mc-app">
<?php if (empty($cards)): ?>
      <div class="mc-shell">
        <div class="mc-empty">No pairs configured yet for this activity.</div>
      </div>
<?php else: ?>
      <section class="mc-shell mc-activity" id="mc-activity">
        <div class="mc-stats">
          <div class="mc-pill">Pairs: <span id="mc-total"><?= (int) $totalPairs ?></span></div>
          <div class="mc-pill">Matched: <span id="mc-matched">0</span></div>
          <div class="mc-pill">Moves: <span id="mc-moves">0</span></div>
        </div>

        <div id="mc-board" class="mc-board"></div>

        <div class="mc-restart-row">
          <button type="button" class="mc-ingame-btn" id="mc-restart">Restart</button>
        </div>
      </section>

      <div id="mc-complete" class="completed-screen"></div>

      <audio id="mc-audio-flip"  preload="auto" src="../../hangman/assets/card%20flip.mp3.mp3"></audio>
      <audio id="mc-audio-match" preload="auto" src="../../hangman/assets/swoosh%20sound.mp3"></audio>
      <audio id="mc-audio-lose"  preload="auto" src="../../hangman/assets/losefun.mp3"></audio>
      <audio id="mc-audio-win"   preload="auto" src="../../hangman/assets/win.mp3"></audio>

      <script>
(function () {
    const seedCards     = <?= json_encode($cards, JSON_UNESCAPED_UNICODE) ?>;
    const totalPairs    = <?= (int) $totalPairs ?>;
    const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;

    const board               = document.getElementById('mc-board');
    const matchedEl           = document.getElementById('mc-matched');
    const movesEl             = document.getElementById('mc-moves');
    const restartBtn          = document.getElementById('mc-restart');
    const completeEl          = document.getElementById('mc-complete');
    const activityEl          = document.getElementById('mc-activity');
    const matchAudioEl        = document.getElementById('mc-audio-match');
    const loseAudioEl         = document.getElementById('mc-audio-lose');
    const winAudioEl          = document.getElementById('mc-audio-win');

    if (!Array.isArray(seedCards) || seedCards.length < 2) { return; }

    let deck      = [];
    let selected  = [];
    let matched   = new Set();
    let lockBoard = false;
    let moves     = 0;

    const matchDelayMs            = 620;
    const vanishDurationMs        = 340;
    const mismatchFlipBackDelayMs = 760;
    const completedDelayMs        = 900;

    function shuffle(list) {
        const copy = list.slice();
        for (let i = copy.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const t = copy[i]; copy[i] = copy[j]; copy[j] = t;
        }
        return copy;
    }

    function escapeHtml(v) {
        return String(v)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function cardBackMarkup(card) {
        if ((card.type||'') === 'image' && (card.image||'').trim() !== '') {
            return '<img src="' + escapeHtml(card.image) + '" alt="Memory card image">';
        }
        return '<p>' + escapeHtml(card.text||'') + '</p>';
    }

    function updateStats() {
        if (matchedEl) matchedEl.textContent = String(Math.floor(matched.size / 2));
        if (movesEl)   movesEl.textContent   = String(moves);
    }

    function playAudio(el, vol) {
        if (!el) return;
        el.volume = Math.max(0, Math.min(1, vol||0.85));
        try { el.currentTime = 0; } catch(e) {}
        const p = el.play(); if (p) p.catch(function(){});
    }

    function playSound(kind) {
        if      (kind === 'match') playAudio(matchAudioEl, 0.85);
        else if (kind === 'lose')  playAudio(loseAudioEl,  0.9);
        else if (kind === 'win')   playAudio(winAudioEl,   0.9);
    }

    var MC_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;

    function navigateNext() {
        if (!MC_RETURN_TO) return;
        try {
            if (window.top && window.top !== window.self) {
                window.top.location.href = MC_RETURN_TO;
                return;
            }
        } catch(e) {}
        window.location.href = MC_RETURN_TO;
    }

    function showCompleted() {
        playSound('win');
        if (activityEl) activityEl.classList.add('is-hidden');
        window.setTimeout(function () {
            if (!completeEl) return;
            completeEl.innerHTML =
                '<div class="af-unscored__card">' +
                '  <div class="af-unscored__prog-label">PAIRS MATCHED</div>' +
                '  <div class="af-unscored__prog-track">' +
                '    <div class="af-unscored__prog-fill" id="af-prog-fill" style="width:100%"></div>' +
                '  </div>' +
                '  <div class="af-unscored__prog-nums">' +
                '    <span>0</span>' +
                '    <strong id="af-prog-text">' + totalPairs + ' / ' + totalPairs + '</strong>' +
                '  </div>' +
                '  <div class="af-unscored__icon">' +
                '    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7F77DD" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>' +
                '  </div>' +
                '  <p class="af-unscored__title">All matched!</p>' +
                '  <p class="af-unscored__sub">You matched all pairs in ' + moves + ' moves!</p>' +
                '  <div class="af-unscored__chips af-unscored__chips--3">' +
                '    <div class="af-unscored__chip">' +
                '      <div class="af-unscored__chip-val" id="af-stat1-val">' + totalPairs + '</div>' +
                '      <div class="af-unscored__chip-lbl">PAIRS</div>' +
                '    </div>' +
                '    <div class="af-unscored__chip">' +
                '      <div class="af-unscored__chip-val af-unscored__chip-val--orange" id="af-stat2-val">' + moves + '</div>' +
                '      <div class="af-unscored__chip-lbl">MOVES</div>' +
                '    </div>' +
                '    <div class="af-unscored__chip">' +
                '      <div class="af-unscored__chip-val" id="af-stat3-val">1</div>' +
                '      <div class="af-unscored__chip-lbl">ROUNDS</div>' +
                '    </div>' +
                '  </div>' +
                '  <div class="af-unscored__banner af-unscored__banner--orange">' +
                '    <div class="af-unscored__banner-icon af-unscored__banner-icon--orange">' +
                '      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8Z"/></svg>' +
                '    </div>' +
                '    <div class="af-unscored__banner-text af-unscored__banner-text--orange">' +
                '      <span class="af-unscored__banner-title">Memory champion!</span>' +
                '      Your memory is getting stronger.' +
                '    </div>' +
                '  </div>' +
                '  <div class="af-unscored__btns">' +
                '    <button class="af-unscored__btn-secondary" id="mc-done-restart">↺ Play again</button>' +
                '  </div>' +
                '</div>';
            completeEl.classList.add('active');
            var restBtn = document.getElementById('mc-done-restart');
            if (restBtn) restBtn.addEventListener('click', function () {
                completeEl.classList.remove('active');
                completeEl.innerHTML = '';
                restart();
            });
        }, completedDelayMs);
    }

    function handleCardClick(index) {
        if (lockBoard || matched.has(index)) return;
        if (selected.indexOf(index) !== -1)  return;

        selected.push(index);
        const node = board.querySelector('[data-card-index="' + index + '"]');
        if (node) node.classList.add('is-flipped');

        if (selected.length < 2) return;

        moves++;
        updateStats();

        const ai = selected[0], bi = selected[1];
        const a  = deck[ai],    b  = deck[bi];

        if (a && b && a.pairId === b.pairId) {
            lockBoard = true;
            matched.add(ai); matched.add(bi);
            playSound('match');

            const an = board.querySelector('[data-card-index="' + ai + '"]');
            const bn = board.querySelector('[data-card-index="' + bi + '"]');
            if (an) { an.classList.add('is-matched'); an.disabled = true; }
            if (bn) { bn.classList.add('is-matched'); bn.disabled = true; }

            selected = [];
            updateStats();

            const isFinal = matched.size === deck.length;
            window.setTimeout(function () {
                playSound('match');
                if (an) an.classList.add('is-vanishing');
                if (bn) bn.classList.add('is-vanishing');
                window.setTimeout(function () {
                    if (an) an.classList.add('is-hidden');
                    if (bn) bn.classList.add('is-hidden');
                    if (isFinal) { showCompleted(); } else { lockBoard = false; }
                }, vanishDurationMs);
            }, matchDelayMs);
            return;
        }

        lockBoard = true;
        playSound('lose');
        window.setTimeout(function () {
            const an = board.querySelector('[data-card-index="' + ai + '"]');
            const bn = board.querySelector('[data-card-index="' + bi + '"]');
            if (an) an.classList.remove('is-flipped');
            if (bn) bn.classList.remove('is-flipped');
            selected  = [];
            lockBoard = false;
        }, mismatchFlipBackDelayMs);
    }

    function renderBoard() {
        board.innerHTML = '';
        deck.forEach(function (card, index) {
            const btn         = document.createElement('button');
            btn.type          = 'button';
            btn.className     = 'mc-card';
            btn.dataset.cardIndex = String(index);
            btn.innerHTML     =
                '<span class="mc-card-inner">' +
                    '<span class="mc-card-face mc-card-front">?</span>' +
                    '<span class="mc-card-face mc-card-back">' + cardBackMarkup(card) + '</span>' +
                '</span>';
            btn.addEventListener('click', function () { handleCardClick(index); });
            board.appendChild(btn);
        });
    }

    function restart() {
        deck      = shuffle(seedCards);
        selected  = [];
        matched   = new Set();
        lockBoard = false;
        moves     = 0;
        if (activityEl) activityEl.classList.remove('is-hidden');
        if (completeEl) completeEl.classList.remove('active');
        renderBoard();
        updateStats();
    }

    if (restartBtn) restartBtn.addEventListener('click', restart);
    // completedRestartBtn is now injected by showPassiveDone

    restart();
})();
      </script>
<?php endif; ?>
    </div>
  </div>
</div>
</div>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fas fa-clone', $content);

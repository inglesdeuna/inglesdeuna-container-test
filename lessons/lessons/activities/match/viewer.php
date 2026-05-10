<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

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

function table_columns(PDO $pdo, string $tableName): array
{
    static $cache = array();

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = :table_name"
    );
    $stmt->execute(array('table_name' => $tableName));

    $cols = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cols[] = (string) $row['column_name'];
        }
    }

    $cache[$tableName] = $cols;
    return $cols;
}

function is_phase_one_or_two_course(PDO $pdo, string $unit, string $returnTo): bool
{
    $matchPhaseLabel = static function (string $value): bool {
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/\bPHASE\s*[12]\b/i', $value);
    };

    $decodedReturnTo = trim(rawurldecode($returnTo));
    if ($matchPhaseLabel($decodedReturnTo)) {
        return true;
    }

    if ($unit === '') {
        return false;
    }

    $unitCols = table_columns($pdo, 'units');
    if (empty($unitCols)) {
        return false;
    }

    $hasName = in_array('name', $unitCols, true);
    $hasPhaseId = in_array('phase_id', $unitCols, true);
    $phaseCols = table_columns($pdo, 'english_phases');
    $hasPhaseName = in_array('name', $phaseCols, true);

    if (!$hasName && !($hasPhaseId && $hasPhaseName)) {
        return false;
    }

    $selectParts = array();
    if ($hasName) {
        $selectParts[] = 'u.name AS unit_name';
    }
    if ($hasPhaseId && $hasPhaseName) {
        $selectParts[] = 'p.name AS phase_name';
    }

    $joinPhase = ($hasPhaseId && $hasPhaseName)
        ? ' LEFT JOIN english_phases p ON p.id::text = u.phase_id::text '
        : ' ';

    $sql =
        'SELECT ' . implode(', ', $selectParts)
        . ' FROM units u '
        . $joinPhase
        . 'WHERE (u.id::text = :unit_id OR u.name = :unit_name) '
        . 'LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        'unit_id' => $unit,
        'unit_name' => $unit,
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }

    $unitName = isset($row['unit_name']) ? trim((string) $row['unit_name']) : '';
    $phaseName = isset($row['phase_name']) ? trim((string) $row['phase_name']) : '';

    return $matchPhaseLabel($unitName) || $matchPhaseLabel($phaseName);
}

function default_match_title(): string
{
    return 'Match';
}

function normalize_match_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_match_title();
}

function normalize_match_payload(mixed $rawData): array
{
    $default = array(
        'title' => default_match_title(),
        'pairs' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $pairsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['pairs']) && is_array($decoded['pairs'])) {
        $pairsSource = $decoded['pairs'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $pairsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $pairsSource = $decoded['data'];
    }

    $pairs = array();

    if (is_array($pairsSource)) {
        foreach ($pairsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $legacyText = isset($item['text']) ? trim((string) $item['text']) : (isset($item['word']) ? trim((string) $item['word']) : '');
            $legacyImage = isset($item['image']) ? trim((string) $item['image']) : (isset($item['img']) ? trim((string) $item['img']) : '');

            $pairs[] = array(
                'id' => isset($item['id']) && trim((string) $item['id']) !== '' ? trim((string) $item['id']) : uniqid('match_'),
                'left_text' => isset($item['left_text']) ? trim((string) $item['left_text']) : '',
                'left_image' => isset($item['left_image']) ? trim((string) $item['left_image']) : $legacyImage,
                'right_text' => isset($item['right_text']) ? trim((string) $item['right_text']) : $legacyText,
                'right_image' => isset($item['right_image']) ? trim((string) $item['right_image']) : '',
            );
        }
    }

    return array(
        'title' => normalize_match_title($title),
        'pairs' => $pairs,
    );
}

function load_match_activity(PDO $pdo, string $activityId, string $unit): array
{
    $columns = activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_match_title(),
        'pairs' => array(),
    );

    $findById = function (string $id) use ($pdo, $selectFields): ?array {
        if ($id === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'match'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitId = function (string $unitId) use ($pdo, $selectFields, $columns): ?array {
        if ($unitId === '' || !in_array('unit_id', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'match'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitLegacy = function (string $unitValue) use ($pdo, $selectFields, $columns): ?array {
        if ($unitValue === '' || !in_array('unit', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'match'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitValue));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $row = null;

    if ($activityId !== '') {
        $row = $findById($activityId);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitId($unit);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitLegacy($unit);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_match_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_match_title((string) ($payload['title'] ?? '')),
        'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? $payload['pairs'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity    = load_match_activity($pdo, $activityId, $unit);
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_match_title();
$pairs       = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();

$hasImages = array_reduce($pairs, function ($carry, $item) {
    return $carry || trim((string) ($item['right_image'] ?? $item['left_image'] ?? '')) !== '';
}, false);

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --m-orange:#F97316;--m-orange-soft:#FFF0E6;--m-orange-dark:#C2580A;
    --m-purple:#7F77DD;--m-purple-dark:#534AB7;--m-purple-soft:#EEEDFE;
    --m-green:#22c55e;--m-green-soft:#f0fdf4;--m-green-dark:#15803d;
    --m-red:#ef4444;--m-red-soft:#fef2f2;
    --m-bg:#F5F3FF;--m-border:#EDE9FA;--m-ink:#271B5D;--m-muted:#9B94BE;
}
*{box-sizing:border-box;}
html,body{width:100%;height:100%;min-height:100%;}
body{margin:0!important;padding:0!important;background:var(--m-bg)!important;font-family:'Nunito','Segoe UI',sans-serif!important;}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;height:100%!important;min-height:0!important;display:flex!important;flex-direction:column!important;background:transparent!important;}
.top-row,.activity-header,.activity-title,.activity-subtitle,.viewer-header{display:none!important;}
.viewer-content{flex:1!important;min-height:0!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important;overflow:hidden!important;}

/* ── Page shell ── */
.m-page{width:100%;height:100%;min-height:0;padding:clamp(10px,2vw,24px) clamp(12px,2.4vw,28px);display:flex;justify-content:center;align-items:stretch;background:var(--m-bg);overflow:hidden;}
.m-app{width:min(960px,100%);height:100%;min-height:0;display:grid;grid-template-rows:auto minmax(0,1fr);gap:clamp(10px,1.8vw,16px);}

/* ── Header ── */
.m-hero{text-align:center;display:grid;gap:6px;justify-items:center;}
.m-kicker{display:inline-flex;align-items:center;padding:5px 14px;border-radius:999px;background:var(--m-orange-soft);border:1px solid #FCDDBF;color:var(--m-orange-dark);font-size:11px;font-weight:900;letter-spacing:.09em;text-transform:uppercase;}
.m-title{margin:0;font-family:'Fredoka',sans-serif;font-weight:700;font-size:clamp(28px,5vw,52px);color:var(--m-orange);line-height:1.05;}
.m-subtitle{margin:0;font-size:clamp(12px,1.6vw,15px);font-weight:800;color:var(--m-muted);}

/* ── Board ── */
.m-board{height:100%;min-height:0;background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(12px,2vw,20px);box-shadow:0 8px 40px rgba(127,119,221,.12);display:grid;grid-template-rows:auto auto minmax(0,1fr) auto auto auto;gap:12px;overflow:hidden;}

/* ── Progress ── */
.m-progress-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;}
.m-progress-track{height:10px;background:#F4F2FD;border:1px solid #E4E1F8;border-radius:999px;overflow:hidden;}
.m-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--m-orange),var(--m-purple));border-radius:999px;transition:width .4s ease;}
.m-badge{background:var(--m-purple);color:#fff;border-radius:999px;padding:5px 16px;font-size:12px;font-weight:900;white-space:nowrap;}

/* ── Hint ── */
.m-hint-wrap{display:flex;justify-content:center;}
.m-hint{display:inline-flex;align-items:center;padding:5px 14px;border-radius:999px;background:var(--m-orange-soft);border:1px solid #FCDDBF;color:var(--m-orange-dark);font-size:12px;font-weight:900;transition:background .2s,border-color .2s,color .2s;}
.m-hint.is-correct{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}
.m-hint.is-wrong{background:#FEF2F2;border-color:#FECACA;color:#B91C1C;}
.m-hint.is-complete{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}

/* ── Pairs table (prompt + slot) ── */
.m-pairs{display:grid;gap:10px;min-height:0;overflow:auto;padding-right:4px;align-content:start;}
.m-pair-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:center;}
.m-pair-row.has-images{grid-template-columns:1fr 1fr;}
.m-pair-row.has-images .m-prompt,
.m-pair-row.has-images .m-slot{min-height:clamp(90px,14vw,130px);padding:10px;}

/* ── Prompt card (left, fixed) ── */
.m-prompt{
    display:flex;align-items:center;justify-content:center;
    min-height:clamp(64px,10vw,90px);padding:10px 14px;
    border:2px solid var(--m-border);border-radius:18px;
    background:#FAFAFE;
    font-family:'Fredoka',sans-serif;font-size:clamp(15px,2vw,20px);font-weight:600;
    color:var(--m-ink);text-align:center;gap:8px;
    user-select:none;
}
.m-prompt img{max-width:min(100%,140px);max-height:100px;object-fit:contain;border-radius:10px;}

/* ── Drop slot (right, target) ── */
.m-slot{
    position:relative;
    display:flex;align-items:center;justify-content:center;
    min-height:clamp(64px,10vw,90px);padding:8px;
    border:2px dashed #C5C0F0;border-radius:18px;
    background:#FAFAFE;
    transition:border-color .18s,background .18s,box-shadow .18s;
    cursor:default;
}
.m-slot::before{
    content:'Drop here';
    position:absolute;
    font-size:11px;font-weight:900;color:var(--m-muted);letter-spacing:.05em;
    pointer-events:none;
}
.m-slot:not(:empty)::before{display:none;}
.m-slot.drag-over{border-color:var(--m-purple);background:var(--m-purple-soft);box-shadow:0 0 0 3px rgba(127,119,221,.18);}
.m-slot.is-correct{border-color:var(--m-green)!important;border-style:solid!important;background:var(--m-green-soft)!important;}
.m-slot.is-wrong{border-color:var(--m-red)!important;border-style:solid!important;background:var(--m-red-soft)!important;animation:m-shake .32s ease;}
.m-slot.is-answered{border-style:solid;border-color:var(--m-border);cursor:not-allowed;}

/* ── Draggable tile ── */
.m-tile{
    display:inline-flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;
    padding:10px 14px;min-height:clamp(56px,9vw,80px);
    border:2px solid var(--m-border);border-radius:16px;
    background:#fff;cursor:grab;user-select:none;
    font-family:'Fredoka',sans-serif;font-size:clamp(14px,1.9vw,19px);font-weight:600;
    color:var(--m-ink);text-align:center;
    box-shadow:0 3px 12px rgba(127,119,221,.09);
    transition:transform .18s,box-shadow .18s,border-color .18s,opacity .18s;
    touch-action:none;
}
.m-tile:active,.m-tile.dragging{cursor:grabbing;opacity:.5;transform:scale(.96);}
.m-tile:hover:not(.is-placed){transform:translateY(-3px);box-shadow:0 8px 22px rgba(127,119,221,.18);border-color:var(--m-purple);}
.m-tile.is-placed{cursor:default;border-color:var(--m-purple-dark);background:var(--m-purple-soft);color:var(--m-purple-dark);}
.m-tile.is-correct{border-color:var(--m-green);background:var(--m-green-soft);color:var(--m-green-dark);cursor:default;}
.m-tile.is-wrong-flash{border-color:var(--m-red);background:var(--m-red-soft);animation:m-shake .32s ease;}
.m-tile img{max-width:min(100%,100px);max-height:80px;object-fit:contain;border-radius:8px;pointer-events:none;}
.m-tile.has-image{min-width:clamp(96px,13vw,132px);min-height:clamp(96px,13vw,132px);}
.m-tile.has-image img{width:auto;height:auto;max-width:min(100%,120px);max-height:110px;object-fit:contain;}

/* ── Pool ── */
.m-pool-label{font-size:11px;font-weight:900;color:var(--m-muted);text-transform:uppercase;letter-spacing:.08em;text-align:center;margin-bottom:8px;}
.m-pool{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;min-height:56px;padding:14px;border:2px dashed #DDD9F8;border-radius:20px;background:#FAFAFE;transition:background .18s,border-color .18s;}
.m-pool{max-height:clamp(88px,18vh,170px);overflow:auto;align-content:flex-start;}
.m-pool.drag-over{background:var(--m-purple-soft);border-color:var(--m-purple);}

.m-pairs::-webkit-scrollbar,.m-pool::-webkit-scrollbar{width:8px;height:8px;}
.m-pairs::-webkit-scrollbar-thumb,.m-pool::-webkit-scrollbar-thumb{background:#D3CEF3;border-radius:999px;}
.m-pairs::-webkit-scrollbar-track,.m-pool::-webkit-scrollbar-track{background:transparent;}

/* ── Score ── */
.m-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;}
.m-score-grid.visible{display:grid;}
.m-score-card{background:#FAFAFE;border:1px solid var(--m-border);border-radius:14px;padding:12px;text-align:center;}
.m-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:28px;line-height:1;}
.m-score-num.c{color:#16a34a;}.m-score-num.w{color:var(--m-orange);}.m-score-num.p{color:var(--m-purple);}
.m-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:var(--m-muted);text-transform:uppercase;letter-spacing:.08em;}

/* ── Buttons ── */
.m-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;}
.m-btn{padding:12px 24px;border-radius:999px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;transition:transform .15s,filter .15s;border:none;min-width:120px;}
.m-btn:hover{transform:translateY(-2px);filter:brightness(1.07);}
.m-btn-check{background:var(--m-orange);color:#fff;box-shadow:0 6px 18px rgba(249,115,22,.22);}
.m-btn-answer{background:var(--m-purple);color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18);}
.m-btn-reset{background:#fff;color:var(--m-purple-dark);border:1.5px solid var(--m-border)!important;}

/* ── Ghost tile for touch drag ── */
#m-ghost{
    position:fixed;pointer-events:none;z-index:9999;opacity:.82;
    transform:rotate(-3deg) scale(1.06);
    border:2px solid var(--m-purple);background:#fff;
    border-radius:16px;padding:10px 14px;
    font-family:'Fredoka',sans-serif;font-size:clamp(14px,1.9vw,19px);font-weight:600;
    color:var(--m-ink);text-align:center;
    box-shadow:0 16px 40px rgba(127,119,221,.22);
    display:none;
    max-width:160px;
    align-items:center;justify-content:center;flex-direction:column;gap:6px;
}
#m-ghost img{max-width:100px;max-height:80px;object-fit:contain;border-radius:8px;}

/* ── Empty ── */
.m-empty{text-align:center;padding:48px 24px;color:var(--m-muted);font-size:17px;font-weight:800;}

/* ── Animations ── */
@keyframes m-shake{0%,100%{transform:translateX(0);}20%{transform:translateX(-7px);}40%{transform:translateX(7px);}60%{transform:translateX(-5px);}80%{transform:translateX(5px);}}
@keyframes m-pop{from{transform:scale(.88);opacity:0;}to{transform:scale(1);opacity:1;}}
.m-tile.popped{animation:m-pop .22s ease;}

@media(max-width:640px){
    .m-pair-row{grid-template-columns:1fr 1fr;}
    .m-page{padding:10px;}
    .m-board{padding:12px;border-radius:22px;gap:10px;}
    .m-score-grid{grid-template-columns:1fr;}
}
@media(max-width:420px){
    .m-pair-row{grid-template-columns:1fr;}
    .m-slot{min-height:54px;}
    .m-prompt{min-height:54px;}
}
</style>

<?php if (empty($pairs)): ?>
<div class="m-page"><div class="m-app"><div class="m-empty">No match data available.</div></div></div>
<?php else: ?>

<?php
/* Build pair data for JS */
$pairsJson = json_encode(array_values($pairs), JSON_UNESCAPED_UNICODE);
$leftHasImages  = (bool) array_reduce($pairs, fn($c,$p) => $c || trim((string)($p['left_image']??''))!=='', false);
$rightHasImages = (bool) array_reduce($pairs, fn($c,$p) => $c || trim((string)($p['right_image']??''))!=='', false);
?>

<div id="m-ghost"></div>

<div class="m-page">
    <div class="m-app">

        <header class="m-hero">
            <div class="m-kicker">Match Activity</div>
            <h1 class="m-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="m-subtitle">Drag each answer to its matching prompt.</p>
        </header>

        <main class="m-board">

            <div class="m-progress-row">
                <div class="m-progress-track">
                    <div class="m-progress-fill" id="m-fill"></div>
                </div>
                <div class="m-badge" id="m-badge">0 / <?= count($pairs) ?></div>
            </div>

            <div class="m-hint-wrap">
                <div class="m-hint" id="m-hint">Drag an answer onto its prompt</div>
            </div>

            <!-- Pair rows: prompt (left) + drop slot (right) -->
            <div class="m-pairs" id="m-pairs"></div>

            <!-- Answer pool -->
            <div>
                <div class="m-pool-label">Answers — drag to the matching prompt</div>
                <div class="m-pool" id="m-pool"></div>
            </div>

            <!-- Score -->
            <div class="m-score-grid" id="m-score-grid">
                <div class="m-score-card">
                    <div class="m-score-num c" id="m-s-correct">0</div>
                    <div class="m-score-lbl">Correct</div>
                </div>
                <div class="m-score-card">
                    <div class="m-score-num w" id="m-s-wrong">0</div>
                    <div class="m-score-lbl">Wrong</div>
                </div>
                <div class="m-score-card">
                    <div class="m-score-num p" id="m-s-pct">0%</div>
                    <div class="m-score-lbl">Score</div>
                </div>
            </div>

            <div class="m-actions">
                <button type="button" class="m-btn m-btn-check"  id="m-check">Check</button>
                <button type="button" class="m-btn m-btn-answer" id="m-answer">Show Answer</button>
            </div>

        </main>
    </div>
</div>

<script>
(function () {
'use strict';

const PAIRS     = <?= $pairsJson ?>;
const RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const ACT_ID    = <?= json_encode((string)($activity['id']??''), JSON_UNESCAPED_UNICODE) ?>;
const TOTAL     = PAIRS.length;

/* ── helpers ── */
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const byId = id => PAIRS.find(p => String(p.id) === String(id));
const leftText  = p => String(p.left_text  || p.text || p.word || '').trim();
const rightText = p => String(p.right_text || p.text || p.word || '').trim();
const leftImg   = p => String(p.left_image  || '').trim();
const rightImg  = p => String(p.right_image || p.image || p.img || '').trim();

function shuffle(arr) {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

/* ── DOM refs ── */
const fillEl   = document.getElementById('m-fill');
const badgeEl  = document.getElementById('m-badge');
const hintEl   = document.getElementById('m-hint');
const pairsEl  = document.getElementById('m-pairs');
const poolEl   = document.getElementById('m-pool');
const scoreGrid= document.getElementById('m-score-grid');
const sCorrect = document.getElementById('m-s-correct');
const sWrong   = document.getElementById('m-s-wrong');
const sPct     = document.getElementById('m-s-pct');
const ghost    = document.getElementById('m-ghost');

/* ── State ── */
let slots   = {};   // pairId → placed tileId (or null)
let wrongs  = 0;
let checked = new Set(); // pairIds that passed check
let answered= false;
let poolOrder = [];  // shuffled ids for answer pool rendering

/* ── Build tile HTML ── */
function tileHTML(p, extraClass) {
    const ri = rightImg(p);
    const rt = esc(rightText(p));
    const hasImg = ri !== '';
    let inner = '';
    if (hasImg) {
        inner = `<img src="${esc(ri)}" alt="${rt}" draggable="false">${rt ? '<span>'+rt+'</span>' : ''}`;
    } else {
        inner = rt;
    }
    const cls = ['m-tile', hasImg ? 'has-image' : '', extraClass||''].filter(Boolean).join(' ');
    return `<div class="${cls}" draggable="true" data-tile="${esc(String(p.id))}">${inner}</div>`;
}

/* ── Build prompt HTML (left side) ── */
function promptHTML(p) {
    const li = leftImg(p);
    const lt = esc(leftText(p));
    let inner = '';
    if (li) inner += `<img src="${esc(li)}" alt="${lt}">`;
    if (lt) inner += `<span>${lt}</span>`;
    return `<div class="m-prompt">${inner}</div>`;
}

/* ── Render ── */
function render() {
    /* pairs */
    pairsEl.innerHTML = PAIRS.map(p => {
        const pid = String(p.id);
        const pairHasImages = leftImg(p) !== '' || rightImg(p) !== '';
        const placedId = slots[pid];
        const isCheckedOk = checked.has(pid);
        const slotCls = ['m-slot', isCheckedOk ? 'is-correct' : '', answered&&!isCheckedOk&&placedId ? 'is-correct' : ''].filter(Boolean).join(' ');
        let slotContent = '';
        if (placedId !== undefined && placedId !== null) {
            const tp = byId(placedId);
            if (tp) {
                const ri = rightImg(tp);
                const rt = esc(rightText(tp));
                const hasImg = ri !== '';
                let inner = hasImg ? `<img src="${esc(ri)}" alt="${rt}" draggable="false">${rt?'<span>'+rt+'</span>':''}` : rt;
                const tc = ['m-tile', hasImg?'has-image':'', isCheckedOk||answered?'is-correct':'is-placed'].filter(Boolean).join(' ');
                slotContent = `<div class="${tc}" data-tile="${esc(placedId)}" data-in-slot="${esc(pid)}" draggable="true">${inner}</div>`;
            }
        }
        return `<div class="m-pair-row ${pairHasImages ? 'has-images' : ''}" data-pair-row="${esc(pid)}">
            ${promptHTML(p)}
            <div class="${slotCls}" data-slot="${esc(pid)}">${slotContent}</div>
        </div>`;
    }).join('');

    /* pool */
    const placedTiles = new Set(Object.values(slots).filter(v => v !== null));
    const pairById = new Map(PAIRS.map(p => [String(p.id), p]));
    const poolPairs = poolOrder
        .filter(id => !placedTiles.has(id))
        .map(id => pairById.get(id))
        .filter(Boolean);
    poolEl.innerHTML = poolPairs.map(p => tileHTML(p, 'popped')).join('') || '<span style="color:var(--m-muted);font-size:13px;font-weight:800;">All placed ✓</span>';

    /* progress */
    const placed = Object.values(slots).filter(v => v !== null).length;
    const pct = TOTAL > 0 ? Math.round((placed / TOTAL) * 100) : 0;
    fillEl.style.width = pct + '%';
    badgeEl.textContent = placed + ' / ' + TOTAL;

    bindDrag();
    bindTouch();
}

/* ── Drag (desktop) ── */
let dragTileId = null;
let dragFromSlot = null; // pairId if dragged from a slot, else null

function bindDrag() {
    document.querySelectorAll('.m-tile[draggable]').forEach(el => {
        el.addEventListener('dragstart', e => {
            dragTileId = el.dataset.tile;
            dragFromSlot = el.dataset.inSlot || null;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragTileId);
            setTimeout(() => el.classList.add('dragging'), 0);
        });
        el.addEventListener('dragend', () => {
            dragTileId = null;
            dragFromSlot = null;
            document.querySelectorAll('.m-tile.dragging').forEach(t => t.classList.remove('dragging'));
        });
    });

    document.querySelectorAll('.m-slot').forEach(slot => {
        slot.addEventListener('dragover', e => {
            if (slot.classList.contains('is-correct')) return;
            e.preventDefault();
            slot.classList.add('drag-over');
        });
        slot.addEventListener('dragleave', () => slot.classList.remove('drag-over'));
        slot.addEventListener('drop', e => {
            e.preventDefault();
            slot.classList.remove('drag-over');
            const tileId = e.dataTransfer.getData('text/plain') || dragTileId;
            if (!tileId) return;
            dropTileOnSlot(tileId, slot.dataset.slot, dragFromSlot);
        });
    });

    /* drop back on pool */
    poolEl.addEventListener('dragover', e => {
        e.preventDefault();
        poolEl.classList.add('drag-over');
    });
    poolEl.addEventListener('dragleave', () => poolEl.classList.remove('drag-over'));
    poolEl.addEventListener('drop', e => {
        e.preventDefault();
        poolEl.classList.remove('drag-over');
        const tileId = e.dataTransfer.getData('text/plain') || dragTileId;
        if (!tileId || !dragFromSlot) return;
        /* return to pool */
        if (!checked.has(dragFromSlot)) {
            slots[dragFromSlot] = null;
            render();
        }
    });
}

/* ── Drop logic ── */
function dropTileOnSlot(tileId, targetPairId, fromSlot) {
    if (checked.has(targetPairId)) return; /* locked */

    /* if target slot is occupied, displace that tile back to pool */
    if (slots[targetPairId] !== null && slots[targetPairId] !== undefined) {
        const displaced = slots[targetPairId];
        /* find the original slot of the displaced tile and clear it */
        Object.keys(slots).forEach(pid => {
            if (slots[pid] === displaced && pid !== targetPairId) slots[pid] = null;
        });
    }

    /* clear source slot */
    if (fromSlot && fromSlot !== targetPairId) {
        slots[fromSlot] = null;
    }

    slots[targetPairId] = tileId;
    render();
    setHint('Placed! Keep going…', null);
}

/* ── Touch drag ── */
let touchTileEl = null;
let touchFromSlot = null;
let touchTileId = null;

function bindTouch() {
    document.querySelectorAll('.m-tile[draggable]').forEach(el => {
        el.addEventListener('touchstart', onTouchStart, {passive:false});
    });
}

function onTouchStart(e) {
    if (e.touches.length !== 1) return;
    const el = e.currentTarget;
    if (el.closest('.m-slot')?.classList.contains('is-correct')) return;
    e.preventDefault();
    touchTileId   = el.dataset.tile;
    touchFromSlot = el.dataset.inSlot || null;
    touchTileEl   = el;

    /* build ghost */
    ghost.innerHTML = el.innerHTML;
    ghost.style.display = 'flex';
    ghost.style.width   = el.offsetWidth + 'px';
    moveGhost(e.touches[0]);

    el.classList.add('dragging');
    document.addEventListener('touchmove', onTouchMove, {passive:false});
    document.addEventListener('touchend',  onTouchEnd,  {passive:false});
}

function moveGhost(touch) {
    ghost.style.left = (touch.clientX - ghost.offsetWidth  / 2) + 'px';
    ghost.style.top  = (touch.clientY - ghost.offsetHeight / 2) + 'px';
}

function onTouchMove(e) {
    if (!touchTileEl) return;
    e.preventDefault();
    moveGhost(e.touches[0]);
    /* highlight slot under finger */
    document.querySelectorAll('.m-slot.drag-over').forEach(s => s.classList.remove('drag-over'));
    const slot = slotFromPoint(e.touches[0].clientX, e.touches[0].clientY);
    if (slot) slot.classList.add('drag-over');
    poolEl.classList.toggle('drag-over', !!poolFromPoint(e.touches[0].clientX, e.touches[0].clientY));
}

function onTouchEnd(e) {
    document.removeEventListener('touchmove', onTouchMove);
    document.removeEventListener('touchend',  onTouchEnd);
    ghost.style.display = 'none';
    document.querySelectorAll('.m-slot.drag-over').forEach(s => s.classList.remove('drag-over'));
    poolEl.classList.remove('drag-over');
    if (!touchTileEl) return;
    touchTileEl.classList.remove('dragging');

    const t = e.changedTouches[0];
    const slot = slotFromPoint(t.clientX, t.clientY);
    if (slot) {
        dropTileOnSlot(touchTileId, slot.dataset.slot, touchFromSlot);
    } else if (poolFromPoint(t.clientX, t.clientY) && touchFromSlot) {
        if (!checked.has(touchFromSlot)) {
            slots[touchFromSlot] = null;
            render();
        }
    }
    touchTileEl   = null;
    touchTileId   = null;
    touchFromSlot = null;
}

function slotFromPoint(x, y) {
    return [...document.querySelectorAll('.m-slot')].find(s => {
        const r = s.getBoundingClientRect();
        return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
    }) || null;
}
function poolFromPoint(x, y) {
    const r = poolEl.getBoundingClientRect();
    return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
}

/* ── Hint ── */
function setHint(text, state) {
    hintEl.textContent = text;
    hintEl.className = 'm-hint' + (state ? ' is-' + state : '');
}

/* ── Check ── */
function checkAnswers() {
    let anyNew = false;
    PAIRS.forEach(p => {
        const pid = String(p.id);
        if (checked.has(pid)) return;
        const placed = slots[pid];
        if (!placed) return;
        anyNew = true;
        if (String(placed) === String(p.id)) {
            checked.add(pid);
        } else {
            wrongs++;
            /* flash wrong on slot */
            const slotEl = document.querySelector(`[data-slot="${CSS.escape(pid)}"]`);
            if (slotEl) {
                slotEl.classList.add('is-wrong');
                setTimeout(() => {
                    slotEl.classList.remove('is-wrong');
                    slots[pid] = null;
                    render();
                }, 500);
            } else {
                slots[pid] = null;
                render();
            }
        }
    });

    if (!anyNew) {
        setHint('Place all answers first', 'wrong');
        return;
    }

    updateScores(checked.size === TOTAL);
    if (checked.size === TOTAL) {
        setHint('🎉 All matched!', 'complete');
        render();
        persistScore();
    } else {
        setHint(checked.size + ' correct, keep going…', 'correct');
        render();
    }
}

/* ── Show Answer ── */
function showAnswers() {
    answered = true;
    PAIRS.forEach(p => {
        slots[String(p.id)] = String(p.id);
        checked.add(String(p.id));
    });
    setHint('🎉 All answers shown!', 'complete');
    updateScores(true);
    render();
}

/* ── Reset ── */
function resetGame() {
    slots   = {};
    wrongs  = 0;
    checked = new Set();
    answered= false;
    poolOrder = shuffle(PAIRS.map(p => String(p.id)));
    PAIRS.forEach(p => { slots[String(p.id)] = null; });
    setHint('Drag an answer onto its prompt');
    updateScores(false);
    render();
}

/* ── Scores ── */
function updateScores(show) {
    const attempts = checked.size + wrongs;
    const pct = attempts > 0 ? Math.round((checked.size / attempts) * 100) : 0;
    sCorrect.textContent = checked.size;
    sWrong.textContent   = wrongs;
    sPct.textContent     = pct + '%';
    scoreGrid.classList.toggle('visible', !!show);
}

/* ── Persist ── */
async function persistScore() {
    if (!RETURN_TO || !ACT_ID) return;
    const attempts = checked.size + wrongs;
    const pct = attempts > 0 ? Math.round((checked.size / attempts) * 100) : 100;
    const sep = RETURN_TO.includes('?') ? '&' : '?';
    const url = RETURN_TO + sep + 'activity_percent=' + pct + '&activity_errors=' + wrongs + '&activity_total=' + TOTAL + '&activity_id=' + encodeURIComponent(ACT_ID) + '&activity_type=match';
    try {
        const r = await fetch(url, {method:'GET',credentials:'same-origin',cache:'no-store'});
        if (!r.ok) window.location.href = url;
    } catch(e) { window.location.href = url; }
}

/* ── Wire buttons ── */
document.getElementById('m-check') .addEventListener('click', checkAnswers);
document.getElementById('m-answer').addEventListener('click', showAnswers);

resetGame();
})();
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);

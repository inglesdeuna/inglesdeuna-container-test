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
html,body{width:100%;min-height:100%;}
body{margin:0!important;padding:0!important;background:var(--m-bg)!important;font-family:'Nunito','Segoe UI',sans-serif!important;}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important;}
.top-row,.activity-header,.activity-title,.activity-subtitle,.viewer-header{display:none!important;}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important;}
.mc-page{width:100%;min-height:100vh;padding:clamp(16px,3vw,40px);box-sizing:border-box;display:flex;justify-content:center;align-items:flex-start;background:var(--mc-bg);}
.mc-app{width:min(900px,100%);display:grid;gap:clamp(16px,2vw,22px);}
.mc-hero{text-align:center;display:grid;gap:8px;justify-items:center;}
.mc-kicker{display:inline-flex;align-items:center;padding:6px 16px;border-radius:999px;background:var(--mc-orange-soft);border:1px solid #FCDDBF;color:var(--mc-orange-dark);font-size:11px;font-weight:900;letter-spacing:.09em;text-transform:uppercase;}
.mc-title{margin:0;font-family:'Fredoka',sans-serif;font-weight:700;font-size:clamp(30px,5.5vw,56px);color:var(--mc-orange);line-height:1.05;}
.mc-subtitle{margin:0;font-size:clamp(13px,1.8vw,16px);font-weight:800;color:var(--mc-muted);}
.mc-board{background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:clamp(16px,3vw,28px);box-shadow:0 8px 40px rgba(127,119,221,.12);display:grid;gap:16px;}
.mc-progress-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;}
.mc-progress-track{height:10px;background:#F4F2FD;border:1px solid #E4E1F8;border-radius:999px;overflow:hidden;}
.mc-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--mc-orange),var(--mc-purple));border-radius:999px;transition:width .4s ease;}
.mc-score-badge{background:var(--mc-purple);color:#fff;border-radius:999px;padding:5px 16px;font-size:12px;font-weight:900;white-space:nowrap;}
.mc-toggle{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.mc-mode-btn{padding:8px 18px;border-radius:999px;border:1.5px solid var(--mc-border);background:#fff;color:var(--mc-purple-dark);font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;cursor:pointer;transition:background .18s,border-color .18s,color .18s;}
.mc-mode-btn.active{background:var(--mc-purple);border-color:var(--mc-purple);color:#fff;}
.mc-hint-wrap{display:flex;justify-content:center;}
.mc-hint{display:inline-flex;align-items:center;padding:5px 14px;border-radius:999px;background:var(--mc-orange-soft);border:1px solid #FCDDBF;color:var(--mc-orange-dark);font-size:12px;font-weight:900;transition:background .2s,border-color .2s,color .2s;}
.mc-hint.hint-selected{background:var(--mc-purple-soft);border-color:#C5C0F0;color:var(--mc-purple-dark);}
.mc-hint.hint-correct{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}
.mc-hint.hint-wrong{background:#FEF2F2;border-color:#FECACA;color:#B91C1C;}
.mc-hint.hint-complete{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}
.mc-section-label{font-size:11px;font-weight:900;color:var(--mc-muted);text-transform:uppercase;letter-spacing:.09em;text-align:center;margin-bottom:6px;}
.mc-words-row{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;}
.mc-word-card{padding:12px 22px;border:2px solid var(--mc-border);border-radius:14px;background:#fff;font-family:'Fredoka',sans-serif;font-size:clamp(16px,2.2vw,20px);font-weight:600;color:var(--mc-ink);cursor:pointer;transition:transform .18s,box-shadow .18s,border-color .18s,background .18s;user-select:none;min-width:80px;text-align:center;box-shadow:0 3px 10px rgba(127,119,221,.07);}
.mc-word-card:hover:not(.matched):not(.wrong){transform:translateY(-3px);box-shadow:0 8px 22px rgba(127,119,221,.16);}
.mc-word-card.selected{border-color:var(--mc-orange);background:var(--mc-orange-soft);box-shadow:0 0 0 3px rgba(249,115,22,.18);transform:translateY(-2px);}
.mc-word-card.matched{border-color:var(--mc-green);background:var(--mc-green-soft);cursor:default;pointer-events:none;color:#15803d;}
.mc-word-card.wrong{border-color:var(--mc-red);background:var(--mc-red-soft);animation:mc-shake .3s ease;pointer-events:none;}
.mc-divider{display:flex;align-items:center;gap:10px;}
.mc-divider::before,.mc-divider::after{content:'';flex:1;height:1px;background:var(--mc-border);}
.mc-imgs-row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;}
.mc-img-card{width:clamp(90px,12vw,120px);height:clamp(90px,12vw,120px);border:2px solid var(--mc-border);border-radius:14px;background:#fff;cursor:pointer;overflow:hidden;display:flex;align-items:center;justify-content:center;transition:transform .18s,box-shadow .18s,border-color .18s,background .18s;user-select:none;font-family:'Fredoka',sans-serif;font-size:clamp(14px,2vw,18px);font-weight:600;color:var(--mc-ink);text-align:center;padding:8px;box-sizing:border-box;box-shadow:0 4px 14px rgba(127,119,221,.08);}
.mc-img-card img,.mc-img-card video{width:100%;height:100%;object-fit:cover;display:block;border-radius:12px;}
.mc-img-card:hover:not(.matched):not(.wrong){transform:translateY(-3px);box-shadow:0 8px 24px rgba(127,119,221,.18);}
.mc-img-card.selected{border-color:var(--mc-purple);box-shadow:0 0 0 3px rgba(127,119,221,.2);transform:translateY(-2px);}
.mc-img-card.matched{border-color:var(--mc-green);background:var(--mc-green-soft);cursor:default;pointer-events:none;}
.mc-img-card.wrong{border-color:var(--mc-red);background:var(--mc-red-soft);animation:mc-shake .3s ease;pointer-events:none;}
@keyframes mc-shake{0%,100%{transform:translateX(0);}20%{transform:translateX(-8px);}40%{transform:translateX(8px);}60%{transform:translateX(-6px);}80%{transform:translateX(6px);}}
.mc-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;}
.mc-btn{padding:12px 22px;border-radius:999px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;transition:transform .15s,filter .15s;border:none;min-width:120px;}
.mc-btn:hover{transform:translateY(-2px);filter:brightness(1.07);}
.mc-btn-check{background:var(--mc-orange);color:#fff;box-shadow:0 6px 18px rgba(249,115,22,.22);}
.mc-btn-answer{background:var(--mc-purple);color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18);}
.mc-btn-reset{background:#fff;color:var(--mc-purple-dark);border:1.5px solid var(--mc-border)!important;}
.mc-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;}
.mc-score-grid.visible{display:grid;}
.mc-score-card{background:#FAFAFE;border:1px solid var(--mc-border);border-radius:14px;padding:12px;text-align:center;}
.mc-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:28px;line-height:1;}
.mc-score-num.is-correct{color:#16a34a;}.mc-score-num.is-wrong{color:var(--mc-orange);}.mc-score-num.is-pct{color:var(--mc-purple);}
.mc-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:var(--mc-muted);text-transform:uppercase;letter-spacing:.08em;}
.mc-empty{text-align:center;padding:48px 24px;color:var(--mc-muted);font-size:17px;font-weight:800;}
@media(max-width:600px){.mc-board{padding:16px;border-radius:22px;}.mc-score-grid{grid-template-columns:1fr;}.mc-img-card{width:clamp(80px,25vw,100px);height:clamp(80px,25vw,100px);}}
</style>

<?php if (empty($pairs)): ?>
<div class="mc-page"><div class="mc-app"><div class="mc-empty">No match data available.</div></div></div>
<?php else: ?>

<div class="mc-page">
    <div class="mc-app">

        <header class="mc-hero">
            <div class="mc-kicker">Match Activity</div>
            <h1 class="mc-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="mc-subtitle">Tap a word, then tap its matching pair.</p>
        </header>

        <main class="mc-board">

            <div class="mc-progress-row">
                <div class="mc-progress-track">
                    <div class="mc-progress-fill" id="mc-fill"></div>
                </div>
                <div class="mc-score-badge" id="mc-badge">0 / <?= count($pairs) ?></div>
            </div>

            <?php if ($hasImages): ?>
            <div class="mc-toggle">
                <button type="button" class="mc-mode-btn active" data-mode="image">Image only</button>
                <button type="button" class="mc-mode-btn" data-mode="text">Text only</button>
            </div>
            <?php endif; ?>

            <div class="mc-hint-wrap">
                <div class="mc-hint" id="mc-hint">Tap a word to start</div>
            </div>

            <div>
                <div class="mc-section-label">Words</div>
                <div class="mc-words-row" id="mc-words"></div>
            </div>

            <div class="mc-divider"></div>

            <div>
                <div class="mc-section-label" id="mc-img-label">Match</div>
                <div class="mc-imgs-row" id="mc-imgs"></div>
            </div>

            <div class="mc-score-grid" id="mc-score-grid">
                <div class="mc-score-card">
                    <div class="mc-score-num is-correct" id="mc-s-correct">0</div>
                    <div class="mc-score-lbl">Correct</div>
                </div>
                <div class="mc-score-card">
                    <div class="mc-score-num is-wrong" id="mc-s-wrong">0</div>
                    <div class="mc-score-lbl">Wrong</div>
                </div>
                <div class="mc-score-card">
                    <div class="mc-score-num is-pct" id="mc-s-pct">0%</div>
                    <div class="mc-score-lbl">Score</div>
                </div>
            </div>

            <div class="mc-actions">
                <button type="button" class="mc-btn mc-btn-check" id="mc-check">Check</button>
                <button type="button" class="mc-btn mc-btn-answer" id="mc-answer">Show Answer</button>
                <button type="button" class="mc-btn mc-btn-reset" id="mc-reset">Reset</button>
            </div>

        </main>
    </div>
</div>

<script>
(function () {
const PAIRS     = <?= json_encode(array_values($pairs), JSON_UNESCAPED_UNICODE) ?>;
const RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
const ACT_ID    = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
const TOTAL     = PAIRS.length;
const HAS_IMG   = <?= $hasImages ? 'true' : 'false' ?>;

const fillEl    = document.getElementById('mc-fill');
const badgeEl   = document.getElementById('mc-badge');
const hintEl    = document.getElementById('mc-hint');
const wordsEl   = document.getElementById('mc-words');
const imgsEl    = document.getElementById('mc-imgs');
const imgLabel  = document.getElementById('mc-img-label');
const scoreGrid = document.getElementById('mc-score-grid');
const sCorrect  = document.getElementById('mc-s-correct');
const sWrong    = document.getElementById('mc-s-wrong');
const sPct      = document.getElementById('mc-s-pct');

let mode      = HAS_IMG ? 'image' : 'text';
let matched   = new Set();
let wrongs    = 0;
let selWord   = null;
let animating = false;
let wordOrder = [];
let imgOrder  = [];

const esc   = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const byId  = id => PAIRS.find(p => String(p.id) === String(id));
const word  = p => String(p.left_text  || p.right_text || p.text || p.word || '').trim();
const match = p => String(p.right_text || p.left_text  || p.text || p.word || '').trim();
const img   = p => String(p.right_image || p.left_image || p.image || p.img || '').trim();

function shuffle(arr) {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

function setHint(text, cls) {
    hintEl.textContent = text;
    hintEl.className = 'mc-hint' + (cls ? ' hint-' + cls : '');
}

function updateProgress() {
    const pct = TOTAL > 0 ? Math.round((matched.size / TOTAL) * 100) : 0;
    fillEl.style.width = pct + '%';
    badgeEl.textContent = matched.size + ' / ' + TOTAL;
}

function updateScores(show) {
    const attempts = matched.size + wrongs;
    const pct = attempts > 0 ? Math.round((matched.size / attempts) * 100) : 0;
    sCorrect.textContent = matched.size;
    sWrong.textContent   = wrongs;
    sPct.textContent     = pct + '%';
    scoreGrid.classList.toggle('visible', !!show);
}

function renderWords() {
    wordsEl.innerHTML = wordOrder.map(id => {
        const p = byId(id);
        if (!p) return '';
        const cls = ['mc-word-card', matched.has(id) ? 'matched' : '', selWord === id ? 'selected' : ''].filter(Boolean).join(' ');
        return `<button type="button" class="${cls}" data-id="${esc(id)}">${esc(word(p))}</button>`;
    }).join('');
    wordsEl.querySelectorAll('[data-id]').forEach(btn => btn.addEventListener('click', () => tapWord(btn.dataset.id)));
}

function renderImgs() {
    imgLabel.textContent = mode === 'image' ? 'Images' : 'Match';
    imgsEl.innerHTML = imgOrder.map(id => {
        const p = byId(id);
        if (!p) return '';
        const cls = ['mc-img-card', matched.has(id) ? 'matched' : ''].filter(Boolean).join(' ');
        let inner = '';
        if (mode === 'image') {
            const src = img(p);
            if (src) {
                inner = src.match(/\.(mp4|webm|ogg|mov|m4v)$/i)
                    ? `<video src="${esc(src)}" autoplay muted loop playsinline></video>`
                    : `<img src="${esc(src)}" alt="${esc(match(p))}">`;
            } else {
                inner = esc(match(p));
            }
        } else {
            inner = esc(match(p));
        }
        return `<button type="button" class="${cls}" data-id="${esc(id)}">${inner}</button>`;
    }).join('');
    imgsEl.querySelectorAll('[data-id]').forEach(btn => btn.addEventListener('click', () => tapImg(btn.dataset.id)));
}

function render() {
    renderWords();
    renderImgs();
    updateProgress();
}

function tapWord(id) {
    if (animating || matched.has(id)) return;
    selWord = id;
    setHint((word(byId(id)) || 'Word') + ' selected — tap its match', 'selected');
    render();
}

function tapImg(id) {
    if (animating || matched.has(id)) return;
    if (!selWord) { setHint('Tap a word first', 'wrong'); return; }
    if (String(selWord) === String(id)) {
        matched.add(selWord);
        selWord = null;
        updateProgress();
        if (matched.size === TOTAL) {
            setHint('🎉 All pairs matched!', 'complete');
            updateScores(true);
            render();
            persistScore();
        } else {
            setHint('Correct! Keep going ✓', 'correct');
            render();
        }
    } else {
        wrongs++;
        animating = true;
        setHint('Not a match — try again', 'wrong');
        const wBtn = wordsEl.querySelector(`[data-id="${CSS.escape(selWord)}"]`);
        const iBtn = imgsEl.querySelector(`[data-id="${CSS.escape(id)}"]`);
        if (wBtn) { wBtn.classList.add('wrong'); wBtn.classList.remove('selected'); }
        if (iBtn) iBtn.classList.add('wrong');
        setTimeout(() => {
            animating = false;
            selWord   = null;
            setHint('Tap a word to start');
            render();
        }, 600);
    }
}

function checkUnmatched() {
    if (matched.size === TOTAL) { updateScores(true); return; }
    wordsEl.querySelectorAll('.mc-word-card:not(.matched)').forEach(b => { b.classList.add('wrong'); b.style.pointerEvents = 'none'; });
    imgsEl.querySelectorAll('.mc-img-card:not(.matched)').forEach(b => { b.classList.add('wrong'); b.style.pointerEvents = 'none'; });
    setHint((TOTAL - matched.size) + ' pair(s) still unmatched', 'wrong');
    setTimeout(() => {
        wordsEl.querySelectorAll('.mc-word-card.wrong').forEach(b => { b.classList.remove('wrong'); b.style.pointerEvents = ''; });
        imgsEl.querySelectorAll('.mc-img-card.wrong').forEach(b => { b.classList.remove('wrong'); b.style.pointerEvents = ''; });
        setHint('Tap a word to start');
    }, 700);
}

function showAnswers() {
    PAIRS.forEach(p => matched.add(String(p.id)));
    selWord = null; animating = false;
    setHint('🎉 All pairs shown!', 'complete');
    updateScores(true);
    render();
}

function resetGame() {
    matched   = new Set();
    wrongs    = 0;
    selWord   = null;
    animating = false;
    wordOrder = shuffle(PAIRS.map(p => String(p.id)));
    imgOrder  = shuffle(PAIRS.map(p => String(p.id)));
    setHint('Tap a word to start');
    updateScores(false);
    render();
}

async function persistScore() {
    if (!RETURN_TO || !ACT_ID) return;
    const attempts = matched.size + wrongs;
    const pct      = attempts > 0 ? Math.round((matched.size / attempts) * 100) : 100;
    const sep      = RETURN_TO.includes('?') ? '&' : '?';
    const url      = RETURN_TO + sep + 'activity_percent=' + pct + '&activity_errors=' + wrongs + '&activity_total=' + TOTAL + '&activity_id=' + encodeURIComponent(ACT_ID) + '&activity_type=match';
    try {
        const r = await fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' });
        if (!r.ok) window.location.href = url;
    } catch (e) { window.location.href = url; }
}

document.querySelectorAll('.mc-mode-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        mode = btn.dataset.mode;
        document.querySelectorAll('.mc-mode-btn').forEach(b => b.classList.toggle('active', b === btn));
        selWord = null;
        setHint('Tap a word to start');
        render();
    });
});

document.getElementById('mc-check').addEventListener('click', checkUnmatched);
document.getElementById('mc-answer').addEventListener('click', showAnswers);
document.getElementById('mc-reset').addEventListener('click', resetGame);

resetGame();
})();
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);

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

function normalize_match_payload($rawData): array
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

$activity = load_match_activity($pdo, $activityId, $unit);
$isPhaseOneOrTwo = is_phase_one_or_two_course($pdo, $unit, $returnTo);
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_match_title();
$pairs = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();
$matchCssVersion = (string) (@filemtime(__DIR__ . '/match.css') ?: time());
$matchJsVersion = (string) (@filemtime(__DIR__ . '/match.js') ?: time());

ob_start();

$hasImages = array_reduce($pairs, function ($carry, $item) {
    return $carry || trim((string) ($item['right_image'] ?? $item['left_image'] ?? '')) !== '';
}, false);
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --mc-orange:#F97316;
    --mc-orange-soft:#FFF0E6;
    --mc-orange-dark:#C2580A;
    --mc-purple:#7F77DD;
    --mc-purple-dark:#534AB7;
    --mc-purple-soft:#EEEDFE;
    --mc-green:#22c55e;
    --mc-green-soft:#f0fdf4;
    --mc-red:#ef4444;
    --mc-red-soft:#fef2f2;
    --mc-bg:#F5F3FF;
    --mc-border:#EDE9FA;
    --mc-ink:#271B5D;
    --mc-muted:#9B94BE;
}
html,body{width:100%;min-height:100%;}
body{margin:0!important;padding:0!important;background:var(--mc-bg)!important;font-family:'Nunito','Segoe UI',sans-serif!important;}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;min-height:100vh;display:flex!important;flex-direction:column!important;background:transparent!important;}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important;}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important;}
.viewer-header{display:none!important;}

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
.mc-score-badge{background:var(--mc-purple);color:#fff;border-radius:999px;padding:5px 16px;font-size:12px;font-weight:900;white-space:nowrap;font-family:'Nunito',sans-serif;}
.mc-toggle{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.mc-mode-btn{padding:8px 18px;border-radius:999px;border:1.5px solid var(--mc-border);background:#fff;color:var(--mc-purple-dark);font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;cursor:pointer;transition:background .18s,border-color .18s,color .18s;}
.mc-mode-btn.active{background:var(--mc-purple);border-color:var(--mc-purple);color:#fff;}
.mc-hint-wrap{display:flex;justify-content:center;}
.mc-hint{display:inline-flex;align-items:center;padding:5px 14px;border-radius:999px;background:var(--mc-orange-soft);border:1px solid #FCDDBF;color:var(--mc-orange-dark);font-size:12px;font-weight:900;font-family:'Nunito',sans-serif;transition:background .2s,border-color .2s,color .2s;}
.mc-hint.hint-selected{background:var(--mc-purple-soft);border-color:#C5C0F0;color:var(--mc-purple-dark);}
.mc-hint.hint-correct{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}
.mc-hint.hint-wrong{background:#FEF2F2;border-color:#FECACA;color:#B91C1C;}
.mc-hint.hint-complete{background:#E6F9F2;border-color:#9FE1CB;color:#0F6E56;}
.mc-section-label{font-size:11px;font-weight:900;color:var(--mc-muted);text-transform:uppercase;letter-spacing:.09em;font-family:'Nunito',sans-serif;text-align:center;margin-bottom:6px;}
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

/* legacy classes kept empty to avoid conflicts */
.match-shell,.match-app,.match-chip,.match-card-chip,.match-text-chip{}

.match-hero{
    text-align:center;
    display:grid;
    justify-items:center;
    gap:8px;
}

.match-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:#FFF0E6;
    border:1px solid #FCDDBF;
    color:#C2580A;
    border-radius:999px;
    padding:5px 14px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:11px;
    line-height:1;
    text-transform:uppercase;
    letter-spacing:.09em;
}

.match-title{
    margin:0;
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-weight:700;
    color:#F97316;
    font-size:clamp(30px, 5.5vw, 58px);
    line-height:1;
}

.match-subtitle{
    margin:0;
    color:#9B94BE;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:800;
    font-size:15px;
}

.match-board{
    background:#ffffff;
    border:1px solid #F0EEF8;
    border-radius:28px;
    padding:22px;
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    overflow:visible;
}

.match-progress{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:12px;
    align-items:center;
    margin-bottom:14px;
}

.match-progress-track{
    height:9px;
    background:#F4F2FD;
    border:1px solid #E4E1F8;
    border-radius:999px;
    overflow:hidden;
}

.match-progress-fill{
    height:100%;
    width:0%;
    background:linear-gradient(90deg, #F97316, #7F77DD);
    border-radius:999px;
    transition:width .4s ease;
}

.match-progress-count{
    background:#7F77DD;
    color:#fff;
    border-radius:999px;
    padding:5px 15px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:12px;
    white-space:nowrap;
}

.match-view-toggle{
    display:flex;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
    margin:4px 0 10px;
}

.match-view-toggle.is-hidden{
    display:none;
}

.match-toggle-btn{
    border:1.5px solid #EDE9FA;
    background:#fff;
    color:#534AB7;
    border-radius:999px;
    padding:8px 14px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:12px;
    cursor:pointer;
    transition:transform .18s ease, box-shadow .18s ease, background .18s ease;
}

.match-toggle-btn.is-active{
    background:#7F77DD;
    color:#fff;
    border-color:#7F77DD;
    box-shadow:0 4px 14px rgba(127,119,221,.22);
}

.match-hint-wrap{
    display:flex;
    justify-content:center;
    margin:0 0 16px;
}

.match-hint{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:24px;
    background:#FFF0E6;
    color:#C2580A;
    border:1px solid #FCDDBF;
    border-radius:999px;
    padding:4px 12px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:12px;
    line-height:1.2;
    text-align:center;
}

.match-hint.is-selected{
    background:#EEEDFE;
    color:#534AB7;
    border-color:#CECBF6;
}

.match-hint.is-correct,
.match-hint.is-complete{
    background:#E6F9F2;
    color:#0F6E56;
    border-color:#9FE1CB;
}

.match-hint.is-wrong{
    background:#FFF0E6;
    color:#C2580A;
    border-color:#FCDDBF;
}

.match-rows{
    display:grid;
    gap:14px;
}

.match-image-row,
.match-text-column{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    justify-content:center;
}

.match-divider{
    display:flex;
    align-items:center;
    gap:9px;
    margin:6px 0;
}

.match-divider::before,
.match-divider::after{
    content:'';
    height:1px;
    background:#F0EEF8;
    flex:1;
}

.match-divider-label{
    display:inline-flex;
    align-items:center;
    gap:7px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.1em;
    color:#9B94BE;
    white-space:nowrap;
}

.match-dot{
    width:8px;
    height:8px;
    border-radius:50%;
    background:#F97316;
}

.match-dot.match-dot-purple{
    background:#7F77DD;
}

.match-chip{
    position:relative;
    border:2px solid #EDE9FA;
    background:#fff;
    color:#271B5D;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    cursor:pointer;
    transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease, color .18s ease, opacity .18s ease;
    box-sizing:border-box;
}

.match-chip:hover{
    transform:translateY(-3px);
    box-shadow:0 10px 24px rgba(127,119,221,.16);
}

.match-card-chip{
    width:120px;
    border-radius:18px;
    box-shadow:0 4px 14px rgba(127,119,221,.08);
    overflow:hidden;
    padding:0;
}

.match-chip-media{
    height:90px;
    background:#FAFAFE;
    border-bottom:1px solid #EDE9FA;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:8px;
    box-sizing:border-box;
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-weight:700;
    color:#271B5D;
    font-size:15px;
    line-height:1.15;
    text-align:center;
    word-break:break-word;
}

.match-chip-media img{
    display:block;
    max-width:80%;
    max-height:80%;
    object-fit:contain;
}

.match-chip-label{
    min-height:30px;
    padding:8px 6px;
    font-size:12px;
    color:#271B5D;
    text-align:center;
    line-height:1.15;
    display:flex;
    align-items:center;
    justify-content:center;
    box-sizing:border-box;
}

.match-chip.is-selected-en{
    border-color:#F97316;
    background:#FFF8F4;
    box-shadow:0 0 0 3px rgba(249,115,22,.18);
    transform:translateY(-4px) scale(1.03);
}

.match-chip.is-selected-match{
    border-color:#7F77DD;
    background:#F5F4FF;
    box-shadow:0 0 0 3px rgba(127,119,221,.18);
    transform:translateY(-4px) scale(1.03);
}

.match-chip.is-matched{
    border-color:#1D9E75;
    background:#F0FDF9;
    box-shadow:0 4px 14px rgba(29,158,117,.18);
    cursor:default;
    opacity:.85;
}

.match-chip.is-wrong{
    border-color:#E24B4A;
    background:#FFF5F5;
    animation:shake .3s ease;
}

.match-badge{
    position:absolute;
    top:6px;
    right:6px;
    width:20px;
    height:20px;
    border-radius:50%;
    background:#1D9E75;
    color:#fff;
    font-size:11px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    display:none;
    align-items:center;
    justify-content:center;
}

.match-chip.is-matched .match-badge{
    display:flex;
}

.match-text-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}

.match-text-column{
    align-content:flex-start;
}

.match-text-chip{
    width:100%;
    padding:14px 18px;
    border-radius:14px;
    font-size:14px;
    text-align:center;
    box-shadow:0 3px 10px rgba(127,119,221,.06);
}

.match-text-chip:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(127,119,221,.13);
}

.match-text-chip.is-selected-en{
    background:#FFF0E6;
    border-color:#F97316;
    color:#C2580A;
    box-shadow:0 0 0 3px rgba(249,115,22,.15);
}

.match-text-chip.is-selected-match{
    background:#EEEDFE;
    border-color:#7F77DD;
    color:#534AB7;
    box-shadow:0 0 0 3px rgba(127,119,221,.15);
}

.match-text-chip.is-matched.match-en{
    background:linear-gradient(135deg,#F97316,#C2580A);
    border-color:#F97316;
    color:#fff;
    cursor:default;
}

.match-text-chip.is-matched.match-pair{
    background:linear-gradient(135deg,#7F77DD,#534AB7);
    border-color:#7F77DD;
    color:#fff;
    cursor:default;
}

.match-text-chip.is-wrong{
    background:#FCEBEB;
    border-color:#E24B4A;
    color:#A32D2D;
    animation:shake .3s ease;
}

.match-score-cards{
    display:none;
    grid-template-columns:repeat(3, 1fr);
    gap:10px;
    margin-top:16px;
}

.match-score-cards.is-visible{
    display:grid;
}

.match-score-card{
    background:#FAFAFE;
    border:1px solid #EDE9FA;
    border-radius:14px;
    padding:12px;
    text-align:center;
}

.match-score-number{
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-weight:700;
    font-size:28px;
    line-height:1;
}

.match-score-number.is-correct{ color:#1D9E75; }
.match-score-number.is-wrong{ color:#F97316; }
.match-score-number.is-percent{ color:#7F77DD; }

.match-score-label{
    margin-top:5px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:10px;
    text-transform:uppercase;
    color:#9B94BE;
    letter-spacing:.08em;
}

.match-actions{
    display:flex;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
}

.match-action-btn{
    border-radius:999px;
    padding:11px 18px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-weight:900;
    font-size:13px;
    cursor:pointer;
    transition:transform .18s ease, box-shadow .18s ease, filter .18s ease;
}

.match-action-btn:hover{
    transform:translateY(-2px);
}

.match-reset-btn{
    background:#fff;
    color:#534AB7;
    border:1.5px solid #EDE9FA;
}

.match-answer-btn{
    background:#7F77DD;
    color:#fff;
    border:1.5px solid #7F77DD;
}

.match-check-btn{
    background:#F97316;
    color:#fff;
    border:1.5px solid #F97316;
}

.match-empty{
    max-width:700px;
    margin:30px auto;
    background:#ffffff;
    border:1px solid #F0EEF8;
    border-radius:18px;
    padding:24px;
    text-align:center;
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    color:#9B94BE;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:18px;
    font-weight:900;
}

.match-final-completed-screen{
    display:none;
}

@keyframes shake{
    0%,100%{transform:translateX(0)}
    25%{transform:translateX(-6px)}
    75%{transform:translateX(6px)}
}

body.embedded-mode .match-shell,
body.fullscreen-embedded .match-shell,
body.presentation-mode .match-shell {
    position:absolute!important;
    inset:0!important;
    max-width:none!important;
    margin:0!important;
    padding:10px 12px!important;
    border-radius:0!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:flex-start!important;
    overflow-y:auto!important;
    overflow-x:hidden!important;
}

body.embedded-mode .match-actions,
body.fullscreen-embedded .match-actions,
body.presentation-mode .match-actions {
    flex-shrink:0!important;
    padding-bottom:12px!important;
}

@media (max-width: 700px){
    .match-board{
        padding:16px;
        border-radius:22px;
    }

    .match-text-grid{
        grid-template-columns:1fr;
    }

    .match-score-cards{
        grid-template-columns:1fr;
    }
}
</style>

<?= render_activity_header($viewerTitle) ?>
<?php if (empty($pairs)) { ?>
    <div class="match-shell">
        <div class="match-empty">No match data available.</div>
    </div>
<?php } else { ?>
    <div class="match-shell">
        <div class="match-app">
            <header class="match-hero">
                <div class="match-kicker">Match Activity</div>
                <h1 class="match-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="match-subtitle">Tap a word, then tap its matching pair.</p>
            </header>

            <main class="match-board" id="match-board">
                <div class="match-progress">
                    <div class="match-progress-track" aria-hidden="true">
                        <div class="match-progress-fill" id="match-progress-fill"></div>
                    </div>
                    <div class="match-progress-count" id="match-progress-count">0 / 0</div>
                </div>

                <div class="match-view-toggle" id="match-view-toggle">
                    <button type="button" class="match-toggle-btn" data-mode="image">Image + Text</button>
                    <button type="button" class="match-toggle-btn" data-mode="text">Text only</button>
                </div>

                <div class="match-hint-wrap">
                    <div class="match-hint" id="match-hint">Tap a word to start</div>
                </div>

                <div id="match-stage" class="match-stage<?= $isPhaseOneOrTwo ? ' match-phase-12' : '' ?>"></div>

                <div class="match-score-cards" id="match-score-cards">
                    <div class="match-score-card">
                        <div class="match-score-number is-correct" id="match-score-correct">0</div>
                        <div class="match-score-label">Correct</div>
                    </div>
                    <div class="match-score-card">
                        <div class="match-score-number is-wrong" id="match-score-wrong">0</div>
                        <div class="match-score-label">Wrong</div>
                    </div>
                    <div class="match-score-card">
                        <div class="match-score-number is-percent" id="match-score-percent">0%</div>
                        <div class="match-score-label">Score</div>
                    </div>
                </div>

                <div class="match-actions">
                    <button type="button" class="match-action-btn match-reset-btn" id="match-reset-btn">Reset</button>
                    <button type="button" class="match-action-btn match-answer-btn" id="match-answer-btn">Show Answer</button>
                    <button type="button" class="match-action-btn match-check-btn" id="match-check-btn">Check</button>
                </div>
            </main>
        </div>
    </div>

    <div id="match-final-completed" class="match-final-completed-screen">
        <p class="match-fc-score" id="match-fc-score-text"></p>
        <p class="match-fc-text" id="match-fc-sub-text"></p>
        <button type="button" id="match-fc-restart-btn" style="display:none;">Try Again</button>
        <button type="button" id="match-fc-continue-btn" style="display:none;">Continue &rarr;</button>
    </div>

    <script>
    const MATCH_DATA = <?= json_encode($pairs, JSON_UNESCAPED_UNICODE) ?>;
    const MATCH_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
    const MATCH_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    const MATCH_PHASE12 = <?= json_encode($isPhaseOneOrTwo) ?>;
    </script>

    <script>
    (function () {
        const pairs = Array.isArray(MATCH_DATA) ? MATCH_DATA : [];
        const total = pairs.length;
        const hasImages = pairs.some((item) => {
            return String(item.left_image || item.right_image || item.image || item.img || '').trim() !== '';
        });

        const stage = document.getElementById('match-stage');
        const hint = document.getElementById('match-hint');
        const progressFill = document.getElementById('match-progress-fill');
        const progressCount = document.getElementById('match-progress-count');
        const toggle = document.getElementById('match-view-toggle');
        const scoreCards = document.getElementById('match-score-cards');
        const scoreCorrect = document.getElementById('match-score-correct');
        const scoreWrong = document.getElementById('match-score-wrong');
        const scorePercent = document.getElementById('match-score-percent');
        const resetBtn = document.getElementById('match-reset-btn');
        const answerBtn = document.getElementById('match-answer-btn');
        const checkBtn = document.getElementById('match-check-btn');
        const continueBtn = document.getElementById('match-fc-continue-btn');

        let selectedEn = null;
        let selectedMatch = null;
        let matched = [];
        let wrongs = 0;
        let viewMode = hasImages ? 'image' : 'text';
        let wrongFlash = null;
        let hintState = 'default';
        let hintText = 'Tap a word to start';
        let scoreVisible = false;

        const escapeHtml = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const getLeftText = (item) => String(item.left_text || item.text || item.word || 'Word').trim();
        const getRightText = (item) => String(item.right_text || item.translation || item.text || item.word || 'Match').trim();
        const getImage = (item) => String(item.right_image || item.left_image || item.image || item.img || '').trim();

        function setHint(text, state) {
            hintText = text;
            hintState = state || 'default';
            if (hint) {
                hint.textContent = hintText;
                hint.className = 'match-hint' + (hintState !== 'default' ? ' is-' + hintState : '');
            }
        }

        function isMatched(id) {
            return matched.indexOf(id) !== -1;
        }

        function updateProgress() {
            const count = matched.length;
            const percent = total > 0 ? Math.round((count / total) * 100) : 0;
            if (progressFill) {
                progressFill.style.width = percent + '%';
            }
            if (progressCount) {
                progressCount.textContent = count + ' / ' + total;
            }
        }

        function updateScores(show) {
            const attempts = matched.length + wrongs;
            const percent = attempts > 0 ? Math.round((matched.length / attempts) * 100) : 0;

            if (scoreCorrect) {
                scoreCorrect.textContent = matched.length;
            }
            if (scoreWrong) {
                scoreWrong.textContent = wrongs;
            }
            if (scorePercent) {
                scorePercent.textContent = percent + '%';
            }
            if (scoreCards) {
                scoreCards.classList.toggle('is-visible', !!show);
            }
        }

        function chipClasses(item, side, baseClass) {
            const id = String(item.id);
            const classes = ['match-chip', baseClass || ''];
            if (side === 'en') {
                classes.push('match-en');
                if (selectedEn === id) {
                    classes.push('is-selected-en');
                }
            } else {
                classes.push('match-pair');
                if (selectedMatch === id) {
                    classes.push('is-selected-match');
                }
            }
            if (isMatched(id)) {
                classes.push('is-matched');
            }
            if (wrongFlash && wrongFlash.id === id && wrongFlash.side === side) {
                classes.push('is-wrong');
            }
            return classes.filter(Boolean).join(' ');
        }

        function renderDivider(label, purple) {
            return '<div class="match-divider"><span class="match-divider-label"><span class="match-dot' + (purple ? ' match-dot-purple' : '') + '"></span>' + escapeHtml(label) + '</span></div>';
        }

        function renderImageMode() {
            const english = pairs.map((item) => {
                const id = escapeHtml(item.id);
                const word = escapeHtml(getLeftText(item));
                return '<button type="button" class="' + chipClasses(item, 'en', 'match-card-chip') + '" data-side="en" data-id="' + id + '">' +
                    '<span class="match-badge">✓</span>' +
                    '<div class="match-chip-media">' + word + '</div>' +
                    '<div class="match-chip-label">' + word + '</div>' +
                    '</button>';
            }).join('');

            const images = pairs.map((item) => {
                const id = escapeHtml(item.id);
                const label = escapeHtml(getRightText(item));
                const image = getImage(item);
                const media = image
                    ? '<img src="' + escapeHtml(image) + '" alt="' + label + '">'
                    : label;
                return '<button type="button" class="' + chipClasses(item, 'match', 'match-card-chip') + '" data-side="match" data-id="' + id + '">' +
                    '<span class="match-badge">✓</span>' +
                    '<div class="match-chip-media">' + media + '</div>' +
                    '<div class="match-chip-label">' + label + '</div>' +
                    '</button>';
            }).join('');

            stage.innerHTML = '<div class="match-rows">' +
                renderDivider('English Words', false) +
                '<div class="match-image-row">' + english + '</div>' +
                renderDivider('Images / Matches', true) +
                '<div class="match-image-row">' + images + '</div>' +
                '</div>';
        }

        function renderTextMode() {
            const english = pairs.map((item) => {
                const id = escapeHtml(item.id);
                return '<button type="button" class="' + chipClasses(item, 'en', 'match-text-chip') + '" data-side="en" data-id="' + id + '">' +
                    '<span class="match-badge">✓</span>' + escapeHtml(getLeftText(item)) + '</button>';
            }).join('');

            const matches = pairs.map((item) => {
                const id = escapeHtml(item.id);
                return '<button type="button" class="' + chipClasses(item, 'match', 'match-text-chip') + '" data-side="match" data-id="' + id + '">' +
                    '<span class="match-badge">✓</span>' + escapeHtml(getRightText(item)) + '</button>';
            }).join('');

            stage.innerHTML = '<div class="match-text-grid">' +
                '<section>' + renderDivider('English', false) + '<div class="match-text-column">' + english + '</div></section>' +
                '<section>' + renderDivider('Spanish / Match', true) + '<div class="match-text-column">' + matches + '</div></section>' +
                '</div>';
        }

        function renderToggle() {
            if (!toggle) {
                return;
            }

            toggle.classList.toggle('is-hidden', !hasImages);
            toggle.querySelectorAll('[data-mode]').forEach((btn) => {
                btn.classList.toggle('is-active', btn.getAttribute('data-mode') === viewMode);
            });
        }

        function render() {
            updateProgress();
            updateScores(scoreVisible);
            renderToggle();

            if (!stage) {
                return;
            }

            if (viewMode === 'image') {
                renderImageMode();
            } else {
                renderTextMode();
            }

            stage.querySelectorAll('[data-side][data-id]').forEach((btn) => {
                btn.addEventListener('click', () => handleTap(btn.getAttribute('data-side'), btn.getAttribute('data-id')));
            });
        }

        function handleTap(side, id) {
            if (isMatched(id)) {
                return;
            }

            if (side === 'en') {
                selectedEn = id;
                selectedMatch = null;
                const item = pairs.find((pair) => String(pair.id) === String(id));
                setHint((item ? getLeftText(item) : 'Word') + ' selected — tap its match', 'selected');
                render();
                return;
            }

            if (selectedEn === null) {
                setHint('Tap an English word first', 'wrong');
                return;
            }

            selectedMatch = id;
            render();
            tryMatch();
        }

        function tryMatch() {
            const enId = selectedEn;
            const matchId = selectedMatch;

            if (enId === null || matchId === null) {
                return;
            }

            if (String(enId) === String(matchId)) {
                matched.push(enId);
                selectedEn = null;
                selectedMatch = null;

                if (matched.length === total) {
                    setHint('🎉 All pairs matched!', 'complete');
                    scoreVisible = true;
                    render();
                    showScore();
                } else {
                    setHint('Correct! Keep going ✓', 'correct');
                    render();
                }
                return;
            }

            wrongs += 1;
            wrongFlash = { id: enId, side: 'en' };
            setHint('Not a match — try again', 'wrong');
            render();

            window.setTimeout(() => {
                wrongFlash = { id: matchId, side: 'match' };
                render();
            }, 150);

            window.setTimeout(() => {
                selectedEn = null;
                selectedMatch = null;
                wrongFlash = null;
                setHint('Tap a word to start', 'default');
                render();
            }, 700);
        }

        function showScore() {
            scoreVisible = true;
            updateScores(true);

            const scoreText = document.getElementById('match-fc-score-text');
            const subText = document.getElementById('match-fc-sub-text');
            const attempts = matched.length + wrongs;
            const percent = attempts > 0 ? Math.round((matched.length / attempts) * 100) : 0;

            if (scoreText) {
                scoreText.textContent = matched.length + ' correct · ' + wrongs + ' wrong · ' + percent + '%';
            }
            if (subText) {
                subText.textContent = matched.length === total ? 'Great job! You matched every pair.' : 'Keep practicing and try again.';
            }
            if (continueBtn && MATCH_RETURN_TO) {
                continueBtn.style.display = 'inline-block';
            }
        }

        function resetGame() {
            selectedEn = null;
            selectedMatch = null;
            matched = [];
            wrongs = 0;
            wrongFlash = null;
            scoreVisible = false;
            setHint('Tap a word to start', 'default');
            render();
        }

        function showAnswers() {
            matched = pairs.map((item) => String(item.id));
            selectedEn = null;
            selectedMatch = null;
            wrongFlash = null;
            scoreVisible = true;
            setHint('🎉 All pairs matched!', 'complete');
            render();
            showScore();
        }

        if (toggle) {
            toggle.querySelectorAll('[data-mode]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    viewMode = btn.getAttribute('data-mode') === 'image' && hasImages ? 'image' : 'text';
                    selectedEn = null;
                    selectedMatch = null;
                    wrongFlash = null;
                    setHint('Tap a word to start', 'default');
                    render();
                });
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', resetGame);
        }
        if (answerBtn) {
            answerBtn.addEventListener('click', showAnswers);
        }
        if (checkBtn) {
            checkBtn.addEventListener('click', showScore);
        }
        if (continueBtn) {
            continueBtn.addEventListener('click', () => {
                if (MATCH_RETURN_TO) {
                    window.location.href = MATCH_RETURN_TO;
                }
            });
        }

        render();
    })();
    </script>
<?php } ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);

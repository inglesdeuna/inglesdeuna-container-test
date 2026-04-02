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
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_match_title();
$pairs = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();
$matchCssVersion = (string) (@filemtime(__DIR__ . '/match.css') ?: time());
$matchJsVersion = (string) (@filemtime(__DIR__ . '/match.js') ?: time());

ob_start();
?>

<link rel="stylesheet" href="match.css?v=<?= htmlspecialchars($matchCssVersion, ENT_QUOTES, 'UTF-8') ?>">

<style>
.match-stage{
    --match-left-accent:#d97706;
    --match-left-soft:#fff4cf;
    --match-right-accent:#0284c7;
    --match-right-soft:#e0f2fe;
    max-width:1060px;
    margin:0 auto;
}

.viewer-header{ display:none !important; }

.match-intro{
    background:linear-gradient(135deg, #fff8df 0%, #eef8ff 52%, #f8fbff 100%);
    border:1px solid #dbe7f5;
    border-radius:26px;
    padding:24px 26px;
    box-shadow:0 16px 34px rgba(15, 23, 42, .09);
}

.match-intro h2{
    margin:0 0 8px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:30px;
    font-weight:700;
    color:#0f172a;
    letter-spacing:.3px;
}

.match-intro p{
    margin:0;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    color:#334155;
    font-size:16px;
    line-height:1.6;
}

.match-columns{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:30px;
    margin-top:18px;
}

.match-column-card{
    position:relative;
    background:#ffffff;
    border:1px solid #dbe7f5;
    border-radius:24px;
    padding:18px 16px;
    box-shadow:0 14px 28px rgba(15, 23, 42, .07);
    overflow:hidden;
    min-width:0;
}

.match-column-card::before{
    content:'';
    position:absolute;
    inset:0 0 auto 0;
    height:8px;
}

.match-column-left{
    background:linear-gradient(180deg, #fffdf7 0%, #ffffff 100%);
}

.match-column-left::before{
    background:linear-gradient(90deg, #f59e0b 0%, #facc15 100%);
}

.match-column-right{
    background:linear-gradient(180deg, #f8fdff 0%, #ffffff 100%);
}

.match-column-right::before{
    background:linear-gradient(90deg, #38bdf8 0%, #0ea5e9 100%);
}

.match-empty{
    max-width:700px;
    margin:30px auto;
    background:#ffffff;
    border-radius:18px;
    padding:24px;
    text-align:center;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
    color:#4b5563;
    font-size:18px;
    font-weight:700;
}

@media (max-width: 760px){
    .match-columns{
        grid-template-columns:1fr;
        gap:18px;
    }
}
</style>

<?php if (empty($pairs)) { ?>
    <div class="match-empty">No match data available.</div>
<?php } else { ?>
    <div class="match-stage">
        <div class="match-intro">
            <h2>Match The Pairs</h2>
            <p>Drag each card from the left to its correct pair on the right. Cards can contain text, images, or a mix of both depending on the activity.</p>
        </div>

        <div class="match-columns">
            <section class="match-column-card match-column-left">
                <div class="board-column" id="match-left"></div>
            </section>

            <section class="match-column-card match-column-right">
                <div class="board-column" id="match-right"></div>
            </section>
        </div>
    </div>

    <div id="match-final-completed" class="match-final-completed-screen">
        <div class="match-fc-icon">✅</div>
        <h2 class="match-fc-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="match-fc-score" id="match-fc-score-text"></p>
        <p class="match-fc-text" id="match-fc-sub-text"></p>
        <div class="match-fc-actions">
            <button type="button" class="match-fc-btn match-fc-btn-secondary" id="match-fc-restart-btn">Try Again</button>
            <button type="button" class="match-fc-btn" id="match-fc-continue-btn" style="display:none;">Continue &rarr;</button>
        </div>
    </div>

    <style>
    .match-final-completed-screen{
        display:none;
        text-align:center;
        max-width:600px;
        margin:0 auto;
        padding:40px 20px;
    }
    .match-final-completed-screen.active{
        display:block;
    }
    .match-fc-icon{
        font-size:80px;
        margin-bottom:20px;
    }
    .match-fc-title{
        font-family:'Fredoka','Trebuchet MS',sans-serif;
        font-size:36px;
        font-weight:700;
        color:#be185d;
        margin:0 0 14px;
        line-height:1.2;
    }
    .match-fc-score{
        font-size:20px;
        font-weight:800;
        color:#0f172a;
        margin:0 0 8px;
    }
    .match-fc-text{
        font-size:15px;
        color:#6b4b5f;
        line-height:1.6;
        margin:0 0 28px;
    }
    .match-fc-actions{
        display:flex;
        gap:10px;
        justify-content:center;
        flex-wrap:wrap;
    }
    .match-fc-btn{
        display:inline-block;
        padding:12px 24px;
        border:none;
        border-radius:999px;
        background:linear-gradient(180deg,#db2777 0%,#be185d 100%);
        color:#fff;
        font-weight:700;
        font-size:16px;
        cursor:pointer;
        box-shadow:0 10px 24px rgba(0,0,0,.14);
        transition:transform .18s ease,filter .18s ease;
    }
    .match-fc-btn:hover{
        transform:scale(1.05);
        filter:brightness(1.07);
    }
    .match-fc-btn-secondary{
        background:linear-gradient(180deg,#60a5fa 0%,#2563eb 100%);
    }
    </style>

    <script>
    const MATCH_DATA = <?= json_encode($pairs, JSON_UNESCAPED_UNICODE) ?>;
    const MATCH_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
    const MATCH_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <script src="match.js?v=<?= htmlspecialchars($matchJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php } ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);

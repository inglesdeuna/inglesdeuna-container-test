<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function mlv2_activities_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = [];
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function mlv2_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $columns = mlv2_activities_columns($pdo);
    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['unit_id'])) return (string) $row['unit_id'];
    }
    return '';
}

function mlv2_normalize_payload($rawData): array
{
    $default = ['title' => 'Matching Lines', 'boards' => []];
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;

    $title        = isset($decoded['title']) ? trim((string) $decoded['title']) : '';
    $boardsSource = isset($decoded['boards']) && is_array($decoded['boards']) ? $decoded['boards'] : [];

    if (empty($boardsSource) && isset($decoded['pairs']) && is_array($decoded['pairs'])) {
        $boardsSource = [['id' => uniqid('ml_'), 'title' => 'Board 1', 'pairs' => $decoded['pairs']]];
    }

    $boards = [];
    foreach ($boardsSource as $i => $board) {
        if (!is_array($board)) continue;
        $pairsSource = isset($board['pairs']) && is_array($board['pairs']) ? $board['pairs'] : [];
        $pairs = [];
        foreach ($pairsSource as $pair) {
            if (!is_array($pair)) continue;
            $leftText   = isset($pair['left_text'])   ? trim((string) $pair['left_text'])   : '';
            $rightText  = isset($pair['right_text'])  ? trim((string) $pair['right_text'])  : '';
            $leftImage  = isset($pair['left_image'])  ? trim((string) $pair['left_image'])  : '';
            $rightImage = isset($pair['right_image']) ? trim((string) $pair['right_image']) : '';
            if (($leftText === '' && $leftImage === '') || ($rightText === '' && $rightImage === '')) continue;
            $pairs[] = [
                'id'          => isset($pair['id']) && trim((string) $pair['id']) !== '' ? trim((string) $pair['id']) : uniqid('ml_pair_'),
                'left_text'   => $leftText,
                'left_image'  => $leftImage,
                'right_text'  => $rightText,
                'right_image' => $rightImage,
            ];
        }
        if (!empty($pairs)) {
            $boards[] = [
                'id'    => isset($board['id']) && trim((string) $board['id']) !== '' ? trim((string) $board['id']) : uniqid('ml_board_'),
                'title' => isset($board['title']) && trim((string) $board['title']) !== '' ? trim((string) $board['title']) : ('Board ' . ((int) $i + 1)),
                'pairs' => $pairs,
            ];
        }
    }

    return ['title' => $title !== '' ? $title : 'Matching Lines', 'boards' => $boards];
}

function mlv2_load(PDO $pdo, string $activityId, string $unit): array
{
    $columns = mlv2_activities_columns($pdo);
    $selectFields = ['id'];
    if (in_array('data', $columns, true))         $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true))        $selectFields[] = 'title';
    if (in_array('name', $columns, true))         $selectFields[] = 'name';

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'matching_lines' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '' && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'matching_lines' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return ['id' => '', 'title' => 'Matching Lines', 'boards' => []];

    $rawData = $row['data'] ?? $row['content_json'] ?? null;
    $payload = mlv2_normalize_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name'])  && trim((string) $row['name'])  !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;

    return [
        'id'     => isset($row['id']) ? (string) $row['id'] : '',
        'title'  => (string) ($payload['title'] ?? 'Matching Lines'),
        'boards' => isset($payload['boards']) && is_array($payload['boards']) ? $payload['boards'] : [],
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = mlv2_resolve_unit($pdo, $activityId);
}

$activity     = mlv2_load($pdo, $activityId, $unit);
$boards       = $activity['boards'];
$viewerTitle  = trim((string) ($activity['title'] ?? 'Matching Lines')) ?: 'Matching Lines';

if (empty($boards)) {
    die('No matching lines data available.');
}

/*
 * Map boards to the format matching_lines.js expects:
 * { prompt, pairs: [{left, right}] }
 *
 * left/right are text strings; images are ignored by the new JS.
 */
$jsQuestions = [];
foreach ($boards as $board) {
    $prompt = isset($board['title']) ? trim((string) $board['title']) : '';
    $pairs  = [];
    foreach ($board['pairs'] as $pair) {
        $left  = $pair['left_text']  !== '' ? $pair['left_text']  : ($pair['left_image']  ?? '');
        $right = $pair['right_text'] !== '' ? $pair['right_text'] : ($pair['right_image'] ?? '');
        if ($left === '' || $right === '') continue;
        $pairs[] = ['left' => $left, 'right' => $right];
    }
    if (!empty($pairs)) {
        $jsQuestions[] = ['prompt' => $prompt, 'pairs' => $pairs];
    }
}

if (empty($jsQuestions)) {
    die('No matching pairs found.');
}

$jsVersion  = (string) (@filemtime(__DIR__ . '/matching_lines.js') ?: time());
$cssVersion = (string) (@filemtime(__DIR__ . '/matching_lines.css') ?: time());

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="matching_lines.css?v=<?php echo htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8'); ?>">

<style>
:root {
    --orange: #F97316;
    --purple: #7F77DD;
    --purple-dark: #534AB7;
    --muted: #9B94BE;
    --soft: #F4F2FD;
    --border: #ECE9FA;
}
* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }
body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
.activity-wrapper { max-width: 100% !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; background: transparent !important; }
.top-row, .activity-header { display: none !important; }
.viewer-content { flex: 1 !important; display: flex !important; flex-direction: column !important; padding: 0 !important; margin: 0 !important; background: transparent !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; }

.ml-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #fff;
    box-sizing: border-box;
}
.ml-app {
    width: min(760px, 100%);
    margin: 0 auto;
}
.ml-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}
.ml-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.ml-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 54px);
    font-weight: 700;
    color: var(--orange);
    margin: 0;
    line-height: 1;
}
.ml-hero p {
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 700;
    color: var(--muted);
    margin: 8px 0 0;
}

/* Progress */
.ml-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.ml-progress-label {
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
    min-width: 48px;
}
.ml-track {
    flex: 1;
    height: 12px;
    background: var(--soft);
    border-radius: 999px;
    overflow: hidden;
}
.ml-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--orange), var(--purple));
    border-radius: 999px;
    transition: width .35s;
}
.ml-badge {
    min-width: 74px;
    text-align: center;
    padding: 7px 10px;
    border-radius: 999px;
    background: var(--purple);
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

/* Card shell */
.ml-card-shell {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 32px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.12);
    margin-bottom: 16px;
}

/* Prompt */
#ml-prompt {
    font-size: clamp(14px, 1.8vw, 16px);
    font-weight: 700;
    color: var(--muted);
    text-align: center;
    margin-bottom: 16px;
    min-height: 20px;
}

/* Matching stage */
.ml-stage {
    position: relative;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 16px;
    align-items: start;
    margin-bottom: 16px;
}
#ml-lines {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    overflow: visible;
}
#ml-left, #ml-right {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ml-lane {
    width: 40px;
}

/* Matching items */
.ml-item {
    padding: 12px 16px;
    border-radius: 16px;
    border: 2px solid var(--border);
    background: #fff;
    font-size: 15px;
    font-weight: 700;
    color: var(--purple-dark);
    cursor: pointer;
    transition: border-color .15s, background .15s, box-shadow .15s;
    text-align: center;
    user-select: none;
}
.ml-item:hover { border-color: var(--purple); background: var(--soft); }
.ml-item.selected { border-color: var(--purple); background: var(--soft); box-shadow: 0 0 0 3px rgba(127,119,221,.2); }
.ml-item.matched  { border-color: #22c55e; background: #f0fdf4; color: #166534; cursor: default; }
.ml-item.wrong    { border-color: #ef4444; background: #fef2f2; color: #991b1b; }

/* Buttons */
.ml-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid var(--border);
    padding-top: 16px;
    margin-top: 8px;
}
.ml-btn {
    border: 0;
    border-radius: 999px;
    padding: 13px 20px;
    min-width: 120px;
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    transition: .18s;
    box-shadow: 0 6px 18px rgba(127,119,221,.15);
}
.ml-btn:hover { transform: translateY(-1px); }
.ml-btn:disabled { opacity: .45; cursor: default; transform: none; }
.ml-btn-orange { background: var(--orange); box-shadow: 0 6px 18px rgba(249,115,22,.22); }
.ml-btn-purple { background: var(--purple); }

#ml-feedback { margin-top: 8px; }
#ml-completed { }

@media (max-width: 640px) {
    .ml-page { padding: 12px; }
    .ml-actions { display: grid; grid-template-columns: 1fr; gap: 9px; }
    .ml-btn { width: 100%; }
    .ml-stage { grid-template-columns: 1fr 20px 1fr; gap: 8px; }
}
</style>

<div class="ml-page">
    <div class="ml-app">

        <div class="ml-hero">
            <div class="ml-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Match each item with its correct pair.</p>
        </div>

        <div id="ml-activity">
            <div class="ml-card-shell">
                <div class="ml-progress">
                    <span class="ml-progress-label" id="ml-progress-label">1 / <?php echo count($jsQuestions); ?></span>
                    <div class="ml-track">
                        <div class="ml-fill" id="ml-progress-fill"></div>
                    </div>
                    <div class="ml-badge" id="ml-progress-badge">Q 1 of <?php echo count($jsQuestions); ?></div>
                </div>

                <div id="ml-prompt"></div>

                <div class="ml-stage">
                    <svg id="ml-lines" aria-hidden="true"></svg>
                    <div id="ml-left"></div>
                    <div class="ml-lane"></div>
                    <div id="ml-right"></div>
                </div>

                <div class="ml-actions">
                    <button class="ml-btn ml-btn-orange" id="ml-check">Check</button>
                    <button class="ml-btn ml-btn-purple" id="ml-show">Show Answer</button>
                    <button class="ml-btn ml-btn-orange" id="ml-next">Next</button>
                </div>
            </div>

            <div id="ml-feedback"></div>
        </div>

        <div id="ml-completed"></div>

    </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.MATCHING_DATA        = <?php echo json_encode($jsQuestions, JSON_UNESCAPED_UNICODE); ?>;
window.MATCHING_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.MATCHING_RETURN_TO   = <?php echo json_encode($returnTo,    JSON_UNESCAPED_UNICODE); ?>;
window.MATCHING_ACTIVITY_ID = <?php echo json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="matching_lines.js?v=<?php echo htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-diagram-project', $content);

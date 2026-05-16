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

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }
body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
.activity-wrapper { max-width: 100% !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; background: transparent !important; }
.top-row, .activity-header { display: none !important; }
.viewer-content { flex: 1 !important; display: flex !important; flex-direction: column !important; padding: 0 !important; margin: 0 !important; background: transparent !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; }

/* ── Page shell ── */
.ml-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #ffffff;
    box-sizing: border-box;
}
.ml-app {
    width: min(1100px, 100%);
    margin: 0 auto;
}

/* ── Hero ── */
.ml-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 24px);
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
    font-family: 'Nunito', sans-serif;
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
    color: #F97316;
    margin: 0;
    line-height: 1;
}
.ml-hero p {
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 800;
    color: #9B94BE;
    margin: 8px 0 0;
    font-family: 'Nunito', sans-serif;
}

/* ── Board ── */
.ml-board {
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    padding: clamp(16px, 2.4vw, 26px);
}

/* ── Progress ── */
.ml-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.ml-progress-label {
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    min-width: 48px;
    font-family: 'Nunito', sans-serif;
}
.ml-track {
    flex: 1;
    height: 12px;
    background: #F4F2FD;
    border: 1px solid #E4E1F8;
    border-radius: 999px;
    overflow: hidden;
}
.ml-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #F97316, #7F77DD);
    border-radius: 999px;
    transition: width .35s;
}
.ml-badge {
    min-width: 74px;
    text-align: center;
    padding: 6px 11px;
    border-radius: 999px;
    background: #7F77DD;
    color: #fff;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
}

/* ── Points chip ── */
.ml-pts-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    border-radius: 999px;
    padding: 8px 18px;
    margin-bottom: 16px;
}
.ml-pts-num {
    font-family: 'Fredoka', sans-serif;
    font-weight: 700;
    font-size: clamp(22px, 3.5vw, 32px);
    color: #F97316;
    line-height: 1;
}
.ml-pts-label {
    font-family: 'Nunito', sans-serif;
    font-weight: 900;
    font-size: 13px;
    color: #C2580A;
    margin-left: 4px;
}

/* ── Stage ── */
.ml-stage {
    display: grid;
    grid-template-columns: 1fr clamp(80px, 12vw, 140px) 1fr;
    grid-template-rows: auto;
    position: relative;
    margin-bottom: 16px;
}
/* SVG spans all 3 columns as a full-stage overlay */
#ml-svg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    overflow: visible;
    z-index: 2;
}
/* Left column in grid slot 1, right in slot 3 */
.ml-left-col  { grid-column: 1; display: flex; flex-direction: column; gap: 10px; }
.ml-right-col { grid-column: 3; display: flex; flex-direction: column; gap: 10px; }
/* Center lane (slot 2) — empty, just provides visual space */
.ml-center-lane { grid-column: 2; }

/* ── Left cards ── */
/* (column styles set individually in grid layout above) */
.ml-lcard, .ml-rcard {
    background: #fff;
    border: 1px solid #EDE9FA;
    border-radius: 20px;
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    padding: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
    min-height: 70px;
}
.ml-lcard-icon, .ml-rcard-icon {
    min-height: 58px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ml-lcard-img {
    max-width: 100%;
    max-height: 140px;
    width: auto;
    height: auto;
    border-radius: 12px;
    object-fit: contain;
    display: block;
}
.ml-lcard-label {
    font-family: 'Fredoka', sans-serif;
    font-weight: 600;
    font-size: clamp(13px, 1.8vw, 16px);
    color: #534AB7;
    text-align: center;
    margin-top: 4px;
}
.ml-rcard-label {
    font-family: 'Fredoka', sans-serif;
    font-weight: 600;
    font-size: clamp(14px, 2vw, 17px);
    color: #F97316;
    text-align: center;
    margin-top: 4px;
}

/* Connection dots */
.ml-dot-r {
    position: absolute;
    right: -9px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #7F77DD;
    cursor: crosshair;
    z-index: 3;
    box-shadow: 0 0 0 3px rgba(127,119,221,.2);
    transition: background .15s, transform .15s, box-shadow .15s;
    user-select: none;
}
.ml-dot-r:hover { background: #534AB7; box-shadow: 0 0 0 5px rgba(127,119,221,.25); }
.ml-dot-r.ml-dot-active {
    background: #F97316;
    transform: translateY(-50%) scale(1.35);
    box-shadow: 0 0 0 5px rgba(249,115,22,.25);
}
.ml-dot-l {
    position: absolute;
    left: -9px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #7F77DD;
    cursor: pointer;
    z-index: 3;
    box-shadow: 0 0 0 3px rgba(127,119,221,.2);
    transition: background .15s, transform .15s;
}
.ml-dot-r:hover, .ml-dot-l:hover { transform: translateY(-50%) scale(1.25); }
.ml-dot-r.ml-selected, .ml-dot-l.ml-selected { background: #F97316; transform: translateY(-50%) scale(1.3); }

/* SVG lane */
.ml-svg-lane { position: relative; }

/* ── Buttons ── */
.ml-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid #EDE9FA;
    padding-top: 16px;
    margin-top: 8px;
}
.ml-btn {
    border: 0;
    border-radius: 999px;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    color: #fff;
    padding: 12px 22px;
    min-width: 110px;
    cursor: pointer;
    transition: transform .18s, opacity .18s;
}
.ml-btn:hover { transform: translateY(-1px); }
.ml-btn:disabled { opacity: .45; cursor: default; transform: none; }
.ml-btn-orange { background: #F97316; box-shadow: 0 6px 18px rgba(249,115,22,.22); }
.ml-btn-purple { background: #7F77DD; box-shadow: 0 6px 18px rgba(127,119,221,.20); }

/* ── Feedback ── */
#ml-feedback {
    font-family: 'Fredoka', sans-serif;
    font-weight: 600;
    text-align: center;
    font-size: clamp(15px, 2vw, 18px);
    margin-top: 12px;
    min-height: 24px;
}

#ml-completed {}

@media (max-width: 600px) {
    .ml-page { padding: 10px; }
    .ml-stage { grid-template-columns: 1fr 24px 1fr; }
    .ml-actions { display: grid; grid-template-columns: 1fr; gap: 9px; }
    .ml-btn { width: 100%; }
}
</style>

<div class="ml-page">
  <div class="ml-app">

    <div class="ml-hero">
      <div class="ml-kicker">Activity</div>
      <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
      <p>Match each item with its correct pair.</p>
    </div>

    <div class="ml-board">

      <div class="ml-progress">
        <span class="ml-progress-label" id="ml-progress-label">1 / <?php echo count($jsQuestions); ?></span>
        <div class="ml-track">
          <div class="ml-fill" id="ml-progress-fill"></div>
        </div>
        <div class="ml-badge" id="ml-progress-badge">Q 1 of <?php echo count($jsQuestions); ?></div>
      </div>

      <div style="text-align:center">
        <div class="ml-pts-chip">
          <span class="ml-pts-num" id="ml-pts-num">0</span>
          <span class="ml-pts-label">PTS</span>
        </div>
      </div>

      <div id="ml-activity">
        <div class="ml-stage">
          <div class="ml-left-col"   id="ml-left-col"></div>
          <div class="ml-center-lane" aria-hidden="true"></div>
          <div class="ml-right-col"  id="ml-right-col"></div>
          <svg id="ml-svg" aria-hidden="true"></svg>
        </div>

        <div class="ml-actions">
          <button class="ml-btn ml-btn-orange" id="ml-check">Check</button>
          <button class="ml-btn ml-btn-purple" id="ml-show">Show Answers</button>
          <button class="ml-btn ml-btn-orange" id="ml-next">Next</button>
        </div>
      </div>

      <div id="ml-feedback"></div>

    </div><!-- /.ml-board -->

    <div id="ml-completed"></div>

  </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.MATCHING_LINES_DATA        = <?php echo json_encode($jsQuestions, JSON_UNESCAPED_UNICODE); ?>;
window.MATCHING_LINES_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.MATCHING_LINES_RETURN_TO   = <?php echo json_encode($returnTo,    JSON_UNESCAPED_UNICODE); ?>;
window.MATCHING_LINES_ACTIVITY_ID = <?php echo json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="matching_lines.js?v=<?php echo htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-diagram-project', $content);

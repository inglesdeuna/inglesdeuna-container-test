<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && isset($row['unit_id'])) {
        return (string) $row['unit_id'];
    }

    return '';
}

function default_multiple_choice_title(): string
{
    return 'Multiple Choice';
}

function normalize_multiple_choice_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_multiple_choice_title();
}

function normalize_multiple_choice_payload($rawData): array
{
    $default = array(
        'title' => default_multiple_choice_title(),
        'questions' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $questionsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $questionsSource = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $questionsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $questionsSource = $decoded['data'];
    }

    $normalized = array();

    foreach ($questionsSource as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options'])
            ? $item['options']
            : array(
                isset($item['option_a']) ? (string) $item['option_a'] : '',
                isset($item['option_b']) ? (string) $item['option_b'] : '',
                isset($item['option_c']) ? (string) $item['option_c'] : '',
            );

        $normalized[] = array(
            'question_type' => (isset($item['question_type']) && $item['question_type'] === 'listen') ? 'listen' : 'text',
            'question' => isset($item['question']) ? trim((string) $item['question']) : '',
            'audio'   => isset($item['audio']) ? trim((string) $item['audio']) : '',
            'voice_id' => (isset($item['voice_id']) && in_array($item['voice_id'], array('josh', 'lily', 'candy'), true)) ? $item['voice_id'] : 'josh',
            'image'   => isset($item['image']) ? trim((string) $item['image']) : '',
            'option_type' => (isset($item['option_type']) && $item['option_type'] === 'image') ? 'image' : 'text',
            'options' => array(
                isset($options[0]) ? trim((string) $options[0]) : '',
                isset($options[1]) ? trim((string) $options[1]) : '',
                isset($options[2]) ? trim((string) $options[2]) : '',
            ),
            'correct' => isset($item['correct']) ? max(0, min(2, (int) $item['correct'])) : 0,
        );
    }

    return array(
        'title' => normalize_multiple_choice_title($title),
        'questions' => $normalized,
    );
}

function load_multiple_choice_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = array(
        'id' => '',
        'title' => default_multiple_choice_title(),
        'questions' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'multiple_choice'
            LIMIT 1
        ");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'multiple_choice'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_multiple_choice_payload($row['data'] ?? null);

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_multiple_choice_title((string) ($payload['title'] ?? '')),
        'questions' => isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_multiple_choice_activity($pdo, $activityId, $unit);
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_multiple_choice_title();
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : array();

$cssVersion = file_exists(__DIR__ . '/multiple_choice.css') ? (string) filemtime(__DIR__ . '/multiple_choice.css') : (string) time();
$jsVersion = file_exists(__DIR__ . '/multiple_choice.js') ? (string) filemtime(__DIR__ . '/multiple_choice.js') : (string) time();

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --mc-orange:#F97316;
    --mc-purple:#7F77DD;
    --mc-purple-soft:#EEEDFE;
    --mc-lila:#EDE9FA;
    --mc-muted:#aaa;
    --mc-bg:#F8F7FE;
    --mc-green:#22c55e;
}

html, body {
    width:100%;
    min-height:100%;
}

body {
    margin:0!important;
    padding:0!important;
    background:var(--mc-bg)!important;
    font-family:'Nunito',sans-serif!important;
}

.activity-wrapper {
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    min-height:0;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
}

.top-row,
.activity-header,
.activity-title,
.activity-subtitle {
    display:none!important;
}

.viewer-content {
    flex:1!important;
    display:flex!important;
    flex-direction:column!important;
    min-height:0!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.mc-page {
    width:100%;
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:clamp(14px,2.2vw,30px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    background:var(--mc-bg);
    box-sizing:border-box;
}

.mc-app {
    width:min(940px,100%);
    margin:0 auto;
}

.mc-hero {
    text-align:center;
    margin-bottom:16px;
}

.mc-kicker {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 14px;
    border-radius:999px;
    background:#FFF0E6;
    color:var(--mc-orange);
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.08em;
    margin-bottom:10px;
}

.mc-hero h1 {
    margin:0;
    font-family:'Fredoka One',sans-serif;
    font-size:clamp(30px,4.8vw,44px);
    color:var(--mc-orange);
    line-height:1.06;
    font-weight:400;
}

.mc-hero p {
    margin:8px 0 0;
    color:var(--mc-muted);
    font-size:14px;
    font-weight:700;
}

.mc-stage-shell {
    width:min(860px,100%);
    margin:0 auto;
    background:#fff;
    border:1px solid var(--mc-lila);
    border-radius:24px;
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    padding:18px;
}

.mc-progress-row {
    display:grid;
    grid-template-columns:auto 1fr auto;
    align-items:center;
    gap:12px;
    margin-bottom:14px;
}

.mc-progress-label {
    color:var(--mc-purple);
    font-size:13px;
    font-weight:800;
}

.mc-progress-track {
    height:7px;
    border-radius:99px;
    background:var(--mc-lila);
    overflow:hidden;
}

.mc-progress-fill {
    height:100%;
    width:0%;
    border-radius:99px;
    background:linear-gradient(90deg,var(--mc-orange),var(--mc-purple));
    transition:width .2s ease;
}

.mc-progress-badge {
    background:var(--mc-purple);
    color:#fff;
    border-radius:999px;
    padding:5px 12px;
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}

.mc-card {
    background:#fff;
    border:1.5px solid var(--mc-lila);
    border-radius:24px;
    padding:18px;
}

.mc-listen-wrap {
    display:flex;
    justify-content:center;
    margin-bottom:12px;
}

.mc-listen-btn {
    border:none;
    border-radius:999px;
    background:var(--mc-orange);
    color:#fff;
    padding:10px 18px;
    font-size:14px;
    font-weight:700;
    font-family:'Nunito',sans-serif;
    cursor:pointer;
}

.mc-listen-btn:disabled {
    opacity:.45;
    cursor:not-allowed;
}

.mc-question {
    margin:0 0 10px;
    text-align:center;
    color:#666;
    font-size:15px;
    font-weight:700;
}

.mc-image-box {
    background:var(--mc-bg);
    border:1.5px solid var(--mc-lila);
    border-radius:16px;
    min-height:140px;
    padding:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:14px;
}

.mc-image-box.is-empty {
    display:none;
}

.mc-image {
    max-width:100%;
    max-height:220px;
    object-fit:contain;
    border-radius:12px;
    display:block;
}

.mc-options {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
}

.mc-option {
    min-height:96px;
    border:2px solid var(--mc-lila);
    border-radius:16px;
    background:#fff;
    color:#444;
    font-family:'Nunito',sans-serif;
    font-size:16px;
    font-weight:700;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    cursor:pointer;
    padding:10px;
}

.mc-option:hover {
    border-color:var(--mc-purple);
    background:#F3F2FD;
}

.mc-option.selected {
    border-color:var(--mc-purple);
    background:var(--mc-purple-soft);
}

.mc-option.correct {
    border-color:var(--mc-green);
}

.mc-option img {
    max-width:100%;
    max-height:110px;
    object-fit:contain;
    border-radius:10px;
    display:block;
}

.mc-controls {
    margin-top:14px;
    display:flex;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap;
}

.mc-btn,
.mc-completed-button {
    border:none;
    border-radius:999px;
    color:#fff;
    min-width:128px;
    padding:11px 20px;
    font-size:14px;
    font-weight:700;
    font-family:'Nunito',sans-serif;
    cursor:pointer;
}

.mc-btn-show,
.mc-completed-button {
    background:var(--mc-purple);
}

.mc-btn-next {
    background:var(--mc-orange);
}

.mc-feedback {
    min-height:18px;
    margin-top:8px;
    text-align:center;
    color:var(--mc-muted);
    font-size:13px;
    font-weight:700;
}

.mc-completed-screen {
    display:none;
    text-align:center;
    padding:24px 12px;
}

.mc-completed-screen.active {
    display:block;
}

.mc-completed-title {
    margin:0;
    color:var(--mc-orange);
    font-family:'Fredoka One',sans-serif;
    font-size:32px;
    font-weight:400;
}

.mc-completed-text {
    color:var(--mc-muted);
    font-size:14px;
    font-weight:700;
}

#mc-score-text {
    color:#666;
    font-size:14px;
    font-weight:800;
}

@media(max-width:760px) {
    .mc-stage-shell { padding:14px; }
    .mc-progress-row { grid-template-columns:1fr; gap:8px; }
    .mc-options { grid-template-columns:repeat(2,minmax(0,1fr)); }
}

@media(max-width:480px) {
    .mc-options { grid-template-columns:1fr; }
    .mc-btn,
    .mc-completed-button { width:100%; }
}
</style>

<div class="mc-page">
    <div class="mc-app">
        <div class="mc-hero">
            <div class="mc-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Choose the correct answer.</p>
        </div>

        <div class="mc-stage-shell">
            <div class="mc-viewer" id="mc-container">
                <div class="mc-progress-row">
                    <div class="mc-progress-label" id="mc-progress-label"></div>
                    <div class="mc-progress-track"><div class="mc-progress-fill" id="mc-progress-fill"></div></div>
                    <div class="mc-progress-badge" id="mc-progress-badge"></div>
                </div>

                <div class="mc-card">
                    <div class="mc-listen-wrap"><button type="button" class="mc-listen-btn" id="mc-listen">🔊 Listen</button></div>
                    <div class="mc-question" id="mc-question"></div>
                    <div class="mc-image-box" id="mc-image-box"><img id="mc-image" class="mc-image" alt=""></div>
                    <div class="mc-options" id="mc-options"></div>
                </div>

                <div class="mc-controls">
                    <button type="button" class="mc-btn mc-btn-show" id="mc-show">Show Answer</button>
                    <button type="button" class="mc-btn mc-btn-next" id="mc-next">Next →</button>
                </div>

                <div class="mc-feedback" id="mc-feedback"></div>

                <div id="mc-completed" class="mc-completed-screen">
                    <div class="mc-completed-icon">✅</div>
                    <h2 class="mc-completed-title" id="mc-completed-title"></h2>
                    <p class="mc-completed-text" id="mc-completed-text"></p>
                    <p class="mc-completed-text" id="mc-score-text" style="font-weight:900;font-size:15px;color:#534AB7;"></p>
                    <button type="button" class="mc-completed-button" id="mc-restart">Restart</button>
                </div>
            </div>
        </div>

    </div>
</div>
<script>
window.MULTIPLE_CHOICE_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_TITLE = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_ACTIVITY_ID = <?php echo json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="multiple_choice.js?v=<?php echo urlencode($jsVersion); ?>"></script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '­ƒôØ', $content);

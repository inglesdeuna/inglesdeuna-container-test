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

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --mc-orange:#F97316;
    --mc-purple:#7F77DD;
    --mc-purple-dark:#534AB7;
    --mc-purple-soft:#EEEDFE;
    --mc-lila:#EDE9FA;
    --mc-muted:#9B94BE;
    --mc-green:#16a34a;
    --mc-red:#dc2626;
}

html, body {
    width:100%;
    min-height:100%;
}

body {
    margin:0!important;
    padding:0!important;
    background:#ffffff!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.activity-wrapper {
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    min-height:100vh;
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
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.mc-page {
    width:100%;
    min-height:100vh;
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    background:#ffffff;
    box-sizing:border-box;
}

.mc-app {
    width:min(860px,100%);
    margin:0 auto;
}

.mc-topbar {
    height:36px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:8px;
}

.mc-topbar-title {
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    color:#9B94BE;
    letter-spacing:.1em;
    text-transform:uppercase;
}

.mc-hero {
    text-align:center;
    margin-bottom:clamp(14px,2vw,22px);
}

.mc-kicker {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 14px;
    border-radius:999px;
    background:#FFF0E6;
    border:1px solid #FCDDBF;
    color:#C2580A;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:10px;
}

.mc-hero h1 {
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    font-weight:700;
    color:#F97316;
    margin:0;
    line-height:1.03;
}

.mc-hero p {
    font-family:'Nunito',sans-serif;
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
    color:#9B94BE;
    margin:8px 0 0;
}

.mc-viewer {
    background:#ffffff!important;
    border:1px solid #F0EEF8!important;
    border-radius:34px!important;
    padding:clamp(16px,2.6vw,26px)!important;
    box-shadow:0 8px 40px rgba(127,119,221,.13)!important;
    width:min(760px,100%)!important;
    margin:0 auto!important;
    box-sizing:border-box!important;
    position:relative!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.mc-status {
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    min-width:74px!important;
    padding:7px 11px!important;
    border-radius:999px!important;
    background:#7F77DD!important;
    color:#ffffff!important;
    font-family:'Nunito',sans-serif!important;
    font-size:12px!important;
    font-weight:900!important;
    margin:0 auto 18px!important;
    text-align:center!important;
}

.mc-card {
    background:#ffffff!important;
    border:1px solid #EDE9FA!important;
    border-radius:28px!important;
    box-shadow:0 12px 36px rgba(127,119,221,.13)!important;
    padding:clamp(22px,4vw,42px)!important;
    min-height:clamp(300px,42vh,430px)!important;
    box-sizing:border-box!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:center!important;
    text-align:center!important;
}

.mc-question {
    width:100%!important;
    max-width:640px!important;
    font-family:'Fredoka',sans-serif!important;
    font-size:clamp(24px,4vw,42px)!important;
    font-weight:700!important;
    color:#534AB7!important;
    line-height:1.15!important;
    text-align:center!important;
    margin:0 0 18px!important;
    overflow-wrap:anywhere!important;
}

.mc-image {
    width:min(100%,540px)!important;
    max-width:min(540px,100%)!important;
    max-height:340px!important;
    object-fit:contain!important;
    border-radius:22px!important;
    margin:0 auto 18px!important;
    background:#ffffff!important;
    border:1px solid #EDE9FA!important;
    box-shadow:0 8px 24px rgba(127,119,221,.10)!important;
}

.mc-image[src=""],
.mc-image:not([src]) {
    display:none!important;
}

.mc-options {
    width:100%!important;
    max-width:640px!important;
    display:grid!important;
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
    gap:10px!important;
    margin-top:4px!important;
}

.mc-option,
.mc-options button,
.mc-options .option {
    width:100%!important;
    min-height:64px!important;
    padding:12px 10px!important;
    border-radius:18px!important;
    background:#ffffff!important;
    border:1px solid #EDE9FA!important;
    color:#534AB7!important;
    font-family:'Fredoka',sans-serif!important;
    font-size:clamp(14px,1.9vw,20px)!important;
    font-weight:600!important;
    text-align:center!important;
    cursor:pointer!important;
    box-shadow:0 4px 14px rgba(127,119,221,.13)!important;
    transition:transform .12s,box-shadow .12s,border-color .12s!important;
}

.mc-option:hover,
.mc-options button:hover,
.mc-options .option:hover {
    transform:translateY(-1px)!important;
    border-color:#7F77DD!important;
    box-shadow:0 12px 24px rgba(127,119,221,.16)!important;
}

.mc-option.correct,
.mc-options button.correct,
.mc-options .option.correct {
    border-color:#16a34a!important;
    color:#16a34a!important;
    box-shadow:0 0 0 2px rgba(22,163,74,.22)!important;
}

.mc-option.wrong,
.mc-options button.wrong,
.mc-options .option.wrong {
    border-color:#dc2626!important;
    color:#dc2626!important;
    box-shadow:0 0 0 2px rgba(220,38,38,.18)!important;
}

.mc-option.selected,
.mc-options button.selected,
.mc-options .option.selected {
    border-color:#7F77DD!important;
    color:#534AB7!important;
    background:#EEEDFE!important;
}

.mc-options img,
.mc-option img {
    max-width:100%!important;
    max-height:130px!important;
    object-fit:contain!important;
    border-radius:14px!important;
    display:block!important;
    margin:0 auto!important;
}

.mc-controls {
    border-top:1px solid #F0EEF8!important;
    margin-top:16px!important;
    padding-top:16px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    gap:10px!important;
    flex-wrap:wrap!important;
    background:#ffffff!important;
}

.mc-btn,
.mc-completed-button {
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:13px 20px!important;
    min-width:clamp(104px,16vw,146px)!important;
    border:none!important;
    border-radius:999px!important;
    font-family:'Nunito',sans-serif!important;
    font-size:13px!important;
    font-weight:900!important;
    color:#ffffff!important;
    cursor:pointer!important;
    white-space:nowrap!important;
    transition:transform .12s,filter .12s,box-shadow .12s!important;
}

.mc-btn:hover,
.mc-completed-button:hover {
    filter:brightness(1.07)!important;
    transform:translateY(-1px)!important;
}

.mc-btn-show {
    background:#7F77DD!important;
    box-shadow:0 6px 18px rgba(127,119,221,.18)!important;
}

.mc-btn-next {
    background:#F97316!important;
    box-shadow:0 6px 18px rgba(249,115,22,.22)!important;
}

.mc-feedback {
    font-family:'Nunito',sans-serif!important;
    font-size:13px!important;
    font-weight:900!important;
    text-align:center!important;
    min-height:18px!important;
    width:100%!important;
    margin-top:10px!important;
    color:#534AB7!important;
}

.mc-feedback.good {
    color:#16a34a!important;
}

.mc-feedback.bad {
    color:#dc2626!important;
}

.mc-completed-screen {
    display:none;
    background:#ffffff!important;
    border:1px solid #EDE9FA!important;
    border-radius:28px!important;
    box-shadow:0 12px 36px rgba(127,119,221,.13)!important;
    min-height:clamp(300px,42vh,430px)!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:center!important;
    text-align:center!important;
    padding:clamp(28px,5vw,48px) 24px!important;
    gap:12px!important;
    box-sizing:border-box!important;
}

.mc-completed-screen.active,
.mc-completed-screen[style*="block"],
.mc-completed-screen[style*="flex"] {
    display:flex!important;
}

.mc-completed-icon {
    font-size:64px!important;
    line-height:1!important;
    margin-bottom:4px!important;
}

.mc-completed-title {
    font-family:'Fredoka',sans-serif!important;
    font-size:clamp(30px,5.5vw,58px)!important;
    font-weight:700!important;
    color:#F97316!important;
    margin:0!important;
    line-height:1.03!important;
}

.mc-completed-text {
    font-family:'Nunito',sans-serif!important;
    font-size:clamp(13px,1.8vw,17px)!important;
    font-weight:800!important;
    color:#9B94BE!important;
    margin:0!important;
}

#mc-score-text {
    color:#534AB7!important;
    font-family:'Nunito',sans-serif!important;
    font-size:15px!important;
    font-weight:900!important;
}

.mc-completed-button {
    background:#7F77DD!important;
    box-shadow:0 6px 18px rgba(127,119,221,.18)!important;
    margin-top:4px!important;
}

@media(max-width:640px) {
    .mc-page {
        padding:12px;
    }

    .mc-topbar {
        height:30px;
        margin-bottom:4px;
    }

    .mc-kicker {
        padding:5px 11px;
        font-size:11px;
        margin-bottom:6px;
    }

    .mc-hero h1 {
        font-size:clamp(26px,8vw,38px);
    }

    .mc-viewer {
        border-radius:26px!important;
        padding:14px!important;
        width:100%!important;
    }

    .mc-card {
        border-radius:22px!important;
        padding:18px!important;
        min-height:300px!important;
    }

    .mc-question {
        font-size:clamp(22px,7vw,32px)!important;
    }

    .mc-image {
        width:min(100%,420px)!important;
        max-width:min(420px,100%)!important;
        max-height:260px!important;
    }

    .mc-options {
        grid-template-columns:repeat(3,minmax(0,1fr))!important;
        gap:8px!important;
    }

    .mc-option,
    .mc-options button,
    .mc-options .option {
        min-height:58px!important;
        font-size:clamp(12px,3.3vw,16px)!important;
        padding:10px 8px!important;
    }

    .mc-controls {
        display:grid!important;
        grid-template-columns:1fr!important;
        gap:9px!important;
    }

    .mc-btn,
    .mc-completed-button {
        width:100%!important;
    }

    .mc-completed-screen {
        border-radius:26px!important;
    }
}
</style>

<div class="mc-page">
    <div class="mc-app">

        <div class="mc-topbar">
            <span class="mc-topbar-title">Multiple Choice</span>
        </div>

        <div class="mc-hero">
            <div class="mc-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Choose the correct answer.</p>
        </div>

        <div class="mc-viewer" id="mc-container">
            <div class="mc-status" id="mc-status"></div>

            <div class="mc-card">
                <div class="mc-question" id="mc-question"></div>
                <img id="mc-image" class="mc-image" alt="">
                <div class="mc-options" id="mc-options"></div>
            </div>

            <div class="mc-controls">
                <button type="button" class="mc-btn mc-btn-show" id="mc-show">Show Answer</button>
                <button type="button" class="mc-btn mc-btn-next" id="mc-next">Next</button>
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

<link rel="stylesheet" href="multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
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

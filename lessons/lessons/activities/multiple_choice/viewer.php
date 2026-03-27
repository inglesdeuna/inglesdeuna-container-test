<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

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
            'question' => isset($item['question']) ? trim((string) $item['question']) : '',
            'image' => isset($item['image']) ? trim((string) $item['image']) : '',
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

<div class="mc-viewer" id="mc-container">
    <section class="mc-intro">
        <h2>Choose The Best Answer</h2>
        <p>Review each question, select the best option, and use Show Answer whenever you need support.</p>
    </section>

    <div class="mc-status" id="mc-status"></div>

    <div class="mc-card">
        <div class="mc-question" id="mc-question"></div>
        <img id="mc-image" class="mc-image" alt="">
        <div class="mc-options" id="mc-options"></div>
    </div>

    <div class="mc-controls">
        <button type="button" class="mc-btn mc-btn-check" id="mc-check">Check Answer</button>
        <button type="button" class="mc-btn mc-btn-show" id="mc-show">Show Answer</button>
        <button type="button" class="mc-btn mc-btn-next" id="mc-next">Next</button>
    </div>

    <div class="mc-feedback" id="mc-feedback"></div>

    <div id="mc-completed" class="mc-completed-screen">
        <div class="mc-completed-icon">✅</div>
        <h2 class="mc-completed-title" id="mc-completed-title"></h2>
        <p class="mc-completed-text" id="mc-completed-text"></p>
        <button type="button" class="mc-completed-button" id="mc-restart">Restart</button>
    </div>
</div>

<link rel="stylesheet" href="multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
<script>
window.MULTIPLE_CHOICE_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_TITLE = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="multiple_choice.js?v=<?php echo urlencode($jsVersion); ?>"></script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '📝', $content);

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

    $activityMode = (isset($decoded['activity_mode']) && in_array($decoded['activity_mode'], array('text', 'listening'), true))
        ? $decoded['activity_mode'] : 'standard';
    $passage = isset($decoded['passage']) ? trim((string) $decoded['passage']) : '';
    $passageVoiceId = (isset($decoded['passage_voice_id']) && in_array($decoded['passage_voice_id'], array('josh', 'lily', 'candy'), true))
        ? $decoded['passage_voice_id'] : 'josh';
    $showPassageText = isset($decoded['show_passage_text']) ? (bool) $decoded['show_passage_text'] : true;

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

        $optionImages = isset($item['option_images']) && is_array($item['option_images']) ? $item['option_images'] : array();

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
            'option_images' => array(
                isset($optionImages[0]) ? trim((string) $optionImages[0]) : '',
                isset($optionImages[1]) ? trim((string) $optionImages[1]) : '',
                isset($optionImages[2]) ? trim((string) $optionImages[2]) : '',
            ),
            'correct' => isset($item['correct']) ? max(0, min(2, (int) $item['correct'])) : 0,
        );
    }

    return array(
        'title'             => normalize_multiple_choice_title($title),
        'activity_mode'     => $activityMode,
        'passage'           => $passage,
        'passage_voice_id'  => $passageVoiceId,
        'show_passage_text' => $showPassageText,
        'questions'         => $normalized,
    );
}

function load_multiple_choice_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = array(
        'id'                => '',
        'title'             => default_multiple_choice_title(),
        'activity_mode'     => 'standard',
        'passage'           => '',
        'passage_voice_id'  => 'josh',
        'show_passage_text' => true,
        'questions'         => array(),
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
        'id'                => isset($row['id']) ? (string) $row['id'] : '',
        'title'             => normalize_multiple_choice_title((string) ($payload['title'] ?? '')),
        'activity_mode'     => isset($payload['activity_mode']) ? (string) $payload['activity_mode'] : 'standard',
        'passage'           => isset($payload['passage']) ? (string) $payload['passage'] : '',
        'passage_voice_id'  => isset($payload['passage_voice_id']) ? (string) $payload['passage_voice_id'] : 'josh',
        'show_passage_text' => isset($payload['show_passage_text']) ? (bool) $payload['show_passage_text'] : true,
        'questions'         => isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_multiple_choice_activity($pdo, $activityId, $unit);
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_multiple_choice_title();
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : array();
$activityMode = isset($activity['activity_mode']) ? (string) $activity['activity_mode'] : 'standard';
$passage = isset($activity['passage']) ? (string) $activity['passage'] : '';
$passageVoiceId = isset($activity['passage_voice_id']) ? (string) $activity['passage_voice_id'] : 'josh';
$showPassageText = isset($activity['show_passage_text']) ? (bool) $activity['show_passage_text'] : true;

$cssVersion = file_exists(__DIR__ . '/multiple_choice.css') ? (string) filemtime(__DIR__ . '/multiple_choice.css') : (string) time();
$jsVersion = file_exists(__DIR__ . '/multiple_choice.js') ? (string) filemtime(__DIR__ . '/multiple_choice.js') : (string) time();

ob_start();
?>

<?php
$mcQuizMode = false;
require __DIR__ . '/_multiple_choice_view.php';
?>
<script>
window.MULTIPLE_CHOICE_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_TITLE = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_ACTIVITY_ID = <?php echo json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_MODE = <?php echo json_encode($activityMode, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_PASSAGE = <?php echo json_encode($passage, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_PASSAGE_VOICE_ID = <?php echo json_encode($passageVoiceId, JSON_UNESCAPED_UNICODE); ?>;
window.MULTIPLE_CHOICE_SHOW_PASSAGE_TEXT = <?php echo json_encode($showPassageText); ?>;
</script>
<script src="multiple_choice.js?v=<?php echo urlencode($jsVersion); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.initPanelFullscreen) return;
    var passSection = document.querySelector('.mc-passage-section');
    var quizSection = document.querySelector('.mc-stage-shell');
    if (passSection) window.initPanelFullscreen(passSection, { label: 'Texto en pantalla completa' });
    if (quizSection) window.initPanelFullscreen(quizSection, { label: 'Preguntas en pantalla completa' });
});
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-list-ul', $content);
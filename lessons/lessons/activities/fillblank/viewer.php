<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

/* Back URL resolution (keep same logic as original) */
$_fb_assignment  = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';
$_fb_source      = isset($_GET['source'])     ? trim((string)$_GET['source'])     : '';
$_fb_returnParam = isset($_GET['return_to'])  ? trim((string)$_GET['return_to'])  : '';
$_fb_isSafeRelative = $_fb_returnParam !== ''
    && !preg_match('#^[a-zA-Z][a-zA-Z0-9+\\-.]*://#', $_fb_returnParam)
    && strpos($_fb_returnParam, '//') !== 0;

if ($_fb_isSafeRelative) {
    $_fb_backUrl = $_fb_returnParam;
} elseif ($_fb_assignment !== '') {
    $_fb_backUrl = '../../academic/teacher_unit.php?assignment=' . urlencode($_fb_assignment) . '&unit=' . urlencode($unit);
} else {
    $_fb_backUrl = '../../academic/unit_view.php?unit=' . urlencode($unit);
    if ($_fb_source !== '') $_fb_backUrl .= '&source=' . urlencode($_fb_source);
}

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function fb2_load(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id'           => '',
        'instructions' => 'Write the missing words in the blanks.',
        'blocks'       => [],
        'media_type'   => 'none',
        'media_url'    => '',
        'tts_text'     => '',
        'voice_id'     => 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> '',
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $data = json_decode($row['data'] ?? '', true);

    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = [[
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
        ]];
    } else {
        $blocks = isset($data['blocks']) ? $data['blocks'] : [];
    }

    /* Parse wordbank: "nearby | closed | open" → ['nearby','closed','open'] */
    $wordbank = isset($data['wordbank']) ? trim((string)$data['wordbank']) : '';
    $options  = [];
    if ($wordbank !== '') {
        $options = array_values(array_filter(array_map('trim', explode('|', $wordbank))));
    }

    return [
        'id'           => (string)($row['id'] ?? ''),
        'instructions' => isset($data['instructions']) ? $data['instructions'] : $fallback['instructions'],
        'blocks'       => $blocks,
        'options'      => $options,
        'media_type'   => in_array($data['media_type'] ?? '', ['tts', 'audio', 'none'], true) ? (string)$data['media_type'] : 'none',
        'media_url'    => trim((string)($data['media_url'] ?? '')),
        'tts_text'     => trim((string)($data['tts_text'] ?? '')),
        'voice_id'     => trim((string)($data['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> trim((string)($data['tts_audio_url'] ?? '')),
    ];
}

$activity = fb2_load($pdo, $unit, $activityId);
$blocks   = $activity['blocks'];

if (empty($blocks)) {
    die('No activity blocks found.');
}

/*
 * Map each block to support MULTIPLE blanks per block
 * Text contains multiple ___ markers, answers array has one answer per blank
 */
$jsQuestions = [];
$instruction = $activity['instructions'];

foreach ($blocks as $block) {
    $text    = isset($block['text']) ? $block['text'] : '';
    $answers = isset($block['answers']) && is_array($block['answers']) ? $block['answers'] : [];
    $image   = isset($block['image']) ? trim((string) $block['image']) : '';

    if ($text === '' && empty($answers) && $image === '') continue;

    $jsQuestions[] = [
        'instruction' => $instruction,
        'text'        => $text,
        'answers'     => array_map('trim', $answers),
        'image_url'   => $image,
        'options'     => $activity['options'] ?? [],
    ];
}

if (empty($jsQuestions)) {
    die('No valid fill-blank questions found.');
}

$viewerTitle = 'Fill in the Blank';

$fbMediaType = (string)($activity['media_type'] ?? 'none');
$fbMediaUrl = trim((string)($activity['media_url'] ?? ''));
$fbTtsAudioUrl = trim((string)($activity['tts_audio_url'] ?? ''));
$fbVoiceId = trim((string)($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub';
$fbBlockTexts = [];
foreach ($blocks as $fbBlock) {
    $fbText = trim((string)($fbBlock['text'] ?? ''));
    if ($fbText !== '') $fbBlockTexts[] = $fbText;
}
$fbTtsText = trim((string)($activity['tts_text'] ?? ''));
if ($fbTtsText === '') {
    $fbTtsText = implode('. ', $fbBlockTexts);
}

ob_start();
?>
<?php
$fbQuizMode = false;
require __DIR__ . '/_fillblank_view.php';
?>

<script>
window.FILLBLANK_DATA        = <?php echo json_encode($jsQuestions, JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_RETURN_TO   = <?php echo json_encode($returnTo,    JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_ACTIVITY_ID = <?php echo json_encode($activityId,  JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_MEDIA_TYPE  = <?php echo json_encode($fbMediaType,  JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_MEDIA_URL   = <?php echo json_encode($fbMediaUrl,   JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_TTS_AUDIO_URL = <?php echo json_encode($fbTtsAudioUrl, JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_TTS_TEXT    = <?php echo json_encode($fbTtsText,    JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_VOICE_ID    = <?php echo json_encode($fbVoiceId,    JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_TTS_URL     = 'tts.php';
</script>
<script src="fillblank.js?v=<?php echo filemtime(__FILE__); ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-pen-to-square', $content);

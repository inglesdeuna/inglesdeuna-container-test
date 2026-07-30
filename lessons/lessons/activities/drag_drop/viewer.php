<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function dd_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function dd_normalize_image_url(string $image): string
{
    $image = trim($image);
    if ($image === '') {
        return '';
    }

    if (preg_match('/^(https?:)?\/\//i', $image) || strpos($image, 'data:') === 0) {
        return $image;
    }

    if (strpos($image, '/lessons/lessons/uploads/') === 0 || strpos($image, '/uploads/') === 0) {
        return $image;
    }

    if (strpos($image, 'lessons/lessons/uploads/') === 0) {
        return '/' . $image;
    }

    if (strpos($image, 'uploads/') === 0) {
        return '/lessons/lessons/' . $image;
    }

    return '/' . ltrim($image, './');
}

function dd_load_activity(PDO $pdo, string $activityId, string $unit): array
{
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'drag_drop' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'drag_drop' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return [
            'id' => '',
            'title' => 'Drag & Drop',
            'voice_id' => 'nzFihrBIvB34imQBuxub',
            'blocks' => [],
        ];
    }

    $decoded = json_decode((string) ($row['data'] ?? ''), true);
    if (!is_array($decoded)) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'title' => 'Drag & Drop',
            'voice_id' => 'nzFihrBIvB34imQBuxub',
            'blocks' => [],
        ];
    }

    $title = trim((string) ($decoded['title'] ?? 'Drag & Drop'));
    if ($title === '') {
        $title = 'Drag & Drop';
    }

    $defaultVoiceId = trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));
    if ($defaultVoiceId === '') {
        $defaultVoiceId = 'nzFihrBIvB34imQBuxub';
    }

    $blocksSource = isset($decoded['blocks']) && is_array($decoded['blocks']) ? $decoded['blocks'] : $decoded;
    $blocks = [];

    foreach ($blocksSource as $block) {
        if (!is_array($block)) {
            continue;
        }

        $text = trim((string) ($block['text'] ?? $block['sentence'] ?? $block['instruction'] ?? $block['prompt'] ?? ''));
        if ($text === '') {
            continue;
        }

        $missingWords = [];
        if (isset($block['missing_words']) && is_array($block['missing_words'])) {
            foreach ($block['missing_words'] as $word) {
                $cleanWord = trim((string) $word);
                if ($cleanWord !== '') {
                    $missingWords[] = $cleanWord;
                }
            }
        }

        $listenEnabled = true;
        if (array_key_exists('listen_enabled', $block)) {
            $parsed = filter_var($block['listen_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $listenEnabled = $parsed === null ? true : (bool) $parsed;
        } elseif (array_key_exists('listen', $block)) {
            $parsed = filter_var($block['listen'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $listenEnabled = $parsed === null ? true : (bool) $parsed;
        }

        $imageRaw = (string) (
            $block['image'] ??
            $block['image_url'] ??
            $block['imageUrl'] ??
            $block['img'] ??
            $block['media_url'] ??
            $block['mediaUrl'] ??
            $block['prompt_image'] ??
            $block['picture'] ??
            ''
        );
        $voiceId = trim((string) ($block['voice_id'] ?? $defaultVoiceId));
        if ($voiceId === '') {
            $voiceId = $defaultVoiceId;
        }

        $blocks[] = [
            'text' => $text,
            'missing_words' => $missingWords,
            'image' => dd_normalize_image_url($imageRaw),
            'listen_enabled' => $listenEnabled,
            'voice_id' => $voiceId,
        ];
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => $title,
        'voice_id' => $defaultVoiceId,
        'blocks' => $blocks,
    ];
}

function dd_build_question(array $block): ?array
{
    $text = (string) ($block['text'] ?? '');
    $missingWords = is_array($block['missing_words'] ?? null) ? $block['missing_words'] : [];
    $image = trim((string) ($block['image'] ?? ''));
    $listenEnabled = !array_key_exists('listen_enabled', $block) || (bool) $block['listen_enabled'];
    $voiceId = trim((string) ($block['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));
    if ($voiceId === '') {
        $voiceId = 'nzFihrBIvB34imQBuxub';
    }

    if ($text === '') {
        return null;
    }

    if (count($missingWords) === 0) {
        $words = preg_split('/\s+/', $text);
        $words = array_values(array_filter($words, 'strlen'));
        if (count($words) === 0) {
            return null;
        }

        $slots = [];
        foreach ($words as $word) {
            $slots[] = ['answer' => $word];
        }

        $instruction = implode(' ', array_fill(0, count($words), '___'));

        return [
            'instruction' => $instruction,
            'slots' => $slots,
            'words' => $words,
            'image' => $image,
            'tts_text' => $text,
            'listen_enabled' => $listenEnabled,
            'voice_id' => $voiceId,
        ];
    }

    $instruction = $text;
    $slotsWithPosition = [];
    foreach ($missingWords as $word) {
        $escaped = preg_quote((string) $word, '/');
        $pattern = '/\b' . $escaped . '\b/i';

        if (!preg_match($pattern, $instruction, $match, PREG_OFFSET_CAPTURE)) {
            $slotsWithPosition[] = [
                'answer' => (string) $word,
                'position' => PHP_INT_MAX,
            ];
            continue;
        }

        $position = isset($match[0][1]) ? (int) $match[0][1] : PHP_INT_MAX;
        $instruction = preg_replace($pattern, '___', $instruction, 1);
        $slotsWithPosition[] = [
            'answer' => (string) $word,
            'position' => $position,
        ];
    }

    usort($slotsWithPosition, function (array $left, array $right): int {
        if ($left['position'] === $right['position']) {
            return 0;
        }

        return $left['position'] <=> $right['position'];
    });

    $slots = array_map(function (array $slot): array {
        return ['answer' => (string) ($slot['answer'] ?? '')];
    }, $slotsWithPosition);

    return [
        'instruction' => $instruction,
        'slots' => $slots,
        'words' => array_map(function (array $slot): string {
            return (string) ($slot['answer'] ?? '');
        }, $slotsWithPosition),
        'image' => $image,
        'tts_text' => $text,
        'listen_enabled' => $listenEnabled,
        'voice_id' => $voiceId,
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = dd_resolve_unit($pdo, $activityId);
}

$activity = dd_load_activity($pdo, $activityId, $unit);
if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

$questions = [];
foreach ((array) ($activity['blocks'] ?? []) as $block) {
    $q = dd_build_question($block);
    if ($q !== null) {
        $questions[] = $q;
    }
}

if (count($questions) === 0) {
    die('No valid drag-drop questions found.');
}

$viewerTitle = (string) ($activity['title'] ?? 'Drag & Drop');

ob_start();
?>
<?php
$ddQuizMode = false;
require __DIR__ . '/_drag_drop_view.php';
?>
<script src="../../core/_activity_feedback.js"></script>
<script>
window.DRAGDROP_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.DRAGDROP_TITLE = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.DRAGDROP_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
window.DRAGDROP_ACTIVITY_ID = <?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="drag_drop.js?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/drag_drop.js')); ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-arrows-up-down-left-right', $content);

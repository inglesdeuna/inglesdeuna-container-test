<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function us_resolve_unit_from_activity_view(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us_default_title_view(): string
{
    return 'Unscramble the Sentence';
}

function us_parse_bool($raw, bool $default = true): bool
{
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_numeric($raw)) {
        return (int) $raw === 1;
    }
    if (is_string($raw)) {
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    return $default;
}

function us_words_from_sentence(string $sentence): array
{
    $sentence = trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence);
    if ($sentence === '') {
        return [];
    }
    return array_values(array_filter(preg_split('/\s+/u', $sentence) ?: [], static fn ($w): bool => $w !== ''));
}

function us_normalize_payload_view($rawData): array
{
    $default = [
        'title' => us_default_title_view(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'sentences' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    $voiceId = trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub';

    $sentencesSource = [];
    if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        $sentencesSource = $decoded['sentences'];
    } elseif (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $sentencesSource = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $sentencesSource = $decoded['items'];
    }

    $sentences = [];
    foreach ($sentencesSource as $item) {
        if (is_string($item)) {
            $sentence = trim($item);
            $listenEnabled = true;
        } elseif (is_array($item)) {
            $sentence = '';
            if (isset($item['sentence']) && is_string($item['sentence'])) {
                $sentence = trim($item['sentence']);
            } elseif (isset($item['text']) && is_string($item['text'])) {
                $sentence = trim($item['text']);
            } elseif (isset($item['answer']) && is_string($item['answer'])) {
                $sentence = trim($item['answer']);
            } elseif (isset($item['correct']) && is_array($item['correct'])) {
                $sentence = trim(implode(' ', array_map('strval', $item['correct'])));
            }

            $listenEnabled = array_key_exists('listen_enabled', $item)
                ? us_parse_bool($item['listen_enabled'])
                : (array_key_exists('listen', $item) ? us_parse_bool($item['listen']) : true);
        } else {
            continue;
        }

        $words = us_words_from_sentence($sentence);
        if ($sentence === '' || empty($words)) {
            continue;
        }

        $sentences[] = [
            'sentence' => $sentence,
            'words' => $words,
            'listen_enabled' => $listenEnabled,
        ];
    }

    return [
        'title' => $title !== '' ? $title : us_default_title_view(),
        'voice_id' => $voiceId,
        'sentences' => $sentences,
    ];
}

function us_load_activity_view(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => us_default_title_view(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'sentences' => [],
    ];

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'unscramble' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = us_normalize_payload_view($row['data'] ?? null);

    return [
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => (string) ($payload['title'] ?? us_default_title_view()),
        'voice_id' => (string) ($payload['voice_id'] ?? 'nzFihrBIvB34imQBuxub'),
        'sentences' => is_array($payload['sentences'] ?? null) ? $payload['sentences'] : [],
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = us_resolve_unit_from_activity_view($pdo, $activityId);
}

$activity = us_load_activity_view($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? us_default_title_view());
$activityVoiceId = (string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub');
$sentences = is_array($activity['sentences'] ?? null) ? $activity['sentences'] : [];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if (count($sentences) === 0) {
    die('No sentences found for this activity');
}

ob_start();
?>

<?php
$usQuizMode = false;
require __DIR__ . '/_unscramble_view.php';
?>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔀', $content);
?>

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

function activities_columns($pdo)
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

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function normalize_multiple_choice_questions($rawData)
{
    if ($rawData === null || $rawData === '') {
        return array();
    }

    if (is_string($rawData)) {
        $decoded = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }

        if (is_string($decoded)) {
            $decodedAgain = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $decodedAgain;
            }
        }
    } else {
        $decoded = $rawData;
    }

    if (!is_array($decoded)) {
        return array();
    }

    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $decoded = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $decoded = $decoded['data'];
    }

    $isList = array_keys($decoded) === range(0, count($decoded) - 1);
    if (!$isList) {
        if (isset($decoded['question']) || isset($decoded['options']) || isset($decoded['option_a'])) {
            $decoded = array($decoded);
        } else {
            return array();
        }
    }

    $normalized = array();

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $question = isset($item['question']) ? trim((string) $item['question']) : '';

        $image = '';
        if (isset($item['image'])) {
            $image = trim((string) $item['image']);
        } elseif (isset($item['img'])) {
            $image = trim((string) $item['img']);
        }

        $options = array('', '', '');
        if (isset($item['options']) && is_array($item['options'])) {
            $options = array(
                isset($item['options'][0]) ? trim((string) $item['options'][0]) : '',
                isset($item['options'][1]) ? trim((string) $item['options'][1]) : '',
                isset($item['options'][2]) ? trim((string) $item['options'][2]) : '',
            );
        } else {
            $options = array(
                isset($item['option_a']) ? trim((string) $item['option_a']) : '',
                isset($item['option_b']) ? trim((string) $item['option_b']) : '',
                isset($item['option_c']) ? trim((string) $item['option_c']) : '',
            );
        }

        $correct = isset($item['correct']) ? (int) $item['correct'] : 0;
        if ($correct < 0 || $correct > 2) {
            $correct = 0;
        }

        if ($question === '' && $options[0] === '' && $options[1] === '' && $options[2] === '') {
            continue;
        }

        $normalized[] = array(
            'question' => $question,
            'image' => $image,
            'options' => $options,
            'correct' => $correct,
        );
    }

    return $normalized;
}

function load_multiple_choice_raw($pdo, $activityId, $unit)
{
    $columns = activities_columns($pdo);

    $hasModern = in_array('unit_id', $columns, true) && in_array('data', $columns, true);
    $hasLegacy = in_array('unit', $columns, true) && in_array('content_json', $columns, true);

    $typeCandidates = array('multiple_choice', 'multiple-choice', 'multiple choice');

    if ($hasModern) {
        if ($activityId !== '' && in_array('id', $columns, true)) {
            $stmt = $pdo->prepare(
                "SELECT data
                 FROM activities
                 WHERE id = :id
                   AND type = 'multiple_choice'
                 LIMIT 1"
            );
            $stmt->execute(array('id' => $activityId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('data', $row)) {
                return $row['data'];
            }

            $stmt = $pdo->prepare(
                "SELECT data
                 FROM activities
                 WHERE id = :id
                 LIMIT 1"
            );
            $stmt->execute(array('id' => $activityId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('data', $row)) {
                return $row['data'];
            }
        }

        if ($unit !== '') {
            $stmt = $pdo->prepare(
                "SELECT data
                 FROM activities
                 WHERE unit_id = :unit
                   AND type = :type
                 LIMIT 1"
            );
            foreach ($typeCandidates as $type) {
                $stmt->execute(array('unit' => $unit, 'type' => $type));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && array_key_exists('data', $row)) {
                    return $row['data'];
                }
            }

            $stmt = $pdo->prepare(
                "SELECT data
                 FROM activities
                 WHERE unit_id = :unit
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('data', $row)) {
                return $row['data'];
            }
        }
    }

    if ($hasLegacy) {
        if ($activityId !== '' && in_array('id', $columns, true)) {
            $stmt = $pdo->prepare(
                "SELECT content_json
                 FROM activities
                 WHERE id = :id
                   AND type = 'multiple_choice'
                 LIMIT 1"
            );
            $stmt->execute(array('id' => $activityId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('content_json', $row)) {
                return $row['content_json'];
            }

            $stmt = $pdo->prepare(
                "SELECT content_json
                 FROM activities
                 WHERE id = :id
                 LIMIT 1"
            );
            $stmt->execute(array('id' => $activityId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('content_json', $row)) {
                return $row['content_json'];
            }
        }

        if ($unit !== '') {
            $stmt = $pdo->prepare(
                "SELECT content_json
                 FROM activities
                 WHERE unit = :unit
                   AND type = :type
                 LIMIT 1"
            );
            foreach ($typeCandidates as $type) {
                $stmt->execute(array('unit' => $unit, 'type' => $type));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && array_key_exists('content_json', $row)) {
                    return $row['content_json'];
                }
            }

            $stmt = $pdo->prepare(
                "SELECT content_json
                 FROM activities
                 WHERE unit = :unit
                 ORDER BY updated_at DESC NULLS LAST
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('content_json', $row)) {
                return $row['content_json'];
            }
        }
    }

    return '[]';
}

$raw = load_multiple_choice_raw($pdo, $activityId, $unit);
$questions = normalize_multiple_choice_questions($raw);

$cssVersion = file_exists(__DIR__ . '/multiple_choice.css') ? (string) filemtime(__DIR__ . '/multiple_choice.css') : (string) time();
$jsVersion = file_exists(__DIR__ . '/multiple_choice.js') ? (string) filemtime(__DIR__ . '/multiple_choice.js') : (string) time();

ob_start();
?>

<div class="mc-viewer" id="mc-container">
    <div class="mc-status" id="mc-status"></div>

    <div class="mc-card">
        <div class="mc-question" id="mc-question"></div>
        <img id="mc-image" class="mc-image" alt="Question image">
        <div class="mc-options" id="mc-options"></div>
    </div>

    <div class="mc-controls">
        <button type="button" class="mc-btn" id="mc-check">✅ Check</button>
        <button type="button" class="mc-btn" id="mc-next">➡️ Next</button>
    </div>

    <div class="mc-feedback" id="mc-feedback"></div>
</div>

<link rel="stylesheet" href="multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
<script>
const MULTIPLE_CHOICE_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="multiple_choice.js?v=<?php echo urlencode($jsVersion); ?>"></script>

<?php
$content = ob_get_clean();
render_activity_viewer('Multiple Choice', '📝', $content);

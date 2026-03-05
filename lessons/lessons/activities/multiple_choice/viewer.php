<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

function activities_columns(PDO $pdo): array
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

function normalize_multiple_choice_questions($rawData): array
{
    if ($rawData === null || $rawData === '') {
        return array();
    }

    $decoded = null;

    if (is_string($rawData)) {
        $decoded = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }

        // A veces viene como JSON doble-encapsulado en string
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

    // Detectar contenedores comunes
    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $decoded = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $decoded = $decoded['data'];
    }

    if (!is_array($decoded)) {
        return array();
    }

    // Evitar range(0, -1) en arrays vacíos
    $count = count($decoded);
    $isList = ($count === 0) ? true : (array_keys($decoded) === range(0, $count - 1));

    if (!$isList) {
        // Si es un objeto "pregunta suelta", lo envolvemos
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

        // 3 opciones (A,B,C)
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

        // Saltar ítems totalmente vacíos
        if ($question === '' && $options[0] === '' && $options[1] === '' && $options[2] === '') {
            continue;
        }

        $normalized[] = array(
            'question' => $question,
            'image'    => $image,
            'options'  => $options,
            'correct'  => $correct,
        );
    }

    return $normalized;
}

function load_multiple_choice_raw(PDO $pdo, string $activityId, string $unit)
{
    $columns = activities_columns($pdo);

    $hasModern = in_array('unit_id', $columns, true) && in_array('data', $columns, true);
    $hasLegacy = in_array('unit', $columns, true) && in_array('content_json', $columns, true);

    $typeCandidates = array('multiple_choice', 'multiple-choice', 'multiple choice');

    // ---------------- MODERNO (data, unit_id) ----------------
    if ($hasModern) {
        if ($activityId !== '' && in_array('id', $columns, true)) {

            // 1) Intentar por id con varios type posibles
            $stmtByIdType = $pdo->prepare(
                "SELECT data
                 FROM activities
                 WHERE id = :id
                   AND type = :type
                 LIMIT 1"
            );

            foreach ($typeCandidates as $type) {
                $stmtByIdType->execute(array('id' => $activityId, 'type' => $type));
                $row = $stmtByIdType->fetch(PDO::FETCH_ASSOC);
                if ($row && array_key_exists('data', $row)) {
                    return $row['data'];
                }
            }

            // 2) Fallback: por id sin filtrar type
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

    // ---------------- LEGADO (content_json, unit) ----------------
    if ($hasLegacy) {
        if ($activityId !== '' && in_array('id', $columns, true)) {

            $stmtByIdType = $pdo->prepare(
                "SELECT content_json
                 FROM activities
                 WHERE id = :id
                   AND type = :type
                 LIMIT 1"
            );

            foreach ($typeCandidates as $type) {
                $stmtByIdType->execute(array('id' => $activityId, 'type' => $type));
                $row = $stmtByIdType->fetch(PDO::FETCH_ASSOC);
                if ($row && array_key_exists('content_json', $row)) {
                    return $row['content_json'];
                }
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

$raw       = load_multiple_choice_raw($pdo, $activityId, $unit);
$questions = normalize_multiple_choice_questions($raw);

/* ===== DEBUG TEMPORAL (quitar después) ===== */
$debugEnabled = isset($_GET['debug']) && $_GET['debug'] === '1';

if ($debugEnabled) {
    header('Content-Type: text/plain; charset=utf-8');

    echo "DEBUG multiple_choice viewer\n";
    echo "============================\n";
    echo "activityId: " . $activityId . "\n";
    echo "unit: " . $unit . "\n";
    echo "raw type: " . gettype($raw) . "\n";
    $preview = substr((string) $raw, 0, 500);
    echo "raw preview: " . $preview . "\n";
    echo "questions count: " . count($questions) . "\n";
    echo "questions json: " . json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit;
}

$cssVersion = file_exists(__DIR__ . '/multiple_choice.css') ? (string) filemtime(__DIR__ . '/multiple_choice.css') : (string) time();
$jsVersion  = file_exists(__DIR__ . '/multiple_choice.js') ? (string) filemtime(__DIR__ . '/multiple_choice.js') : (string) time();

ob_start();
?>

<div class="mc-viewer" id="mc-container">
    <div class="mc-status" id="mc-status"></div>

    <div class="mc-card">
        <div class="mc-question" id="mc-question"></div>
        <img id="mc-image" class="mc-image" alt="">
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

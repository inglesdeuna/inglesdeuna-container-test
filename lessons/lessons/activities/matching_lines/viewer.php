<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function mlv_activities_columns(PDO $pdo): array
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

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function mlv_resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = mlv_activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function mlv_default_title(): string
{
    return 'Matching Lines';
}

function mlv_normalize_payload($rawData): array
{
    $default = array(
        'title' => mlv_default_title(),
        'boards' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = isset($decoded['title']) ? trim((string) $decoded['title']) : '';
    $boardsSource = isset($decoded['boards']) && is_array($decoded['boards'])
        ? $decoded['boards']
        : array();

    if (empty($boardsSource) && isset($decoded['pairs']) && is_array($decoded['pairs'])) {
        $boardsSource = array(
            array(
                'id' => uniqid('ml_board_'),
                'title' => 'Board 1',
                'pairs' => $decoded['pairs'],
            )
        );
    }

    $boards = array();

    foreach ($boardsSource as $i => $board) {
        if (!is_array($board)) {
            continue;
        }

        $pairsSource = isset($board['pairs']) && is_array($board['pairs']) ? $board['pairs'] : array();
        $pairs = array();

        foreach ($pairsSource as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $leftText = isset($pair['left_text']) ? trim((string) $pair['left_text']) : '';
            $rightText = isset($pair['right_text']) ? trim((string) $pair['right_text']) : '';
            $leftImage = isset($pair['left_image']) ? trim((string) $pair['left_image']) : '';
            $rightImage = isset($pair['right_image']) ? trim((string) $pair['right_image']) : '';

            if (($leftText === '' && $leftImage === '') || ($rightText === '' && $rightImage === '')) {
                continue;
            }

            $pairs[] = array(
                'id' => isset($pair['id']) && trim((string) $pair['id']) !== '' ? trim((string) $pair['id']) : uniqid('ml_pair_'),
                'left_text' => $leftText,
                'left_image' => $leftImage,
                'right_text' => $rightText,
                'right_image' => $rightImage,
            );
        }

        if (!empty($pairs)) {
            $boards[] = array(
                'id' => isset($board['id']) && trim((string) $board['id']) !== '' ? trim((string) $board['id']) : uniqid('ml_board_'),
                'title' => isset($board['title']) && trim((string) $board['title']) !== '' ? trim((string) $board['title']) : ('Board ' . ((int) $i + 1)),
                'pairs' => $pairs,
            );
        }
    }

    return array(
        'title' => $title !== '' ? $title : mlv_default_title(),
        'boards' => $boards,
    );
}

function mlv_load_activity(PDO $pdo, string $activityId, string $unit): array
{
    $columns = mlv_activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $findById = function (string $id) use ($pdo, $selectFields): ?array {
        if ($id === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'matching_lines'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitId = function (string $unitId) use ($pdo, $selectFields, $columns): ?array {
        if ($unitId === '' || !in_array('unit_id', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'matching_lines'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $findByUnitLegacy = function (string $unitValue) use ($pdo, $selectFields, $columns): ?array {
        if ($unitValue === '' || !in_array('unit', $columns, true)) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'matching_lines'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unitValue));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    };

    $row = null;
    if ($activityId !== '') {
        $row = $findById($activityId);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitId($unit);
    }
    if (!$row && $unit !== '') {
        $row = $findByUnitLegacy($unit);
    }

    if (!$row) {
        return array('id' => '', 'title' => mlv_default_title(), 'boards' => array());
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = mlv_normalize_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => (string) ($payload['title'] ?? mlv_default_title()),
        'boards' => isset($payload['boards']) && is_array($payload['boards']) ? $payload['boards'] : array(),
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = mlv_resolve_unit_from_activity($pdo, $activityId);
}

$activity = mlv_load_activity($pdo, $activityId, $unit);
$boards = isset($activity['boards']) && is_array($activity['boards']) ? $activity['boards'] : array();
$viewerTitle = isset($activity['title']) && trim((string) $activity['title']) !== ''
    ? trim((string) $activity['title'])
    : mlv_default_title();

$cssVersion = (string) (@filemtime(__DIR__ . '/matching_lines.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/matching_lines.js') ?: time());

ob_start();
?>

<link rel="stylesheet" href="matching_lines.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">

<?php if (empty($boards)) { ?>
    <div class="mlv-empty">No matching lines data available.</div>
<?php } else { ?>
    <section class="mlv-wrap">
        <header class="mlv-hero">
            <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <p>Tap one card on the left, then one card on the right to draw the line.</p>
        </header>

        <div class="mlv-board-meta">
            <div class="mlv-board-title" id="mlvBoardTitle"></div>
            <div class="mlv-progress" id="mlvProgress"></div>
        </div>

        <div class="mlv-stage" id="mlvStage">
            <svg id="mlvLines" class="mlv-lines" aria-hidden="true"></svg>
            <div class="mlv-col mlv-left" id="mlvLeft"></div>
            <div class="mlv-col mlv-right" id="mlvRight"></div>
        </div>

        <div class="mlv-toolbar">
            <button type="button" class="mlv-btn mlv-btn-soft" id="mlvPrevBtn">Previous</button>
            <button type="button" class="mlv-btn mlv-btn-accent" id="mlvShowBtn">Show Answer</button>
            <button type="button" class="mlv-btn mlv-btn-soft" id="mlvResetBtn">Reset</button>
            <button type="button" class="mlv-btn mlv-btn-soft" id="mlvNextBtn">Next</button>
        </div>
    </section>

    <script>
    window.MATCHING_LINES_DATA = <?= json_encode($boards, JSON_UNESCAPED_UNICODE) ?>;
    window.MATCHING_LINES_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
    window.MATCHING_LINES_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="matching_lines.js?v=<?= htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php } ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧠', $content);

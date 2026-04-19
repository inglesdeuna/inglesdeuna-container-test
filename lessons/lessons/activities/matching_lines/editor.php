<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function ml_activities_columns(PDO $pdo): array
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

function ml_resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = ml_activities_columns($pdo);

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

function ml_default_title(): string
{
    return 'Matching Lines';
}

function ml_default_board_title(int $index): string
{
    return 'Board ' . ($index + 1);
}

function ml_normalize_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : ml_default_title();
}

function ml_normalize_payload($rawData): array
{
    $default = array(
        'title' => ml_default_title(),
        'boards' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    $boardsSource = array();
    if (isset($decoded['boards']) && is_array($decoded['boards'])) {
        $boardsSource = $decoded['boards'];
    } elseif (isset($decoded['sets']) && is_array($decoded['sets'])) {
        $boardsSource = $decoded['sets'];
    } elseif (isset($decoded['pairs']) && is_array($decoded['pairs'])) {
        $boardsSource = array(
            array(
                'id' => uniqid('ml_board_'),
                'title' => ml_default_board_title(0),
                'pairs' => $decoded['pairs'],
            )
        );
    }

    $boards = array();

    foreach ($boardsSource as $boardIndex => $board) {
        if (!is_array($board)) {
            continue;
        }

        $boardPairsSource = isset($board['pairs']) && is_array($board['pairs'])
            ? $board['pairs']
            : array();

        $pairs = array();
        foreach ($boardPairsSource as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $pairs[] = array(
                'id' => isset($pair['id']) && trim((string) $pair['id']) !== '' ? trim((string) $pair['id']) : uniqid('ml_pair_'),
                'left_text' => isset($pair['left_text']) ? trim((string) $pair['left_text']) : '',
                'left_image' => isset($pair['left_image']) ? trim((string) $pair['left_image']) : '',
                'right_text' => isset($pair['right_text']) ? trim((string) $pair['right_text']) : '',
                'right_image' => isset($pair['right_image']) ? trim((string) $pair['right_image']) : '',
            );
        }

        $boards[] = array(
            'id' => isset($board['id']) && trim((string) $board['id']) !== '' ? trim((string) $board['id']) : uniqid('ml_board_'),
            'title' => isset($board['title']) && trim((string) $board['title']) !== ''
                ? trim((string) $board['title'])
                : ml_default_board_title((int) $boardIndex),
            'pairs' => $pairs,
        );
    }

    if (empty($boards)) {
        $boards[] = array(
            'id' => uniqid('ml_board_'),
            'title' => ml_default_board_title(0),
            'pairs' => array(),
        );
    }

    return array(
        'title' => ml_normalize_title($title),
        'boards' => $boards,
    );
}

function ml_encode_payload(array $payload): string
{
    return json_encode(
        array(
            'title' => ml_normalize_title(isset($payload['title']) ? (string) $payload['title'] : ''),
            'boards' => isset($payload['boards']) && is_array($payload['boards']) ? array_values($payload['boards']) : array(),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function ml_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = ml_activities_columns($pdo);

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

    $fallback = array(
        'id' => '',
        'title' => ml_default_title(),
        'boards' => array(
            array('id' => uniqid('ml_board_'), 'title' => ml_default_board_title(0), 'pairs' => array()),
        ),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'matching_lines'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'matching_lines'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'matching_lines'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = ml_normalize_payload($rawData);

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
        'title' => ml_normalize_title((string) ($payload['title'] ?? '')),
        'boards' => isset($payload['boards']) && is_array($payload['boards']) ? $payload['boards'] : array(),
    );
}

function ml_save_activity(PDO $pdo, string $unit, string $activityId, string $title, array $boards): string
{
    $columns = ml_activities_columns($pdo);
    $title = ml_normalize_title($title);
    $json = ml_encode_payload(array(
        'title' => $title,
        'boards' => $boards,
    ));

    $hasUnitId = in_array('unit_id', $columns, true);
    $hasUnit = in_array('unit', $columns, true);
    $hasData = in_array('data', $columns, true);
    $hasContentJson = in_array('content_json', $columns, true);
    $hasId = in_array('id', $columns, true);
    $hasTitle = in_array('title', $columns, true);
    $hasName = in_array('name', $columns, true);

    $targetId = $activityId;

    if ($targetId === '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit_id = :unit
                   AND type = 'matching_lines'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }

        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit = :unit
                   AND type = 'matching_lines'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = array();
        $params = array('id' => $targetId);

        if ($hasData) {
            $setParts[] = 'data = :data';
            $params['data'] = $json;
        }

        if ($hasContentJson) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $json;
        }

        if ($hasTitle) {
            $setParts[] = 'title = :title';
            $params['title'] = $title;
        }

        if ($hasName) {
            $setParts[] = 'name = :name';
            $params['name'] = $title;
        }

        if (!empty($setParts)) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET " . implode(', ', $setParts) . "
                 WHERE id = :id
                   AND type = 'matching_lines'"
            );
            $stmt->execute($params);
        }

        return $targetId;
    }

    $insertColumns = array();
    $insertValues = array();
    $params = array();

    $newId = '';
    if ($hasId) {
        $newId = md5(random_bytes(16));
        $insertColumns[] = 'id';
        $insertValues[] = ':id';
        $params['id'] = $newId;
    }

    if ($hasUnitId) {
        $insertColumns[] = 'unit_id';
        $insertValues[] = ':unit_id';
        $params['unit_id'] = $unit;
    } elseif ($hasUnit) {
        $insertColumns[] = 'unit';
        $insertValues[] = ':unit';
        $params['unit'] = $unit;
    }

    $insertColumns[] = 'type';
    $insertValues[] = "'matching_lines'";

    if ($hasData) {
        $insertColumns[] = 'data';
        $insertValues[] = ':data';
        $params['data'] = $json;
    }

    if ($hasContentJson) {
        $insertColumns[] = 'content_json';
        $insertValues[] = ':content_json';
        $params['content_json'] = $json;
    }

    if ($hasTitle) {
        $insertColumns[] = 'title';
        $insertValues[] = ':title';
        $params['title'] = $title;
    }

    if ($hasName) {
        $insertColumns[] = 'name';
        $insertValues[] = ':name';
        $params['name'] = $title;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO activities (" . implode(', ', $insertColumns) . ")
         VALUES (" . implode(', ', $insertValues) . ")"
    );
    $stmt->execute($params);

    return $newId;
}

if ($unit === '' && $activityId !== '') {
    $unit = ml_resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = ml_load_activity($pdo, $unit, $activityId);
$boards = isset($activity['boards']) && is_array($activity['boards']) ? $activity['boards'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : ml_default_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';

    $boardTokens = isset($_POST['board_token']) && is_array($_POST['board_token']) ? $_POST['board_token'] : array();
    $boardTitles = isset($_POST['board_title']) && is_array($_POST['board_title']) ? $_POST['board_title'] : array();

    $pairIds = isset($_POST['pair_id']) && is_array($_POST['pair_id']) ? $_POST['pair_id'] : array();
    $pairBoards = isset($_POST['pair_board']) && is_array($_POST['pair_board']) ? $_POST['pair_board'] : array();
    $leftTexts = isset($_POST['left_text']) && is_array($_POST['left_text']) ? $_POST['left_text'] : array();
    $rightTexts = isset($_POST['right_text']) && is_array($_POST['right_text']) ? $_POST['right_text'] : array();
    $leftImages = isset($_POST['left_image_existing']) && is_array($_POST['left_image_existing']) ? $_POST['left_image_existing'] : array();
    $rightImages = isset($_POST['right_image_existing']) && is_array($_POST['right_image_existing']) ? $_POST['right_image_existing'] : array();
    $leftRemoveFlags = isset($_POST['left_remove_image']) && is_array($_POST['left_remove_image']) ? $_POST['left_remove_image'] : array();
    $rightRemoveFlags = isset($_POST['right_remove_image']) && is_array($_POST['right_remove_image']) ? $_POST['right_remove_image'] : array();

    $leftImageFiles = isset($_FILES['left_image_file']) ? $_FILES['left_image_file'] : null;
    $rightImageFiles = isset($_FILES['right_image_file']) ? $_FILES['right_image_file'] : null;

    $boardsByToken = array();
    $boardsOrdered = array();

    $boardsCount = max(count($boardTokens), count($boardTitles));
    for ($i = 0; $i < $boardsCount; $i++) {
        $token = isset($boardTokens[$i]) && trim((string) $boardTokens[$i]) !== ''
            ? trim((string) $boardTokens[$i])
            : uniqid('ml_board_');

        $title = isset($boardTitles[$i]) && trim((string) $boardTitles[$i]) !== ''
            ? trim((string) $boardTitles[$i])
            : ml_default_board_title($i);

        $boardItem = array(
            'id' => $token,
            'title' => $title,
            'pairs' => array(),
        );

        $boardsByToken[$token] = $boardItem;
        $boardsOrdered[] = $token;
    }

    $pairsCount = max(
        count($pairIds),
        count($pairBoards),
        count($leftTexts),
        count($rightTexts),
        count($leftImages),
        count($rightImages)
    );

    for ($i = 0; $i < $pairsCount; $i++) {
        $boardToken = isset($pairBoards[$i]) ? trim((string) $pairBoards[$i]) : '';
        if ($boardToken === '' || !isset($boardsByToken[$boardToken])) {
            continue;
        }

        $pairId = isset($pairIds[$i]) && trim((string) $pairIds[$i]) !== ''
            ? trim((string) $pairIds[$i])
            : uniqid('ml_pair_');

        $leftText = isset($leftTexts[$i]) ? trim((string) $leftTexts[$i]) : '';
        $rightText = isset($rightTexts[$i]) ? trim((string) $rightTexts[$i]) : '';
        $leftImage = isset($leftImages[$i]) ? trim((string) $leftImages[$i]) : '';
        $rightImage = isset($rightImages[$i]) ? trim((string) $rightImages[$i]) : '';

        $leftRemove = isset($leftRemoveFlags[$i]) && (string) $leftRemoveFlags[$i] === '1';
        $rightRemove = isset($rightRemoveFlags[$i]) && (string) $rightRemoveFlags[$i] === '1';

        if ($leftRemove) {
            $leftImage = '';
        }
        if ($rightRemove) {
            $rightImage = '';
        }

        if (
            $leftImageFiles
            && isset($leftImageFiles['name'][$i])
            && $leftImageFiles['name'][$i] !== ''
            && isset($leftImageFiles['tmp_name'][$i])
            && $leftImageFiles['tmp_name'][$i] !== ''
        ) {
            $uploaded = upload_to_cloudinary($leftImageFiles['tmp_name'][$i]);
            if ($uploaded) {
                $leftImage = $uploaded;
            }
        }

        if (
            $rightImageFiles
            && isset($rightImageFiles['name'][$i])
            && $rightImageFiles['name'][$i] !== ''
            && isset($rightImageFiles['tmp_name'][$i])
            && $rightImageFiles['tmp_name'][$i] !== ''
        ) {
            $uploaded = upload_to_cloudinary($rightImageFiles['tmp_name'][$i]);
            if ($uploaded) {
                $rightImage = $uploaded;
            }
        }

        $leftHasContent = ($leftText !== '' || $leftImage !== '');
        $rightHasContent = ($rightText !== '' || $rightImage !== '');

        if (!$leftHasContent || !$rightHasContent) {
            continue;
        }

        $boardsByToken[$boardToken]['pairs'][] = array(
            'id' => $pairId,
            'left_text' => $leftText,
            'left_image' => $leftImage,
            'right_text' => $rightText,
            'right_image' => $rightImage,
        );
    }

    $sanitizedBoards = array();
    foreach ($boardsOrdered as $index => $token) {
        if (!isset($boardsByToken[$token])) {
            continue;
        }

        $board = $boardsByToken[$token];
        $hasPairs = !empty($board['pairs']);

        if (!$hasPairs) {
            continue;
        }

        $sanitizedBoards[] = array(
            'id' => $board['id'],
            'title' => trim((string) $board['title']) !== '' ? trim((string) $board['title']) : ml_default_board_title((int) $index),
            'pairs' => array_values($board['pairs']),
        );
    }

    if (empty($sanitizedBoards)) {
        $sanitizedBoards[] = array(
            'id' => uniqid('ml_board_'),
            'title' => ml_default_board_title(0),
            'pairs' => array(),
        );
    }

    $savedActivityId = ml_save_activity($pdo, $unit, $activityId, $postedTitle, $sanitizedBoards);

    $params = array('unit=' . urlencode($unit), 'saved=1');

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
    } elseif ($activityId !== '') {
        $params[] = 'id=' . urlencode($activityId);
    }

    if ($assignment !== '') {
        $params[] = 'assignment=' . urlencode($assignment);
    }

    if ($source !== '') {
        $params[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $params));
    exit;
}

ob_start();
?>

<style>
.ml-editor{max-width:980px;margin:0 auto}
.ml-title-box{background:linear-gradient(135deg,#f0fdfa 0%,#f5f3ff 100%);border:1px solid #c7d2fe;border-radius:16px;padding:14px 16px;margin-bottom:14px}
.ml-title-box label{display:block;font-weight:800;color:#115e59;margin-bottom:8px}
.ml-title-box input{width:100%;border:1px solid #99f6e4;border-radius:12px;padding:10px 12px;font-size:15px;background:#ffffff}
.ml-help{background:#ffffff;border:1px dashed #99f6e4;border-radius:14px;padding:12px 14px;color:#0f766e;margin-bottom:14px}
.ml-boards{display:grid;gap:14px}
.ml-board{background:#ffffff;border:1px solid #ddd6fe;border-radius:18px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.05)}
.ml-board-top{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap}
.ml-board-top input{flex:1;min-width:220px;border:1px solid #c4b5fd;background:#f8f7ff;border-radius:10px;padding:8px 10px;font-weight:700;color:#4c1d95}
.ml-pairs{display:grid;gap:10px}
.ml-pair{border:1px solid #bfdbfe;border-radius:14px;padding:12px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%)}
.ml-pair-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ml-side{border:1px solid #99f6e4;border-radius:12px;padding:10px;background:#f0fdfa}
.ml-side h4{margin:0 0 8px;font-size:14px;color:#134e4a}
.ml-side label{display:block;font-size:12px;font-weight:800;color:#0f766e;margin:0 0 5px}
.ml-side input[type="text"], .ml-side input[type="file"]{width:100%;border:1px solid #5eead4;background:#ffffff;border-radius:9px;padding:8px 10px;margin-bottom:8px}
.ml-preview{display:block;max-width:120px;max-height:100px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;object-fit:contain;margin-bottom:8px}
.ml-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:12px}
.ml-btn{border:none;border-radius:999px;padding:10px 14px;font-weight:800;cursor:pointer}
.ml-btn-add{background:linear-gradient(180deg,#14b8a6,#0f766e);color:#fff}
.ml-btn-add-board{background:linear-gradient(180deg,#8b5cf6,#6d28d9);color:#fff}
.ml-btn-remove{background:linear-gradient(180deg,#f43f5e,#be123c);color:#fff;padding:7px 10px;font-size:12px}
.ml-btn-soft{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;padding:7px 10px;font-size:12px}
.ml-save{background:linear-gradient(180deg,#0d9488,#0f766e);color:#fff;border:none;border-radius:999px;padding:11px 22px;font-weight:800;cursor:pointer}
@media (max-width:780px){.ml-pair-grid{grid-template-columns:1fr}}
</style>

<?php if (isset($_GET['saved'])) { ?>
<p style="color:#065f46;font-weight:800;margin-bottom:12px;">Saved successfully.</p>
<?php } ?>

<form id="mlEditorForm" class="ml-editor" method="post" enctype="multipart/form-data">
    <div class="ml-title-box">
        <label for="activity_title">Activity title</label>
        <input id="activity_title" name="activity_title" type="text" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Match each number with its quantity" required>
    </div>

    <div class="ml-help">Create one or more boards. Each board accepts text and/or image on both sides. The viewer will show Previous, Next and Show Answer controls.</div>

    <div class="ml-actions" style="justify-content:flex-start;margin:0 0 12px;">
        <button type="button" class="ml-btn ml-btn-add-board" onclick="mlAddBoard()">+ Add Board</button>
    </div>

    <div id="mlBoards" class="ml-boards">
        <?php foreach ($boards as $boardIndex => $board) { ?>
            <?php
            $boardId = isset($board['id']) && trim((string) $board['id']) !== '' ? trim((string) $board['id']) : uniqid('ml_board_');
            $boardTitle = isset($board['title']) ? trim((string) $board['title']) : ml_default_board_title((int) $boardIndex);
            $boardPairs = isset($board['pairs']) && is_array($board['pairs']) ? $board['pairs'] : array();
            ?>
            <section class="ml-board" data-board-id="<?= htmlspecialchars($boardId, ENT_QUOTES, 'UTF-8') ?>">
                <div class="ml-board-top">
                    <input type="hidden" name="board_token[]" value="<?= htmlspecialchars($boardId, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" name="board_title[]" value="<?= htmlspecialchars($boardTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Board title">
                    <button type="button" class="ml-btn ml-btn-remove" onclick="mlRemoveBoard(this)">Remove Board</button>
                </div>

                <div class="ml-pairs">
                    <?php foreach ($boardPairs as $pair) { ?>
                        <?php
                        $leftImage = isset($pair['left_image']) ? trim((string) $pair['left_image']) : '';
                        $rightImage = isset($pair['right_image']) ? trim((string) $pair['right_image']) : '';
                        ?>
                        <article class="ml-pair">
                            <input type="hidden" name="pair_id[]" value="<?= htmlspecialchars(isset($pair['id']) ? (string) $pair['id'] : uniqid('ml_pair_'), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="pair_board[]" value="<?= htmlspecialchars($boardId, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="left_image_existing[]" value="<?= htmlspecialchars($leftImage, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="right_image_existing[]" value="<?= htmlspecialchars($rightImage, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="left_remove_image[]" value="0">
                            <input type="hidden" name="right_remove_image[]" value="0">

                            <div class="ml-pair-grid">
                                <div class="ml-side">
                                    <h4>Left Item</h4>
                                    <label>Text</label>
                                    <input type="text" name="left_text[]" value="<?= htmlspecialchars(isset($pair['left_text']) ? (string) $pair['left_text'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: 2">
                                    <label>Image</label>
                                    <img class="ml-preview" src="<?= htmlspecialchars($leftImage, ENT_QUOTES, 'UTF-8') ?>" alt="left image" style="<?= $leftImage === '' ? 'display:none;' : '' ?>">
                                    <button type="button" class="ml-btn ml-btn-soft" onclick="mlClearImage(this,'left')">Remove image</button>
                                    <input type="file" name="left_image_file[]" accept="image/*" onchange="mlPreviewFile(this)">
                                </div>
                                <div class="ml-side">
                                    <h4>Right Item</h4>
                                    <label>Text</label>
                                    <input type="text" name="right_text[]" value="<?= htmlspecialchars(isset($pair['right_text']) ? (string) $pair['right_text'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: two circles">
                                    <label>Image</label>
                                    <img class="ml-preview" src="<?= htmlspecialchars($rightImage, ENT_QUOTES, 'UTF-8') ?>" alt="right image" style="<?= $rightImage === '' ? 'display:none;' : '' ?>">
                                    <button type="button" class="ml-btn ml-btn-soft" onclick="mlClearImage(this,'right')">Remove image</button>
                                    <input type="file" name="right_image_file[]" accept="image/*" onchange="mlPreviewFile(this)">
                                </div>
                            </div>

                            <div class="ml-actions">
                                <button type="button" class="ml-btn ml-btn-remove" onclick="mlRemovePair(this)">Remove Pair</button>
                            </div>
                        </article>
                    <?php } ?>
                </div>

                <div class="ml-actions">
                    <button type="button" class="ml-btn ml-btn-add" onclick="mlAddPair(this)">+ Add Pair</button>
                </div>
            </section>
        <?php } ?>
    </div>

    <div class="ml-actions" style="margin-top:16px;">
        <button type="submit" class="ml-save">Save Activity</button>
    </div>
</form>

<script>
function mlToken(prefix) {
    return prefix + '_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
}

function mlPairMarkup(boardId) {
    const pairId = mlToken('ml_pair');
    return `
    <article class="ml-pair">
        <input type="hidden" name="pair_id[]" value="${pairId}">
        <input type="hidden" name="pair_board[]" value="${boardId}">
        <input type="hidden" name="left_image_existing[]" value="">
        <input type="hidden" name="right_image_existing[]" value="">
        <input type="hidden" name="left_remove_image[]" value="0">
        <input type="hidden" name="right_remove_image[]" value="0">

        <div class="ml-pair-grid">
            <div class="ml-side">
                <h4>Left Item</h4>
                <label>Text</label>
                <input type="text" name="left_text[]" placeholder="Example: 5">
                <label>Image</label>
                <img class="ml-preview" src="" alt="left image" style="display:none;">
                <button type="button" class="ml-btn ml-btn-soft" onclick="mlClearImage(this,'left')">Remove image</button>
                <input type="file" name="left_image_file[]" accept="image/*" onchange="mlPreviewFile(this)">
            </div>
            <div class="ml-side">
                <h4>Right Item</h4>
                <label>Text</label>
                <input type="text" name="right_text[]" placeholder="Example: five stars">
                <label>Image</label>
                <img class="ml-preview" src="" alt="right image" style="display:none;">
                <button type="button" class="ml-btn ml-btn-soft" onclick="mlClearImage(this,'right')">Remove image</button>
                <input type="file" name="right_image_file[]" accept="image/*" onchange="mlPreviewFile(this)">
            </div>
        </div>

        <div class="ml-actions">
            <button type="button" class="ml-btn ml-btn-remove" onclick="mlRemovePair(this)">Remove Pair</button>
        </div>
    </article>`;
}

function mlBoardMarkup() {
    const boardId = mlToken('ml_board');
    return `
    <section class="ml-board" data-board-id="${boardId}">
        <div class="ml-board-top">
            <input type="hidden" name="board_token[]" value="${boardId}">
            <input type="text" name="board_title[]" value="" placeholder="Board title">
            <button type="button" class="ml-btn ml-btn-remove" onclick="mlRemoveBoard(this)">Remove Board</button>
        </div>
        <div class="ml-pairs">
            ${mlPairMarkup(boardId)}
        </div>
        <div class="ml-actions">
            <button type="button" class="ml-btn ml-btn-add" onclick="mlAddPair(this)">+ Add Pair</button>
        </div>
    </section>`;
}

function mlAddBoard() {
    const wrap = document.getElementById('mlBoards');
    wrap.insertAdjacentHTML('beforeend', mlBoardMarkup());
}

function mlRemoveBoard(btn) {
    const board = btn.closest('.ml-board');
    if (!board) {
        return;
    }
    const allBoards = document.querySelectorAll('.ml-board');
    if (allBoards.length <= 1) {
        alert('At least one board is required.');
        return;
    }
    board.remove();
}

function mlAddPair(btn) {
    const board = btn.closest('.ml-board');
    if (!board) {
        return;
    }
    const boardId = board.getAttribute('data-board-id') || mlToken('ml_board');
    const pairsWrap = board.querySelector('.ml-pairs');
    pairsWrap.insertAdjacentHTML('beforeend', mlPairMarkup(boardId));
}

function mlRemovePair(btn) {
    const pair = btn.closest('.ml-pair');
    if (!pair) {
        return;
    }
    const board = btn.closest('.ml-board');
    const pairs = board ? board.querySelectorAll('.ml-pair') : [];
    if (pairs.length <= 1) {
        alert('Each board needs at least one pair.');
        return;
    }
    pair.remove();
}

function mlClearImage(btn, side) {
    const pair = btn.closest('.ml-pair');
    const sideWrap = btn.closest('.ml-side');
    if (!pair || !sideWrap) {
        return;
    }

    const preview = sideWrap.querySelector('.ml-preview');
    if (preview) {
        preview.src = '';
        preview.style.display = 'none';
    }

    const fileInput = sideWrap.querySelector('input[type="file"]');
    if (fileInput) {
        fileInput.value = '';
    }

    const flagName = side === 'left' ? 'left_remove_image[]' : 'right_remove_image[]';
    const flag = pair.querySelector(`input[name="${flagName}"]`);
    if (flag) {
        flag.value = '1';
    }

    const existingName = side === 'left' ? 'left_image_existing[]' : 'right_image_existing[]';
    const hidden = pair.querySelector(`input[name="${existingName}"]`);
    if (hidden) {
        hidden.value = '';
    }
}

function mlPreviewFile(input) {
    const sideWrap = input.closest('.ml-side');
    if (!sideWrap || !input.files || !input.files[0]) {
        return;
    }

    const pair = input.closest('.ml-pair');
    const preview = sideWrap.querySelector('.ml-preview');
    if (!preview) {
        return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
        preview.src = event.target && event.target.result ? event.target.result : '';
        preview.style.display = preview.src ? 'block' : 'none';
    };
    reader.readAsDataURL(input.files[0]);

    if (pair) {
        const isLeft = input.name === 'left_image_file[]';
        const flagName = isLeft ? 'left_remove_image[]' : 'right_remove_image[]';
        const flag = pair.querySelector(`input[name="${flagName}"]`);
        if (flag) {
            flag.value = '0';
        }
    }
}

(function mlEnsureInitialBoard() {
    const wrap = document.getElementById('mlBoards');
    if (!wrap || wrap.children.length > 0) {
        return;
    }
    wrap.insertAdjacentHTML('beforeend', mlBoardMarkup());
})();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Matching Lines', 'fa-solid fa-diagram-project', $content);

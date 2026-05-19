<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (!empty($_SESSION['student_logged'])) {
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

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

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

function default_memory_cards_title(): string
{
    return 'Memory Cards';
}

function normalize_memory_side($raw): array
{
    $side = is_array($raw) ? $raw : array();

    $type = strtolower(trim((string) ($side['type'] ?? 'text')));
    $text = trim((string) ($side['text'] ?? ''));
    $image = trim((string) ($side['image'] ?? ''));

    if ($type !== 'text' && $type !== 'image') {
        $type = $image !== '' ? 'image' : 'text';
    }

    if ($type === 'text' && $text === '' && $image !== '') {
        $type = 'image';
    }

    if ($type === 'image' && $image === '' && $text !== '') {
        $type = 'text';
    }

    return array(
        'type' => $type,
        'text' => $text,
        'image' => $image,
    );
}

function pair_side_is_valid(array $side): bool
{
    $type = strtolower((string) ($side['type'] ?? 'text'));
    if ($type === 'image') {
        return trim((string) ($side['image'] ?? '')) !== '';
    }

    return trim((string) ($side['text'] ?? '')) !== '';
}

function normalize_memory_cards_payload($rawData): array
{
    $default = array(
        'title' => default_memory_cards_title(),
        'pairs' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    if ($title === '') {
        $title = default_memory_cards_title();
    }

    $pairsSource = isset($decoded['pairs']) && is_array($decoded['pairs'])
        ? $decoded['pairs']
        : array();

    $pairs = array();

    foreach ($pairsSource as $index => $pairRaw) {
        if (!is_array($pairRaw)) {
            continue;
        }

        $left = normalize_memory_side(isset($pairRaw['left']) ? $pairRaw['left'] : array());
        $right = normalize_memory_side(isset($pairRaw['right']) ? $pairRaw['right'] : array());

        if (!pair_side_is_valid($left) || !pair_side_is_valid($right)) {
            continue;
        }

        $pairId = trim((string) ($pairRaw['id'] ?? ''));
        if ($pairId === '') {
            $pairId = 'pair_' . ($index + 1) . '_' . mt_rand(1000, 9999);
        }

        $pairs[] = array(
            'id' => $pairId,
            'left' => $left,
            'right' => $right,
        );
    }

    return array(
        'title' => $title,
        'pairs' => $pairs,
    );
}

function encode_memory_cards_payload(array $payload): string
{
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        $title = default_memory_cards_title();
    }

    return json_encode(
        array(
            'title' => $title,
            'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? array_values($payload['pairs']) : array(),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function load_memory_cards_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

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
        'title' => default_memory_cards_title(),
        'pairs' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'memory_cards'
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
               AND type = 'memory_cards'
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
               AND type = 'memory_cards'
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

    $payload = normalize_memory_cards_payload($rawData);

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
        'title' => (string) ($payload['title'] ?? default_memory_cards_title()),
        'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? $payload['pairs'] : array(),
    );
}

function save_memory_cards_activity(PDO $pdo, string $unit, string $activityId, string $title, array $pairs): string
{
    $columns = activities_columns($pdo);

    $payload = array(
        'title' => $title,
        'pairs' => $pairs,
    );
    $json = encode_memory_cards_payload($payload);

    $normalizedTitle = trim($title);
    if ($normalizedTitle === '') {
        $normalizedTitle = default_memory_cards_title();
    }

    $targetId = trim($activityId);

    if ($targetId === '') {
        if (in_array('unit_id', $columns, true)) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit_id = :unit
                   AND type = 'memory_cards'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = (string) $stmt->fetchColumn();
        } elseif (in_array('unit', $columns, true)) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit = :unit
                   AND type = 'memory_cards'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = (string) $stmt->fetchColumn();
        }
    }

    if ($targetId !== '') {
        $setParts = array();
        $params = array('id' => $targetId);

        if (in_array('data', $columns, true)) {
            $setParts[] = 'data = :data';
            $params['data'] = $json;
        }

        if (in_array('content_json', $columns, true)) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $json;
        }

        if (in_array('title', $columns, true)) {
            $setParts[] = 'title = :title';
            $params['title'] = $normalizedTitle;
        }

        if (in_array('name', $columns, true)) {
            $setParts[] = 'name = :name';
            $params['name'] = $normalizedTitle;
        }

        if (!empty($setParts)) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET " . implode(', ', $setParts) . "
                 WHERE id = :id
                   AND type = 'memory_cards'"
            );
            $stmt->execute($params);
        }

        return $targetId;
    }

    $newId = function_exists('uuid_create')
        ? uuid_create(UUID_TYPE_RANDOM)
        : uniqid('memory_cards_', true);

    $insertColumns = array('id');
    $insertValues = array(':id');
    $params = array('id' => $newId);

    $hasUnitId = in_array('unit_id', $columns, true);
    $hasUnit = in_array('unit', $columns, true);
    $hasData = in_array('data', $columns, true);
    $hasContentJson = in_array('content_json', $columns, true);
    $hasTitle = in_array('title', $columns, true);
    $hasName = in_array('name', $columns, true);

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
    $insertValues[] = "'memory_cards'";

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
        $params['title'] = $normalizedTitle;
    }

    if ($hasName) {
        $insertColumns[] = 'name';
        $insertValues[] = ':name';
        $params['name'] = $normalizedTitle;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO activities (" . implode(', ', $insertColumns) . ")
         VALUES (" . implode(', ', $insertValues) . ")"
    );
    $stmt->execute($params);

    return $newId;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_memory_cards_activity($pdo, $unit, $activityId);
$pairs = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_memory_cards_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';

    $pairIds = isset($_POST['pair_id']) && is_array($_POST['pair_id']) ? $_POST['pair_id'] : array();

    $leftTypes = isset($_POST['left_type']) && is_array($_POST['left_type']) ? $_POST['left_type'] : array();
    $leftTexts = isset($_POST['left_text']) && is_array($_POST['left_text']) ? $_POST['left_text'] : array();
    $leftImages = isset($_POST['left_image_existing']) && is_array($_POST['left_image_existing']) ? $_POST['left_image_existing'] : array();

    $rightTypes = isset($_POST['right_type']) && is_array($_POST['right_type']) ? $_POST['right_type'] : array();
    $rightTexts = isset($_POST['right_text']) && is_array($_POST['right_text']) ? $_POST['right_text'] : array();
    $rightImages = isset($_POST['right_image_existing']) && is_array($_POST['right_image_existing']) ? $_POST['right_image_existing'] : array();

    $leftImageFiles = isset($_FILES['left_image_file']) ? $_FILES['left_image_file'] : null;
    $rightImageFiles = isset($_FILES['right_image_file']) ? $_FILES['right_image_file'] : null;

    $count = max(
        count($pairIds),
        count($leftTypes),
        count($leftTexts),
        count($leftImages),
        count($rightTypes),
        count($rightTexts),
        count($rightImages)
    );

    $sanitizedPairs = array();

    for ($i = 0; $i < $count; $i++) {
        $pairId = isset($pairIds[$i]) ? trim((string) $pairIds[$i]) : '';
        if ($pairId === '') {
            $pairId = uniqid('pair_');
        }

        $leftType = isset($leftTypes[$i]) ? strtolower(trim((string) $leftTypes[$i])) : 'text';
        $rightType = isset($rightTypes[$i]) ? strtolower(trim((string) $rightTypes[$i])) : 'text';

        if ($leftType !== 'image') {
            $leftType = 'text';
        }
        if ($rightType !== 'image') {
            $rightType = 'text';
        }

        $leftText = isset($leftTexts[$i]) ? trim((string) $leftTexts[$i]) : '';
        $rightText = isset($rightTexts[$i]) ? trim((string) $rightTexts[$i]) : '';
        $leftImage = isset($leftImages[$i]) ? trim((string) $leftImages[$i]) : '';
        $rightImage = isset($rightImages[$i]) ? trim((string) $rightImages[$i]) : '';

        if (
            $leftImageFiles &&
            isset($leftImageFiles['name'][$i]) &&
            $leftImageFiles['name'][$i] !== '' &&
            isset($leftImageFiles['tmp_name'][$i]) &&
            $leftImageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedLeft = upload_to_cloudinary($leftImageFiles['tmp_name'][$i]);
            if ($uploadedLeft) {
                $leftImage = $uploadedLeft;
            }
        }

        if (
            $rightImageFiles &&
            isset($rightImageFiles['name'][$i]) &&
            $rightImageFiles['name'][$i] !== '' &&
            isset($rightImageFiles['tmp_name'][$i]) &&
            $rightImageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedRight = upload_to_cloudinary($rightImageFiles['tmp_name'][$i]);
            if ($uploadedRight) {
                $rightImage = $uploadedRight;
            }
        }

        $leftSide = normalize_memory_side(array(
            'type' => $leftType,
            'text' => $leftText,
            'image' => $leftImage,
        ));

        $rightSide = normalize_memory_side(array(
            'type' => $rightType,
            'text' => $rightText,
            'image' => $rightImage,
        ));

        if (!pair_side_is_valid($leftSide) || !pair_side_is_valid($rightSide)) {
            continue;
        }

        $sanitizedPairs[] = array(
            'id' => $pairId,
            'left' => $leftSide,
            'right' => $rightSide,
        );
    }

    $savedActivityId = save_memory_cards_activity($pdo, $unit, $activityId, $postedTitle, $sanitizedPairs);

    $params = array(
        'unit=' . urlencode($unit),
        'saved=1',
    );

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

if (empty($pairs)) {
    $pairs = array(
        array(
            'id' => uniqid('pair_'),
            'left' => array('type' => 'text', 'text' => '', 'image' => ''),
            'right' => array('type' => 'text', 'text' => '', 'image' => ''),
        ),
        array(
            'id' => uniqid('pair_'),
            'left' => array('type' => 'text', 'text' => '', 'image' => ''),
            'right' => array('type' => 'text', 'text' => '', 'image' => ''),
        ),
    );
}

ob_start();
?>
<style>
.memory-form{max-width:1080px;margin:0 auto;text-align:left}
.memory-title-box{background:#f8fafc;padding:16px;border:1px solid #dbeafe;border-radius:14px;margin-bottom:14px}
.memory-title-box label{display:block;font-weight:800;color:#1e3a8a;margin-bottom:8px}
.memory-title-box input{width:100%;padding:12px;border:1px solid #bfdbfe;border-radius:10px;font-size:15px;font-weight:700}
.memory-pairs{display:grid;gap:14px}
.memory-pair{border:1px solid #dbeafe;border-radius:18px;background:#ffffff;box-shadow:0 8px 20px rgba(15,23,42,.06);padding:14px}
.memory-pair-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.memory-pair-title{margin:0;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:20px;color:#1d4ed8}
.memory-remove{border:none;background:linear-gradient(180deg,#fb7185,#e11d48);color:#fff;border-radius:999px;padding:8px 12px;font-weight:800;cursor:pointer}
.memory-pair-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.memory-side{border:1px solid #e2e8f0;border-radius:14px;padding:12px;background:#f8fbff}
.memory-side h4{margin:0 0 10px;font-size:15px;color:#1e293b}
.memory-label{display:block;font-size:12px;font-weight:800;color:#475569;margin:8px 0 6px;text-transform:uppercase;letter-spacing:.04em}
.memory-input,.memory-select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#fff}
.memory-file{width:100%}
.memory-preview{margin-top:8px;padding:1mm;border:1px dashed #cbd5e1;background:#fff;border-radius:10px;min-height:44px;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:12px;overflow:hidden}
.memory-preview img{max-width:100%;max-height:120px;border-radius:8px;object-fit:contain}
.memory-tools{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.memory-btn{border:none;border-radius:999px;padding:10px 16px;font-weight:800;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,.1)}
.memory-btn-add{background:linear-gradient(180deg,#34d399,#059669);color:#ecfdf5}
.memory-btn-save{background:linear-gradient(180deg,#3b82f6,#1d4ed8);color:#fff}
.memory-hint{margin:0 0 10px;color:#64748b;font-size:13px;font-weight:700}
.memory-counter{font-size:13px;font-weight:800;color:#64748b;padding:6px 0;display:inline-flex;align-items:center;gap:6px}
.memory-counter.at-max{color:#dc2626}
.memory-btn-add:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
@media (max-width:960px){.memory-pair-grid{grid-template-columns:1fr}}
</style>

<?php if (isset($_GET['saved'])): ?>
<p style="color:#15803d;font-weight:800;margin-bottom:12px;">Saved successfully.</p>
<?php endif; ?>

<form class="memory-form" id="memoryForm" method="post" enctype="multipart/form-data">
    <div class="memory-title-box">
        <label for="activity_title">Activity title</label>
        <input id="activity_title" type="text" name="activity_title" required value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Memory Cards - Unit 1">
    </div>

    <p class="memory-hint">Create pairs with text/text, image/image, or mixed image/text. Cards are shuffled automatically in the viewer. Maximum 6 pairs (12 cards) so all cards are always visible in full screen.</p>

    <div id="pairsContainer" class="memory-pairs">
        <?php foreach ($pairs as $idx => $pair): ?>
        <?php
            $left = normalize_memory_side(isset($pair['left']) ? $pair['left'] : array());
            $right = normalize_memory_side(isset($pair['right']) ? $pair['right'] : array());
        ?>
        <div class="memory-pair" data-index="<?= (int) $idx ?>">
            <div class="memory-pair-head">
                <h3 class="memory-pair-title">Pair <span class="pair-number"><?= (int) $idx + 1 ?></span></h3>
                <button type="button" class="memory-remove" onclick="removePair(this)">Remove</button>
            </div>

            <input type="hidden" name="pair_id[]" value="<?= htmlspecialchars((string) ($pair['id'] ?? uniqid('pair_')), ENT_QUOTES, 'UTF-8') ?>">

            <div class="memory-pair-grid">
                <div class="memory-side">
                    <h4>Card A</h4>
                    <label class="memory-label">Content type</label>
                    <select class="memory-select side-type" name="left_type[]" onchange="toggleSideInputs(this)">
                        <option value="text" <?= $left['type'] === 'text' ? 'selected' : '' ?>>Text</option>
                        <option value="image" <?= $left['type'] === 'image' ? 'selected' : '' ?>>Image</option>
                    </select>

                    <div class="side-text-wrap">
                        <label class="memory-label">Text</label>
                        <input class="memory-input" type="text" name="left_text[]" value="<?= htmlspecialchars((string) $left['text'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Apple">
                    </div>

                    <div class="side-image-wrap">
                        <label class="memory-label">Image URL</label>
                        <input class="memory-input image-url" type="text" name="left_image_existing[]" value="<?= htmlspecialchars((string) $left['image'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://...">
                        <label class="memory-label">Upload image (optional)</label>
                        <input class="memory-file" type="file" name="left_image_file[]" accept="image/*">
                    </div>

                    <div class="memory-preview side-preview"></div>
                </div>

                <div class="memory-side">
                    <h4>Card B</h4>
                    <label class="memory-label">Content type</label>
                    <select class="memory-select side-type" name="right_type[]" onchange="toggleSideInputs(this)">
                        <option value="text" <?= $right['type'] === 'text' ? 'selected' : '' ?>>Text</option>
                        <option value="image" <?= $right['type'] === 'image' ? 'selected' : '' ?>>Image</option>
                    </select>

                    <div class="side-text-wrap">
                        <label class="memory-label">Text</label>
                        <input class="memory-input" type="text" name="right_text[]" value="<?= htmlspecialchars((string) $right['text'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. A red fruit">
                    </div>

                    <div class="side-image-wrap">
                        <label class="memory-label">Image URL</label>
                        <input class="memory-input image-url" type="text" name="right_image_existing[]" value="<?= htmlspecialchars((string) $right['image'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://...">
                        <label class="memory-label">Upload image (optional)</label>
                        <input class="memory-file" type="file" name="right_image_file[]" accept="image/*">
                    </div>

                    <div class="memory-preview side-preview"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="memory-tools">
        <button type="button" class="memory-btn memory-btn-add" id="addPairBtn" onclick="addPair()">Add Pair</button>
        <button type="submit" class="memory-btn memory-btn-save">Save Memory Cards</button>
        <span class="memory-counter" id="pairCounter"></span>
    </div>
</form>

<script>
const MAX_PAIRS = 6;
let formChanged = false;
let formSubmitted = false;

function updatePairCounter() {
    const count = document.querySelectorAll('.memory-pair').length;
    const counter = document.getElementById('pairCounter');
    const btn = document.getElementById('addPairBtn');
    if (counter) {
        counter.textContent = count + ' / ' + MAX_PAIRS + ' pairs';
        counter.classList.toggle('at-max', count >= MAX_PAIRS);
    }
    if (btn) btn.disabled = count >= MAX_PAIRS;
}

function renumberPairs() {
    document.querySelectorAll('.memory-pair').forEach(function (pair, index) {
        pair.dataset.index = String(index);
        const number = pair.querySelector('.pair-number');
        if (number) number.textContent = String(index + 1);
    });
    updatePairCounter();
}

function toggleSideInputs(selectEl) {
    const side = selectEl.closest('.memory-side');
    if (!side) return;

    const type = (selectEl.value || 'text').toLowerCase();
    const textWrap = side.querySelector('.side-text-wrap');
    const imageWrap = side.querySelector('.side-image-wrap');

    if (textWrap) textWrap.style.display = type === 'text' ? '' : 'none';
    if (imageWrap) imageWrap.style.display = type === 'image' ? '' : 'none';

    updateSidePreview(side);
}

function updateSidePreview(side) {
    const typeSelect = side.querySelector('.side-type');
    const preview = side.querySelector('.side-preview');
    if (!typeSelect || !preview) return;

    const type = (typeSelect.value || 'text').toLowerCase();
    if (type === 'image') {
        const urlInput = side.querySelector('.image-url');
        const url = urlInput ? (urlInput.value || '').trim() : '';
        if (url !== '') {
            preview.innerHTML = '<img src="' + url.replace(/"/g, '&quot;') + '" alt="Preview">';
        } else {
            preview.textContent = 'Image preview appears here';
        }
        return;
    }

    const textInput = side.querySelector('input[name="left_text[]"], input[name="right_text[]"]');
    const text = textInput ? (textInput.value || '').trim() : '';
    preview.textContent = text !== '' ? text : 'Text preview appears here';
}

function bindPairEvents(pair) {
    pair.querySelectorAll('.side-type').forEach(function (selectEl) {
        selectEl.addEventListener('change', function () {
            toggleSideInputs(selectEl);
            formChanged = true;
        });
    });

    pair.querySelectorAll('input').forEach(function (input) {
        input.addEventListener('input', function () {
            const side = input.closest('.memory-side');
            if (side) updateSidePreview(side);
            formChanged = true;
        });
        input.addEventListener('change', function () {
            const side = input.closest('.memory-side');
            if (side) updateSidePreview(side);
            formChanged = true;
        });
    });

    pair.querySelectorAll('.memory-side').forEach(function (side) {
        const selectEl = side.querySelector('.side-type');
        if (selectEl) toggleSideInputs(selectEl);
    });
}

function removePair(btn) {
    const pair = btn.closest('.memory-pair');
    if (!pair) return;

    pair.remove();
    renumberPairs();
    formChanged = true;
}

function addPair() {
    const container = document.getElementById('pairsContainer');
    const index = container.querySelectorAll('.memory-pair').length;
    if (index >= MAX_PAIRS) {
        alert('Maximum of ' + MAX_PAIRS + ' pairs (12 cards) reached. All cards are always visible in full screen with this limit.');
        return;
    }
    const pairId = 'pair_' + Date.now() + '_' + Math.floor(Math.random() * 1000);

    const wrapper = document.createElement('div');
    wrapper.className = 'memory-pair';
    wrapper.dataset.index = String(index);
    wrapper.innerHTML = `
        <div class="memory-pair-head">
            <h3 class="memory-pair-title">Pair <span class="pair-number">${index + 1}</span></h3>
            <button type="button" class="memory-remove" onclick="removePair(this)">Remove</button>
        </div>

        <input type="hidden" name="pair_id[]" value="${pairId}">

        <div class="memory-pair-grid">
            <div class="memory-side">
                <h4>Card A</h4>
                <label class="memory-label">Content type</label>
                <select class="memory-select side-type" name="left_type[]" onchange="toggleSideInputs(this)">
                    <option value="text" selected>Text</option>
                    <option value="image">Image</option>
                </select>

                <div class="side-text-wrap">
                    <label class="memory-label">Text</label>
                    <input class="memory-input" type="text" name="left_text[]" placeholder="e.g. Ocean">
                </div>

                <div class="side-image-wrap">
                    <label class="memory-label">Image URL</label>
                    <input class="memory-input image-url" type="text" name="left_image_existing[]" placeholder="https://...">
                    <label class="memory-label">Upload image (optional)</label>
                    <input class="memory-file" type="file" name="left_image_file[]" accept="image/*">
                </div>

                <div class="memory-preview side-preview"></div>
            </div>

            <div class="memory-side">
                <h4>Card B</h4>
                <label class="memory-label">Content type</label>
                <select class="memory-select side-type" name="right_type[]" onchange="toggleSideInputs(this)">
                    <option value="text" selected>Text</option>
                    <option value="image">Image</option>
                </select>

                <div class="side-text-wrap">
                    <label class="memory-label">Text</label>
                    <input class="memory-input" type="text" name="right_text[]" placeholder="e.g. A large body of water">
                </div>

                <div class="side-image-wrap">
                    <label class="memory-label">Image URL</label>
                    <input class="memory-input image-url" type="text" name="right_image_existing[]" placeholder="https://...">
                    <label class="memory-label">Upload image (optional)</label>
                    <input class="memory-file" type="file" name="right_image_file[]" accept="image/*">
                </div>

                <div class="memory-preview side-preview"></div>
            </div>
        </div>
    `;

    container.appendChild(wrapper);
    bindPairEvents(wrapper);
    renumberPairs();
    updatePairCounter();
    formChanged = true;
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.memory-pair').forEach(bindPairEvents);
    updatePairCounter();

    const form = document.getElementById('memoryForm');
    if (form) {
        form.addEventListener('submit', function () {
            formSubmitted = true;
            formChanged = false;
        });
    }
});

window.addEventListener('beforeunload', function (event) {
    if (formChanged && !formSubmitted) {
        event.preventDefault();
        event.returnValue = '';
    }
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Memory Cards Editor', 'fas fa-clone', $content);

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
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

function default_match_title(): string
{
    return 'Match';
}

function normalize_match_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_match_title();
}

function normalize_match_payload($rawData): array
{
    $default = array(
        'title' => default_match_title(),
        'pairs' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $pairsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['pairs']) && is_array($decoded['pairs'])) {
        $pairsSource = $decoded['pairs'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $pairsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $pairsSource = $decoded['data'];
    }

    $pairs = array();

    if (is_array($pairsSource)) {
        foreach ($pairsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $legacyText = isset($item['text']) ? trim((string) $item['text']) : (isset($item['word']) ? trim((string) $item['word']) : '');
            $legacyImage = isset($item['image']) ? trim((string) $item['image']) : (isset($item['img']) ? trim((string) $item['img']) : '');

            $pairs[] = array(
                'id' => isset($item['id']) && trim((string) $item['id']) !== '' ? trim((string) $item['id']) : uniqid('match_'),
                'left_text' => isset($item['left_text']) ? trim((string) $item['left_text']) : '',
                'left_image' => isset($item['left_image']) ? trim((string) $item['left_image']) : $legacyImage,
                'right_text' => isset($item['right_text']) ? trim((string) $item['right_text']) : $legacyText,
                'right_image' => isset($item['right_image']) ? trim((string) $item['right_image']) : '',
            );
        }
    }

    return array(
        'title' => normalize_match_title($title),
        'pairs' => $pairs,
    );
}

function encode_match_payload(array $payload): string
{
    return json_encode(
        array(
            'title' => normalize_match_title(isset($payload['title']) ? (string) $payload['title'] : ''),
            'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? array_values($payload['pairs']) : array(),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function load_match_activity(PDO $pdo, string $unit, string $activityId): array
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
        'title' => default_match_title(),
        'pairs' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'match'
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
               AND type = 'match'
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
               AND type = 'match'
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

    $payload = normalize_match_payload($rawData);

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
        'title' => normalize_match_title((string) $payload['title']),
        'pairs' => isset($payload['pairs']) && is_array($payload['pairs']) ? $payload['pairs'] : array(),
    );
}

function save_match_activity(PDO $pdo, string $unit, string $activityId, string $title, array $pairs): string
{
    $columns = activities_columns($pdo);
    $title = normalize_match_title($title);
    $json = encode_match_payload(array(
        'title' => $title,
        'pairs' => $pairs,
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
                   AND type = 'match'
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
                   AND type = 'match'
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
                   AND type = 'match'"
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
    $insertValues[] = "'match'";

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
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_match_activity($pdo, $unit, $activityId);
$pairs = isset($activity['pairs']) && is_array($activity['pairs']) ? $activity['pairs'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_match_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $leftTexts = isset($_POST['left_text']) && is_array($_POST['left_text']) ? $_POST['left_text'] : array();
    $rightTexts = isset($_POST['right_text']) && is_array($_POST['right_text']) ? $_POST['right_text'] : array();
    $leftImages = isset($_POST['left_image_existing']) && is_array($_POST['left_image_existing']) ? $_POST['left_image_existing'] : array();
    $rightImages = isset($_POST['right_image_existing']) && is_array($_POST['right_image_existing']) ? $_POST['right_image_existing'] : array();
    $ids = isset($_POST['pair_id']) && is_array($_POST['pair_id']) ? $_POST['pair_id'] : array();
    $leftImageFiles = isset($_FILES['left_image_file']) ? $_FILES['left_image_file'] : null;
    $rightImageFiles = isset($_FILES['right_image_file']) ? $_FILES['right_image_file'] : null;

    $sanitized = array();

    $totalPairs = max(count($leftTexts), count($rightTexts), count($leftImages), count($rightImages), count($ids));

    for ($i = 0; $i < $totalPairs; $i++) {
        $leftText = isset($leftTexts[$i]) ? trim((string) $leftTexts[$i]) : '';
        $rightText = isset($rightTexts[$i]) ? trim((string) $rightTexts[$i]) : '';
        $leftImage = isset($leftImages[$i]) ? trim((string) $leftImages[$i]) : '';
        $rightImage = isset($rightImages[$i]) ? trim((string) $rightImages[$i]) : '';
        $pairId = isset($ids[$i]) && trim((string) $ids[$i]) !== '' ? trim((string) $ids[$i]) : uniqid('match_');

        if (
            $leftImageFiles &&
            isset($leftImageFiles['name'][$i]) &&
            $leftImageFiles['name'][$i] !== '' &&
            isset($leftImageFiles['tmp_name'][$i]) &&
            $leftImageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedImage = upload_to_cloudinary($leftImageFiles['tmp_name'][$i]);
            if ($uploadedImage) {
                $leftImage = $uploadedImage;
            }
        }

        if (
            $rightImageFiles &&
            isset($rightImageFiles['name'][$i]) &&
            $rightImageFiles['name'][$i] !== '' &&
            isset($rightImageFiles['tmp_name'][$i]) &&
            $rightImageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedImage = upload_to_cloudinary($rightImageFiles['tmp_name'][$i]);
            if ($uploadedImage) {
                $rightImage = $uploadedImage;
            }
        }

        $leftHasContent = ($leftText !== '' || $leftImage !== '');
        $rightHasContent = ($rightText !== '' || $rightImage !== '');

        if (!$leftHasContent && !$rightHasContent) {
            continue;
        }

        if (!$leftHasContent || !$rightHasContent) {
            continue;
        }

        $sanitized[] = array(
            'id' => $pairId,
            'left_text' => $leftText,
            'left_image' => $leftImage,
            'right_text' => $rightText,
            'right_image' => $rightImage,
        );
    }

    $savedActivityId = save_match_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    $params = array(
        'unit=' . urlencode($unit),
        'saved=1'
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

ob_start();
?>

<style>
.match-form{
    max-width:900px;
    margin:0 auto;
    text-align:left;
}

.title-box{
    background:#f9fafb;
    padding:14px;
    margin-bottom:14px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}

.title-box label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}

.title-box input{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
    box-sizing:border-box;
}

.match-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:12px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}

.match-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
}

.match-side{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:12px;
}

.match-side h4{
    margin:0 0 10px 0;
    font-size:15px;
    color:#111827;
}

.match-side small{
    display:block;
    margin-top:-4px;
    margin-bottom:10px;
    color:#64748b;
    font-size:12px;
}

.match-item label{
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.match-item input[type="text"],
.match-item input[type="file"]{
    width:100%;
    padding:10px;
    border:1px solid #d1d5db;
    border-radius:8px;
    margin:0 0 12px 0;
    box-sizing:border-box;
}

.image-preview{
    display:block;
    max-width:120px;
    max-height:120px;
    object-fit:contain;
    border-radius:10px;
    border:1px solid #d1d5db;
    background:#fff;
    margin-bottom:10px;
}

.toolbar-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:center;
    margin-top:8px;
}

.quick-types{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin:0 0 18px;
}

.btn-add{
    background:linear-gradient(180deg,#14b8a6,#0d9488);
    color:#fff;
    padding:10px 14px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:800;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:14px;
    transition:transform .15s ease, filter .15s ease;
    box-shadow:0 2px 8px rgba(13,148,136,.2);
}
.btn-add:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}
.save-btn{
    background:linear-gradient(180deg,#0d9488,#0f766e);
    color:#fff;
    padding:10px 20px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:800;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:15px;
    transition:transform .15s ease, filter .15s ease;
    box-shadow:0 2px 8px rgba(13,148,136,.22);
}
.save-btn:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}

.btn-type{
    background:#ffffff;
    color:#0f172a;
    padding:10px 14px;
    border:1px solid #cbd5e1;
    border-radius:10px;
    cursor:pointer;
    font-weight:700;
}

.btn-type:hover{
    background:#f8fafc;
    border-color:#94a3b8;
}

.btn-remove{
    background:linear-gradient(180deg,#ef4444,#b91c1c);
    color:#fff;
    border:none;
    padding:8px 12px;
    border-radius:10px;
    cursor:pointer;
    font-weight:800;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:13px;
    transition:transform .15s ease, filter .15s ease;
}
.btn-remove:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}

.match-help{
    margin:0 0 14px;
    color:#4b5563;
    font-size:14px;
}

.match-examples{
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:12px;
    margin:0 0 18px;
}

.match-example-card{
    background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border:1px solid #dbe4ee;
    border-radius:14px;
    padding:12px;
}

.match-example-card strong{
    display:block;
    margin-bottom:6px;
    color:#0f172a;
    font-size:14px;
}

.match-example-card span{
    display:block;
    color:#475569;
    font-size:13px;
    line-height:1.45;
}

@media (max-width: 760px){
    .match-examples{
        grid-template-columns:repeat(2, minmax(0, 1fr));
    }

    .match-grid{
        grid-template-columns:1fr;
    }
}

@media (max-width: 520px){
    .match-examples{
        grid-template-columns:1fr;
    }
}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>
<?php } ?>

<form class="match-form" id="matchForm" method="post" enctype="multipart/form-data">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Match the animals"
            required
        >
    </div>

    <p class="match-help">Each pair can use text and image on both sides. You can build text-to-text, image-to-image, text-to-image, or image-to-text activities.</p>

    <div class="match-examples">
        <div class="match-example-card">
            <strong>Text + text</strong>
            <span>Left: dog</span>
            <span>Right: perro</span>
        </div>
        <div class="match-example-card">
            <strong>Image + text</strong>
            <span>Left: picture of an apple</span>
            <span>Right: apple</span>
        </div>
        <div class="match-example-card">
            <strong>Image + image</strong>
            <span>Left: flag of Colombia</span>
            <span>Right: map of the country</span>
        </div>
        <div class="match-example-card">
            <strong>Text + image</strong>
            <span>Left: teacher</span>
            <span>Right: teacher photo</span>
        </div>
    </div>

    <div class="quick-types">
        <button type="button" class="btn-type" onclick="addPair('text-text')">+ Text + Text</button>
        <button type="button" class="btn-type" onclick="addPair('image-text')">+ Image + Text</button>
        <button type="button" class="btn-type" onclick="addPair('image-image')">+ Image + Image</button>
        <button type="button" class="btn-type" onclick="addPair('text-image')">+ Text + Image</button>
    </div>

    <div id="pairsContainer">
        <?php foreach ($pairs as $pair) { ?>
            <div class="match-item">
                <input type="hidden" name="pair_id[]" value="<?= htmlspecialchars(isset($pair['id']) ? $pair['id'] : uniqid('match_'), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="left_image_existing[]" value="<?= htmlspecialchars(isset($pair['left_image']) ? $pair['left_image'] : '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="right_image_existing[]" value="<?= htmlspecialchars(isset($pair['right_image']) ? $pair['right_image'] : '', ENT_QUOTES, 'UTF-8') ?>">

                <div class="match-grid">
                    <div class="match-side">
                        <h4>Left item</h4>
                        <label>Text</label>
                        <input type="text" name="left_text[]" value="<?= htmlspecialchars(isset($pair['left_text']) ? $pair['left_text'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Dog">

                        <label>Image</label>
                        <?php if (!empty($pair['left_image'])) { ?>
                            <img src="<?= htmlspecialchars($pair['left_image'], ENT_QUOTES, 'UTF-8') ?>" alt="left-match-image" class="image-preview">
                        <?php } ?>
                        <input type="file" name="left_image_file[]" accept="image/*">
                    </div>

                    <div class="match-side">
                        <h4>Right item</h4>
                        <label>Text</label>
                        <input type="text" name="right_text[]" value="<?= htmlspecialchars(isset($pair['right_text']) ? $pair['right_text'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Perro">

                        <label>Image</label>
                        <?php if (!empty($pair['right_image'])) { ?>
                            <img src="<?= htmlspecialchars($pair['right_image'], ENT_QUOTES, 'UTF-8') ?>" alt="right-match-image" class="image-preview">
                        <?php } ?>
                        <input type="file" name="right_image_file[]" accept="image/*">
                    </div>
                </div>

                <button type="button" class="btn-remove" onclick="removePair(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addPair()">+ Add Pair</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function removePair(button) {
    const item = button.closest('.match-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function buildPairTemplate(pairType) {
    let leftPlaceholder = 'Example: Sun';
    let rightPlaceholder = 'Example: Sol';
    let leftHint = 'Add text or upload an image';
    let rightHint = 'Add text or upload an image';

    if (pairType === 'text-text') {
        leftPlaceholder = 'Example: dog';
        rightPlaceholder = 'Example: perro';
        leftHint = 'Use text on this side';
        rightHint = 'Use text on this side';
    } else if (pairType === 'image-text') {
        leftPlaceholder = '';
        rightPlaceholder = 'Example: apple';
        leftHint = 'Upload an image on this side';
        rightHint = 'Use text on this side';
    } else if (pairType === 'image-image') {
        leftPlaceholder = '';
        rightPlaceholder = '';
        leftHint = 'Upload an image on this side';
        rightHint = 'Upload an image on this side';
    } else if (pairType === 'text-image') {
        leftPlaceholder = 'Example: teacher';
        rightPlaceholder = '';
        leftHint = 'Use text on this side';
        rightHint = 'Upload an image on this side';
    }

    return `
        <input type="hidden" name="pair_id[]" value="match_${Date.now()}_${Math.floor(Math.random() * 1000)}">
        <input type="hidden" name="left_image_existing[]" value="">
        <input type="hidden" name="right_image_existing[]" value="">

        <div class="match-grid">
            <div class="match-side">
                <h4>Left item</h4>
                <label>Text</label>
                <input type="text" name="left_text[]" placeholder="${leftPlaceholder}">

                <label>Image</label>
                <input type="file" name="left_image_file[]" accept="image/*">
                <small>${leftHint}</small>
            </div>

            <div class="match-side">
                <h4>Right item</h4>
                <label>Text</label>
                <input type="text" name="right_text[]" placeholder="${rightPlaceholder}">

                <label>Image</label>
                <input type="file" name="right_image_file[]" accept="image/*">
                <small>${rightHint}</small>
            </div>
        </div>

        <button type="button" class="btn-remove" onclick="removePair(this)">✖ Remove</button>
    `;
}

function addPair(pairType = 'text-text') {
    const container = document.getElementById('pairsContainer');
    const div = document.createElement('div');
    div.className = 'match-item';
    div.innerHTML = buildPairTemplate(pairType);
    container.appendChild(div);
    bindChangeTracking(div);
    markChanged();
}

function bindChangeTracking(scope) {
    const elements = scope.querySelectorAll('input, textarea, select');
    elements.forEach(function(el) {
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindChangeTracking(document);

    const form = document.getElementById('matchForm');
    if (form) {
        form.addEventListener('submit', function () {
            formSubmitted = true;
            formChanged = false;
        });
    }
});

window.addEventListener('beforeunload', function (e) {
    if (formChanged && !formSubmitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('🧩 Match Editor', '🧩', $content);

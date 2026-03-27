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

function default_dictation_title(): string
{
    return 'Dictation';
}

function normalize_activity_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_dictation_title();
}

function normalize_dictation_payload($rawData): array
{
    $default = array(
        'title' => default_dictation_title(),
        'items' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $itemsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $itemsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $itemsSource = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
        $itemsSource = $decoded['words'];
    }

    $normalizedItems = array();

    if (is_array($itemsSource)) {
        foreach ($itemsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $en = isset($item['en']) ? trim((string) $item['en']) : '';
            if ($en === '' && isset($item['word'])) {
                $en = trim((string) $item['word']);
            }
            if ($en === '' && isset($item['sentence'])) {
                $en = trim((string) $item['sentence']);
            }

            $normalizedItems[] = array(
                'img' => isset($item['img']) ? trim((string) $item['img']) : (isset($item['image']) ? trim((string) $item['image']) : ''),
                'en' => $en,
                'ph' => isset($item['ph']) ? trim((string) $item['ph']) : '',
                'es' => isset($item['es']) ? trim((string) $item['es']) : '',
                'audio' => isset($item['audio']) ? trim((string) $item['audio']) : '',
            );
        }
    }

    return array(
        'title' => normalize_activity_title($title),
        'items' => $normalizedItems,
    );
}

function encode_dictation_payload(string $title, array $items): string
{
    return json_encode(
        array(
            'title' => normalize_activity_title($title),
            'items' => array_values($items),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function load_dictation_activity(PDO $pdo, string $unit, string $activityId): array
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
        'title' => default_dictation_title(),
        'items' => array(),
    );

    $findById = function (string $id) use ($pdo, $selectFields): ?array {
        if ($id === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'dictation'
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
               AND type = 'dictation'
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
               AND type = 'dictation'
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
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_dictation_payload($rawData);

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
        'title' => normalize_activity_title((string) ($payload['title'] ?? '')),
        'items' => isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array(),
    );
}

function save_dictation_activity(PDO $pdo, string $unit, string $activityId, string $title, array $items): string
{
    $columns = activities_columns($pdo);
    $title = normalize_activity_title($title);
    $json = encode_dictation_payload($title, $items);

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
                   AND type = 'dictation'
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
                   AND type = 'dictation'
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
                   AND type = 'dictation'"
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
    $insertValues[] = "'dictation'";

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

$activity = load_dictation_activity($pdo, $unit, $activityId);
$items = isset($activity['items']) && is_array($activity['items']) ? $activity['items'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_dictation_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $ens = isset($_POST['en']) && is_array($_POST['en']) ? $_POST['en'] : array();
    $phs = isset($_POST['ph']) && is_array($_POST['ph']) ? $_POST['ph'] : array();
    $ess = isset($_POST['es']) && is_array($_POST['es']) ? $_POST['es'] : array();
    $imgs = isset($_POST['img']) && is_array($_POST['img']) ? $_POST['img'] : array();
    $audios = isset($_POST['audio']) && is_array($_POST['audio']) ? $_POST['audio'] : array();

    $imageFiles = isset($_FILES['img_file']) ? $_FILES['img_file'] : null;

    $sanitized = array();

    foreach ($ens as $i => $enRaw) {
        $en = trim((string) $enRaw);
        $ph = isset($phs[$i]) ? trim((string) $phs[$i]) : '';
        $es = isset($ess[$i]) ? trim((string) $ess[$i]) : '';

        $img = isset($imgs[$i]) ? trim((string) $imgs[$i]) : '';
        $audio = isset($audios[$i]) ? trim((string) $audios[$i]) : '';

        if (
            $imageFiles &&
            isset($imageFiles['name'][$i]) &&
            $imageFiles['name'][$i] !== '' &&
            isset($imageFiles['tmp_name'][$i]) &&
            $imageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedImage = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($uploadedImage) {
                $img = $uploadedImage;
            }
        }

        if ($en === '' && $img === '' && $ph === '' && $es === '') {
            continue;
        }

        $sanitized[] = array(
            'img' => $img,
            'en' => $en,
            'ph' => $ph,
            'es' => $es,
            'audio' => $audio,
        );
    }

    $savedActivityId = save_dictation_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    $redirectParams = array(
        'unit=' . urlencode($unit),
        'saved=1'
    );

    if ($savedActivityId !== '') {
        $redirectParams[] = 'id=' . urlencode($savedActivityId);
    } elseif ($activityId !== '') {
        $redirectParams[] = 'id=' . urlencode($activityId);
    }

    if ($assignment !== '') {
        $redirectParams[] = 'assignment=' . urlencode($assignment);
    }

    if ($source !== '') {
        $redirectParams[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $redirectParams));
    exit;
}

ob_start();
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

.dict-form{
    max-width:900px;
    margin:0 auto;
    text-align:left;
    font-family:'Nunito', 'Segoe UI', sans-serif;
}

.dict-intro{
    background:linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 52%, #f0fdf4 100%);
    border:1px solid #ccfbf1;
    border-radius:20px;
    padding:18px 20px;
    margin:0 0 14px;
    box-shadow:0 12px 26px rgba(15, 23, 42, .08);
}

.dict-intro h3{
    margin:0 0 6px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:24px;
    font-weight:700;
    color:#0f172a;
}

.dict-intro p{
    margin:0;
    color:#475569;
    font-size:14px;
    line-height:1.5;
}

.title-box{
    background:#ffffff;
    padding:14px;
    margin-bottom:14px;
    border-radius:14px;
    border:1px solid #e2e8f0;
    box-shadow:0 8px 18px rgba(15, 23, 42, .04);
}

.title-box label{
    display:block;
    font-weight:800;
    margin-bottom:8px;
    color:#1e293b;
}

.title-box input{
    width:100%;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #cbd5e1;
    font-size:15px;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    box-sizing:border-box;
}

.dict-block{
    position:relative;
    overflow:hidden;
    background:linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%);
    padding:14px;
    margin-bottom:12px;
    border-radius:16px;
    border:1px solid #ccfbf1;
    box-shadow:0 10px 22px rgba(15, 23, 42, .06);
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px 10px;
}

.dict-block::before{
    content:'';
    position:absolute;
    top:0;
    left:0;
    right:0;
    height:7px;
    background:linear-gradient(90deg, #14b8a6 0%, #0d9488 100%);
}

.dict-block label{
    font-weight:800;
    grid-column:span 2;
    color:#0f172a;
    margin-top:2px;
}

.dict-block input{
    padding:9px 11px;
    border-radius:10px;
    border:1px solid #cbd5e1;
    font-size:14px;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    grid-column:span 2;
}

.image-preview-wrap{
    grid-column:span 2;
    margin-top:4px;
}

.preview-label{
    margin:0 0 6px 0;
    font-size:13px;
    font-weight:700;
    color:#374151;
}

.image-preview{
    max-width:140px;
    max-height:140px;
    border-radius:12px;
    border:1px solid #d1d5db;
    background:#fff;
    display:block;
}

.actions-row{
    display:flex;
    gap:10px;
    justify-content:center;
    margin-top:10px;
    flex-wrap:wrap;
}

.btn-add,
.btn-save,
.btn-remove{
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:800;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    transition:transform .15s ease, filter .15s ease;
}

.btn-add:hover,
.btn-save:hover,
.btn-remove:hover{
    filter:brightness(1.06);
    transform:translateY(-1px);
}

.btn-add{
    background:#14b8a6;
    color:#fff;
    padding:10px 14px;
}

.btn-save{
    background:#0d9488;
    color:#fff;
    padding:10px 14px;
}

.btn-remove{
    background:#ef4444;
    color:#fff;
    padding:8px 12px;
    justify-self:end;
    grid-column:span 2;
}

.saved-notice{
    max-width:900px;
    margin:0 auto 14px;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #86efac;
    background:#f0fdf4;
    color:#166534;
    font-weight:800;
    font-family:'Nunito', 'Segoe UI', sans-serif;
}

@media (max-width:680px){
    .dict-block{
        display:flex;
        flex-direction:column;
    }

    .dict-intro h3{
        font-size:22px;
    }
}
</style>

<?php if (isset($_GET['saved'])) { ?>
<p class="saved-notice">✔ Saved successfully</p>
<?php } ?>

<form class="dict-form" id="dictationForm" method="post" enctype="multipart/form-data">
    <section class="dict-intro">
        <h3>Dictation Editor</h3>
        <p>Create sentences for dictation practice. Students will listen and write what they hear. Include the sentence, phonetic help, Spanish translation, and optional image for context.</p>
    </section>

    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?php echo htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8'); ?>"
            placeholder="Example: Basic Sentences"
            required
        >
    </div>

    <div id="items">
        <?php foreach ($items as $item) { ?>
            <div class="dict-block">
                <label>Sentence (English)</label>
                <input type="text" name="en[]" value="<?php echo htmlspecialchars(isset($item['en']) ? $item['en'] : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="My name is John" required>

                <label>Phonetic</label>
                <input type="text" name="ph[]" value="<?php echo htmlspecialchars(isset($item['ph']) ? $item['ph'] : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="mai néim iz john">

                <label>Spanish</label>
                <input type="text" name="es[]" value="<?php echo htmlspecialchars(isset($item['es']) ? $item['es'] : '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Mi nombre es John">

                <label>Image (optional)</label>
                <input type="file" name="img_file[]" accept="image/*">
                <input type="hidden" name="img[]" value="<?php echo htmlspecialchars(isset($item['img']) ? $item['img'] : '', ENT_QUOTES, 'UTF-8'); ?>">

                <?php if (!empty($item['img'])) { ?>
                    <div class="image-preview-wrap">
                        <p class="preview-label">Current image:</p>
                        <img src="<?php echo htmlspecialchars($item['img'], ENT_QUOTES, 'UTF-8'); ?>" alt="Saved image" class="image-preview">
                    </div>
                <?php } ?>

                <input type="hidden" name="audio[]" value="<?php echo htmlspecialchars(isset($item['audio']) ? $item['audio'] : '', ENT_QUOTES, 'UTF-8'); ?>">

                <button type="button" onclick="removeItem(this)" class="btn-remove">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="actions-row">
        <button type="button" onclick="addItem()" class="btn-add">+ Add Card</button>
        <button type="submit" class="btn-save">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markAsChanged() {
    formChanged = true;
}

function addItem() {
    var container = document.getElementById('items');
    var div = document.createElement('div');
    div.className = 'dict-block';
    div.innerHTML = '' +
      '<label>Sentence (English)</label>' +
      '<input type="text" name="en[]" placeholder="My name is John" required>' +
      '<label>Phonetic</label>' +
      '<input type="text" name="ph[]" placeholder="mai néim iz john">' +
      '<label>Spanish</label>' +
      '<input type="text" name="es[]" placeholder="Mi nombre es John">' +
      '<label>Image (optional)</label>' +
      '<input type="file" name="img_file[]" accept="image/*">' +
      '<input type="hidden" name="img[]" value="">' +
      '<input type="hidden" name="audio[]" value="">' +
      '<button type="button" onclick="removeItem(this)" class="btn-remove">✖ Remove</button>';

    container.appendChild(div);
    bindChangeTracking(div);
    markAsChanged();
}

function removeItem(btn) {
    var block = btn.closest('.dict-block');
    if (block) {
        block.remove();
        markAsChanged();
    }
}

function bindChangeTracking(scope) {
    var elements = scope.querySelectorAll('input, textarea, select');
    elements.forEach(function(el) {
        el.addEventListener('input', markAsChanged);
        el.addEventListener('change', markAsChanged);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindChangeTracking(document);

    var form = document.getElementById('dictationForm');
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
render_activity_editor('Dictation Editor', '✍️', $content);

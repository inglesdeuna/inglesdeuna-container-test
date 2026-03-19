<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

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

function default_flashcards_title(): string
{
    return 'Flashcards';
}

function normalize_flashcards_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_flashcards_title();
}

function normalize_flashcards_payload($rawData): array
{
    $default = array(
        'title' => default_flashcards_title(),
        'cards' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $cardsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['cards']) && is_array($decoded['cards'])) {
        $cardsSource = $decoded['cards'];
    }

    $cards = array();

    if (is_array($cardsSource)) {
        foreach ($cardsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $cards[] = array(
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('flashcard_'),
                'text' => isset($item['text']) ? trim((string) $item['text']) : '',
                'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            );
        }
    }

    return array(
        'title' => normalize_flashcards_title($title),
        'cards' => $cards,
    );
}

function encode_flashcards_payload(array $payload): string
{
    return json_encode(
        array(
            'title' => normalize_flashcards_title(isset($payload['title']) ? (string) $payload['title'] : ''),
            'cards' => isset($payload['cards']) && is_array($payload['cards']) ? array_values($payload['cards']) : array(),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function load_flashcards_activity(PDO $pdo, string $unit, string $activityId): array
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
        'title' => default_flashcards_title(),
        'cards' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'flashcards'
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
               AND type = 'flashcards'
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
               AND type = 'flashcards'
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

    $payload = normalize_flashcards_payload($rawData);

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
        'title' => normalize_flashcards_title((string) $payload['title']),
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

function save_flashcards_activity(PDO $pdo, string $unit, string $activityId, string $title, array $cards): string
{
    $columns = activities_columns($pdo);
    $title = normalize_flashcards_title($title);
    $json = encode_flashcards_payload(array(
        'title' => $title,
        'cards' => $cards,
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
                   AND type = 'flashcards'
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
                   AND type = 'flashcards'
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
                   AND type = 'flashcards'"
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
    $insertValues[] = "'flashcards'";

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
    die('Unidad no especificada');
}

$activity = load_flashcards_activity($pdo, $unit, $activityId);
$cards = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_flashcards_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $texts = isset($_POST['text']) && is_array($_POST['text']) ? $_POST['text'] : array();
    $images = isset($_POST['image_existing']) && is_array($_POST['image_existing']) ? $_POST['image_existing'] : array();
    $ids = isset($_POST['card_id']) && is_array($_POST['card_id']) ? $_POST['card_id'] : array();
    $imageFiles = isset($_FILES['image_file']) ? $_FILES['image_file'] : null;

    $sanitized = array();

    foreach ($texts as $i => $textRaw) {
        $text = trim((string) $textRaw);
        $image = isset($images[$i]) ? trim((string) $images[$i]) : '';
        $cardId = isset($ids[$i]) && trim((string) $ids[$i]) !== '' ? trim((string) $ids[$i]) : uniqid('flashcard_');

        if (
            $imageFiles &&
            isset($imageFiles['name'][$i]) &&
            $imageFiles['name'][$i] !== '' &&
            isset($imageFiles['tmp_name'][$i]) &&
            $imageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedImage = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($uploadedImage) {
                $image = $uploadedImage;
            }
        }

        if ($text === '' && $image === '') {
            continue;
        }

        $sanitized[] = array(
            'id' => $cardId,
            'text' => $text,
            'image' => $image,
        );
    }

    $savedActivityId = save_flashcards_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

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
.flashcards-form{
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
}

.card-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:12px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}

.card-item label{
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.card-item input[type="text"],
.card-item input[type="file"]{
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

.btn-add{
    background:#16a34a;
    color:#fff;
    padding:10px 14px;
    border:none;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}

.btn-remove{
    background:#ef4444;
    color:#fff;
    border:none;
    padding:8px 12px;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>
<?php } ?>

<form class="flashcards-form" id="flashcardsForm" method="post" enctype="multipart/form-data">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Animals Flashcards"
            required
        >
    </div>

    <div id="cardsContainer">
        <?php foreach ($cards as $card) { ?>
            <div class="card-item">
                <input type="hidden" name="card_id[]" value="<?= htmlspecialchars(isset($card['id']) ? $card['id'] : uniqid('flashcard_'), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars(isset($card['image']) ? $card['image'] : '', ENT_QUOTES, 'UTF-8') ?>">

                <label>Word / text</label>
                <input type="text" name="text[]" value="<?= htmlspecialchars(isset($card['text']) ? $card['text'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Write the word" required>

                <label>Image (optional)</label>
                <?php if (!empty($card['image'])) { ?>
                    <img src="<?= htmlspecialchars($card['image'], ENT_QUOTES, 'UTF-8') ?>" alt="flashcard-image" class="image-preview">
                <?php } ?>
                <input type="file" name="image_file[]" accept="image/*">

                <button type="button" class="btn-remove" onclick="removeCard(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addCard()">+ Add Card</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function removeCard(button) {
    const item = button.closest('.card-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function addCard() {
    const container = document.getElementById('cardsContainer');
    const div = document.createElement('div');
    div.className = 'card-item';
    div.innerHTML = `
        <input type="hidden" name="card_id[]" value="flashcard_${Date.now()}_${Math.floor(Math.random() * 1000)}">
        <input type="hidden" name="image_existing[]" value="">

        <label>Word / text</label>
        <input type="text" name="text[]" placeholder="Write the word" required>

        <label>Image (optional)</label>
        <input type="file" name="image_file[]" accept="image/*">

        <button type="button" class="btn-remove" onclick="removeCard(this)">✖ Remove</button>
    `;
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

    const form = document.getElementById('flashcardsForm');
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
render_activity_editor('🃏 Flashcards Editor', '🃏', $content);

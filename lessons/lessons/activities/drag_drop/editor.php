<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

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

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_drag_drop_title(): string
{
    return 'Build the Sentence';
}

function normalize_drag_drop_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_drag_drop_title();
}

function normalize_words($rawWords): array
{
    $parts = preg_split('/[,\n]/', (string) $rawWords);
    $clean = [];

    foreach ($parts as $word) {
        $trimmed = trim((string) $word);
        if ($trimmed !== '') {
            $clean[] = $trimmed;
        }
    }

    return array_values(array_unique($clean));
}

function normalize_drag_drop_payload($rawData): array
{
    $default = [
        'title' => default_drag_drop_title(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'blocks' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $voiceId = trim((string) ($decoded['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));
    if ($voiceId === '') {
        $voiceId = 'nzFihrBIvB34imQBuxub';
    }
    $blocksSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['blocks']) && is_array($decoded['blocks'])) {
        $blocksSource = $decoded['blocks'];
    }

    $blocks = [];

    foreach ($blocksSource as $block) {
        if (!is_array($block)) {
            continue;
        }

        $text = '';
        if (isset($block['text']) && is_string($block['text'])) {
            $text = trim($block['text']);
        } elseif (isset($block['sentence']) && is_string($block['sentence'])) {
            $text = trim($block['sentence']);
        }

        $missingWords = [];
        if (isset($block['missing_words']) && is_array($block['missing_words'])) {
            foreach ($block['missing_words'] as $word) {
                $w = trim((string) $word);
                if ($w !== '') {
                    $missingWords[] = $w;
                }
            }
        }

        $listenEnabled = true;
        if (array_key_exists('listen_enabled', $block)) {
            $listenEnabled = filter_var($block['listen_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $listenEnabled = $listenEnabled === null ? true : $listenEnabled;
        } elseif (array_key_exists('listen', $block)) {
            $listenEnabled = filter_var($block['listen'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $listenEnabled = $listenEnabled === null ? true : $listenEnabled;
        }

        if ($text === '') {
            continue;
        }

        $image = isset($block['image']) && is_string($block['image']) ? trim($block['image']) : '';

        $blocks[] = [
            'id' => trim((string) ($block['id'] ?? uniqid('drag_drop_'))),
            'text' => $text,
            'missing_words' => $missingWords,
            'listen_enabled' => (bool) $listenEnabled,
            'image' => $image,
        ];
    }

    return [
        'title' => normalize_drag_drop_title($title),
        'voice_id' => $voiceId,
        'blocks' => $blocks,
    ];
}

function encode_drag_drop_payload(array $payload): string
{
    $blocks = array_map(function (array $b): array {
        return [
            'id'            => $b['id'] ?? '',
            'text'          => $b['text'] ?? '',
            'missing_words' => $b['missing_words'] ?? [],
            'listen_enabled'=> $b['listen_enabled'] ?? true,
            'image'         => $b['image'] ?? '',
        ];
    }, array_values($payload['blocks'] ?? []));

    return json_encode([
        'title'  => normalize_drag_drop_title((string) ($payload['title'] ?? '')),
        'voice_id' => trim((string) ($payload['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'blocks' => $blocks,
    ], JSON_UNESCAPED_UNICODE);
}

function load_drag_drop_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id' => '',
        'title' => default_drag_drop_title(),
        'voice_id' => 'nzFihrBIvB34imQBuxub',
        'blocks' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'drag_drop'
            LIMIT 1
        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'drag_drop'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_drag_drop_payload($row['data'] ?? null);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? default_drag_drop_title()),
        'voice_id' => (string) ($payload['voice_id'] ?? 'nzFihrBIvB34imQBuxub'),
        'blocks' => is_array($payload['blocks'] ?? null) ? $payload['blocks'] : [],
    ];
}

function save_drag_drop_activity(PDO $pdo, string $unit, string $activityId, string $title, string $voiceId, array $blocks): string
{
    $json = encode_drag_drop_payload([
        'title' => $title,
        'voice_id' => $voiceId,
        'blocks' => $blocks,
    ]);

    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id = :unit
              AND type = 'drag_drop'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE id = :id
              AND type = 'drag_drop'
        ");
        $stmt->execute([
            'data' => $json,
            'id' => $targetId,
        ]);

        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (
            :unit_id,
            'drag_drop',
            :data,
            (
                SELECT COALESCE(MAX(position), 0) + 1
                FROM activities
                WHERE unit_id = :unit_id2
            ),
            CURRENT_TIMESTAMP
        )
        RETURNING id
    ");
    $stmt->execute([
        'unit_id' => $unit,
        'unit_id2' => $unit,
        'data' => $json,
    ]);

    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_drag_drop_activity($pdo, $unit, $activityId);
$activityTitle = (string) ($activity['title'] ?? default_drag_drop_title());
$activityVoiceId = (string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub');
$blocks = is_array($activity['blocks'] ?? null) ? $activity['blocks'] : [];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string) ($_POST['activity_title'] ?? ''));
    $allowedVoices = ['nzFihrBIvB34imQBuxub', 'NoOVOzCQFLOvtsMoNcdT', 'Nggzl2QAXh3OijoXD116'];
    $postedVoiceId = isset($_POST['voice_id']) && in_array(trim((string) $_POST['voice_id']), $allowedVoices, true)
        ? trim((string) $_POST['voice_id'])
        : 'nzFihrBIvB34imQBuxub';
    $blockIds = isset($_POST['block_id']) && is_array($_POST['block_id']) ? $_POST['block_id'] : [];
    $texts = isset($_POST['text']) && is_array($_POST['text']) ? $_POST['text'] : [];
    $missingWordsRaw = isset($_POST['missing_words']) && is_array($_POST['missing_words']) ? $_POST['missing_words'] : [];
    $listenEnabledValues = isset($_POST['listen_enabled']) && is_array($_POST['listen_enabled']) ? $_POST['listen_enabled'] : [];
    $existingImages = isset($_POST['image_existing']) && is_array($_POST['image_existing']) ? $_POST['image_existing'] : [];
    $imageFiles = isset($_FILES['image_file']) ? $_FILES['image_file'] : null;

    $sanitized = [];

    foreach ($texts as $i => $textRaw) {
        $text = trim((string) $textRaw);
        $missingWords = normalize_words($missingWordsRaw[$i] ?? '');
        $listenEnabled = isset($listenEnabledValues[$i]) && (string) $listenEnabledValues[$i] === '1';
        $blockId = trim((string) ($blockIds[$i] ?? uniqid('drag_drop_')));
        $image = isset($existingImages[$i]) ? trim((string) $existingImages[$i]) : '';

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

        if ($text === '') {
            continue;
        }

        $sanitized[] = [
            'id' => $blockId !== '' ? $blockId : uniqid('drag_drop_'),
            'text' => $text,
            'missing_words' => $missingWords,
            'listen_enabled' => $listenEnabled,
            'image' => $image,
        ];
    }

    $savedActivityId = save_drag_drop_activity($pdo, $unit, $activityId, $postedTitle, $postedVoiceId, $sanitized);

    $params = [
        'unit=' . urlencode($unit),
        'saved=1'
    ];

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
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

if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>';
}
?>

<style>
.dd-form{
    max-width:860px;
    margin:0 auto;
    text-align:left;
}
.title-box,
.block-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:14px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}
.title-box label,
.block-item label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}
.title-box input,
.title-box select,
.block-item input,
.block-item textarea{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #d1d5db;
    box-sizing:border-box;
    margin-bottom:12px;
    font-size:14px;
}
.block-item textarea{
    min-height:90px;
    resize:vertical;
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
.help{
    margin:-6px 0 12px 0;
    color:#6b7280;
    font-size:13px;
}
.checkbox-row{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:700;
    margin-bottom:10px;
}
.checkbox-row input[type="checkbox"]{
    width:auto;
    margin:0;
}
.image-preview{
    display:block;
    max-width:200px;
    max-height:160px;
    border-radius:8px;
    margin-bottom:8px;
    object-fit:contain;
    border:1px solid #e5e7eb;
}
</style>

<form method="post" enctype="multipart/form-data" class="dd-form" id="dragDropForm">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Build the sentence"
            required
        >

        <label for="voice_id">Voice for students</label>
        <select id="voice_id" name="voice_id">
            <option value="nzFihrBIvB34imQBuxub"<?= $activityVoiceId === 'nzFihrBIvB34imQBuxub' ? ' selected' : '' ?>>Adult Male (Josh)</option>
            <option value="NoOVOzCQFLOvtsMoNcdT"<?= $activityVoiceId === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : '' ?>>Adult Female (Lily)</option>
            <option value="Nggzl2QAXh3OijoXD116"<?= $activityVoiceId === 'Nggzl2QAXh3OijoXD116' ? ' selected' : '' ?>>Child (Candy)</option>
        </select>
    </div>

    <div id="blocksContainer">
        <?php foreach ($blocks as $block) { ?>
            <div class="block-item">
                <input type="hidden" name="block_id[]" value="<?= htmlspecialchars((string) ($block['id'] ?? uniqid('drag_drop_')), ENT_QUOTES, 'UTF-8') ?>">

                <label>Sentence or paragraph</label>
                <textarea name="text[]" required><?= htmlspecialchars((string) ($block['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

                <label>Words to drag</label>
                <input
                    type="text"
                    name="missing_words[]"
                    value="<?= htmlspecialchars(implode(', ', is_array($block['missing_words'] ?? null) ? $block['missing_words'] : []), ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Example: usually, early, coffee"
                    required
                >
                <p class="help">Separate the draggable words with commas.</p>

                <label>Block image (optional)</label>
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars((string) ($block['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <?php if (!empty($block['image'])) { ?>
                    <img src="<?= htmlspecialchars((string) $block['image'], ENT_QUOTES, 'UTF-8') ?>" alt="block-image" class="image-preview">
                <?php } ?>
                <input type="file" name="image_file[]" accept="image/*">

                <label class="checkbox-row">
                    <input type="hidden" name="listen_enabled[]" value="0">
                    <input type="checkbox" value="1" <?= !empty($block['listen_enabled']) ? 'checked' : '' ?> onchange="syncCheckboxValue(this)">
                    Activate Listen in this block
                </label>

                <button type="button" class="btn-remove" onclick="removeBlock(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addBlock()">+ Add Block</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function syncCheckboxValue(checkbox) {
    const hidden = checkbox.parentElement.querySelector('input[type="hidden"][name="listen_enabled[]"]');
    if (hidden) {
        hidden.value = checkbox.checked ? '1' : '0';
    }
    markChanged();
}

function removeBlock(button) {
    const item = button.closest('.block-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function addBlock() {
    const container = document.getElementById('blocksContainer');
    const div = document.createElement('div');
    div.className = 'block-item';
    div.innerHTML = `
        <input type="hidden" name="block_id[]" value="drag_drop_${Date.now()}_${Math.floor(Math.random() * 1000)}">

        <label>Sentence or paragraph</label>
        <textarea name="text[]" required></textarea>

        <label>Words to drag</label>
        <input type="text" name="missing_words[]" placeholder="Example: usually, early, coffee" required>
        <p class="help">Separate the draggable words with commas.</p>

        <label>Block image (optional)</label>
        <input type="hidden" name="image_existing[]" value="">
        <input type="file" name="image_file[]" accept="image/*">

        <label class="checkbox-row">
            <input type="hidden" name="listen_enabled[]" value="0">
            <input type="checkbox" value="1" onchange="syncCheckboxValue(this)">
            Activate Listen in this block
        </label>

        <button type="button" class="btn-remove" onclick="removeBlock(this)">✖ Remove</button>
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

    document.querySelectorAll('.checkbox-row input[type="checkbox"]').forEach(function(cb) {
        const hidden = cb.parentElement.querySelector('input[type="hidden"][name="listen_enabled[]"]');
        if (hidden) {
            hidden.value = cb.checked ? '1' : '0';
        }
    });

    const form = document.getElementById('dragDropForm');
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
render_activity_editor('✏ Drag & Drop Editor', '✏', $content);

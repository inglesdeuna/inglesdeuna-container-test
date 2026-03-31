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

function default_tracing_title(): string { return 'Tracing'; }
function normalize_tracing_title(string $title): string { $title = trim($title); return $title !== '' ? $title : default_tracing_title(); }
function normalize_tracing_payload($rawData): array {
    $default = array('title' => default_tracing_title(), 'images' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;
    $title = '';
    $imagesSource = $decoded;
    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['images']) && is_array($decoded['images'])) $imagesSource = $decoded['images'];
    $images = array();
    if (is_array($imagesSource)) {
        foreach ($imagesSource as $item) {
            if (!is_array($item)) continue;
            $images[] = array(
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('tracing_'),
                'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            );
        }
    }
    return array('title' => normalize_tracing_title($title), 'images' => $images);
}
function encode_tracing_payload(array $payload): string {
    return json_encode(array(
        'title' => normalize_tracing_title(isset($payload['title']) ? (string) $payload['title'] : ''),
        'images' => isset($payload['images']) && is_array($payload['images']) ? array_values($payload['images']) : array(),
    ), JSON_UNESCAPED_UNICODE);
}
function activities_columns(PDO $pdo): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}
function load_tracing_activity(PDO $pdo, string $unit, string $activityId): array {
    $columns = activities_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';
    $fallback = array('id' => '', 'title' => default_tracing_title(), 'images' => array());
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'tracing' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];
    $payload = normalize_tracing_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;
    return array('id' => isset($row['id']) ? (string) $row['id'] : '', 'title' => normalize_tracing_title((string) $payload['title']), 'images' => isset($payload['images']) && is_array($payload['images']) ? $payload['images'] : array());
}
function save_tracing_activity(PDO $pdo, string $unit, string $activityId, string $title, array $images): string {
    $columns = activities_columns($pdo);
    $title = normalize_tracing_title($title);
    $json = encode_tracing_payload(array('title' => $title, 'images' => $images));
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
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }
    if ($targetId !== '') {
        $setParts = array();
        $params = array('id' => $targetId);
        if ($hasData) { $setParts[] = 'data = :data'; $params['data'] = $json; }
        if ($hasContentJson) { $setParts[] = 'content_json = :content_json'; $params['content_json'] = $json; }
        if ($hasTitle) { $setParts[] = 'title = :title'; $params['title'] = $title; }
        if ($hasName) { $setParts[] = 'name = :name'; $params['name'] = $title; }
        if (!empty($setParts)) {
            $stmt = $pdo->prepare("UPDATE activities SET " . implode(', ', $setParts) . " WHERE id = :id AND type = 'tracing'");
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
    if ($hasUnitId) { $insertColumns[] = 'unit_id'; $insertValues[] = ':unit_id'; $params['unit_id'] = $unit; }
    elseif ($hasUnit) { $insertColumns[] = 'unit'; $insertValues[] = ':unit'; $params['unit'] = $unit; }
    $insertColumns[] = 'type'; $insertValues[] = "'tracing'";
    if ($hasData) { $insertColumns[] = 'data'; $insertValues[] = ':data'; $params['data'] = $json; }
    if ($hasContentJson) { $insertColumns[] = 'content_json'; $insertValues[] = ':content_json'; $params['content_json'] = $json; }
    if ($hasTitle) { $insertColumns[] = 'title'; $insertValues[] = ':title'; $params['title'] = $title; }
    if ($hasName) { $insertColumns[] = 'name'; $insertValues[] = ':name'; $params['name'] = $title; }
    $stmt = $pdo->prepare("INSERT INTO activities (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")");
    $stmt->execute($params);
    return $newId;
}

// Cargar datos existentes
$activity = load_tracing_activity($pdo, $unit, $activityId);
$images = isset($activity['images']) && is_array($activity['images']) ? $activity['images'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_tracing_title();
if ($activityId === '' && !empty($activity['id'])) $activityId = (string) $activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $ids = isset($_POST['image_id']) && is_array($_POST['image_id']) ? $_POST['image_id'] : array();
    $imagesExisting = isset($_POST['image_existing']) && is_array($_POST['image_existing']) ? $_POST['image_existing'] : array();
    $imageFiles = isset($_FILES['image_file']) ? $_FILES['image_file'] : null;
    $sanitized = array();
    foreach ($ids as $i => $imgId) {
        $imgUrl = isset($imagesExisting[$i]) ? trim((string) $imagesExisting[$i]) : '';
        if ($imageFiles && isset($imageFiles['name'][$i]) && $imageFiles['name'][$i] !== '' && isset($imageFiles['tmp_name'][$i]) && $imageFiles['tmp_name'][$i] !== '') {
            $uploadedImage = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($uploadedImage) $imgUrl = $uploadedImage;
        }
        if ($imgUrl === '') continue;
        $sanitized[] = array('id' => $imgId !== '' ? $imgId : uniqid('tracing_'), 'image' => $imgUrl);
    }
    $savedActivityId = save_tracing_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);
    $params = array('unit=' . urlencode($unit));
    if ($source !== '') $params[] = 'source=' . urlencode($source);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($savedActivityId !== '') $params[] = 'id=' . urlencode($savedActivityId);
    header('Location: editor.php?' . implode('&', $params) . '&saved=1');
    exit;
}

ob_start();
?>
<style>
.tracing-form { max-width: 520px; margin: 0 auto; }
.tracing-title-box { margin-bottom: 18px; }
.tracing-title-box label { font-weight: 700; }
.tracing-title-box input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #ccc; font-size: 15px; }
.tracing-images-list { list-style: none; padding: 0; margin: 0 0 18px 0; }
.tracing-image-item { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; background: #f9fafb; border-radius: 12px; border: 1px solid #e5e7eb; padding: 10px; }
.tracing-image-thumb { max-width: 90px; max-height: 90px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; object-fit: contain; }
.tracing-image-actions { display: flex; flex-direction: column; gap: 6px; }
.tracing-btn { background: #2563eb; color: #fff; border: none; padding: 7px 12px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 14px; }
.tracing-btn:hover { background: #1d4ed8; }
.tracing-btn-remove { background: #ef4444; }
.tracing-btn-remove:hover { background: #b91c1c; }
.tracing-btn-move { background: #fbbf24; color: #1e293b; }
.tracing-btn-move:hover { background: #f59e42; }
.tracing-add-box { margin-bottom: 18px; }
</style>
<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>
<?php } ?>
<form class="tracing-form" id="tracingForm" method="post" enctype="multipart/form-data">
    <div class="tracing-title-box">
        <label for="activity_title">Activity title</label>
        <input id="activity_title" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Trace the Letters" required>
    </div>
    <div class="tracing-add-box">
        <input type="file" name="image_file[]" accept="image/*" multiple>
        <span style="font-size:13px;color:#64748b;">You can add multiple images at once.</span>
    </div>
    <ul class="tracing-images-list" id="imagesList">
        <?php foreach ($images as $i => $img) { ?>
            <li class="tracing-image-item">
                <input type="hidden" name="image_id[]" value="<?= htmlspecialchars($img['id'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>" class="tracing-image-thumb" alt="tracing-image">
                <div class="tracing-image-actions">
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, -1)">↑</button>
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, 1)">↓</button>
                    <button type="button" class="tracing-btn tracing-btn-remove" onclick="removeImage(this)">Remove</button>
                </div>
            </li>
        <?php } ?>
    </ul>
    <button type="submit" class="tracing-btn" style="margin-top:10px;">💾 Save</button>
</form>
<script>
function moveImage(btn, dir) {
    const item = btn.closest('.tracing-image-item');
    const list = document.getElementById('imagesList');
    const items = Array.from(list.children);
    const idx = items.indexOf(item);
    if ((dir === -1 && idx === 0) || (dir === 1 && idx === items.length - 1)) return;
    const swapIdx = idx + dir;
    if (dir === -1) {
        list.insertBefore(item, items[swapIdx]);
    } else {
        list.insertBefore(items[swapIdx], item);
    }
}
function removeImage(btn) {
    const item = btn.closest('.tracing-image-item');
    if (item) item.remove();
}
// Antes de enviar el formulario, asegura el orden de los <li> (inputs ya están dentro)
document.getElementById('tracingForm').addEventListener('submit', function(e) {
    const list = document.getElementById('imagesList');
    const items = Array.from(list.children);
    // Validar que haya al menos una imagen
    if (items.length === 0) {
        alert('Debes agregar al menos una imagen para guardar la actividad.');
        e.preventDefault();
        return false;
    }
    // No es necesario eliminar ni reinsertar inputs, solo asegurar el orden visual
    items.forEach(item => list.appendChild(item));
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('✏️ Tracing Editor', '✏️', $content);

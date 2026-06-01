<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/tracing_functions.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Access control
if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

// Params
$activityId = $_GET['id'] ?? '';
$unit = $_GET['unit'] ?? '';
$source = $_GET['source'] ?? '';
$assignment = $_GET['assignment'] ?? '';

// Load existing
$activity = load_tracing_activity($pdo, $unit, $activityId);
$images = $activity['images'] ?? [];
$title = $activity['title'] ?? default_tracing_title();
$subtitle = $activity['subtitle'] ?? '';


// ================= SAVE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title    = trim($_POST['activity_title'] ?? '');
    $subtitle = trim($_POST['activity_subtitle'] ?? '');

    $ids      = $_POST['image_id'] ?? [];
    $existing = $_POST['image_existing'] ?? [];
    $files    = $_FILES['image_file'] ?? null;

    $clean = [];
    $fileIndex = 0; // tracks index into $_FILES for NEW uploads only

    foreach ($ids as $i => $id) {

        $imgUrl = trim($existing[$i] ?? '');

        // New image (no existing URL) — map to the next available uploaded file
        if ($imgUrl === '') {
            if (
                $files &&
                isset($files['tmp_name'][$fileIndex]) &&
                $files['tmp_name'][$fileIndex] !== ''
            ) {
                $uploaded = upload_to_cloudinary($files['tmp_name'][$fileIndex]);
                if ($uploaded) {
                    $imgUrl = $uploaded;
                }
            }
            $fileIndex++;
        }

        if ($imgUrl === '') continue;

        $clean[] = [
            'id'    => $id ?: uniqid('img_'),
            'image' => $imgUrl,
        ];
    }

    if (empty($clean)) {
        die('No images were saved. Upload may have failed.');
    }

    $activityId = save_tracing_activity($pdo, $unit, $activityId, $title, $subtitle, $clean);

    header("Location: editor.php?unit=" . urlencode($unit) . "&id=" . urlencode($activityId) . "&saved=1");
    exit;
}


// ================= UI =================
ob_start();
?>

<style>
.tracing-form .section-label {
    font-weight: 700;
    font-size: 0.9rem;
    color: #374151;
    margin-bottom: 4px;
}
.tracing-image-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 14px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    margin-bottom: 8px;
}
.tracing-image-thumb {
    width: 90px;
    height: 66px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    flex-shrink: 0;
}
.tracing-image-name {
    flex: 1;
    font-size: 0.85rem;
    color: #6b7280;
    word-break: break-all;
}
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success mb-3">Saved successfully</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="tracingForm" class="tracing-form">

    <div class="mb-3">
        <label class="form-label section-label">Activity Title</label>
        <input
            type="text"
            class="form-control"
            name="activity_title"
            value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="e.g. Trace the Letters"
            required>
    </div>

    <div class="mb-4">
        <label class="form-label section-label">Instructions <small class="text-muted fw-normal">(shown below the title in the viewer)</small></label>
        <input
            type="text"
            class="form-control"
            name="activity_subtitle"
            value="<?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="e.g. Choose a color and trace each page carefully">
    </div>

    <hr class="my-3">

    <div class="mb-3">
        <label class="form-label section-label">Tracing Images</label>
        <p class="text-muted small mb-2">Upload one image per page. Students will trace them in order.</p>

        <label class="btn btn-outline-primary btn-sm mb-3" style="cursor:pointer">
            + Add Images
            <input type="file" id="imageUploadInput" name="image_file[]" multiple accept="image/*" style="display:none">
        </label>

        <ul id="imagesList" class="list-unstyled mb-0">
            <?php foreach ($images as $img): ?>
            <li class="tracing-image-item">
                <input type="hidden" name="image_id[]" value="<?= htmlspecialchars($img['id'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>" class="tracing-image-thumb" alt="">
                <span class="tracing-image-name"><?= htmlspecialchars(basename($img['image']), ENT_QUOTES, 'UTF-8') ?></span>
                <button type="button" onclick="removeImage(this)" class="btn btn-sm btn-outline-danger">Remove</button>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="d-flex justify-content-end">
        <button type="submit" class="btn btn-success px-4">Save Activity</button>
    </div>

</form>


<script>
function removeImage(btn) {
    btn.closest('li').remove();
}

document.getElementById('imageUploadInput').addEventListener('change', function (e) {
    const files = Array.from(e.target.files);
    const list  = document.getElementById('imagesList');

    files.forEach(function (file) {
        const reader = new FileReader();
        reader.onload = function (ev) {
            const li = document.createElement('li');
            li.className = 'tracing-image-item';
            li.innerHTML =
                '<input type="hidden" name="image_id[]" value="img_' + Date.now() + '">' +
                '<input type="hidden" name="image_existing[]" value="">' +
                '<img src="' + ev.target.result + '" class="tracing-image-thumb" alt="">' +
                '<span class="tracing-image-name">' + file.name + '</span>' +
                '<button type="button" onclick="removeImage(this)" class="btn btn-sm btn-outline-danger">Remove</button>';
            list.appendChild(li);
        };
        reader.readAsDataURL(file);
    });
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Tracing Editor', 'fas fa-pencil-alt', $content);

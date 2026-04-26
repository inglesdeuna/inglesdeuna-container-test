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


// ================= SAVE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title = trim($_POST['activity_title'] ?? '');

    $ids = $_POST['image_id'] ?? [];
    $existing = $_POST['image_existing'] ?? [];
    $files = $_FILES['image_file'] ?? null;

    $clean = [];

    foreach ($ids as $i => $id) {

        $imgUrl = trim($existing[$i] ?? '');

        // ✅ FIXED: correct index usage
        if (
            $imgUrl === '' &&
            $files &&
            isset($files['tmp_name'][$i]) &&
            $files['tmp_name'][$i] !== ''
        ) {
            $uploaded = upload_to_cloudinary($files['tmp_name'][$i]);
            if ($uploaded) {
                $imgUrl = $uploaded;
            }
        }

        if ($imgUrl === '') continue;

        $clean[] = [
            'id' => $id ?: uniqid('img_'),
            'image' => $imgUrl
        ];
    }

    if (empty($clean)) {
        die('No images were saved. Upload may have failed.');
    }

    $activityId = save_tracing_activity($pdo, $unit, $activityId, $title, $clean);

    header("Location: editor.php?unit=" . urlencode($unit) . "&id=" . urlencode($activityId) . "&saved=1");
    exit;
}


// ================= UI =================
ob_start();
?>

<style>
/* KEEPING YOUR ORIGINAL DESIGN */
.tracing-form { max-width:900px; margin:0 auto; font-family:'Nunito','Segoe UI'; }
.tracing-image-item { display:flex; gap:12px; margin-bottom:12px; background:#f9fafb; padding:10px; border-radius:12px; }
.tracing-image-thumb { max-width:140px; max-height:140px; }
.tracing-btn { background:#2563eb; color:#fff; padding:8px 12px; border:none; border-radius:8px; cursor:pointer; }
.tracing-btn-remove { background:#ef4444; }
</style>

<?php if (isset($_GET['saved'])): ?>
<p style="color:green;font-weight:bold;">Saved successfully</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="tracingForm">

    <h3>Tracing Editor</h3>

    <input type="text" name="activity_title" value="<?= htmlspecialchars($title) ?>" required>

    <br><br>

    <input type="file" id="imageUploadInput" name="image_file[]" multiple>

    <ul id="imagesList">

        <?php foreach ($images as $img): ?>
        <li class="tracing-image-item">
            <input type="hidden" name="image_id[]" value="<?= $img['id'] ?>">
            <input type="hidden" name="image_existing[]" value="<?= $img['image'] ?>">

            <img src="<?= $img['image'] ?>" class="tracing-image-thumb">

            <button type="button" onclick="removeImage(this)" class="tracing-btn tracing-btn-remove">Remove</button>
        </li>
        <?php endforeach; ?>

    </ul>

    <br>

    <button type="submit" class="tracing-btn">Save</button>

</form>


<script>
// REMOVE
function removeImage(btn) {
    btn.closest('li').remove();
}

// ADD IMAGES (preview only)
document.getElementById('imageUploadInput').addEventListener('change', function(e) {

    const files = Array.from(e.target.files);
    const list = document.getElementById('imagesList');

    files.forEach(file => {

        const reader = new FileReader();

        reader.onload = function(ev) {

            const li = document.createElement('li');
            li.className = 'tracing-image-item';

            li.innerHTML = `
                <input type="hidden" name="image_id[]" value="img_${Date.now()}">
                <input type="hidden" name="image_existing[]" value="">

                <img src="${ev.target.result}" class="tracing-image-thumb">

                <button type="button" onclick="removeImage(this)" class="tracing-btn tracing-btn-remove">Remove</button>
            `;

            list.appendChild(li);
        };

        reader.readAsDataURL(file);
    });
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Tracing Editor', '✏️', $content);

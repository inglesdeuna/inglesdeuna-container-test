<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/tracing_functions.php';
if (!function_exists('render_activity_editor')) {
    require_once __DIR__ . '/../../core/_activity_editor_template.php';
}

// Block student access to editor
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
    $fileIndex = 0;
    foreach ($ids as $i => $imgId) {
        $imgUrl = isset($imagesExisting[$i]) ? trim((string) $imagesExisting[$i]) : '';
        // Si no hay URL, intenta subir el archivo correspondiente
        if ($imgUrl === '' && $imageFiles && isset($imageFiles['name'][$fileIndex]) && $imageFiles['name'][$fileIndex] !== '' && isset($imageFiles['tmp_name'][$fileIndex]) && $imageFiles['tmp_name'][$fileIndex] !== '') {
            $uploadedImage = upload_to_cloudinary($imageFiles['tmp_name'][$fileIndex]);
            if ($uploadedImage) $imgUrl = $uploadedImage;
            $fileIndex++;
        } elseif ($imgUrl !== '') {
            if ($imageFiles && isset($imageFiles['name'][$fileIndex]) && $imageFiles['name'][$fileIndex] !== '') {
                $fileIndex++;
            }
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
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

.tracing-form {
    max-width:900px;
    margin:0 auto;
    text-align:left;
    font-family:'Nunito', 'Segoe UI', sans-serif;
}

.tracing-intro{
    background:linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 52%, #f0fdf4 100%);
    border:1px solid #ccfbf1;
    border-radius:20px;
    padding:18px 20px;
    margin:0 0 14px;
    box-shadow:0 12px 26px rgba(15, 23, 42, .08);
}

.tracing-intro h3{
    margin:0 0 6px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:24px;
    font-weight:700;
    color:#0f172a;
}

.tracing-intro p{
    margin:0;
    color:#475569;
    font-size:14px;
    line-height:1.5;
}

.tracing-title-box{
    background:#ffffff;
    padding:14px;
    margin-bottom:14px;
    border-radius:14px;
    border:1px solid #e2e8f0;
    box-shadow:0 8px 18px rgba(15, 23, 42, .04);
}

.tracing-title-box label{
    display:block;
    font-weight:800;
    margin-bottom:8px;
    color:#1e293b;
}

.tracing-title-box input{
    width:100%;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #cbd5e1;
    font-size:15px;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    box-sizing:border-box;
}

.tracing-images-list {
    list-style: none;
    padding: 0;
    margin: 0 0 18px 0;
}

.tracing-image-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    background: #f9fafb;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    padding: 10px;
}

.tracing-image-thumb {
    max-width: 140px;
    max-height: 140px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    background: #fff;
    object-fit: contain;
}

.tracing-image-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.tracing-btn {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 10px 14px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 800;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    font-size: 15px;
    transition:transform .15s ease, filter .15s ease;
}

.tracing-btn:hover {
    background: #1d4ed8;
    filter:brightness(1.06);
    transform:translateY(-1px);
}

.tracing-btn-remove {
    background: #ef4444;
}
.tracing-btn-remove:hover {
    background: #b91c1c;
}
.tracing-btn-move {
    background: #fbbf24;
    color: #1e293b;
}
.tracing-btn-move:hover {
    background: #f59e42;
}
.tracing-add-box {
    margin-bottom: 18px;
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
    .tracing-image-item{flex-direction:column;align-items:flex-start;}
    .tracing-intro h3{font-size:22px;}
}
</style>
<?php if (isset($_GET['saved'])) { ?>
<p class="saved-notice">✔ Saved successfully</p>
<?php } ?>
<form class="tracing-form" id="tracingForm" method="post" enctype="multipart/form-data">
    <section class="tracing-intro">
        <h3>Tracing Editor</h3>
        <p>Upload images for tracing activities. Students will trace over the images you provide. You can add multiple images and reorder them as needed.</p>
    </section>
    <div class="tracing-title-box">
        <label for="activity_title">Activity title</label>
        <input id="activity_title" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Trace the Letters" required>
    </div>
    <div class="tracing-add-box">
        <input type="file" id="imageUploadInput" name="image_file[]" accept="image/*" multiple>
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
                    <button type="button" class="tracing-btn tracing-btn-remove" onclick="removeImage(this)">✖ Remove</button>
                </div>
            </li>
        <?php } ?>
    </ul>
    <div class="actions-row" style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
        <button type="button" onclick="document.getElementById('imageUploadInput').click()" class="tracing-btn">+ Add Image</button>
        <button type="submit" class="tracing-btn" style="background:#0d9488;">💾 Save</button>
    </div>
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

// Previsualización de imágenes seleccionadas
document.getElementById('imageUploadInput').addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    const list = document.getElementById('imagesList');
    files.forEach(file => {
        const reader = new FileReader();
        reader.onload = function(evt) {
            const li = document.createElement('li');
            li.className = 'tracing-image-item';
            li.innerHTML = `
                <input type="hidden" name="image_id[]" value="tracing_${Date.now()}_${Math.floor(Math.random()*1000)}">
                <input type="hidden" name="image_existing[]" value="">
                <img src="${evt.target.result}" class="tracing-image-thumb" alt="tracing-image">
                <div class="tracing-image-actions">
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, -1)">↑</button>
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, 1)">↓</button>
                    <button type="button" class="tracing-btn tracing-btn-remove" onclick="removeImage(this)">✖ Remove</button>
                </div>
            `;
            list.appendChild(li);
        };
        reader.readAsDataURL(file);
    });
    // Limpiar input para permitir volver a seleccionar los mismos archivos si se desea
    e.target.value = '';
});

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

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
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
    max-width:960px;
    margin:0 auto;
    text-align:left;
    font-family:'Nunito', 'Segoe UI', sans-serif;
}

.tracing-intro{
    background:linear-gradient(135deg, #fff7ed 0%, #fef3c7 48%, #fffbeb 100%);
    border:1px solid #fdba74;
    border-radius:22px;
    padding:18px 20px;
    margin:0 0 14px;
    box-shadow:0 12px 26px rgba(124, 45, 18, .12);
}

.tracing-intro h3{
    margin:0 0 6px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:24px;
    font-weight:700;
    color:#9a3412;
}

.tracing-intro p{
    margin:0;
    color:#7c2d12;
    font-size:14px;
    line-height:1.5;
}

.worksheet-guide{
    margin-top:10px;
    font-size:13px;
    font-weight:700;
    color:#7c2d12;
}

.tracing-title-box{
    background:#ffffff;
    padding:14px;
    margin-bottom:14px;
    border-radius:14px;
    border:1px solid #e2e8f0;
    box-shadow:0 8px 18px rgba(15, 23, 42, .04);
}

.palette-preview-box{
    background:#ffffff;
    border:1px solid #fde68a;
    border-radius:14px;
    padding:12px 14px;
    margin-bottom:14px;
    box-shadow:0 8px 18px rgba(120, 53, 15, .08);
}

.palette-preview-title{
    margin:0 0 8px;
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.06em;
    font-weight:800;
    color:#92400e;
}

.palette-preview-note{
    margin:8px 0 0;
    font-size:12px;
    font-weight:700;
    color:#7c2d12;
}

.palette-swatches{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.palette-swatch{
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:1px solid #e5e7eb;
    border-radius:999px;
    padding:5px 9px 5px 5px;
    background:#fff;
}

.palette-dot{
    width:20px;
    height:20px;
    border-radius:50%;
    border:1px solid rgba(0,0,0,.18);
    display:inline-block;
}

.palette-label{
    font-size:12px;
    font-weight:800;
    color:#334155;
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

.tracing-title-box input:focus,
.tracing-add-box input[type="file"]:focus{
    outline:none;
    border-color:#0d9488;
    box-shadow:0 0 0 3px rgba(13, 148, 136, .16);
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

.tracing-image-item.is-new{
    border-style:dashed;
    border-color:#a7f3d0;
    background:linear-gradient(180deg, #ecfdf5 0%, #f8fafc 100%);
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

.tracing-btn:focus-visible{
    outline:none;
    box-shadow:0 0 0 3px rgba(37, 99, 235, .24);
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

.tracing-btn-add{
    background:#14b8a6;
}

.tracing-btn-add:hover{
    background:#0f766e;
}

.tracing-btn-save{
    background:#0d9488;
}

.tracing-btn-save:hover{
    background:#0f766e;
}

.tracing-add-box {
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    background:#ffffff;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding:12px 14px;
    margin-bottom: 18px;
}

.worksheet-preview {
    margin-top:10px;
    border:1px dashed #f59e0b;
    border-radius:12px;
    background:linear-gradient(180deg, #fff 0%, #fffbeb 100%);
    padding:10px;
    text-align:center;
}

.worksheet-preview img {
    width:100%;
    max-width:260px;
    aspect-ratio:3/4;
    object-fit:contain;
    border:2px solid #fed7aa;
    border-radius:8px;
    background:#fff;
}

.worksheet-preview p {
    margin:6px 0 0;
    font-size:12px;
    font-weight:700;
    color:#9a3412;
}

.tracing-add-box input[type="file"]{
    flex:1 1 320px;
    padding:8px 10px;
    border-radius:10px;
    border:1px solid #cbd5e1;
    font-size:14px;
    color:#334155;
    background:#fff;
}

.tracing-hint{
    margin:0;
    font-size:13px;
    color:#64748b;
    font-weight:700;
}

.tracing-empty{
    text-align:center;
    color:#64748b;
    font-weight:700;
    border:1px dashed #cbd5e1;
    border-radius:12px;
    padding:14px;
    background:#f8fafc;
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

    .tracing-btn{
        width:100%;
    }

    .tracing-image-actions{
        width:100%;
    }

    .tracing-add-box input[type="file"]{
        flex-basis:100%;
        width:100%;
    }
}
</style>
<?php if (isset($_GET['saved'])) { ?>
<p class="saved-notice">✔ Saved successfully</p>
<?php } ?>
<form class="tracing-form" id="tracingForm" method="post" enctype="multipart/form-data">
    <section class="tracing-intro">
        <h3>Coloring Page Editor</h3>
        <p>Upload black and white illustrations with thick clean lines. The activity will be shown as a vertical printable worksheet for children.</p>
        <div class="worksheet-guide">Recommended style: simple friendly character, centered composition, minimal details for easy coloring.</div>
    </section>
    <div class="tracing-title-box">
        <label for="activity_title">Activity title</label>
        <input id="activity_title" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Unicorn Coloring Page" required>
    </div>

    <div class="palette-preview-box">
        <p class="palette-preview-title">Auto generated crayon palette</p>
        <div class="palette-swatches" id="palettePreview"></div>
        <p class="palette-preview-note">Primary and secondary colors are generated automatically to guide children while coloring.</p>
    </div>

    <div class="tracing-add-box">
        <input type="file" id="imageUploadInput" name="image_file[]" accept="image/*" multiple>
        <p class="tracing-hint">You can add multiple pages. They will be shown in the exact order they appear in this list.</p>
    </div>
    <ul class="tracing-images-list" id="imagesList">
        <?php foreach ($images as $i => $img) { ?>
            <li class="tracing-image-item">
                <input type="hidden" name="image_id[]" value="<?= htmlspecialchars($img['id'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>" class="tracing-image-thumb" alt="tracing-image">
                <div class="worksheet-preview">
                    <img src="<?= htmlspecialchars($img['image'], ENT_QUOTES, 'UTF-8') ?>" alt="worksheet-preview">
                    <p>Printable vertical worksheet preview</p>
                </div>
                <div class="tracing-image-actions">
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, -1)">↑</button>
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, 1)">↓</button>
                    <button type="button" class="tracing-btn tracing-btn-remove" onclick="removeImage(this)">✖ Remove</button>
                </div>
            </li>
        <?php } ?>
        <?php if (count($images) === 0) { ?>
            <li class="tracing-empty" id="tracingEmptyState">No images yet. Use Add Image to start building this activity.</li>
        <?php } ?>
    </ul>
    <div class="actions-row">
        <button type="button" onclick="document.getElementById('imageUploadInput').click()" class="tracing-btn tracing-btn-add">+ Add Image</button>
        <button type="submit" class="tracing-btn tracing-btn-save">💾 Save</button>
    </div>
</form>
<script>
function syncEmptyState() {
    const list = document.getElementById('imagesList');
    const empty = document.getElementById('tracingEmptyState');
    if (!list) return;
    const imageItems = list.querySelectorAll('.tracing-image-item').length;
    if (imageItems === 0 && !empty) {
        const li = document.createElement('li');
        li.className = 'tracing-empty';
        li.id = 'tracingEmptyState';
        li.textContent = 'No images yet. Use Add Image to start building this activity.';
        list.appendChild(li);
    }
    if (imageItems > 0 && empty) {
        empty.remove();
    }
}

function getCrayonPalette() {
    return [
        { name: 'Red', value: '#ef4444' },
        { name: 'Orange', value: '#f97316' },
        { name: 'Yellow', value: '#eab308' },
        { name: 'Green', value: '#22c55e' },
        { name: 'Blue', value: '#3b82f6' },
        { name: 'Purple', value: '#8b5cf6' },
        { name: 'Pink', value: '#ec4899' },
        { name: 'Brown', value: '#92400e' },
        { name: 'Black', value: '#111827' }
    ];
}

function renderPalettePreview() {
    const target = document.getElementById('palettePreview');
    if (!target) return;
    target.innerHTML = '';
    const palette = getCrayonPalette();
    palette.forEach(c => {
        const chip = document.createElement('span');
        chip.className = 'palette-swatch';
        chip.innerHTML = '<span class="palette-dot" style="background:' + c.value + '"></span><span class="palette-label">' + c.name + '</span>';
        target.appendChild(chip);
    });
}

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
    syncEmptyState();
}

// Previsualización de imágenes seleccionadas
document.getElementById('imageUploadInput').addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    const list = document.getElementById('imagesList');
    files.forEach(file => {
        const reader = new FileReader();
        reader.onload = function(evt) {
            const li = document.createElement('li');
            li.className = 'tracing-image-item is-new';
            li.innerHTML = `
                <input type="hidden" name="image_id[]" value="tracing_${Date.now()}_${Math.floor(Math.random()*1000)}">
                <input type="hidden" name="image_existing[]" value="">
                <img src="${evt.target.result}" class="tracing-image-thumb" alt="tracing-image">
                <div class="worksheet-preview">
                    <img src="${evt.target.result}" alt="worksheet-preview">
                    <p>Printable vertical worksheet preview</p>
                </div>
                <div class="tracing-image-actions">
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, -1)">↑</button>
                    <button type="button" class="tracing-btn tracing-btn-move" onclick="moveImage(this, 1)">↓</button>
                    <button type="button" class="tracing-btn tracing-btn-remove" onclick="removeImage(this)">✖ Remove</button>
                </div>
            `;
            list.appendChild(li);
            syncEmptyState();
        };
        reader.readAsDataURL(file);
    });
    // Mantener el valor del input para que los archivos sí se envíen al guardar.
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

syncEmptyState();
renderPalettePreview();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Coloring Page Editor', 'fas fa-palette', $content);

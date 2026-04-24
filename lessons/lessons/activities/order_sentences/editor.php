
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access
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
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function os_default_title(): string { return 'Order the Sentences'; }

function os_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string) ($row['unit_id'] ?? '') : '';
}

function os_normalize(mixed $rawData): array
{
    $default = [
        'title'        => os_default_title(),
        'instructions' => 'Listen and put the sentences in the correct order.',
        'media_type'   => 'tts',
        'media_url'    => '',
        'tts_text'     => '',
        'sentences'    => [],
    ];

    if ($rawData === null || $rawData === '') return $default;
    $d = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($d)) return $default;

    $sentences = [];
    foreach ((array) ($d['sentences'] ?? []) as $s) {
        $text = trim((string) ($s['text'] ?? ''));
        $image = isset($s['image']) ? trim((string) $s['image']) : '';
        $display = isset($s['display']) ? $s['display'] : 'text';
        if ($text === '' && $image === '') continue;
        $sentences[] = [
            'id'   => trim((string) ($s['id'] ?? uniqid('os_'))),
            'text' => $text,
            'image' => $image,
            'display' => $display,
        ];
    }

    return [
        'title'        => trim((string) ($d['title'] ?? '')) ?: os_default_title(),
        'instructions' => trim((string) ($d['instructions'] ?? '')) ?: $default['instructions'],
        'media_type'   => in_array($d['media_type'] ?? '', ['tts', 'video', 'audio', 'none'], true)
                            ? $d['media_type'] : 'tts',
        'media_url'    => trim((string) ($d['media_url'] ?? '')),
        'tts_text'     => trim((string) ($d['tts_text'] ?? '')),
        'sentences'    => $sentences,
    ];
}

function os_encode(array $p): string
{
    return json_encode([
        'title'        => $p['title'],
        'instructions' => $p['instructions'],
        'media_type'   => $p['media_type'],
        'media_url'    => $p['media_url'],
        'tts_text'     => $p['tts_text'],
        'sentences'    => array_map(function($s) {
            return [
                'id'    => $s['id'],
                'text'  => $s['text'],
                'image' => isset($s['image']) ? $s['image'] : '',
                'display' => isset($s['display']) ? $s['display'] : 'text',
            ];
        }, array_values($p['sentences'])),
    ], JSON_UNESCAPED_UNICODE);
}

function os_load(PDO $pdo, string $unit, string $activityId): array
{
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='order_sentences' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:u AND type='order_sentences' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['u' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return ['id' => '', ...os_normalize(null)];
    $p = os_normalize($row['data'] ?? null);
    $p['id'] = (string) ($row['id'] ?? '');
    return $p;
}

function os_save(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json     = os_encode($payload);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id=:u AND type='order_sentences' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['u' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $pdo->prepare("UPDATE activities SET data=:data WHERE id=:id AND type='order_sentences'")
            ->execute(['data' => $json, 'id' => $targetId]);
        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:uid, 'order_sentences', :data,
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:uid2),
            CURRENT_TIMESTAMP)
        RETURNING id");
    $stmt->execute(['uid' => $unit, 'uid2' => $unit, 'data' => $json]);
    return (string) $stmt->fetchColumn();
}

// Bootstrap
if ($unit === '' && $activityId !== '') {
    $unit = os_resolve_unit($pdo, $activityId);
}
if ($unit === '') die('Unit not specified');

$activity = os_load($pdo, $unit, $activityId);
if ($activityId === '' && $activity['id'] !== '') $activityId = $activity['id'];

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaType = $_POST['media_type'] ?? 'tts';
    if (!in_array($mediaType, ['tts', 'video', 'audio', 'none'], true)) $mediaType = 'tts';
    $currentMediaType = trim((string) ($_POST['current_media_type'] ?? ''));
    $currentMediaUrl  = trim((string) ($_POST['current_media_url'] ?? ''));
    $currentVideoUrl  = trim((string) ($_POST['current_video_url'] ?? ''));
    $currentAudioUrl  = trim((string) ($_POST['current_audio_url'] ?? ''));

    if ($currentVideoUrl === '' && $currentMediaType === 'video' && $currentMediaUrl !== '') {
        $currentVideoUrl = $currentMediaUrl;
    }
    if ($currentAudioUrl === '' && $currentMediaType === 'audio' && $currentMediaUrl !== '') {
        $currentAudioUrl = $currentMediaUrl;
    }

    if ($mediaType === 'video') {
        $mediaUrl = trim((string) ($_POST['video_url'] ?? ''));
    } elseif ($mediaType === 'audio') {
        $mediaUrl = trim((string) ($_POST['audio_url'] ?? ''));
    } else {
        $mediaUrl = '';
    }

    $hasNewUpload = false;
    if (isset($_FILES['media_file']) && !empty($_FILES['media_file']['name'])
        && ($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $hasNewUpload = true;
        if ($mediaType === 'video') {
            $uploaded = upload_video_to_cloudinary($_FILES['media_file']['tmp_name']);
        } else {
            $uploaded = upload_audio_to_cloudinary($_FILES['media_file']['tmp_name']);
        }
        if ($uploaded) $mediaUrl = $uploaded;
    }

    if (in_array($mediaType, ['video', 'audio'], true)
        && $mediaUrl === ''
        && !$hasNewUpload) {
        if ($mediaType === 'video' && $currentVideoUrl !== '') {
            $mediaUrl = $currentVideoUrl;
        } elseif ($mediaType === 'audio' && $currentAudioUrl !== '') {
            $mediaUrl = $currentAudioUrl;
        } elseif ($currentMediaUrl !== '' && $currentMediaType === $mediaType) {
            $mediaUrl = $currentMediaUrl;
        }
    }

    $rawTexts = isset($_POST['sentence_text']) && is_array($_POST['sentence_text'])
                ? $_POST['sentence_text'] : [];
    $rawIds   = isset($_POST['sentence_id']) && is_array($_POST['sentence_id'])
                ? $_POST['sentence_id'] : [];
    // Capture display selection for each sentence ("text" or "image")
    $rawDisplays = isset($_POST['sentence_display']) && is_array($_POST['sentence_display'])
                ? $_POST['sentence_display'] : [];
    $sentences = [];
    $imageUploads = isset($_FILES['sentence_image_upload']) ? $_FILES['sentence_image_upload'] : null;
    foreach ($rawTexts as $i => $text) {
        $text = trim((string) $text);
        $id = trim((string) ($rawIds[$i] ?? '')) ?: uniqid('os_');
        // Determine whether this sentence should display as text or image
        $display = isset($rawDisplays[$i]) && $rawDisplays[$i] === 'image' ? 'image' : 'text';
        $uploadedImg = '';
        if ($imageUploads && isset($imageUploads['tmp_name'][$i]) && $imageUploads['error'][$i] === UPLOAD_ERR_OK && !empty($imageUploads['name'][$i])) {
            $uploadedImg = upload_to_cloudinary($imageUploads['tmp_name'][$i]);
        }
        $finalImg = $uploadedImg;
        // Skip empty sentences unless there is an uploaded image
        if ($text === '' && $finalImg === '') continue;
        $sentences[] = [
            'id'      => $id,
            'text'    => $text,
            'image'   => $finalImg,
            'display' => $display,
        ];
    }

    $payload = [
        'title'        => trim((string) ($_POST['activity_title'] ?? '')) ?: os_default_title(),
        'instructions' => trim((string) ($_POST['instructions'] ?? '')) ?: 'Listen and put the sentences in the correct order.',
        'media_type'   => $mediaType,
        'media_url'    => $mediaUrl,
        'tts_text'     => trim((string) ($_POST['tts_text'] ?? '')),
        'sentences'    => $sentences,
    ];

    $savedId = os_save($pdo, $unit, $activityId, $payload);
    $params  = ['unit=' . urlencode($unit), 'saved=1'];
    if ($savedId !== '')    $params[] = 'id=' . urlencode($savedId);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source !== '')     $params[] = 'source=' . urlencode($source);
    header('Location: editor.php?' . implode('&', $params));
    exit;
}

// Render
ob_start();
if (isset($_GET['saved'])) {
    echo '<p style="color:#15803d;font-weight:700;margin-bottom:14px;">✔ Saved successfully</p>';
}

$d = $activity;
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

.os-form{max-width:860px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif;}
.os-card{
    background:#f9fafb;padding:16px;margin-bottom:14px;
    border-radius:14px;border:1px solid #e5e7eb;
}
.os-card label{display:block;font-weight:700;margin-bottom:6px;color:#1e293b;}
.os-card input[type=text],
.os-card textarea,
.os-card select{
    width:100%;padding:10px 12px;border-radius:8px;
    border:1px solid #d1d5db;box-sizing:border-box;
    margin-bottom:10px;font-size:14px;font-family:inherit;
}
.os-card textarea{min-height:72px;resize:vertical;}
.os-sentence-item{
    display:flex;align-items:center;gap:8px;
    background:#fff;border:1px solid #e2e8f0;
    border-radius:10px;padding:8px 10px;margin-bottom:8px;
    cursor:grab;
}
.os-sentence-item .handle{color:#94a3b8;font-size:18px;cursor:grab;}
.os-sentence-item input{
    flex:1;border:none;outline:none;font-size:14px;
    font-family:inherit;background:transparent;padding:2px 4px;
}
.os-sentence-item input[type=hidden]{display:none;}
.btn-remove-s{
    background:#ef4444;color:#fff;border:none;
    padding:5px 10px;border-radius:7px;cursor:pointer;font-weight:700;font-size:12px;
}
.btn-add-s{
    background:#2563eb;color:#fff;border:none;
    padding:9px 14px;border-radius:8px;cursor:pointer;font-weight:700;
}
.os-save-btn{
    background:linear-gradient(180deg,#0d9488,#0f766e);color:#fff;
    padding:10px 22px;border:none;border-radius:10px;cursor:pointer;
    font-weight:800;font-size:15px;font-family:inherit;
    box-shadow:0 2px 8px rgba(13,148,136,.22);
    transition:transform .15s ease,filter .15s ease;
}
.os-save-btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
.toolbar-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:10px;}
.help-text{color:#6b7280;font-size:12px;margin:-4px 0 8px;}
.media-section{display:none;}
.media-section.active{display:block;}
/* Style for sentence display selector and child-friendly image mode */
.os-display-select{
    padding:4px 8px;
    border-radius:6px;
    border:1px solid #cbd5e1;
    background:#f1f5f9;
    font-size:13px;
    color:#1e293b;
}
/* When the sentence is set to image display, make the background more playful */
.os-sentence-item.image-mode{
    background:#fff7ed;
    border-color:#fdba74;
}
</style>

<form method="post" enctype="multipart/form-data" class="os-form" id="osSentencesForm">
    <input type="hidden" name="current_media_type" value="<?= htmlspecialchars($d['media_type'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="current_media_url" value="<?= htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="current_video_url" id="os_current_video_url" value="<?= htmlspecialchars($d['media_type']==='video' ? $d['media_url'] : '', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="current_audio_url" id="os_current_audio_url" value="<?= htmlspecialchars($d['media_type']==='audio' ? $d['media_url'] : '', ENT_QUOTES, 'UTF-8') ?>">

    <!-- Title -->
    <div class="os-card">
        <label for="os_title">Activity title</label>
        <input id="os_title" type="text" name="activity_title"
               value="<?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Order the Sentences" required>

        <label for="os_instructions">Instructions for students</label>
        <textarea name="instructions" id="os_instructions"
                  placeholder="Listen and put the sentences in the correct order."
        ><?= htmlspecialchars($d['instructions'], ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <!-- Media -->
    <div class="os-card">
        <label>Media type</label>
        <select name="media_type" id="os_media_type" onchange="toggleMedia(this.value)">
            <option value="tts"   <?= $d['media_type']==='tts'   ? 'selected' : '' ?>>🔊 Text-to-Speech (TTS)</option>
            <option value="video" <?= $d['media_type']==='video' ? 'selected' : '' ?>>🎬 Video</option>
            <option value="audio" <?= $d['media_type']==='audio' ? 'selected' : '' ?>>🎵 Audio file</option>
            <option value="none"  <?= $d['media_type']==='none'  ? 'selected' : '' ?>>— No media</option>
        </select>

        <!-- TTS -->
        <div id="ms-tts" class="media-section <?= $d['media_type']==='tts' ? 'active' : '' ?>">
            <label for="os_tts">Text to read aloud</label>
            <textarea name="tts_text" id="os_tts"
                      placeholder="Paste the text or song lyrics that students listen to..."
            ><?= htmlspecialchars($d['tts_text'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <p class="help-text">Leave blank to use the sentence list itself as the audio script.</p>
        </div>

        <!-- Video -->
        <div id="ms-video" class="media-section <?= $d['media_type']==='video' ? 'active' : '' ?>">
            <label>Video URL or upload</label>
            <input type="text" name="video_url"
                   value="<?= $d['media_type']==='video' ? htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                   placeholder="https://... (YouTube embed, Cloudinary, etc.)">
            <p class="help-text">Or upload a video file:</p>
            <input type="file" name="media_file" accept="video/*">
            <?php if ($d['media_url'] !== '' && $d['media_type'] === 'video'): ?>
                <p class="help-text">Current: <a href="<?= htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">View</a></p>
            <?php endif; ?>
        </div>

        <!-- Audio -->
        <div id="ms-audio" class="media-section <?= $d['media_type']==='audio' ? 'active' : '' ?>">
            <label>Audio URL or upload</label>
            <input type="text" name="audio_url"
                   value="<?= $d['media_type']==='audio' ? htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                   placeholder="https://...">
            <p class="help-text">Or upload an audio file:</p>
            <input type="file" name="media_file" accept="audio/*">
            <?php if ($d['media_url'] !== '' && $d['media_type'] === 'audio'): ?>
                <p class="help-text">Current: <a href="<?= htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">Listen</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sentences -->
    <div class="os-card">
        <label>Sentences — enter them in the <strong>correct order</strong></label>
        <p class="help-text">Students will see them shuffled and must drag them back into this order.</p>

        <div id="os-sentences-list">
            <?php foreach ($d['sentences'] as $idx => $s): ?>
            <div class="os-sentence-item<?= ($s['display'] === 'image') ? ' image-mode' : '' ?>" draggable="true">
                <span class="handle">☰</span>
                <span style="color:#94a3b8;font-size:13px;min-width:22px;"><?= $idx + 1 ?>.</span>
                <input type="hidden" name="sentence_id[]"   value="<?= htmlspecialchars($s['id'],   ENT_QUOTES, 'UTF-8') ?>">
                <input type="text"   name="sentence_text[]" value="<?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Type sentence…">
                <input type="file"   name="sentence_image_upload[]" accept="image/*" style="max-width:140px;">
                <?php if (!empty($s['image'])): ?>
                  <a href="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="margin-left:4px;">🖼️</a>
                <?php endif; ?>
                <!-- Display mode selector: Text or Image -->
                <select name="sentence_display[]" class="os-display-select" onchange="handleDisplaySelectChange(event)">
                    <option value="text" <?= $s['display'] === 'image' ? '' : 'selected' ?>>Text</option>
                    <option value="image" <?= $s['display'] === 'image' ? 'selected' : '' ?>>Image</option>
                </select>
                <button type="button" class="btn-remove-s" onclick="removeSentence(this)">✖</button>
            </div>
            <?php endforeach; ?>

        <div class="toolbar-row" style="justify-content:flex-start;margin-top:6px;">
            <button type="button" class="btn-add-s" onclick="addSentence()">+ Add Sentence</button>
        </div>
    </div>

    <div class="toolbar-row">
        <button type="submit" class="os-save-btn">💾 Save</button>
    </div>
</form>

<script>
function toggleMedia(val) {
    ['tts','video','audio'].forEach(function(id) {
        var el = document.getElementById('ms-' + id);
        if (el) el.classList.toggle('active', id === val);
    });
}

function reindexSentences() {
    var items = document.querySelectorAll('#os-sentences-list .os-sentence-item');
    items.forEach(function(item, i) {
        var numEl = item.querySelector('span[style]');
        if (numEl) numEl.textContent = (i + 1) + '.';
    });
}

function addSentence() {
    var list = document.getElementById('os-sentences-list');
    var idx  = list.children.length;
    var div  = document.createElement('div');
    // New sentences default to text display
    div.className = 'os-sentence-item';
    div.draggable = true;
    div.innerHTML =
        '<span class="handle">☰</span>' +
        '<span style="color:#94a3b8;font-size:13px;min-width:22px;">' + (idx + 1) + '.</span>' +
        '<input type="hidden" name="sentence_id[]" value="os_' + Date.now() + '">' +
        '<input type="text" name="sentence_text[]" placeholder="Type sentence…">' +
        '<input type="file" name="sentence_image_upload[]" accept="image/*" style="max-width:140px;">' +
        '<select name="sentence_display[]" class="os-display-select" onchange="handleDisplaySelectChange(event)"><option value="text" selected>Text</option><option value="image">Image</option></select>' +
        '<button type="button" class="btn-remove-s" onclick="removeSentence(this)">✖</button>';
    list.appendChild(div);
    attachDrag(div);
    div.querySelector('input[type=text]').focus();
}

function removeSentence(btn) {
    var item = btn.closest('.os-sentence-item');
    if (item) {
        item.remove();
        reindexSentences();
    }
}

var dragSrc = null;

function attachDrag(el) {
    el.addEventListener('dragstart', function(e) {
        dragSrc = el;
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(function(){ el.style.opacity = '0.4'; }, 0);
    });
    el.addEventListener('dragend', function() {
        el.style.opacity = '1';
        reindexSentences();
    });
    el.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var list = document.getElementById('os-sentences-list');
        var after = getDragAfter(list, e.clientY);
        if (after == null) {
            list.appendChild(dragSrc);
        } else {
            list.insertBefore(dragSrc, after);
        }
    });
}

function getDragAfter(container, y) {
    var items = Array.from(container.querySelectorAll('.os-sentence-item:not([style*="opacity: 0.4"])'));
    return items.reduce(function(closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        }
        return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

document.querySelectorAll('#os-sentences-list .os-sentence-item').forEach(attachDrag);

// Handle changes in the display mode selector (text vs image)
function handleDisplaySelectChange(e) {
    var item = e.target.closest('.os-sentence-item');
    if (!item) return;
    if (e.target.value === 'image') {
        item.classList.add('image-mode');
    } else {
        item.classList.remove('image-mode');
    }
}

// Bind change events to existing display selectors and set initial state
document.querySelectorAll('.os-display-select').forEach(function(el) {
    el.addEventListener('change', handleDisplaySelectChange);
    if (el.value === 'image') {
        var item = el.closest('.os-sentence-item');
        if (item) item.classList.add('image-mode');
    }
});

function syncMediaCaches() {
    var videoInput = document.querySelector('input[name="video_url"]');
    var audioInput = document.querySelector('input[name="audio_url"]');
    var videoCache = document.getElementById('os_current_video_url');
    var audioCache = document.getElementById('os_current_audio_url');

    if (videoInput && videoCache && videoInput.value.trim() !== '') {
        videoCache.value = videoInput.value.trim();
    }
    if (audioInput && audioCache && audioInput.value.trim() !== '') {
        audioCache.value = audioInput.value.trim();
    }
}

var _osVideoInput = document.querySelector('input[name="video_url"]');
var _osAudioInput = document.querySelector('input[name="audio_url"]');
if (_osVideoInput) _osVideoInput.addEventListener('input', syncMediaCaches);
if (_osAudioInput) _osAudioInput.addEventListener('input', syncMediaCaches);

document.getElementById('osSentencesForm').addEventListener('submit', function () {
    syncMediaCaches();
    document.querySelectorAll('.media-section:not(.active) input, .media-section:not(.active) textarea').forEach(function (el) {
        el.disabled = true;
    });
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Order the Sentences – Editor', '📝', $content);
?>

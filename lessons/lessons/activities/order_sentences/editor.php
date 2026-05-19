<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
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
        'voice_id'     => 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> '',
        'sentences'    => [],
    ];

    if ($rawData === null || $rawData === '') return $default;
    $d = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($d)) return $default;

    $sentences = [];
    foreach ((array) ($d['sentences'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $text    = trim((string) ($s['text']    ?? ''));
        $image   = trim((string) ($s['image']   ?? ''));
        $display = trim((string) ($s['display'] ?? 'both'));
        if (!in_array($display, ['text', 'image', 'both'], true)) $display = 'both';
        if ($text === '' && $image === '') continue;
        $sentences[] = [
            'id'      => trim((string) ($s['id'] ?? uniqid('os_'))),
            'text'    => $text,
            'image'   => $image,
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
        'voice_id'     => trim((string) ($d['voice_id'] ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> trim((string) ($d['tts_audio_url'] ?? '')),
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
        'voice_id'     => $p['voice_id'],
        'tts_audio_url'=> $p['tts_audio_url'],
        'sentences'    => array_map(function ($s) {
            return [
                'id'      => $s['id'],
                'text'    => $s['text'],
                'image'   => $s['image'] ?? '',
                'display' => $s['display'] ?? 'both',
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

if ($unit === '' && $activityId !== '') {
    $unit = os_resolve_unit($pdo, $activityId);
}
if ($unit === '') die('Unit not specified');

$activity = os_load($pdo, $unit, $activityId);
if ($activityId === '' && $activity['id'] !== '') $activityId = $activity['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaType = $_POST['media_type'] ?? 'tts';
    if (!in_array($mediaType, ['tts', 'video', 'audio', 'none'], true)) $mediaType = 'tts';
    $currentMediaType = trim((string) ($_POST['current_media_type'] ?? ''));
    $currentMediaUrl  = trim((string) ($_POST['current_media_url']  ?? ''));
    $currentVideoUrl  = trim((string) ($_POST['current_video_url']  ?? ''));
    $currentAudioUrl  = trim((string) ($_POST['current_audio_url']  ?? ''));

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

    if (in_array($mediaType, ['video', 'audio'], true) && $mediaUrl === '' && !$hasNewUpload) {
        if ($mediaType === 'video' && $currentVideoUrl !== '') {
            $mediaUrl = $currentVideoUrl;
        } elseif ($mediaType === 'audio' && $currentAudioUrl !== '') {
            $mediaUrl = $currentAudioUrl;
        } elseif ($currentMediaUrl !== '' && $currentMediaType === $mediaType) {
            $mediaUrl = $currentMediaUrl;
        }
    }

    $rawTexts    = isset($_POST['sentence_text'])           && is_array($_POST['sentence_text'])           ? $_POST['sentence_text']           : [];
    $rawIds      = isset($_POST['sentence_id'])             && is_array($_POST['sentence_id'])             ? $_POST['sentence_id']             : [];
    $rawImages   = isset($_POST['sentence_image_existing']) && is_array($_POST['sentence_image_existing']) ? $_POST['sentence_image_existing'] : [];
    $rawDisplays = isset($_POST['sentence_display'])        && is_array($_POST['sentence_display'])        ? $_POST['sentence_display']        : [];
    $sentences   = [];
    $imageFiles  = isset($_FILES['sentence_image']) ? $_FILES['sentence_image'] : null;

    foreach ($rawTexts as $i => $text) {
        $text    = trim((string) $text);
        $id      = trim((string) ($rawIds[$i] ?? '')) ?: uniqid('os_');
        $image   = isset($rawImages[$i]) ? trim((string) $rawImages[$i]) : '';
        $display = isset($rawDisplays[$i]) ? trim((string) $rawDisplays[$i]) : 'both';
        if (!in_array($display, ['text', 'image', 'both'], true)) $display = 'both';

        if ($imageFiles && isset($imageFiles['name'][$i]) && $imageFiles['name'][$i] !== ''
            && isset($imageFiles['tmp_name'][$i]) && $imageFiles['tmp_name'][$i] !== '') {
            $uploaded = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($uploaded) $image = $uploaded;
        }
        if ($text === '' && $image === '') continue;
        $sentences[] = [
            'id'      => $id,
            'text'    => $text,
            'image'   => $image,
            'display' => $display,
        ];
    }

    $payload = [
        'title'        => trim((string) ($_POST['activity_title'] ?? '')) ?: os_default_title(),
        'instructions' => trim((string) ($_POST['instructions']   ?? '')) ?: 'Listen and put the sentences in the correct order.',
        'media_type'   => $mediaType,
        'media_url'    => $mediaUrl,
        'tts_text'     => trim((string) ($_POST['tts_text'] ?? '')),
        'voice_id'     => (function() {
            $allowedVoices = ['nzFihrBIvB34imQBuxub', 'NoOVOzCQFLOvtsMoNcdT', 'Nggzl2QAXh3OijoXD116'];
            $v = trim((string) ($_POST['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));
            return in_array($v, $allowedVoices, true) ? $v : 'nzFihrBIvB34imQBuxub';
        })(),
        'tts_audio_url'=> trim((string) ($_POST['tts_audio_url'] ?? '')),
        'sentences'    => $sentences,
    ];

    $savedId = os_save($pdo, $unit, $activityId, $payload);
    $params  = ['unit=' . urlencode($unit), 'saved=1'];
    if ($savedId !== '')    $params[] = 'id='         . urlencode($savedId);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source !== '')     $params[] = 'source='     . urlencode($source);
    header('Location: editor.php?' . implode('&', $params));
    exit;
}

ob_start();
if (isset($_GET['saved'])) {
    echo '<div class="os-saved-banner">✔ Saved successfully</div>';
}

$d = $activity;
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

<style>
/* ══════════════════════════════
   CSS VARIABLES — TEAL PALETTE
   ══════════════════════════════ */
:root {
    --teal-50:  #E1F5EE;
    --teal-100: #9FE1CB;
    --teal-200: #5DCAA5;
    --teal-400: #1D9E75;
    --teal-600: #0F6E56;
    --teal-800: #085041;
    --teal-900: #04342C;
    --purple:   #7F77DD;
    --purple-d: #534AB7;
    --red:      #dc2626;
    --green:    #16a34a;
    --radius:   10px;
    --radius-lg:14px;
    --shadow:   0 2px 12px rgba(4,52,44,.10);
}

/* ══════════════════════════════
   LAYOUT
   ══════════════════════════════ */
.os-editor {
    max-width: 820px;
    margin: 0 auto;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    padding-bottom: 40px;
}

.os-saved-banner {
    background: var(--teal-50);
    border: 1.5px solid var(--teal-200);
    color: var(--teal-600);
    font-weight: 800;
    font-size: 14px;
    padding: 10px 16px;
    border-radius: var(--radius);
    margin-bottom: 16px;
    font-family: 'Nunito', sans-serif;
}

/* ══════════════════════════════
   SECTION CARDS
   ══════════════════════════════ */
.os-section {
    background: #fff;
    border: 1px solid var(--teal-100);
    border-radius: var(--radius-lg);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: var(--shadow);
}

.os-section-header {
    background: var(--teal-50);
    border-bottom: 1px solid var(--teal-100);
    padding: 10px 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.os-section-header .os-section-icon {
    font-size: 16px;
}

.os-section-header h3 {
    font-family: 'Fredoka', sans-serif;
    font-size: 16px;
    font-weight: 600;
    color: var(--teal-800);
    margin: 0;
}

.os-section-body {
    padding: 16px 18px;
}

/* ══════════════════════════════
   FORM ELEMENTS
   ══════════════════════════════ */
.os-field {
    margin-bottom: 14px;
}

.os-field:last-child {
    margin-bottom: 0;
}

.os-label {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: var(--teal-600);
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 5px;
    font-family: 'Nunito', sans-serif;
}

.os-input,
.os-textarea,
.os-select {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid var(--teal-100);
    border-radius: var(--radius);
    font-size: 14px;
    font-family: 'Nunito', sans-serif;
    font-weight: 600;
    color: #1e293b;
    background: #fff;
    box-sizing: border-box;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}

.os-input:focus,
.os-textarea:focus,
.os-select:focus {
    border-color: var(--teal-400);
    box-shadow: 0 0 0 3px rgba(29,158,117,.12);
}

.os-textarea {
    min-height: 76px;
    resize: vertical;
}

.os-select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%230F6E56' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
}

.os-help {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
    margin-top: 4px;
}

/* ══════════════════════════════
   MEDIA SECTIONS
   ══════════════════════════════ */
.os-media-panel {
    display: none;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--teal-100);
}

.os-media-panel.active {
    display: block;
}

/* ══════════════════════════════
   SENTENCE ITEMS
   ══════════════════════════════ */
#os-sentences-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.os-sentence-item {
    background: #f9fffe;
    border: 1.5px solid var(--teal-100);
    border-radius: var(--radius);
    padding: 10px 12px;
    cursor: grab;
    transition: border-color .15s, box-shadow .15s;
}

.os-sentence-item:hover {
    border-color: var(--teal-200);
    box-shadow: 0 2px 8px rgba(29,158,117,.10);
}

.os-sentence-item.dragging-over {
    border-color: var(--teal-400);
    box-shadow: 0 0 0 2px rgba(29,158,117,.18);
}

.os-sentence-top {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.os-handle {
    color: var(--teal-200);
    font-size: 18px;
    cursor: grab;
    flex-shrink: 0;
    line-height: 1;
}

.os-num {
    font-size: 13px;
    font-weight: 800;
    color: var(--teal-400);
    min-width: 22px;
    flex-shrink: 0;
    font-family: 'Nunito', sans-serif;
}

.os-sentence-top .os-input {
    flex: 1;
    min-width: 160px;
    margin-bottom: 0;
}

.os-display-select {
    width: auto;
    min-width: 130px;
    flex-shrink: 0;
}

.os-btn-remove {
    background: #fee2e2;
    color: var(--red);
    border: 1px solid #fca5a5;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    flex-shrink: 0;
    transition: background .12s;
}

.os-btn-remove:hover {
    background: #fecaca;
}

.os-sentence-image-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed var(--teal-100);
    flex-wrap: wrap;
}

.os-sentence-image-row .os-label {
    margin-bottom: 0;
    font-size: 11px;
    flex-shrink: 0;
}

.os-thumb {
    width: 52px;
    height: 52px;
    border-radius: 8px;
    object-fit: contain;
    border: 1.5px solid var(--teal-100);
    background: var(--teal-50);
}

/* ══════════════════════════════
   TOOLBAR
   ══════════════════════════════ */
.os-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 12px;
}

.os-btn-add {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--teal-400);
    color: #fff;
    border: none;
    border-radius: var(--radius);
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    transition: background .15s, transform .12s;
}

.os-btn-add:hover {
    background: var(--teal-600);
    transform: translateY(-1px);
}

.os-btn-save {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--teal-800);
    color: #fff;
    border: none;
    border-radius: var(--radius-lg);
    padding: 12px 28px;
    font-size: 15px;
    font-weight: 800;
    font-family: 'Fredoka', sans-serif;
    letter-spacing: .02em;
    cursor: pointer;
    transition: background .15s, transform .15s;
    box-shadow: 0 4px 14px rgba(8,80,65,.25);
}

.os-btn-save:hover {
    background: var(--teal-900);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(8,80,65,.30);
}

.os-save-row {
    display: flex;
    justify-content: center;
    margin-top: 8px;
}
</style>

<form method="post" enctype="multipart/form-data" class="os-editor" id="osSentencesForm">
    <input type="hidden" name="current_media_type" value="<?= htmlspecialchars($d['media_type'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="current_media_url"  value="<?= htmlspecialchars($d['media_url'],   ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="current_video_url"  id="os_current_video_url" value="<?= htmlspecialchars($d['media_type']==='video' ? $d['media_url'] : '', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="current_audio_url"  id="os_current_audio_url" value="<?= htmlspecialchars($d['media_type']==='audio' ? $d['media_url'] : '', ENT_QUOTES, 'UTF-8') ?>">

    <!-- ── SECTION 1: Title & Instructions ── -->
    <div class="os-section">
        <div class="os-section-header">
            <span class="os-section-icon">📝</span>
            <h3>Activity info</h3>
        </div>
        <div class="os-section-body">
            <div class="os-field">
                <label class="os-label" for="os_title">Activity title</label>
                <input id="os_title" class="os-input" type="text" name="activity_title"
                       value="<?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Order the Sentences" required>
            </div>
            <div class="os-field">
                <label class="os-label" for="os_instructions">Instructions for students</label>
                <textarea class="os-textarea" name="instructions" id="os_instructions"
                          placeholder="Listen and put the sentences in the correct order."
                ><?= htmlspecialchars($d['instructions'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── SECTION 2: Media ── -->
    <div class="os-section">
        <div class="os-section-header">
            <span class="os-section-icon">🎬</span>
            <h3>Media</h3>
        </div>
        <div class="os-section-body">
            <div class="os-field">
                <label class="os-label" for="os_media_type">Media type</label>
                <select class="os-select" name="media_type" id="os_media_type" onchange="toggleMedia(this.value)">
                    <option value="tts"   <?= $d['media_type']==='tts'   ? 'selected' : '' ?>>🔊 Text-to-Speech (TTS)</option>
                    <option value="video" <?= $d['media_type']==='video' ? 'selected' : '' ?>>🎬 Video</option>
                    <option value="audio" <?= $d['media_type']==='audio' ? 'selected' : '' ?>>🎵 Audio file</option>
                    <option value="none"  <?= $d['media_type']==='none'  ? 'selected' : '' ?>>— No media</option>
                </select>
            </div>

            <!-- TTS -->
            <div id="ms-tts" class="os-media-panel <?= $d['media_type']==='tts' ? 'active' : '' ?>">
                <div class="os-field">
                    <label class="os-label" for="os_tts">Text to read aloud</label>
                    <textarea class="os-textarea" name="tts_text" id="os_tts"
                              placeholder="Paste the text or song lyrics that students listen to..."
                    ><?= htmlspecialchars($d['tts_text'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="os-help">Leave blank to use the sentence list itself as the audio script.</p>
                </div>
                <div class="os-field" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:4px">
                    <div style="flex:0 0 auto">
                        <label class="os-label">Voice</label>
                        <select name="voice_id" class="os-select js-os-voiceid" style="min-width:210px">
                            <option value="nzFihrBIvB34imQBuxub"<?= ($d['voice_id'] ?? 'nzFihrBIvB34imQBuxub') === 'nzFihrBIvB34imQBuxub' ? ' selected' : '' ?>>👨 Adult Male (Josh)</option>
                            <option value="NoOVOzCQFLOvtsMoNcdT"<?= ($d['voice_id'] ?? '') === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : '' ?>>👩 Adult Female (Lily)</option>
                            <option value="Nggzl2QAXh3OijoXD116"<?= ($d['voice_id'] ?? '') === 'Nggzl2QAXh3OijoXD116' ? ' selected' : '' ?>>🧒 Child (Candy)</option>
                        </select>
                    </div>
                    <button type="button" class="js-os-generate-tts" style="background:#1E9A7A;color:#fff;border:none;border-radius:999px;padding:11px 18px;font-size:12px;font-weight:900;cursor:pointer;white-space:nowrap;flex-shrink:0">🔊 Generate audio</button>
                    <input type="hidden" name="tts_audio_url" class="js-os-audiourl" value="<?= htmlspecialchars($d['tts_audio_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="js-os-tts-status" style="font-size:12px;font-weight:800;margin-top:6px;min-height:18px"></div>
                <?php if (!empty($d['tts_audio_url'])): ?>
                <div class="js-os-tts-preview" style="margin-top:10px;display:flex;align-items:center;gap:10px">
                    <audio src="<?= htmlspecialchars($d['tts_audio_url'], ENT_QUOTES, 'UTF-8') ?>" controls preload="none" style="flex:1;height:36px"></audio>
                    <button type="button" class="js-os-remove-tts" style="background:none;border:none;color:#E24B4A;font-size:11px;font-weight:900;cursor:pointer">✖ Remove</button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Video -->
            <div id="ms-video" class="os-media-panel <?= $d['media_type']==='video' ? 'active' : '' ?>">
                <div class="os-field">
                    <label class="os-label">Video URL</label>
                    <input class="os-input" type="text" name="video_url"
                           value="<?= $d['media_type']==='video' ? htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                           placeholder="https://... (YouTube embed, Cloudinary, etc.)">
                </div>
                <div class="os-field">
                    <label class="os-label">Or upload a video file</label>
                    <input type="file" name="media_file" accept="video/*">
                </div>
                <?php if ($d['media_url'] !== '' && $d['media_type'] === 'video'): ?>
                    <p class="os-help">Current: <a href="<?= htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color:var(--teal-600);">View video</a></p>
                <?php endif; ?>
            </div>

            <!-- Audio -->
            <div id="ms-audio" class="os-media-panel <?= $d['media_type']==='audio' ? 'active' : '' ?>">
                <div class="os-field">
                    <label class="os-label">Audio URL</label>
                    <input class="os-input" type="text" name="audio_url"
                           value="<?= $d['media_type']==='audio' ? htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                           placeholder="https://...">
                </div>
                <div class="os-field">
                    <label class="os-label">Or upload an audio file</label>
                    <input type="file" name="media_file" accept="audio/*">
                </div>
                <?php if ($d['media_url'] !== '' && $d['media_type'] === 'audio'): ?>
                    <p class="os-help">Current: <a href="<?= htmlspecialchars($d['media_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color:var(--teal-600);">Listen</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── SECTION 3: Sentences ── -->
    <div class="os-section">
        <div class="os-section-header">
            <span class="os-section-icon">🗂️</span>
            <h3>Sentences — enter in the correct order</h3>
        </div>
        <div class="os-section-body">
            <p class="os-help" style="margin-bottom:12px;">Students will see them shuffled. Use <strong>Show mode</strong> to control whether they see text, image, or both.</p>

            <div id="os-sentences-list">
                <?php foreach ($d['sentences'] as $idx => $s):
                    $disp = $s['display'] ?? 'both';
                ?>
                <div class="os-sentence-item" draggable="true">
                    <div class="os-sentence-top">
                        <span class="os-handle">☰</span>
                        <span class="os-num"><?= $idx + 1 ?>.</span>
                        <input type="hidden" name="sentence_id[]" value="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <input class="os-input" type="text" name="sentence_text[]"
                               value="<?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Sentence text">
                        <select name="sentence_display[]" class="os-select os-display-select" title="Show mode">
                            <option value="both"  <?= $disp==='both'  ? 'selected' : '' ?>>Text + Image</option>
                            <option value="text"  <?= $disp==='text'  ? 'selected' : '' ?>>Text only</option>
                            <option value="image" <?= $disp==='image' ? 'selected' : '' ?>>Image only</option>
                        </select>
                        <button type="button" class="os-btn-remove" onclick="removeSentence(this)">✖ Remove</button>
                    </div>
                    <div class="os-sentence-image-row">
                        <label class="os-label">Image:</label>
                        <input type="hidden" name="sentence_image_existing[]" value="<?= htmlspecialchars($s['image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="file" name="sentence_image[]" accept="image/*" style="font-size:13px;font-family:'Nunito',sans-serif;">
                        <?php if (!empty($s['image'])): ?>
                            <img class="os-thumb" src="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="os-toolbar">
                <button type="button" class="os-btn-add" onclick="addSentence()">+ Add Sentence</button>
            </div>
        </div>
    </div>

    <!-- ── SAVE ── -->
    <div class="os-save-row">
        <button type="submit" class="os-btn-save">💾 Save Activity</button>
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
    document.querySelectorAll('#os-sentences-list .os-sentence-item').forEach(function(item, i) {
        var numEl = item.querySelector('.os-num');
        if (numEl) numEl.textContent = (i + 1) + '.';
    });
}

function addSentence() {
    var list = document.getElementById('os-sentences-list');
    var idx  = list.children.length;
    var div  = document.createElement('div');
    div.className = 'os-sentence-item';
    div.draggable = true;
    div.innerHTML =
        '<div class="os-sentence-top">' +
            '<span class="os-handle">☰</span>' +
            '<span class="os-num">' + (idx + 1) + '.</span>' +
            '<input type="hidden" name="sentence_id[]" value="os_' + Date.now() + '">' +
            '<input class="os-input" type="text" name="sentence_text[]" placeholder="Sentence text">' +
            '<select name="sentence_display[]" class="os-select os-display-select" title="Show mode">' +
                '<option value="both" selected>Text + Image</option>' +
                '<option value="text">Text only</option>' +
                '<option value="image">Image only</option>' +
            '</select>' +
            '<button type="button" class="os-btn-remove" onclick="removeSentence(this)">✖ Remove</button>' +
        '</div>' +
        '<div class="os-sentence-image-row">' +
            '<label class="os-label">Image:</label>' +
            '<input type="hidden" name="sentence_image_existing[]" value="">' +
            '<input type="file" name="sentence_image[]" accept="image/*" style="font-size:13px;font-family:\'Nunito\',sans-serif;">' +
        '</div>';
    list.appendChild(div);
    attachDrag(div);
    div.querySelector('input[type=text]').focus();
}

function removeSentence(btn) {
    var item = btn.closest('.os-sentence-item');
    if (item) { item.remove(); reindexSentences(); }
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
        if (after == null) { list.appendChild(dragSrc); }
        else { list.insertBefore(dragSrc, after); }
    });
}

function getDragAfter(container, y) {
    var items = Array.from(container.querySelectorAll('.os-sentence-item:not([style*="opacity: 0.4"])'));
    return items.reduce(function(closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) return { offset: offset, element: child };
        return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

document.querySelectorAll('#os-sentences-list .os-sentence-item').forEach(attachDrag);

function syncMediaCaches() {
    var videoInput = document.querySelector('input[name="video_url"]');
    var audioInput = document.querySelector('input[name="audio_url"]');
    var videoCache = document.getElementById('os_current_video_url');
    var audioCache = document.getElementById('os_current_audio_url');
    if (videoInput && videoCache && videoInput.value.trim() !== '') videoCache.value = videoInput.value.trim();
    if (audioInput && audioCache && audioInput.value.trim() !== '') audioCache.value = audioInput.value.trim();
}

var _v = document.querySelector('input[name="video_url"]');
var _a = document.querySelector('input[name="audio_url"]');
if (_v) _v.addEventListener('input', syncMediaCaches);
if (_a) _a.addEventListener('input', syncMediaCaches);

document.getElementById('osSentencesForm').addEventListener('submit', function () {
    var mediaTypeEl = document.getElementById('os-media-type');
    if (mediaTypeEl && mediaTypeEl.value === 'tts') {
        var panel = document.getElementById('ms-tts');
        var textEl = panel ? panel.querySelector('textarea[name="tts_text"]') : null;
        var audioEl = panel ? panel.querySelector('.js-os-audiourl') : null;
        var text = textEl ? textEl.value.trim() : '';
        var audio = audioEl ? String(audioEl.value || '').trim() : '';
        if (text !== '' && audio === '') {
            alert('Generate ElevenLabs audio before saving this TTS activity.');
            if (textEl) textEl.focus();
            return false;
        }
    }
    syncMediaCaches();
    document.querySelectorAll('.os-media-panel:not(.active) input, .os-media-panel:not(.active) textarea').forEach(function (el) {
        el.disabled = true;
    });
});

// ── ElevenLabs TTS for order_sentences ───────────────────────────────────────
(function () {
    var panel = document.getElementById('ms-tts');
    if (!panel) return;

    var generateBtn = panel.querySelector('.js-os-generate-tts');
    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            var textarea = panel.querySelector('textarea[name="tts_text"]');
            var text = textarea ? textarea.value.trim() : '';
            if (!text) { alert('Please enter TTS text first.'); return; }
            var voiceSelect = panel.querySelector('.js-os-voiceid');
            var voiceId = voiceSelect ? voiceSelect.value : 'nzFihrBIvB34imQBuxub';
            var statusEl = panel.querySelector('.js-os-tts-status');
            var audioHidden = panel.querySelector('.js-os-audiourl');

            generateBtn.disabled = true;
            if (statusEl) { statusEl.textContent = 'Generating…'; statusEl.style.color = ''; }

            var fd = new FormData();
            fd.append('text', text);
            fd.append('voice_id', voiceId);

            fetch('tts.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) throw new Error(data.error);
                    if (audioHidden) audioHidden.value = data.url;

                    var old = panel.querySelector('.js-os-tts-preview');
                    if (old) old.remove();

                    var div = document.createElement('div');
                    div.className = 'js-os-tts-preview';
                    div.style.cssText = 'margin-top:10px;display:flex;align-items:center;gap:10px';
                    div.innerHTML = '<audio src="' + data.url + '" controls preload="none" style="flex:1;height:36px"></audio>' +
                        '<button type="button" class="js-os-remove-tts" style="background:none;border:none;color:#E24B4A;font-size:11px;font-weight:900;cursor:pointer">✖ Remove</button>';
                    panel.appendChild(div);

                    if (statusEl) { statusEl.textContent = '✓ Audio generated successfully'; statusEl.style.color = '#1D9E75'; }
                })
                .catch(function (err) {
                    if (statusEl) { statusEl.textContent = '✘ ' + (err.message || 'Generation failed'); statusEl.style.color = '#E24B4A'; }
                })
                .finally(function () { generateBtn.disabled = false; });
        });
    }

    panel.addEventListener('click', function (e) {
        if (e.target && e.target.classList.contains('js-os-remove-tts')) {
            var audioHidden = panel.querySelector('.js-os-audiourl');
            if (audioHidden) audioHidden.value = '';
            var preview = panel.querySelector('.js-os-tts-preview');
            if (preview) preview.remove();
            var statusEl = panel.querySelector('.js-os-tts-status');
            if (statusEl) { statusEl.textContent = 'Audio removed.'; statusEl.style.color = ''; }
        }
    });
}());
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Order the Sentences – Editor', '📝', $content);

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (!empty($_SESSION['student_logged'])) {
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

function fb_ed_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['unit_id'] ?? '';
}

function fb_ed_load(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = array(
        'id'           => '',
        'instructions' => 'Write the missing words in the blanks.',
        'blocks'       => array(),
        'wordbank'     => '',
        'media_type'   => 'none',
        'media_url'    => '',
        'tts_text'     => '',
        'voice_id'     => 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> '',
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id=:id AND type='fillblank' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id=:unit AND type='fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $data = json_decode($row['data'] ?? '', true);

    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = array(array(
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
            'image'   => '',
        ));
    } else {
        $blocks = isset($data['blocks']) ? $data['blocks'] : array();
    }

    return array(
        'id'           => (string)($row['id'] ?? ''),
        'instructions' => isset($data['instructions']) ? $data['instructions'] : $fallback['instructions'],
        'blocks'       => $blocks,
        'wordbank'     => isset($data['wordbank'])   ? $data['wordbank']   : '',
        'media_type'   => isset($data['media_type']) ? $data['media_type'] : 'none',
        'media_url'    => isset($data['media_url'])  ? $data['media_url']  : '',
        'tts_text'     => isset($data['tts_text'])   ? $data['tts_text']   : '',
        'voice_id'     => isset($data['voice_id'])      ? $data['voice_id']      : 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> isset($data['tts_audio_url']) ? $data['tts_audio_url'] : '',
    );
}

function fb_ed_save(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json     = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id=:unit AND type='fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $targetId = trim((string)$stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $pdo->prepare("UPDATE activities SET data=:data WHERE id=:id AND type='fillblank'")
            ->execute(array('data' => $json, 'id' => $targetId));
        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:uid, 'fillblank', :data,
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:uid2),
            CURRENT_TIMESTAMP)
        RETURNING id");
    $stmt->execute(array('uid' => $unit, 'uid2' => $unit, 'data' => $json));
    return (string)$stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') $unit = fb_ed_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../core/cloudinary_upload.php';

    $mediaType = in_array($_POST['media_type'] ?? '', array('tts','audio','none'), true) ? $_POST['media_type'] : 'none';
    $mediaUrl  = trim((string)($_POST['media_url'] ?? ''));
    $ttsText   = trim((string)($_POST['tts_text']  ?? ''));
    $allowedVoices = array('nzFihrBIvB34imQBuxub', 'NoOVOzCQFLOvtsMoNcdT', 'Nggzl2QAXh3OijoXD116');
    $voiceId   = trim((string)($_POST['voice_id']  ?? 'nzFihrBIvB34imQBuxub'));
    if (!in_array($voiceId, $allowedVoices, true)) $voiceId = 'nzFihrBIvB34imQBuxub';
    $ttsAudioUrl = trim((string)($_POST['tts_audio_url'] ?? ''));

    if ($mediaType === 'audio') {
        if (isset($_FILES['media_file'])
            && !empty($_FILES['media_file']['name'])
            && ($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = upload_audio_to_cloudinary($_FILES['media_file']['tmp_name']);
            if ($uploaded) $mediaUrl = $uploaded;
        }
        if ($mediaUrl === '') $mediaUrl = trim((string)($_POST['current_media_url'] ?? ''));
    }

    $blockTexts   = isset($_POST['text'])      && is_array($_POST['text'])      ? $_POST['text']      : array();
    $blockAnswers = isset($_POST['answers'])   && is_array($_POST['answers'])   ? $_POST['answers']   : array();
    $blockImages  = isset($_POST['image_url']) && is_array($_POST['image_url']) ? $_POST['image_url'] : array();
    $imageUploads = isset($_FILES['image_upload']) ? $_FILES['image_upload'] : null;

    $blocks = array();

    foreach ($blockTexts as $i => $text) {
        $text   = trim((string)$text);
        $rawAns = isset($blockAnswers[$i]) ? (string)$blockAnswers[$i] : '';

        /* The editor now uses individual answer input boxes.
           JavaScript joins them with | before submit.
           Keep comma fallback for backward compatibility. */
        if (strpos($rawAns, '|') !== false) {
            $answers = explode('|', $rawAns);
        } else {
            $answers = explode(',', $rawAns);
        }

        $answers = array_values(array_filter(array_map('trim', $answers), 'strlen'));

        $imgUrl = isset($blockImages[$i]) ? trim((string)$blockImages[$i]) : '';

        if ($imageUploads
            && isset($imageUploads['tmp_name'][$i])
            && $imageUploads['error'][$i] === UPLOAD_ERR_OK
            && !empty($imageUploads['name'][$i])) {
            $up = upload_to_cloudinary($imageUploads['tmp_name'][$i]);
            if ($up) $imgUrl = $up;
        }

        $blocks[] = array('text' => $text, 'answers' => $answers, 'image' => $imgUrl);
    }

    $savedId = fb_ed_save($pdo, $unit, $activityId, array(
        'instructions' => trim((string)($_POST['instructions'] ?? '')),
        'wordbank'     => trim((string)($_POST['wordbank']     ?? '')),
        'media_type'   => $mediaType,
        'media_url'    => $mediaUrl,
        'tts_text'     => $ttsText,
        'voice_id'     => $voiceId,
        'tts_audio_url'=> $ttsAudioUrl,
        'blocks'       => $blocks,
    ));

    $params = array('unit='.urlencode($unit), 'saved=1', 'id='.urlencode($savedId));
    if ($assignment !== '') $params[] = 'assignment='.urlencode($assignment);
    if ($source     !== '') $params[] = 'source='.urlencode($source);

    header('Location: editor.php?'.implode('&', $params));
    exit;
}

$activity = fb_ed_load($pdo, $unit, $activityId);
if ($activityId === '' && $activity['id'] !== '') $activityId = $activity['id'];

ob_start();

if (isset($_GET['saved'])) {
    echo '<div class="fb-saved-banner">&#10004; Saved successfully</div>';
}
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --fb-orange: #F97316;
    --fb-orange-dark: #C2580A;
    --fb-orange-soft: #FFF0E6;
    --fb-purple: #7F77DD;
    --fb-purple-dark: #534AB7;
    --fb-purple-soft: #EEEDFE;
    --fb-white: #FFFFFF;
    --fb-lila-border: #EDE9FA;
    --fb-muted: #9B94BE;
    --fb-ink: #271B5D;
    --fb-red: #dc2626;
    --fb-green: #16a34a;
}

.fb-editor {
    max-width: 860px;
    margin: 0 auto;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    padding-bottom: 40px;
}

.fb-saved-banner {
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-weight: 900;
    font-size: 14px;
    padding: 10px 16px;
    border-radius: 14px;
    margin-bottom: 16px;
}

.fb-section {
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 24px;
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
}

.fb-section-header {
    background: #ffffff;
    border-bottom: 1px solid #F0EEF8;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.fb-section-header h3 {
    font-family: 'Fredoka', sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: #F97316;
    margin: 0;
}

.fb-section-body {
    padding: 18px;
}

.fb-field {
    margin-bottom: 14px;
}

.fb-field:last-child {
    margin-bottom: 0;
}

.fb-label {
    display: block;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.fb-label small {
    text-transform: none;
    font-weight: 700;
    letter-spacing: 0;
    color: #9B94BE;
    font-size: 11px;
}

.fb-input,
.fb-textarea,
.fb-select {
    width: 100%;
    padding: 11px 13px;
    border: 1.5px solid #EDE9FA;
    border-radius: 14px;
    font-size: 14px;
    font-family: 'Nunito', sans-serif;
    font-weight: 700;
    color: #271B5D;
    background: #ffffff;
    box-sizing: border-box;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.fb-input:focus,
.fb-textarea:focus,
.fb-select:focus {
    border-color: #7F77DD;
    box-shadow: 0 0 0 3px rgba(127,119,221,.18);
}

.fb-textarea {
    min-height: 92px;
    resize: vertical;
}

.fb-select {
    appearance: none;
    cursor: pointer;
    padding-right: 34px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23534AB7' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
}

.fb-help {
    font-size: 12px;
    color: #9B94BE;
    font-weight: 700;
    margin-top: 5px;
}

.fb-callout {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: #EEEDFE;
    border: 1px solid #EDE9FA;
    border-radius: 14px;
    padding: 12px 14px;
    font-size: 12px;
    font-weight: 800;
    color: #534AB7;
    margin-bottom: 14px;
    line-height: 1.55;
}

.fb-callout code {
    background: #ffffff;
    border-radius: 6px;
    padding: 1px 6px;
    font-size: 13px;
    color: #7F77DD;
    font-weight: 900;
}

.fb-media-panel {
    display: none;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed #EDE9FA;
}

.fb-media-panel.active {
    display: block;
}

#fb-blocks-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.fb-block-item {
    background: #ffffff;
    border: 1.5px solid #EDE9FA;
    border-radius: 20px;
    padding: 16px;
    transition: border-color .15s, box-shadow .15s;
    box-shadow: 0 4px 14px rgba(127,119,221,.08);
}

.fb-block-item:hover {
    border-color: #7F77DD;
    box-shadow: 0 8px 24px rgba(127,119,221,.13);
}

.fb-block-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.fb-block-num {
    font-family: 'Fredoka', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: #F97316;
}

.fb-answer-tools {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.fb-blank-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #EEEDFE;
    color: #534AB7;
    border: 1px solid #EDE9FA;
    border-radius: 999px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 900;
}

.fb-btn-mini {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #7F77DD;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
    transition: filter .12s, transform .12s;
}

.fb-btn-mini:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.fb-answer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
}

.fb-answer-box {
    background: #FAFAFE;
    border: 1px solid #EDE9FA;
    border-radius: 16px;
    padding: 10px;
}

.fb-answer-box label {
    display: block;
    font-family: 'Nunito', sans-serif;
    font-size: 11px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.fb-answer-empty {
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    border-radius: 14px;
    color: #C2580A;
    font-size: 13px;
    font-weight: 900;
    padding: 12px 14px;
}

.fb-hidden-answer {
    display: none;
}

.fb-thumb {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    object-fit: contain;
    border: 1.5px solid #EDE9FA;
    background: #ffffff;
    margin-top: 6px;
}

.fb-btn-add {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #F97316;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 11px 18px;
    font-size: 13px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
    transition: filter .15s, transform .12s;
}

.fb-btn-add:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.fb-btn-remove {
    background: #ffffff;
    color: #dc2626;
    border: 1px solid #fca5a5;
    border-radius: 999px;
    padding: 7px 13px;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    transition: background .12s;
}

.fb-btn-remove:hover {
    background: #fee2e2;
}

.fb-btn-save {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #F97316;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 13px 28px;
    font-size: 15px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
    transition: filter .15s, transform .15s;
}

.fb-btn-save:hover {
    filter: brightness(1.07);
    transform: translateY(-2px);
}

.fb-save-row {
    display: flex;
    justify-content: center;
    margin-top: 8px;
}

.fb-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 14px;
}

@media (max-width: 640px) {
    .fb-editor {
        padding: 0 2px 32px;
    }

    .fb-section {
        border-radius: 20px;
    }

    .fb-section-body {
        padding: 14px;
    }

    .fb-block-item {
        padding: 14px;
        border-radius: 18px;
    }

    .fb-block-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .fb-answer-grid {
        grid-template-columns: 1fr;
    }

    .fb-btn-save,
    .fb-btn-add {
        width: 100%;
        justify-content: center;
    }
}
</style>

<form method="post" enctype="multipart/form-data" class="fb-editor" id="fbEditorForm" novalidate>
<input type="hidden" name="current_media_url" value="<?php echo htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8'); ?>">

    <div class="fb-section">
        <div class="fb-section-header"><span>&#x1F4DD;</span><h3>Activity settings</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Instructions</label>
                <input class="fb-input" type="text" name="instructions"
                    value="<?php echo htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="Write the missing words in the blanks." required>
            </div>

            <div class="fb-field">
                <label class="fb-label">Word bank <small>(optional — leave blank to hide)</small></label>
                <input class="fb-input" type="text" name="wordbank"
                    value="<?php echo htmlspecialchars($activity['wordbank'], ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="phrase one | phrase two | single word">
                <p class="fb-help">Separate entries with <strong>|</strong> if you want to show a word bank.</p>
            </div>
        </div>
    </div>

    <div class="fb-section">
        <div class="fb-section-header"><span>&#x1F3B5;</span><h3>Media</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Media type</label>
                <select class="fb-select" name="media_type" id="fb-media-type" onchange="fbToggleMedia(this.value)">
                    <option value="none" <?php echo $activity['media_type']==='none'  ? 'selected' : ''; ?>>— No media</option>
                    <option value="tts"  <?php echo $activity['media_type']==='tts'   ? 'selected' : ''; ?>>Text-to-Speech (TTS)</option>
                    <option value="audio"<?php echo $activity['media_type']==='audio' ? 'selected' : ''; ?>>Audio file upload</option>
                </select>
            </div>

            <div id="fb-panel-tts" class="fb-media-panel <?php echo $activity['media_type']==='tts' ? 'active' : ''; ?>">
                <div class="fb-field">
                    <label class="fb-label">TTS text <small>(students listen while filling blanks)</small></label>
                    <textarea class="fb-textarea" name="tts_text"
                        placeholder="Paste the full text here for TTS playback..."
                    ><?php echo htmlspecialchars($activity['tts_text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <p class="fb-help">Leave blank to auto-read all block texts in order.</p>
                </div>
                <div class="fb-field" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:4px">
                    <div style="flex:0 0 auto">
                        <label class="fb-label">Voice</label>
                        <select name="voice_id" class="fb-select js-fb-voiceid" style="min-width:210px">
                            <option value="nzFihrBIvB34imQBuxub"<?php echo ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub') === 'nzFihrBIvB34imQBuxub' ? ' selected' : ''; ?>>👨 Adult Male (Josh)</option>
                            <option value="NoOVOzCQFLOvtsMoNcdT"<?php echo ($activity['voice_id'] ?? '') === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : ''; ?>>👩 Adult Female (Lily)</option>
                            <option value="Nggzl2QAXh3OijoXD116"<?php echo ($activity['voice_id'] ?? '') === 'Nggzl2QAXh3OijoXD116' ? ' selected' : ''; ?>>🧒 Child (Candy)</option>
                        </select>
                    </div>
                    <button type="button" class="js-fb-generate-tts" style="background:#7F77DD;color:#fff;border:none;border-radius:999px;padding:11px 18px;font-size:12px;font-weight:900;cursor:pointer;white-space:nowrap;flex-shrink:0">🔊 Generate audio</button>
                    <input type="hidden" name="tts_audio_url" class="js-fb-audiourl" value="<?php echo htmlspecialchars($activity['tts_audio_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="js-fb-tts-status" style="font-size:12px;font-weight:800;margin-top:6px;min-height:18px"></div>
                <?php if (!empty($activity['tts_audio_url'])): ?>
                <div class="js-fb-tts-preview" style="margin-top:10px;display:flex;align-items:center;gap:10px">
                    <audio src="<?php echo htmlspecialchars($activity['tts_audio_url'], ENT_QUOTES, 'UTF-8'); ?>" controls preload="none" style="flex:1;height:36px"></audio>
                    <button type="button" class="js-fb-remove-tts" style="background:none;border:none;color:#E24B4A;font-size:11px;font-weight:900;cursor:pointer">✖ Remove</button>
                </div>
                <?php endif; ?>
            </div>

            <div id="fb-panel-audio" class="fb-media-panel <?php echo $activity['media_type']==='audio' ? 'active' : ''; ?>">
                <div class="fb-field">
                    <label class="fb-label">Audio URL</label>
                    <input class="fb-input" type="text" name="media_url"
                        value="<?php echo $activity['media_type']==='audio' ? htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                        placeholder="https://...">
                </div>

                <div class="fb-field">
                    <label class="fb-label">Or upload audio file</label>
                    <input type="file" name="media_file" accept="audio/*" style="font-size:13px;font-family:'Nunito',sans-serif">
                    <?php if ($activity['media_type']==='audio' && !empty($activity['media_url'])): ?>
                        <p class="fb-help">Current: <a href="<?php echo htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color:#534AB7;font-weight:900">Listen</a></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="fb-section">
        <div class="fb-section-header">
            <span>&#x1F5C2;&#xFE0F;</span>
            <h3>Blocks</h3>
        </div>

        <div class="fb-section-body">
            <div class="fb-callout">
                <span>&#x1F4A1;</span>
                <span>
                    Write the sentence or paragraph and type <code>___</code> where each answer goes.
                    Then click <strong>Update Answer Boxes</strong>. The editor will create one answer box for each blank.
                </span>
            </div>

            <div id="fb-blocks-list"></div>

            <div class="fb-toolbar">
                <button type="button" class="fb-btn-add" onclick="fbAddBlock()">+ Add Block</button>
            </div>
        </div>
    </div>

    <div class="fb-save-row">
        <button type="submit" class="fb-btn-save">Save Activity</button>
    </div>
</form>

<script>
var FB_BLOCKS = <?php echo json_encode($activity['blocks'], JSON_UNESCAPED_UNICODE); ?>;

function fbToggleMedia(val) {
    ['tts','audio'].forEach(function(id) {
        var el = document.getElementById('fb-panel-' + id);
        if (el) el.classList.toggle('active', id === val);
    });
}

function escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function countBlanks(text) {
    return (String(text || '').match(/_{3,}/g) || []).length;
}

function fbGetAnswerValues(card) {
    return Array.from(card.querySelectorAll('.fb-answer-input'))
        .map(function(input) { return input.value.trim(); });
}

function fbBuildAnswerBoxes(card, answers) {
    var textArea = card.querySelector('textarea[name="text[]"]');
    var wrap     = card.querySelector('.fb-answer-grid');
    var countEl  = card.querySelector('.fb-blank-count');
    var blankNum = countBlanks(textArea.value);

    wrap.innerHTML = '';
    countEl.textContent = blankNum + (blankNum === 1 ? ' blank' : ' blanks');

    if (blankNum === 0) {
        wrap.innerHTML = '<div class="fb-answer-empty">No blanks found. Add ___ in the sentence to create answer boxes.</div>';
        return;
    }

    for (var i = 0; i < blankNum; i++) {
        var value = answers && answers[i] ? answers[i] : '';
        var box = document.createElement('div');
        box.className = 'fb-answer-box';
        box.innerHTML =
            '<label>Answer ' + (i + 1) + '</label>' +
            '<input class="fb-input fb-answer-input" type="text" value="' + escapeHtml(value) + '" placeholder="Type answer ' + (i + 1) + '">';
        wrap.appendChild(box);
    }
}

function fbUpdateAnswerBoxes(btn) {
    var card = btn.closest('.fb-block-item');
    var existing = fbGetAnswerValues(card);
    fbBuildAnswerBoxes(card, existing);
}

function fbSyncHiddenAnswers() {
    document.querySelectorAll('.fb-block-item').forEach(function(card) {
        var values = fbGetAnswerValues(card);
        var hidden = card.querySelector('input[name="answers[]"]');
        hidden.value = values.join(' | ');
    });
}

function fbRenderBlocks() {
    var container = document.getElementById('fb-blocks-list');
    container.innerHTML = '';

    if (!Array.isArray(FB_BLOCKS) || FB_BLOCKS.length === 0) {
        fbAddBlock();
        return;
    }

    FB_BLOCKS.forEach(function(block, idx) {
        var div = document.createElement('div');
        div.className = 'fb-block-item';

        div.innerHTML =
            '<div class="fb-block-top">' +
                '<div class="fb-block-num">Block ' + (idx + 1) + '</div>' +
                '<button type="button" class="fb-btn-remove" onclick="fbRemoveBlock(this)">Remove block</button>' +
            '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Sentence / paragraph <small>— use ___ for each blank</small></label>' +
                '<textarea class="fb-textarea" name="text[]" required placeholder="Yesterday it was ___ and my boss ___ all day.">' +
                    escapeHtml(block.text || '') +
                '</textarea>' +
                '<p class="fb-help">Example: <strong>My favorite color is ___.</strong></p>' +
            '</div>' +

            '<div class="fb-field">' +
                '<div class="fb-answer-tools">' +
                    '<span class="fb-blank-count">0 blanks</span>' +
                    '<button type="button" class="fb-btn-mini" onclick="fbUpdateAnswerBoxes(this)">Update Answer Boxes</button>' +
                '</div>' +
                '<input class="fb-hidden-answer" type="hidden" name="answers[]" value="">' +
                '<div class="fb-answer-grid"></div>' +
            '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Image URL <small>(optional)</small></label>' +
                '<input class="fb-input" type="text" name="image_url[]"' +
                    ' value="' + escapeHtml(block.image || '') + '"' +
                    ' placeholder="https://...">' +
            '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Or upload image</label>' +
                '<input type="file" name="image_upload[]" accept="image/*"' +
                    ' style="font-size:13px;font-family:\'Nunito\',sans-serif">' +
                (block.image ? '<img class="fb-thumb" src="' + escapeHtml(block.image) + '" alt="">' : '') +
            '</div>';

        container.appendChild(div);

        var answers = Array.isArray(block.answers) ? block.answers : [];
        fbBuildAnswerBoxes(div, answers);

        var textArea = div.querySelector('textarea[name="text[]"]');
        textArea.addEventListener('input', function() {
            var blanks = countBlanks(textArea.value);
            var countEl = div.querySelector('.fb-blank-count');
            countEl.textContent = blanks + (blanks === 1 ? ' blank' : ' blanks');
        });
    });
}

function fbAddBlock() {
    if (!Array.isArray(FB_BLOCKS)) FB_BLOCKS = [];
    FB_BLOCKS.push({ text: '', answers: [], image: '' });
    fbRenderBlocks();

    var cards = document.querySelectorAll('.fb-block-item');
    if (cards.length) cards[cards.length - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });

    var tas = document.querySelectorAll('.fb-block-item textarea');
    if (tas.length) tas[tas.length - 1].focus();
}

function fbRemoveBlock(btn) {
    var idx = Array.from(document.querySelectorAll('.fb-block-item')).indexOf(btn.closest('.fb-block-item'));
    if (idx > -1) FB_BLOCKS.splice(idx, 1);
    fbRenderBlocks();
}

document.getElementById('fbEditorForm').addEventListener('submit', function(e) {
    fbSyncHiddenAnswers();

    var mediaTypeEl = document.getElementById('fb-media-type');
    if (mediaTypeEl && mediaTypeEl.value === 'tts') {
        var panel = document.getElementById('fb-panel-tts');
        var ttsTextEl = panel ? panel.querySelector('textarea[name="tts_text"]') : null;
        var ttsAudioEl = panel ? panel.querySelector('.js-fb-audiourl') : null;
        var ttsText = ttsTextEl ? ttsTextEl.value.trim() : '';
        var ttsAudio = ttsAudioEl ? String(ttsAudioEl.value || '').trim() : '';
        if (ttsText !== '' && ttsAudio === '') {
            alert('Generate ElevenLabs audio before saving this TTS activity.');
            if (ttsTextEl) ttsTextEl.focus();
            e.preventDefault();
            return false;
        }
    }

    var cards = document.querySelectorAll('.fb-block-item');

    for (var i = 0; i < cards.length; i++) {
        var text     = cards[i].querySelector('textarea[name="text[]"]').value;
        var blanks   = countBlanks(text);
        var answers  = fbGetAnswerValues(cards[i]);
        var filled   = answers.filter(function(a) { return a !== ''; }).length;

        if (blanks === 0) {
            alert('Block ' + (i + 1) + ': Add at least one blank using ___.');
            e.preventDefault();
            cards[i].querySelector('textarea[name="text[]"]').focus();
            return false;
        }

        if (blanks !== answers.length) {
            alert('Block ' + (i + 1) + ': Click "Update Answer Boxes" so the answer boxes match the blanks.');
            e.preventDefault();
            return false;
        }

        if (filled !== blanks) {
            alert('Block ' + (i + 1) + ': Please fill all answer boxes.');
            e.preventDefault();
            var empty = cards[i].querySelector('.fb-answer-input[value=""]');
            if (empty) empty.focus();
            return false;
        }
    }
});

document.addEventListener('DOMContentLoaded', fbRenderBlocks);

// ── ElevenLabs TTS for fillblank ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    var panel = document.getElementById('fb-panel-tts');
    if (!panel) return;

    var generateBtn = panel.querySelector('.js-fb-generate-tts');
    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            var textarea = panel.querySelector('textarea[name="tts_text"]');
            var text = textarea ? textarea.value.trim() : '';
            if (!text) { alert('Please enter TTS text first.'); return; }
            var voiceSelect = panel.querySelector('.js-fb-voiceid');
            var voiceId = voiceSelect ? voiceSelect.value : 'nzFihrBIvB34imQBuxub';
            var statusEl = panel.querySelector('.js-fb-tts-status');
            var audioHidden = panel.querySelector('.js-fb-audiourl');

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

                    var old = panel.querySelector('.js-fb-tts-preview');
                    if (old) old.remove();

                    var div = document.createElement('div');
                    div.className = 'js-fb-tts-preview';
                    div.style.cssText = 'margin-top:10px;display:flex;align-items:center;gap:10px';
                    div.innerHTML = '<audio src="' + data.url + '" controls preload="none" style="flex:1;height:36px"></audio>' +
                        '<button type="button" class="js-fb-remove-tts" style="background:none;border:none;color:#E24B4A;font-size:11px;font-weight:900;cursor:pointer">✖ Remove</button>';
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
        if (e.target && e.target.classList.contains('js-fb-remove-tts')) {
            var audioHidden = panel.querySelector('.js-fb-audiourl');
            if (audioHidden) audioHidden.value = '';
            var preview = panel.querySelector('.js-fb-tts-preview');
            if (preview) preview.remove();
            var statusEl = panel.querySelector('.js-fb-tts-status');
            if (statusEl) { statusEl.textContent = 'Audio removed.'; statusEl.style.color = ''; }
        }
    });
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Fill-in-the-Blank Editor', 'fa-solid fa-pen-to-square', $content);

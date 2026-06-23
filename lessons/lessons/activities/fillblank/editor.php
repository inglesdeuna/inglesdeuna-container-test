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
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';
$examId     = isset($_GET['exam_id'])    ? (int) $_GET['exam_id']             : 0;

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
    if (!is_array($data)) $data = array();

    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = array(array(
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
            'image'   => '',
        ));
    } else {
        $blocks = isset($data['blocks']) && is_array($data['blocks']) ? $data['blocks'] : array();
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
if ($examId <= 0 && $source === 'eval_builder' && $unit !== '' && isset($_SESSION['eval_builder_exam_for_unit'][$unit])) {
    $examId = (int) $_SESSION['eval_builder_exam_for_unit'][$unit];
}
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

        if ($text === '' && empty($answers) && $imgUrl === '') continue;
        $blocks[] = array('text' => $text, 'answers' => $answers, 'image' => $imgUrl);
    }

    $existing = fb_ed_load($pdo, $unit, $activityId);
    $existingData = array();
    if ($activityId !== '') {
        $dStmt = $pdo->prepare("SELECT data FROM activities WHERE id=:id AND type='fillblank' LIMIT 1");
        $dStmt->execute(array('id' => $activityId));
        $rawData = $dStmt->fetchColumn();
        $existingData = is_string($rawData) ? json_decode($rawData, true) : array();
        if (!is_array($existingData)) $existingData = array();
    }

    $payload = array_merge($existingData, array(
        'instructions' => trim((string)($_POST['instructions'] ?? '')),
        'wordbank'     => trim((string)($_POST['wordbank']     ?? '')),
        'media_type'   => $mediaType,
        'media_url'    => $mediaUrl,
        'tts_text'     => $ttsText,
        'voice_id'     => $voiceId,
        'tts_audio_url'=> $ttsAudioUrl,
        'blocks'       => $blocks,
    ));
    if ($source === 'eval_builder' && $examId > 0) {
        $payload['_exam_id'] = $examId;
        $payload['_exam_builder'] = true;
        $_SESSION['eval_builder_exam_for_unit'][$unit] = $examId;
    }

    $savedId = fb_ed_save($pdo, $unit, $activityId, $payload);

    $params = array('unit='.urlencode($unit), 'saved=1', 'id='.urlencode($savedId));
    if ($assignment !== '') $params[] = 'assignment='.urlencode($assignment);
    if ($source     !== '') $params[] = 'source='.urlencode($source);
    if ($examId > 0) $params[] = 'exam_id='.urlencode((string)$examId);

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
    color: var(--fb-ink);
    margin: 0;
}

.fb-section-body { padding: 18px; }
.fb-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.fb-field { margin-bottom:14px; }
.fb-label { display:block; font-size:12px; font-weight:900; color:var(--fb-purple-dark); margin-bottom:6px; text-transform:uppercase; letter-spacing:.06em; }
.fb-input, .fb-textarea, .fb-select { width:100%; border:1px solid var(--fb-lila-border); border-radius:14px; padding:10px 12px; font-family:'Nunito',sans-serif; font-weight:700; color:var(--fb-ink); background:#fff; }
.fb-textarea { min-height:110px; resize:vertical; }
.fb-help { color:var(--fb-muted); font-size:12px; font-weight:700; margin-top:5px; }
.fb-block { border:1px solid var(--fb-lila-border); border-radius:20px; padding:16px; margin-bottom:14px; background:#fff; }
.fb-block-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.fb-block-title { font-family:'Fredoka',sans-serif; color:var(--fb-purple-dark); font-weight:700; }
.fb-btn { border:0; border-radius:14px; padding:10px 16px; font-family:'Nunito',sans-serif; font-weight:900; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
.fb-btn-main { background:var(--fb-orange); color:#fff; }
.fb-btn-sec { background:var(--fb-purple-soft); color:var(--fb-purple-dark); }
.fb-btn-red { background:#FEE2E2; color:#B91C1C; }
.fb-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:18px; }
.fb-answer-row { display:flex; gap:8px; margin-bottom:8px; }
.fb-answer-row input { flex:1; }
@media(max-width:720px){.fb-grid-2{grid-template-columns:1fr}}
</style>

<div class="fb-editor">
<form method="POST" enctype="multipart/form-data" id="fb-form">
    <div class="fb-section">
        <div class="fb-section-header"><h3>Fill in the Blank</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Instructions</label>
                <input class="fb-input" type="text" name="instructions" value="<?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="fb-grid-2">
                <div class="fb-field">
                    <label class="fb-label">Media Type</label>
                    <select class="fb-select" name="media_type">
                        <?php foreach (array('none'=>'None','audio'=>'Audio','tts'=>'Text to Speech') as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $activity['media_type']===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fb-field">
                    <label class="fb-label">Media URL</label>
                    <input class="fb-input" type="text" name="media_url" value="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="current_media_url" value="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="fb-field">
                <label class="fb-label">Upload Audio</label>
                <input class="fb-input" type="file" name="media_file" accept="audio/*">
            </div>
            <div class="fb-field">
                <label class="fb-label">Word Bank</label>
                <input class="fb-input" type="text" name="wordbank" value="<?= htmlspecialchars($activity['wordbank'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>
    </div>

    <div class="fb-section">
        <div class="fb-section-header"><h3>Blocks</h3></div>
        <div class="fb-section-body" id="blocks-wrap"></div>
    </div>

    <div class="fb-actions">
        <button type="button" class="fb-btn fb-btn-sec" onclick="addBlock()">+ Add block</button>
        <button type="submit" class="fb-btn fb-btn-main" onclick="prepareAnswers()">Save Fill in the Blank</button>
    </div>
</form>
</div>

<script>
const existingBlocks = <?= json_encode($activity['blocks'], JSON_UNESCAPED_UNICODE) ?>;
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function addBlock(block){
    block = block || {text:'',answers:[],image:''};
    const wrap = document.getElementById('blocks-wrap');
    const idx = wrap.children.length;
    const div = document.createElement('div');
    div.className = 'fb-block';
    const answers = Array.isArray(block.answers) && block.answers.length ? block.answers : [''];
    div.innerHTML = `
      <div class="fb-block-head"><div class="fb-block-title">Block ${idx+1}</div><button type="button" class="fb-btn fb-btn-red" onclick="this.closest('.fb-block').remove()">Remove</button></div>
      <div class="fb-field"><label class="fb-label">Text with blanks</label><textarea class="fb-textarea" name="text[]" placeholder="Use ___ for each blank">${esc(block.text)}</textarea><div class="fb-help">Example: I ___ to school every day.</div></div>
      <div class="fb-field"><label class="fb-label">Answers</label><div class="ans-wrap">${answers.map(a=>`<div class="fb-answer-row"><input class="fb-input ans-input" type="text" value="${esc(a)}"><button type="button" class="fb-btn fb-btn-red" onclick="this.parentElement.remove()">x</button></div>`).join('')}</div><input type="hidden" name="answers[]" class="answers-hidden"><button type="button" class="fb-btn fb-btn-sec" onclick="addAnswer(this)">+ Answer</button></div>
      <div class="fb-grid-2"><div class="fb-field"><label class="fb-label">Image URL</label><input class="fb-input" type="text" name="image_url[]" value="${esc(block.image)}"></div><div class="fb-field"><label class="fb-label">Upload Image</label><input class="fb-input" type="file" name="image_upload[]" accept="image/*"></div></div>`;
    wrap.appendChild(div);
}
function addAnswer(btn){
    const wrap = btn.closest('.fb-field').querySelector('.ans-wrap');
    const row = document.createElement('div');
    row.className = 'fb-answer-row';
    row.innerHTML = '<input class="fb-input ans-input" type="text"><button type="button" class="fb-btn fb-btn-red" onclick="this.parentElement.remove()">x</button>';
    wrap.appendChild(row);
}
function prepareAnswers(){
    document.querySelectorAll('.fb-block').forEach(block=>{
        const vals = Array.from(block.querySelectorAll('.ans-input')).map(i=>i.value.trim()).filter(Boolean);
        block.querySelector('.answers-hidden').value = vals.join('|');
    });
}
if (existingBlocks.length) existingBlocks.forEach(addBlock); else addBlock();
document.getElementById('fb-form').addEventListener('submit', prepareAnswers);
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Fill in the Blank', 'fas fa-pen-nib', $content);
?>

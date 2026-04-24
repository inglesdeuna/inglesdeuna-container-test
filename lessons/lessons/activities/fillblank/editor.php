<?php
// Start session immediately, before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Ensure academic or admin login
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

// Collect query parameters
$activityId  = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit        = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source      = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment  = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

// --- Helper functions ---
function resolve_unit_from_activity(PDO $pdo, string $activityId): string {
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['unit_id'] ?? '';
}

function default_fillblank_title(): string {
    return 'Fill in the Blank';
}

function load_fillblank_activity(PDO $pdo, string $unit, string $activityId): array {
    $fallback = [
        'id' => '',
        'instructions' => 'Write the missing words in the blanks.',
        'text' => '',
        'wordbank' => '',
        'answerkey' => '',
    ];
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $data = json_decode($row['data'] ?? '', true);
    return [
        'id' => (string)($row['id'] ?? ''),
        'instructions' => $data['instructions'] ?? $fallback['instructions'],
        'text' => $data['text'] ?? '',
        'wordbank' => $data['wordbank'] ?? '',
        'answerkey' => $data['answerkey'] ?? '',
    ];
}

function save_fillblank_activity(PDO $pdo, string $unit, string $activityId, array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string)$stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'fillblank'");
        $stmt->execute(['data' => $json, 'id' => $targetId]);
        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:unit_id, 'fillblank', :data,
        (SELECT COALESCE(MAX(position), 0) + 1 FROM activities WHERE unit_id = :unit_id2),
        CURRENT_TIMESTAMP) RETURNING id
    ");
    $stmt->execute(['unit_id' => $unit, 'unit_id2' => $unit, 'data' => $json]);
    return (string)$stmt->fetchColumn();
}

// --- Resolve unit ---
if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}
if ($unit === '') {
    die('Unit not specified');
}

// --- Handle POST save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../core/cloudinary_upload.php';
    $instructions = trim((string)($_POST['instructions'] ?? ''));
    $wordbank = trim((string)($_POST['wordbank'] ?? ''));
    $blockTexts = isset($_POST['text']) && is_array($_POST['text']) ? $_POST['text'] : [];
    $blockAnswers = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];
    $blockImages = isset($_POST['image_url']) && is_array($_POST['image_url']) ? $_POST['image_url'] : [];
    $imageUploads = isset($_FILES['image_upload']) ? $_FILES['image_upload'] : null;
    $blocks = [];
    foreach ($blockTexts as $i => $text) {
        $text = trim((string)$text);
        $answers = isset($blockAnswers[$i]) ? array_map('trim', explode(',', $blockAnswers[$i])) : [];
        $imgUrl = isset($blockImages[$i]) ? trim((string)$blockImages[$i]) : '';
        $uploadedImg = '';
        if ($imageUploads && isset($imageUploads['tmp_name'][$i]) && $imageUploads['error'][$i] === UPLOAD_ERR_OK && !empty($imageUploads['name'][$i])) {
            $uploadedImg = upload_to_cloudinary($imageUploads['tmp_name'][$i]);
        }
        $finalImg = $uploadedImg ?: $imgUrl;
        $blocks[] = [
            'text' => $text,
            'answers' => $answers,
            'image' => $finalImg,
        ];
    }
    $payload = [
        'instructions' => $instructions,
        'blocks' => $blocks,
        'wordbank' => $wordbank,
    ];
    $savedActivityId = save_fillblank_activity($pdo, $unit, $activityId, $payload);

    $params = [
        'unit=' . urlencode($unit),
        'saved=1',
        'id=' . urlencode($savedActivityId),
    ];
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source !== '') $params[] = 'source=' . urlencode($source);

    header('Location: editor.php?' . implode('&', $params));
    exit;
}


// --- Render new block-based form ---
$activity = load_fillblank_activity($pdo, $unit, $activityId);
$blocks = [];
if (!empty($activity['text'])) {
    // For backward compatibility: parse old single-text activities into one block
    $blocks[] = [
        'text' => $activity['text'],
        'answers' => array_map('trim', explode(',', $activity['answerkey'])),
    ];
}

ob_start();
if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>';
}
?>
<form method="post" class="needs-validation" id="fillBlankForm" novalidate enctype="multipart/form-data">
    <div class="mb-4">
        <label for="instructions" class="form-label fw-bold">Instructions</label>
        <input id="instructions" type="text" name="instructions" value="<?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?>" required class="form-control" />
    </div>
    <div id="blocksContainer"></div>
    <div class="d-flex gap-2 justify-content-center mt-3">
        <button type="button" class="btn btn-success" onclick="addBlock()"><i class="fas fa-plus"></i> Add Block</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
    </div>
</form>
<script>
let initialBlocks = <?= json_encode($blocks) ?>;
function renderBlocks() {
    const container = document.getElementById('blocksContainer');
    container.innerHTML = '';
    if (initialBlocks.length === 0) addBlock();
    initialBlocks.forEach((block, idx) => {
        const div = document.createElement('div');
        div.className = 'card mb-3 block-item';
        div.innerHTML = `
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Sentence or paragraph <span class="text-muted">(use <b>___</b> for blanks)</span></label>
                    <textarea name="text[]" required class="form-control" placeholder="Type your sentence and use ___ for blanks">${block.text ? block.text.replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''}</textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Answers for blanks <span class="text-muted">(comma separated, in order)</span></label>
                    <input type="text" name="answers[]" value="${block.answers ? block.answers.join(', ') : ''}" required class="form-control" placeholder="e.g. apple, banana, orange">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Image URL (optional)</label>
                    <input type="text" name="image_url[]" value="${block.image ? block.image : ''}" class="form-control" placeholder="https://...">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Or upload image</label>
                    <input type="file" name="image_upload[]" accept="image/*" class="form-control">
                    ${block.image ? `<div class='mt-2'><a href='${block.image}' target='_blank'>🖼️ View current image</a></div>` : ''}
                </div>
                <button type="button" class="btn btn-danger" onclick="removeBlock(this)"><i class="fas fa-trash-alt"></i> Remove</button>
            </div>
        `;
        container.appendChild(div);
    });
}
function addBlock() {
    initialBlocks.push({text:'',answers:[],image:''});
    renderBlocks();
}
function removeBlock(btn) {
    const idx = Array.from(document.querySelectorAll('.block-item')).indexOf(btn.closest('.block-item'));
    if (idx > -1) initialBlocks.splice(idx,1);
    renderBlocks();
}
document.addEventListener('DOMContentLoaded', renderBlocks);
document.getElementById('fillBlankForm').onsubmit = function(e) {
    // Validate blanks and answers
    const blocks = document.querySelectorAll('.block-item');
    for (let i=0; i<blocks.length; ++i) {
        const text = blocks[i].querySelector('textarea').value;
        const answers = blocks[i].querySelector('input[name="answers[]"]').value.split(',').map(s=>s.trim()).filter(Boolean);
        const blanks = (text.match(/___/g)||[]).length;
        if (blanks !== answers.length) {
            alert(`Block ${i+1}: Number of blanks (___) does not match number of answers.`);
            e.preventDefault();
            return false;
        }
    }
};
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Fill-in-the-Blank Editor', 'fa-solid fa-pen-to-square', $content);

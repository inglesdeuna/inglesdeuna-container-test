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
    $payload = [
        'instructions' => trim((string)($_POST['instructions'] ?? '')),
        'text' => trim((string)($_POST['text'] ?? '')),
        'wordbank' => trim((string)($_POST['wordbank'] ?? '')),
        'answerkey' => trim((string)($_POST['answerkey'] ?? '')),
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
<style>
.fbk-form {
    max-width: 860px;
    margin: 0 auto;
    text-align: left;
}
.block-item {
    background: #f9fafb;
    padding: 14px;
    margin-bottom: 14px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}
.block-item label {
    display: block;
    font-weight: 700;
    margin-bottom: 8px;
}
.block-item textarea, .block-item input {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    box-sizing: border-box;
    margin-bottom: 12px;
    font-size: 14px;
}
.toolbar-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 8px;
}
.btn-add {
    background: #16a34a;
    color: #fff;
    padding: 10px 14px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 700;
}
.btn-remove {
    background: #ef4444;
    color: #fff;
    border: none;
    padding: 8px 12px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 700;
}
.save-btn {
    background: linear-gradient(180deg,#0d9488,#0f766e);
    color: #fff;
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 800;
    font-family: 'Nunito','Segoe UI',sans-serif;
    font-size: 15px;
    transition: transform .15s ease, filter .15s ease;
    box-shadow: 0 2px 8px rgba(13,148,136,.22);
}
.save-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}
</style>
<form method="post" class="fbk-form" id="fillBlankForm">
    <div class="title-box">
        <label for="instructions">Instructions</label>
        <input id="instructions" type="text" name="instructions" value="<?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?>" required />
    </div>
    <div id="blocksContainer">
        <!-- Blocks will be rendered here by JS -->
    </div>
    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addBlock()">+ Add Block</button>
        <button type="submit" class="save-btn">💾 Save</button>
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
        div.className = 'block-item';
        div.innerHTML = `
            <label>Sentence or paragraph (use <b>___</b> for blanks)</label>
            <textarea name="text[]" required placeholder="Type your sentence and use ___ for blanks">${block.text ? block.text.replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''}</textarea>
            <label>Answers for blanks (comma separated, in order)</label>
            <input type="text" name="answers[]" value="${block.answers ? block.answers.join(', ') : ''}" required placeholder="e.g. apple, banana, orange">
            <button type="button" class="btn-remove" onclick="removeBlock(this)">✖ Remove</button>
        `;
        container.appendChild(div);
    });
}
function addBlock() {
    initialBlocks.push({text:'',answers:[]});
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
        const answers = blocks[i].querySelector('input').value.split(',').map(s=>s.trim()).filter(Boolean);
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

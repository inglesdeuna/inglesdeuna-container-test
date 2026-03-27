<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && isset($row['unit_id'])) {
        return (string) $row['unit_id'];
    }

    return '';
}

function default_multiple_choice_title(): string
{
    return 'Multiple Choice';
}

function normalize_multiple_choice_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_multiple_choice_title();
}

function normalize_multiple_choice_payload($rawData): array
{
    $default = array(
        'title' => default_multiple_choice_title(),
        'questions' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $questionsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $questionsSource = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $questionsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $questionsSource = $decoded['data'];
    }

    $questions = array();

    foreach ($questionsSource as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options'])
            ? $item['options']
            : array(
                isset($item['option_a']) ? (string) $item['option_a'] : '',
                isset($item['option_b']) ? (string) $item['option_b'] : '',
                isset($item['option_c']) ? (string) $item['option_c'] : '',
            );

        $questions[] = array(
            'id' => isset($item['id']) && trim((string) $item['id']) !== '' ? trim((string) $item['id']) : uniqid('mc_'),
            'question' => isset($item['question']) ? trim((string) $item['question']) : '',
            'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            'options' => array(
                isset($options[0]) ? trim((string) $options[0]) : '',
                isset($options[1]) ? trim((string) $options[1]) : '',
                isset($options[2]) ? trim((string) $options[2]) : '',
            ),
            'correct' => isset($item['correct']) ? max(0, min(2, (int) $item['correct'])) : 0,
        );
    }

    return array(
        'title' => normalize_multiple_choice_title($title),
        'questions' => $questions,
    );
}

function encode_multiple_choice_payload(array $payload): string
{
    return json_encode(
        array(
            'title' => normalize_multiple_choice_title(isset($payload['title']) ? (string) $payload['title'] : ''),
            'questions' => isset($payload['questions']) && is_array($payload['questions']) ? array_values($payload['questions']) : array(),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function load_multiple_choice_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = array(
        'id' => '',
        'title' => default_multiple_choice_title(),
        'questions' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'multiple_choice'
            LIMIT 1
        ");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'multiple_choice'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_multiple_choice_payload($row['data'] ?? null);

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_multiple_choice_title((string) ($payload['title'] ?? '')),
        'questions' => isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : array(),
    );
}

function save_multiple_choice_activity(PDO $pdo, string $unit, string $activityId, string $title, array $questions): string
{
    $title = normalize_multiple_choice_title($title);
    $json = encode_multiple_choice_payload(array(
        'title' => $title,
        'questions' => $questions,
    ));

    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id = :unit
              AND type = 'multiple_choice'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(array('unit' => $unit));
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE id = :id
              AND type = 'multiple_choice'
        ");
        $stmt->execute(array(
            'data' => $json,
            'id' => $targetId,
        ));

        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (
            :unit_id,
            'multiple_choice',
            :data,
            (
                SELECT COALESCE(MAX(position), 0) + 1
                FROM activities
                WHERE unit_id = :unit_id2
            ),
            CURRENT_TIMESTAMP
        )
        RETURNING id
    ");

    $stmt->execute(array(
        'unit_id' => $unit,
        'unit_id2' => $unit,
        'data' => $json,
    ));

    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_multiple_choice_activity($pdo, $unit, $activityId);
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_multiple_choice_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $questionIds = isset($_POST['question_id']) && is_array($_POST['question_id']) ? $_POST['question_id'] : array();
    $questionTexts = isset($_POST['question']) && is_array($_POST['question']) ? $_POST['question'] : array();
    $images = isset($_POST['image_existing']) && is_array($_POST['image_existing']) ? $_POST['image_existing'] : array();
    $optionA = isset($_POST['option_a']) && is_array($_POST['option_a']) ? $_POST['option_a'] : array();
    $optionB = isset($_POST['option_b']) && is_array($_POST['option_b']) ? $_POST['option_b'] : array();
    $optionC = isset($_POST['option_c']) && is_array($_POST['option_c']) ? $_POST['option_c'] : array();
    $corrects = isset($_POST['correct']) && is_array($_POST['correct']) ? $_POST['correct'] : array();
    $imageFiles = isset($_FILES['image_file']) ? $_FILES['image_file'] : null;

    $sanitized = array();

    foreach ($questionTexts as $i => $questionRaw) {
        $question = trim((string) $questionRaw);
        $image = isset($images[$i]) ? trim((string) $images[$i]) : '';
        $qid = isset($questionIds[$i]) && trim((string) $questionIds[$i]) !== '' ? trim((string) $questionIds[$i]) : uniqid('mc_');

        if (
            $imageFiles &&
            isset($imageFiles['name'][$i]) &&
            $imageFiles['name'][$i] !== '' &&
            isset($imageFiles['tmp_name'][$i]) &&
            $imageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedImage = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($uploadedImage) {
                $image = $uploadedImage;
            }
        }

        $a = isset($optionA[$i]) ? trim((string) $optionA[$i]) : '';
        $b = isset($optionB[$i]) ? trim((string) $optionB[$i]) : '';
        $c = isset($optionC[$i]) ? trim((string) $optionC[$i]) : '';
        $correct = isset($corrects[$i]) ? max(0, min(2, (int) $corrects[$i])) : 0;

        if ($question === '' && $image === '' && $a === '' && $b === '' && $c === '') {
            continue;
        }

        $sanitized[] = array(
            'id' => $qid,
            'question' => $question,
            'image' => $image,
            'options' => array($a, $b, $c),
            'correct' => $correct,
        );
    }

    $savedActivityId = save_multiple_choice_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    $params = array(
        'unit=' . urlencode($unit),
        'saved=1'
    );

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
    } elseif ($activityId !== '') {
        $params[] = 'id=' . urlencode($activityId);
    }

    if ($assignment !== '') {
        $params[] = 'assignment=' . urlencode($assignment);
    }

    if ($source !== '') {
        $params[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $params));
    exit;
}

ob_start();
?>

<style>
.mc-form{
    max-width:980px;
    margin:0 auto;
    text-align:left;
}

.title-box{
    background:#f9fafb;
    padding:14px;
    margin-bottom:14px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}

.title-box label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}

.title-box input{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
    box-sizing:border-box;
}

.question-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:12px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}

.question-item label{
    display:block;
    font-weight:700;
    margin-bottom:6px;
}

.question-item input[type="text"],
.question-item input[type="file"],
.question-item textarea,
.question-item select{
    width:100%;
    padding:10px;
    border:1px solid #d1d5db;
    border-radius:8px;
    margin:0 0 12px 0;
    box-sizing:border-box;
    font-size:14px;
}

.question-item textarea{
    min-height:90px;
    resize:vertical;
}

.image-preview{
    display:block;
    max-width:120px;
    max-height:120px;
    object-fit:contain;
    border-radius:10px;
    border:1px solid #d1d5db;
    background:#fff;
    margin-bottom:10px;
}

.toolbar-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:center;
    margin-top:8px;
}

.btn-add{
    background:#16a34a;
    color:#fff;
    padding:10px 14px;
    border:none;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}

.btn-remove{
    background:#ef4444;
    color:#fff;
    border:none;
    padding:8px 12px;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>
<?php } ?>

<form class="mc-form" id="multipleChoiceForm" method="post" enctype="multipart/form-data">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Classroom Quiz"
            required
        >
    </div>

    <div id="questionsContainer">
        <?php foreach ($questions as $question) { ?>
            <div class="question-item">
                <input type="hidden" name="question_id[]" value="<?= htmlspecialchars(isset($question['id']) ? $question['id'] : uniqid('mc_'), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars(isset($question['image']) ? $question['image'] : '', ENT_QUOTES, 'UTF-8') ?>">

                <label>Question</label>
                <textarea name="question[]" placeholder="Write the question" required><?= htmlspecialchars(isset($question['question']) ? $question['question'] : '', ENT_QUOTES, 'UTF-8') ?></textarea>

                <label>Image (optional)</label>
                <?php if (!empty($question['image'])) { ?>
                    <img src="<?= htmlspecialchars($question['image'], ENT_QUOTES, 'UTF-8') ?>" alt="mc-image" class="image-preview">
                <?php } ?>
                <input type="file" name="image_file[]" accept="image/*">

                <label>Option A</label>
                <input type="text" name="option_a[]" value="<?= htmlspecialchars(isset($question['options'][0]) ? $question['options'][0] : '', ENT_QUOTES, 'UTF-8') ?>" required>

                <label>Option B</label>
                <input type="text" name="option_b[]" value="<?= htmlspecialchars(isset($question['options'][1]) ? $question['options'][1] : '', ENT_QUOTES, 'UTF-8') ?>" required>

                <label>Option C</label>
                <input type="text" name="option_c[]" value="<?= htmlspecialchars(isset($question['options'][2]) ? $question['options'][2] : '', ENT_QUOTES, 'UTF-8') ?>" required>

                <label>Correct answer</label>
                <select name="correct[]">
                    <option value="0" <?= (isset($question['correct']) && (int) $question['correct'] === 0) ? 'selected' : '' ?>>Option A</option>
                    <option value="1" <?= (isset($question['correct']) && (int) $question['correct'] === 1) ? 'selected' : '' ?>>Option B</option>
                    <option value="2" <?= (isset($question['correct']) && (int) $question['correct'] === 2) ? 'selected' : '' ?>>Option C</option>
                </select>

                <button type="button" class="btn-remove" onclick="removeQuestion(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addQuestion()">+ Add Question</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function removeQuestion(button) {
    const item = button.closest('.question-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function addQuestion() {
    const container = document.getElementById('questionsContainer');
    const div = document.createElement('div');
    div.className = 'question-item';
    div.innerHTML = `
        <input type="hidden" name="question_id[]" value="mc_${Date.now()}_${Math.floor(Math.random() * 1000)}">
        <input type="hidden" name="image_existing[]" value="">

        <label>Question</label>
        <textarea name="question[]" placeholder="Write the question" required></textarea>

        <label>Image (optional)</label>
        <input type="file" name="image_file[]" accept="image/*">

        <label>Option A</label>
        <input type="text" name="option_a[]" required>

        <label>Option B</label>
        <input type="text" name="option_b[]" required>

        <label>Option C</label>
        <input type="text" name="option_c[]" required>

        <label>Correct answer</label>
        <select name="correct[]">
            <option value="0">Option A</option>
            <option value="1">Option B</option>
            <option value="2">Option C</option>
        </select>

        <button type="button" class="btn-remove" onclick="removeQuestion(this)">✖ Remove</button>
    `;
    container.appendChild(div);
    bindChangeTracking(div);
    markChanged();
}

function bindChangeTracking(scope) {
    const elements = scope.querySelectorAll('input, textarea, select');
    elements.forEach(function(el) {
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindChangeTracking(document);

    const form = document.getElementById('multipleChoiceForm');
    if (form) {
        form.addEventListener('submit', function () {
            formSubmitted = true;
            formChanged = false;
        });
    }
});

window.addEventListener('beforeunload', function (e) {
    if (formChanged && !formSubmitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('📝 Multiple Choice Editor', '📝', $content);

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
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

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_qa_title(): string
{
    return 'Questions & Answers';
}

function normalize_qa_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_qa_title();
}

function normalize_qa_payload($rawData): array
{
    $default = array(
        'title' => default_qa_title(),
        'cards' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = '';
    $cardsSource = $decoded;

    if (isset($decoded['title'])) {
        $title = trim((string) $decoded['title']);
    }

    if (isset($decoded['cards']) && is_array($decoded['cards'])) {
        $cardsSource = $decoded['cards'];
    }

    $cards = array();

    if (is_array($cardsSource)) {
        foreach ($cardsSource as $item) {
            if (!is_array($item)) {
                continue;
            }

            $cards[] = array(
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('qa_'),
                'question' => isset($item['question']) ? trim((string) $item['question']) : '',
                'answer' => isset($item['answer']) ? trim((string) $item['answer']) : '',
            );
        }
    }

    return array(
        'title' => normalize_qa_title($title),
        'cards' => $cards,
    );
}

function encode_qa_payload(array $payload): string
{
    return json_encode(
        array(
            'title' => normalize_qa_title(isset($payload['title']) ? (string) $payload['title'] : ''),
            'cards' => isset($payload['cards']) && is_array($payload['cards']) ? array_values($payload['cards']) : array(),
        ),
        JSON_UNESCAPED_UNICODE
    );
}

function load_qa_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_qa_title(),
        'cards' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'question_answer'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'question_answer'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'question_answer'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_qa_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_qa_title((string) $payload['title']),
        'cards' => isset($payload['cards']) && is_array($payload['cards']) ? $payload['cards'] : array(),
    );
}

function save_qa_activity(PDO $pdo, string $unit, string $activityId, string $title, array $cards): string
{
    $columns = activities_columns($pdo);
    $title = normalize_qa_title($title);
    $json = encode_qa_payload(array(
        'title' => $title,
        'cards' => $cards,
    ));

    $hasUnitId = in_array('unit_id', $columns, true);
    $hasUnit = in_array('unit', $columns, true);
    $hasData = in_array('data', $columns, true);
    $hasContentJson = in_array('content_json', $columns, true);
    $hasId = in_array('id', $columns, true);
    $hasTitle = in_array('title', $columns, true);
    $hasName = in_array('name', $columns, true);

    $targetId = $activityId;

    if ($targetId === '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit_id = :unit
                   AND type = 'question_answer'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }

        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit = :unit
                   AND type = 'question_answer'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = array();
        $params = array('id' => $targetId);

        if ($hasData) {
            $setParts[] = 'data = :data';
            $params['data'] = $json;
        }

        if ($hasContentJson) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $json;
        }

        if ($hasTitle) {
            $setParts[] = 'title = :title';
            $params['title'] = $title;
        }

        if ($hasName) {
            $setParts[] = 'name = :name';
            $params['name'] = $title;
        }

        if (!empty($setParts)) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET " . implode(', ', $setParts) . "
                 WHERE id = :id
                   AND type = 'question_answer'"
            );
            $stmt->execute($params);
        }

        return $targetId;
    }

    $insertColumns = array();
    $insertValues = array();
    $params = array();

    $newId = '';
    if ($hasId) {
        $newId = md5(random_bytes(16));
        $insertColumns[] = 'id';
        $insertValues[] = ':id';
        $params['id'] = $newId;
    }

    if ($hasUnitId) {
        $insertColumns[] = 'unit_id';
        $insertValues[] = ':unit_id';
        $params['unit_id'] = $unit;
    } elseif ($hasUnit) {
        $insertColumns[] = 'unit';
        $insertValues[] = ':unit';
        $params['unit'] = $unit;
    }

    $insertColumns[] = 'type';
    $insertValues[] = "'question_answer'";

    if ($hasData) {
        $insertColumns[] = 'data';
        $insertValues[] = ':data';
        $params['data'] = $json;
    }

    if ($hasContentJson) {
        $insertColumns[] = 'content_json';
        $insertValues[] = ':content_json';
        $params['content_json'] = $json;
    }

    if ($hasTitle) {
        $insertColumns[] = 'title';
        $insertValues[] = ':title';
        $params['title'] = $title;
    }

    if ($hasName) {
        $insertColumns[] = 'name';
        $insertValues[] = ':name';
        $params['name'] = $title;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO activities (" . implode(', ', $insertColumns) . ")
         VALUES (" . implode(', ', $insertValues) . ")"
    );
    $stmt->execute($params);

    return $newId;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_qa_activity($pdo, $unit, $activityId);
$cards = isset($activity['cards']) && is_array($activity['cards']) ? $activity['cards'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_qa_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAutoSaveRequest = isset($_POST['autosave']) && (string) $_POST['autosave'] === '1';
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $questions = isset($_POST['question']) && is_array($_POST['question']) ? $_POST['question'] : array();
    $answers = isset($_POST['answer']) && is_array($_POST['answer']) ? $_POST['answer'] : array();
    $ids = isset($_POST['card_id']) && is_array($_POST['card_id']) ? $_POST['card_id'] : array();

    $sanitized = array();

    foreach ($questions as $i => $questionRaw) {
        $question = trim((string) $questionRaw);
        $answer = isset($answers[$i]) ? trim((string) $answers[$i]) : '';
        $cardId = isset($ids[$i]) && trim((string) $ids[$i]) !== '' ? trim((string) $ids[$i]) : uniqid('qa_');

        if ($question === '' && $answer === '') {
            continue;
        }

        $sanitized[] = array(
            'id' => $cardId,
            'question' => $question,
            'answer' => $answer,
        );
    }

    $savedActivityId = save_qa_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    if ($isAutoSaveRequest) {
        http_response_code(204);
        exit;
    }

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
.qa-form {
    max-width: 900px;
    margin: 0 auto;
    text-align: left;
}

.title-box {
    background: #f9fafb;
    padding: 14px;
    margin-bottom: 14px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.title-box label {
    display: block;
    font-weight: 700;
    margin-bottom: 8px;
}

.title-box input {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 15px;
}

.card-item {
    background: #f9fafb;
    padding: 14px;
    margin-bottom: 12px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.card-item label {
    display: block;
    font-weight: 700;
    margin-bottom: 6px;
}

.card-item input[type="text"],
.card-item textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    margin: 0 0 12px 0;
    box-sizing: border-box;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 15px;
}

.card-item textarea {
    min-height: 80px;
    resize: vertical;
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

.save-btn {
    background: linear-gradient(180deg, #0d9488, #0f766e);
    color: #fff;
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 800;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 15px;
    transition: transform 0.15s ease, filter 0.15s ease;
    box-shadow: 0 2px 8px rgba(13, 148, 136, 0.22);
}

.save-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
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
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color: green; font-weight: bold; margin-bottom: 15px;">✔ Saved successfully</p>
<?php } ?>

<form class="qa-form" id="qaForm" method="post" enctype="multipart/form-data">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Advanced Interview Questions"
            required
        >
    </div>

    <div id="cardsContainer">
        <?php foreach ($cards as $card) { ?>
            <div class="card-item">
                <input type="hidden" name="card_id[]" value="<?= htmlspecialchars(isset($card['id']) ? $card['id'] : uniqid('qa_'), ENT_QUOTES, 'UTF-8') ?>">

                <label>Question</label>
                <textarea name="question[]" placeholder="Write the question" required><?= htmlspecialchars(isset($card['question']) ? $card['question'] : '', ENT_QUOTES, 'UTF-8') ?></textarea>

                <label>Answer</label>
                <textarea name="answer[]" placeholder="Write the complete answer" required><?= htmlspecialchars(isset($card['answer']) ? $card['answer'] : '', ENT_QUOTES, 'UTF-8') ?></textarea>

                <button type="button" class="btn-remove" onclick="removeCard(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addCard()">+ Add Question</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;
let autoSaveRequested = false;

function markChanged() {
    formChanged = true;
}

function removeCard(button) {
    const item = button.closest('.card-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function addCard() {
    const container = document.getElementById('cardsContainer');
    const div = document.createElement('div');
    div.className = 'card-item';
    div.innerHTML = `
        <input type="hidden" name="card_id[]" value="qa_${Date.now()}_${Math.floor(Math.random() * 1000)}">

        <label>Question</label>
        <textarea name="question[]" placeholder="Write the question" required></textarea>

        <label>Answer</label>
        <textarea name="answer[]" placeholder="Write the complete answer" required></textarea>

        <button type="button" class="btn-remove" onclick="removeCard(this)">✖ Remove</button>
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

    const form = document.getElementById('qaForm');
    if (form) {
        form.addEventListener('submit', function () {
            formSubmitted = true;
            formChanged = false;
        });
    }
});

function autoSaveOnExit() {
    if (formSubmitted || !formChanged || autoSaveRequested) {
        return;
    }

    const form = document.getElementById('qaForm');
    if (!form) {
        return;
    }

    const payload = new FormData(form);
    payload.append('autosave', '1');

    autoSaveRequested = true;

    try {
        if (navigator.sendBeacon) {
            const sent = navigator.sendBeacon(window.location.href, payload);
            if (sent) {
                formChanged = false;
                return;
            }
        }
    } catch (e) {}

    fetch(window.location.href, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        keepalive: true
    }).then(function () {
        formChanged = false;
    }).catch(function () {
        autoSaveRequested = false;
    });
}

window.addEventListener('beforeunload', function () {
    autoSaveOnExit();
});

window.addEventListener('pagehide', function () {
    autoSaveOnExit();
});

document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
        autoSaveOnExit();
    }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('❓ Question & Answer Editor', '❓', $content);

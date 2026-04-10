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

function us_resolve_unit_from_activity(PDO $pdo, string $activityId): string
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
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us_default_title(): string
{
    return 'Unscramble the Sentence';
}

function us_normalize_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : us_default_title();
}

function us_normalize_payload($rawData): array
{
    $default = [
        'title' => us_default_title(),
        'sentences' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));

    $sentencesSource = [];
    if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        $sentencesSource = $decoded['sentences'];
    }

    $sentences = [];

    foreach ($sentencesSource as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sentence = '';
        if (isset($item['sentence']) && is_string($item['sentence'])) {
            $sentence = trim($item['sentence']);
        } elseif (isset($item['text']) && is_string($item['text'])) {
            $sentence = trim($item['text']);
        }

        if ($sentence === '') {
            continue;
        }

        $listenEnabled = true;
        if (array_key_exists('listen_enabled', $item)) {
            $listenEnabled = filter_var($item['listen_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $listenEnabled = $listenEnabled === null ? true : $listenEnabled;
        }

        $sentences[] = [
            'id' => trim((string) ($item['id'] ?? uniqid('us_'))),
            'sentence' => $sentence,
            'listen_enabled' => (bool) $listenEnabled,
        ];
    }

    return [
        'title' => us_normalize_title($title),
        'sentences' => $sentences,
    ];
}

function us_encode_payload(array $payload): string
{
    return json_encode([
        'title' => us_normalize_title((string) ($payload['title'] ?? '')),
        'sentences' => array_values($payload['sentences'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function us_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id' => '',
        'title' => us_default_title(),
        'sentences' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'unscramble'
            LIMIT 1
        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'unscramble'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = us_normalize_payload($row['data'] ?? null);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? us_default_title()),
        'sentences' => is_array($payload['sentences'] ?? null) ? $payload['sentences'] : [],
    ];
}

function us_save_activity(PDO $pdo, string $unit, string $activityId, string $title, array $sentences): string
{
    $json = us_encode_payload([
        'title' => $title,
        'sentences' => $sentences,
    ]);

    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id = :unit
              AND type = 'unscramble'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE id = :id
              AND type = 'unscramble'
        ");
        $stmt->execute([
            'data' => $json,
            'id' => $targetId,
        ]);

        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (
            :unit_id,
            'unscramble',
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
    $stmt->execute([
        'unit_id' => $unit,
        'unit_id2' => $unit,
        'data' => $json,
    ]);

    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = us_resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = us_load_activity($pdo, $unit, $activityId);
$activityTitle = (string) ($activity['title'] ?? us_default_title());
$sentences = is_array($activity['sentences'] ?? null) ? $activity['sentences'] : [];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string) ($_POST['activity_title'] ?? ''));
    $sentenceIds = isset($_POST['sentence_id']) && is_array($_POST['sentence_id']) ? $_POST['sentence_id'] : [];
    $sentenceTexts = isset($_POST['sentence']) && is_array($_POST['sentence']) ? $_POST['sentence'] : [];
    $listenEnabledValues = isset($_POST['listen_enabled']) && is_array($_POST['listen_enabled']) ? $_POST['listen_enabled'] : [];

    $sanitized = [];

    foreach ($sentenceTexts as $i => $textRaw) {
        $sentence = trim((string) $textRaw);
        if ($sentence === '') {
            continue;
        }

        $listenEnabled = isset($listenEnabledValues[$i]) && (string) $listenEnabledValues[$i] === '1';
        $sentenceId = trim((string) ($sentenceIds[$i] ?? uniqid('us_')));

        $sanitized[] = [
            'id' => $sentenceId !== '' ? $sentenceId : uniqid('us_'),
            'sentence' => $sentence,
            'listen_enabled' => $listenEnabled,
        ];
    }

    $savedActivityId = us_save_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    $params = [
        'unit=' . urlencode($unit),
        'saved=1'
    ];

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
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

if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>';
}
?>

<style>
.us-form{
    max-width:860px;
    margin:0 auto;
    text-align:left;
}
.title-box,
.sentence-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:14px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}
.title-box label,
.sentence-item label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}
.title-box input,
.sentence-item input,
.sentence-item textarea{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #d1d5db;
    box-sizing:border-box;
    margin-bottom:12px;
    font-size:14px;
}
.sentence-item textarea{
    min-height:80px;
    resize:vertical;
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
.save-btn{
    background:linear-gradient(180deg,#7c3aed,#6d28d9);
    color:#fff;
    padding:10px 20px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:800;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:15px;
    transition:transform .15s ease, filter .15s ease;
    box-shadow:0 2px 8px rgba(109,40,217,.22);
}
.save-btn:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}
.help{
    margin:-6px 0 12px 0;
    color:#6b7280;
    font-size:13px;
}
.checkbox-row{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:700;
    margin-bottom:10px;
}
.checkbox-row input[type="checkbox"]{
    width:auto;
    margin:0;
}
</style>

<form method="post" class="us-form" id="unscrambleForm">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Unscramble the sentences"
            required
        >
    </div>

    <div id="sentencesContainer">
        <?php foreach ($sentences as $item) { ?>
            <div class="sentence-item">
                <input type="hidden" name="sentence_id[]" value="<?= htmlspecialchars((string) ($item['id'] ?? uniqid('us_')), ENT_QUOTES, 'UTF-8') ?>">

                <label>Sentence (all words will be scrambled)</label>
                <textarea name="sentence[]" required><?= htmlspecialchars((string) ($item['sentence'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <p class="help">Write the complete sentence. Students will drag the scrambled words into the correct order.</p>

                <label class="checkbox-row">
                    <input type="hidden" name="listen_enabled[]" value="0">
                    <input type="checkbox" value="1" <?= !empty($item['listen_enabled']) ? 'checked' : '' ?> onchange="syncCheckboxValue(this)">
                    Activate Listen for this sentence
                </label>

                <button type="button" class="btn-remove" onclick="removeSentence(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addSentence()">+ Add Sentence</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function syncCheckboxValue(checkbox) {
    const hidden = checkbox.parentElement.querySelector('input[type="hidden"][name="listen_enabled[]"]');
    if (hidden) {
        hidden.value = checkbox.checked ? '1' : '0';
    }
    markChanged();
}

function removeSentence(button) {
    const item = button.closest('.sentence-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function addSentence() {
    const container = document.getElementById('sentencesContainer');
    const div = document.createElement('div');
    div.className = 'sentence-item';
    div.innerHTML = `
        <input type="hidden" name="sentence_id[]" value="us_${Date.now()}_${Math.floor(Math.random() * 1000)}">

        <label>Sentence (all words will be scrambled)</label>
        <textarea name="sentence[]" required></textarea>
        <p class="help">Write the complete sentence. Students will drag the scrambled words into the correct order.</p>

        <label class="checkbox-row">
            <input type="hidden" name="listen_enabled[]" value="1">
            <input type="checkbox" value="1" checked onchange="syncCheckboxValue(this)">
            Activate Listen for this sentence
        </label>

        <button type="button" class="btn-remove" onclick="removeSentence(this)">✖ Remove</button>
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

    document.querySelectorAll('.checkbox-row input[type="checkbox"]').forEach(function(cb) {
        const hidden = cb.parentElement.querySelector('input[type="hidden"][name="listen_enabled[]"]');
        if (hidden) {
            hidden.value = cb.checked ? '1' : '0';
        }
    });

    const form = document.getElementById('unscrambleForm');
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
render_activity_editor('🔀 Unscramble Editor', '🔀', $content);



<?php
// --- Lógica PHP debe ir antes de cualquier salida ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

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

function resolve_unit_from_activity(PDO $pdo, string $activityId): string {
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string)$row['unit_id'] : '';
}

function default_fillblank_title(): string { return 'Fill in the Blank'; }

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
    $json = json_encode([
        'instructions' => $payload['instructions'],
        'text' => $payload['text'],
        'wordbank' => $payload['wordbank'],
        'answerkey' => $payload['answerkey'],
    ], JSON_UNESCAPED_UNICODE);
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
    $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data, position, created_at) VALUES (:unit_id, 'fillblank', :data, (SELECT COALESCE(MAX(position), 0) + 1 FROM activities WHERE unit_id = :unit_id2), CURRENT_TIMESTAMP) RETURNING id");
    $stmt->execute(['unit_id' => $unit, 'unit_id2' => $unit, 'data' => $json]);
    return (string)$stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}
if ($unit === '') {
    die('Unit not specified');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instructions = trim((string)($_POST['instructions'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    $wordbank = trim((string)($_POST['wordbank'] ?? ''));
    $answerkey = trim((string)($_POST['answerkey'] ?? ''));
    $payload = [
        'instructions' => $instructions,
        'text' => $text,
        'wordbank' => $wordbank,
        'answerkey' => $answerkey,
    ];
    $savedActivityId = save_fillblank_activity($pdo, $unit, $activityId, $payload);
    $params = [
        'unit=' . urlencode($unit),
        'saved=1',
        'id=' . urlencode($savedActivityId)
    ];
    if ($assignment !== '') {
        $params[] = 'assignment=' . urlencode($assignment);
    }
    if ($source !== '') {
        $params[] = 'source=' . urlencode($source);
    }
    header('Location: editor.php?' . implode('&', $params));
    exit;
}

$activity = load_fillblank_activity($pdo, $unit, $activityId);

ob_start();
if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>';
}
?>
<form method="post" class="dd-form" id="fillblankForm">
  <div class="title-box">
    <label for="instructions">Instructions</label>
    <input id="instructions" type="text" name="instructions" value="<?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?>" required class="form-control" />
  </div>
  <div class="title-box">
    <label for="text">Text (use [blank] for missing words)</label>
    <textarea id="text" name="text" rows="6" required class="form-control"><?= htmlspecialchars($activity['text'], ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="title-box">
    <label for="wordbank">Word Bank (optional, comma separated)</label>
    <input id="wordbank" type="text" name="wordbank" value="<?= htmlspecialchars($activity['wordbank'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" />
  </div>
  <div class="title-box">
    <label for="answerkey">Answer Key (comma separated, in order)</label>
    <input id="answerkey" type="text" name="answerkey" value="<?= htmlspecialchars($activity['answerkey'], ENT_QUOTES, 'UTF-8') ?>" class="form-control" />
  </div>
  <div class="toolbar-row">
    <button type="button" class="btn-add" id="fbk-add-block">+ Add Block</button>
    <button type="submit" class="save-btn">💾 Save</button>
  </div>
</form>
<script>
document.getElementById('fbk-add-block').onclick = function() {
  alert('Add Block: Aquí puedes implementar la lógica para agregar bloques de texto o preguntas.');
};
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Fill-in-the-Blank Editor', 'fa-solid fa-pen-to-square', $content);
?>

<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: ../../../student_dashboard.php?error=access_denied');
    exit;
}

// Ensure teacher/admin is logged in
if (!isset($_SESSION['academic_logged']) || !$_SESSION['academic_logged']) {
    header('Location: ../../../login.php');
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
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_external_title(): string
{
    return 'External Resource';
}

function default_external_button_label(): string
{
    return 'Open Resource';
}

function normalize_external_payload($rawData): array
{
    $default = [
        'title' => default_external_title(),
        'url' => '',
        'description' => '',
        'button_label' => default_external_button_label(),
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    $url = trim((string) ($decoded['url'] ?? ''));
    $description = trim((string) ($decoded['description'] ?? ''));
    $buttonLabel = trim((string) ($decoded['button_label'] ?? ''));

    return [
        'title' => $title !== '' ? $title : default_external_title(),
        'url' => $url,
        'description' => $description,
        'button_label' => $buttonLabel !== '' ? $buttonLabel : default_external_button_label(),
    ];
}

function encode_external_payload(array $payload): string
{
    return json_encode([
        'title' => trim((string) ($payload['title'] ?? '')) !== '' ? trim((string) $payload['title']) : default_external_title(),
        'url' => trim((string) ($payload['url'] ?? '')),
        'description' => trim((string) ($payload['description'] ?? '')),
        'button_label' => trim((string) ($payload['button_label'] ?? '')) !== '' ? trim((string) $payload['button_label']) : default_external_button_label(),
    ], JSON_UNESCAPED_UNICODE);
}

function load_external_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id' => '',
        'title' => default_external_title(),
        'url' => '',
        'description' => '',
        'button_label' => default_external_button_label(),
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'external'
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
              AND type = 'external'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_external_payload($row['data'] ?? null);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? default_external_title()),
        'url' => (string) ($payload['url'] ?? ''),
        'description' => (string) ($payload['description'] ?? ''),
        'button_label' => (string) ($payload['button_label'] ?? default_external_button_label()),
    ];
}

function save_external_activity(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json = encode_external_payload($payload);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id = :unit
              AND type = 'external'
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
              AND type = 'external'
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
            'external',
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
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_external_activity($pdo, $unit, $activityId);

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['activity_title'] ?? ''));
    $url = trim((string) ($_POST['url'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $buttonLabel = trim((string) ($_POST['button_label'] ?? ''));

    if ($url !== '') {
        $savedActivityId = save_external_activity($pdo, $unit, $activityId, [
            'title' => $title,
            'url' => $url,
            'description' => $description,
            'button_label' => $buttonLabel,
        ]);

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
}

ob_start();
?>

<style>
.ex-form{
    max-width:860px;
    margin:0 auto;
    text-align:left;
}
.ex-shell{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:20px;
    box-shadow:0 10px 24px rgba(0,0,0,.08);
    overflow:hidden;
}
.ex-hero{
    background:linear-gradient(135deg,#e0ecff 0%, #f4f8ff 100%);
    padding:22px 24px 18px;
    border-bottom:1px solid #e5e7eb;
}
.ex-hero-title{
    margin:0 0 8px 0;
    color:#1d4ed8;
    font-size:26px;
    font-weight:800;
}
.ex-hero-text{
    margin:0;
    color:#475569;
    font-size:14px;
    line-height:1.5;
}
.ex-body{
    padding:20px;
}
.form-group{
    margin-bottom:16px;
}
.form-group label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}
.form-group input,
.form-group textarea{
    width:100%;
    padding:12px 14px;
    border:1px solid #d1d5db;
    border-radius:12px;
    box-sizing:border-box;
    font-size:14px;
    background:#fff;
}
.form-group textarea{
    min-height:110px;
    resize:vertical;
}
.ex-preview{
    margin-top:8px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:18px;
}
.preview-label{
    font-size:12px;
    font-weight:800;
    color:#64748b;
    text-transform:uppercase;
    letter-spacing:.04em;
    margin-bottom:8px;
}
.preview-title{
    margin:0 0 8px 0;
    color:#0f172a;
    font-size:22px;
    font-weight:800;
}
.preview-desc{
    margin:0 0 12px 0;
    color:#475569;
    line-height:1.5;
}
.preview-url{
    display:block;
    color:#2563eb;
    text-decoration:none;
    word-break:break-word;
    margin-bottom:14px;
    font-size:14px;
}
.preview-btn{
    display:inline-block;
    background:#0b5ed7;
    color:#fff;
    border:none;
    padding:12px 18px;
    border-radius:14px;
    font-weight:800;
    font-size:14px;
}
.toolbar-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:center;
    margin-top:14px;
}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>
<?php } ?>

<form method="post" class="ex-form" id="externalForm">
    <div class="ex-shell">
        <div class="ex-hero">
            <h2 class="ex-hero-title">🌐 External Activity</h2>
            <p class="ex-hero-text">Create an activity that opens an external resource with a cleaner presentation consistent with the rest of the system.</p>
        </div>

        <div class="ex-body">
            <div class="form-group">
                <label for="activity_title">Activity title</label>
                <input
                    id="activity_title"
                    type="text"
                    name="activity_title"
                    value="<?= htmlspecialchars((string) ($activity['title'] ?? default_external_title()), ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Example: Visit the science website"
                    required
                >
            </div>

            <div class="form-group">
                <label for="url">Resource URL</label>
                <input
                    id="url"
                    type="url"
                    name="url"
                    value="<?= htmlspecialchars((string) ($activity['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="https://example.com"
                    required
                >
            </div>

            <div class="form-group">
                <label for="description">Short description</label>
                <textarea
                    id="description"
                    name="description"
                    placeholder="Example: Open this page and explore the interactive content."><?= htmlspecialchars((string) ($activity['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="form-group">
                <label for="button_label">Button text</label>
                <input
                    id="button_label"
                    type="text"
                    name="button_label"
                    value="<?= htmlspecialchars((string) ($activity['button_label'] ?? default_external_button_label()), ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Open Resource"
                    required
                >
            </div>

            <div class="ex-preview">
                <div class="preview-label">Preview</div>
                <h3 class="preview-title" id="previewTitle"><?= htmlspecialchars((string) ($activity['title'] ?? default_external_title()), ENT_QUOTES, 'UTF-8') ?></h3>
                <p class="preview-desc" id="previewDescription"><?= htmlspecialchars((string) ($activity['description'] ?? 'External resource for this activity.'), ENT_QUOTES, 'UTF-8') ?></p>
                <a class="preview-url" id="previewUrl" href="<?= htmlspecialchars((string) ($activity['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) ($activity['url'] ?? 'https://example.com'), ENT_QUOTES, 'UTF-8') ?></a>
                <span class="preview-btn" id="previewButton"><?= htmlspecialchars((string) ($activity['button_label'] ?? default_external_button_label()), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="toolbar-row">
                <button type="submit" class="save-btn">💾 Save</button>
            </div>
        </div>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
    updatePreview();
}

function updatePreview() {
    const title = document.getElementById('activity_title').value.trim();
    const url = document.getElementById('url').value.trim();
    const description = document.getElementById('description').value.trim();
    const buttonLabel = document.getElementById('button_label').value.trim();

    document.getElementById('previewTitle').textContent = title || 'External Resource';
    document.getElementById('previewDescription').textContent = description || 'External resource for this activity.';

    const previewUrl = document.getElementById('previewUrl');
    previewUrl.textContent = url || 'https://example.com';
    previewUrl.href = url || '#';

    document.getElementById('previewButton').textContent = buttonLabel || 'Open Resource';
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('externalForm');
    const elements = form.querySelectorAll('input, textarea');

    elements.forEach(function (el) {
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });

    form.addEventListener('submit', function () {
        formSubmitted = true;
        formChanged = false;
    });

    updatePreview();
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
render_activity_editor('🌐 External Resource Editor', '🌐', $content);

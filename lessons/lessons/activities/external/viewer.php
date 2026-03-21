<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

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

function load_external_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'title' => default_external_title(),
        'url' => '',
        'description' => '',
        'button_label' => default_external_button_label(),
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("
            SELECT data
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
            SELECT data
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

    return normalize_external_payload($row['data'] ?? null);
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_external_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? default_external_title());
$url = trim((string) ($activity['url'] ?? ''));
$description = trim((string) ($activity['description'] ?? ''));
$buttonLabel = trim((string) ($activity['button_label'] ?? default_external_button_label()));

ob_start();
?>

<style>
.ex-viewer{
    max-width:860px;
    margin:0 auto;
}
.ex-card{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:24px;
    overflow:hidden;
    box-shadow:0 14px 30px rgba(0,0,0,.08);
}
.ex-banner{
    padding:28px 28px 18px;
    background:linear-gradient(135deg,#dbeafe 0%, #eff6ff 40%, #f8fbff 100%);
    border-bottom:1px solid #e5e7eb;
}
.ex-badge{
    display:inline-block;
    margin-bottom:12px;
    padding:8px 12px;
    border-radius:999px;
    background:#ffffff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:800;
    box-shadow:0 4px 10px rgba(0,0,0,.06);
}
.ex-heading{
    margin:0 0 10px 0;
    color:#0f172a;
    font-size:30px;
    font-weight:800;
}
.ex-text{
    margin:0;
    color:#475569;
    line-height:1.6;
    font-size:15px;
}
.ex-body{
    padding:24px 28px 28px;
}
.ex-url-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:16px;
    margin-bottom:18px;
}
.ex-url-label{
    font-size:12px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:#64748b;
    margin-bottom:8px;
}
.ex-url{
    color:#2563eb;
    word-break:break-word;
    text-decoration:none;
    font-size:15px;
}
.ex-actions{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}
.ex-btn{
    border:none;
    border-radius:14px;
    padding:13px 18px;
    font-weight:800;
    font-size:14px;
    cursor:pointer;
}
.ex-btn.primary{
    background:#0b5ed7;
    color:#fff;
}
.ex-btn.secondary{
    background:#e2e8f0;
    color:#0f172a;
}
.ex-empty{
    text-align:center;
    color:#b91c1c;
    font-weight:700;
    padding:30px 10px;
}
</style>

<div class="ex-viewer">
    <div class="ex-card">
        <?php if ($url === '') { ?>
            <div class="ex-empty">No URL configured for this activity.</div>
        <?php } else { ?>
            <div class="ex-banner">
                <span class="ex-badge">🌐 External Activity</span>
                <h2 class="ex-heading"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="ex-text"><?= htmlspecialchars($description !== '' ? $description : 'Click the button below to open the external resource for this activity.', ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="ex-body">
                <div class="ex-url-box">
                    <div class="ex-url-label">Resource link</div>
                    <a class="ex-url" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></a>
                </div>

                <div class="ex-actions">
                    <button class="ex-btn primary" type="button" onclick="openExternal()">
                        🔗 <?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button class="ex-btn secondary" type="button" onclick="copyExternalLink()">
                        📋 Copy Link
                    </button>
                </div>
            </div>
        <?php } ?>
    </div>
</div>

<script>
const externalUrl = <?= json_encode($url, JSON_UNESCAPED_UNICODE) ?>;

function openExternal() {
    if (!externalUrl) return;
    window.open(externalUrl, '_blank');
}

async function copyExternalLink() {
    if (!externalUrl) return;

    try {
        await navigator.clipboard.writeText(externalUrl);
        alert('Link copied');
    } catch (e) {
        alert('Could not copy the link');
    }
}
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🌐', $content);

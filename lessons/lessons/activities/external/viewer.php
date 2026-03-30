<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
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
.ex-intro{
    margin-bottom:18px;
    padding:24px 26px;
    border-radius:26px;
    border:1px solid #cdeee4;
    background:linear-gradient(135deg,#e6fffb 0%, #eefbf3 48%, #fff6e6 100%);
    box-shadow:0 16px 34px rgba(15, 23, 42, .09);
}
.ex-intro h2{
    margin:0 0 8px 0;
    color:#0f766e;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-size:30px;
    line-height:1.1;
}
.ex-intro p{
    margin:0;
    color:#4b5563;
    line-height:1.6;
    font-size:16px;
}
.ex-card{
    background:linear-gradient(180deg,#fffdf9 0%, #ffffff 100%);
    border:1px solid #cdeee4;
    border-radius:24px;
    overflow:hidden;
    box-shadow:0 14px 30px rgba(15,23,42,.08);
}
.ex-banner{
    padding:28px 28px 18px;
    background:linear-gradient(135deg,#dffcf7 0%, #f0fdf4 40%, #fff8e7 100%);
    border-bottom:1px solid #cdeee4;
}
.ex-badge{
    display:inline-block;
    margin-bottom:12px;
    padding:8px 12px;
    border-radius:999px;
    background:#ffffff;
    color:#0f766e;
    font-size:12px;
    font-weight:800;
    box-shadow:0 4px 10px rgba(0,0,0,.06);
}
.ex-heading{
    margin:0 0 10px 0;
    color:#0f172a;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
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
    background:#f8fffd;
    border:1px solid #cdeee4;
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
    color:#0f766e;
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
    border-radius:999px;
    padding:13px 18px;
    font-weight:800;
    font-size:14px;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(15,23,42,.12);
    transition:transform .15s ease, filter .15s ease;
}
.ex-btn:hover{
    filter:brightness(1.04);
    transform:translateY(-1px);
}
.ex-btn.primary{
    background:linear-gradient(180deg,#14b8a6 0%, #0f766e 100%);
    color:#fff;
}
.ex-btn.secondary{
    background:linear-gradient(180deg,#fbbf24 0%, #f59e0b 100%);
    color:#78350f;
}
.ex-btn.done{
    background:linear-gradient(180deg,#16a34a 0%, #15803d 100%);
    color:#fff;
}
.ex-empty{
    text-align:center;
    color:#b91c1c;
    font-weight:700;
    padding:30px 10px;
}
@media (max-width:760px){
    .ex-intro{padding:20px 18px}
    .ex-intro h2{font-size:26px}
    .ex-actions{flex-direction:column}
    .ex-btn{width:100%}
}
.ex-completed-screen{display:none;text-align:center;max-width:600px;margin:40px auto;padding:40px 20px}
.ex-completed-screen.active{display:block}
.ex-completed-icon{font-size:80px;margin-bottom:20px}
.ex-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:36px;font-weight:700;color:#be185d;margin:0 0 14px;line-height:1.2}
.ex-completed-text{font-size:16px;color:#6b4b5f;line-height:1.6;margin:0 0 28px}
.ex-completed-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.ex-completed-btn{display:inline-block;padding:12px 24px;border:none;border-radius:999px;background:linear-gradient(180deg,#db2777 0%,#be185d 100%);color:#fff;font-weight:700;font-size:16px;cursor:pointer;box-shadow:0 10px 24px rgba(0,0,0,.14);transition:transform .18s ease,filter .18s ease}
.ex-completed-btn:hover{transform:scale(1.05);filter:brightness(1.07)}
</style>

<div class="ex-viewer">
    <section class="ex-intro">
        <h2>External Resource</h2>
        <p>Open the resource in a new tab or copy the link quickly. The card layout now keeps spacing and buttons aligned on mobile screens too.</p>
    </section>

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

                    <button class="ex-btn done" type="button" id="ex-mark-done-btn">
                        ✓ Mark as Completed
                    </button>
                </div>
            </div>
        <?php } ?>
    </div>
</div>

<div id="ex-completed-screen" class="ex-completed-screen">
    <div class="ex-completed-icon">✅</div>
    <h2 class="ex-completed-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="ex-completed-text">You've completed this external resource activity. Great work!</p>
    <div class="ex-completed-actions">
        <button type="button" class="ex-completed-btn" id="ex-restart-btn">Back to Resource</button>
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

(function(){
    const markDoneBtn = document.getElementById('ex-mark-done-btn');
    const completedScreen = document.getElementById('ex-completed-screen');
    const viewerEl = document.querySelector('.ex-viewer');
    const restartBtn = document.getElementById('ex-restart-btn');

    if (markDoneBtn && completedScreen) {
        markDoneBtn.addEventListener('click', function(){
            if (viewerEl) viewerEl.style.display = 'none';
            completedScreen.classList.add('active');
        });
    }

    if (restartBtn && completedScreen) {
        restartBtn.addEventListener('click', function(){
            completedScreen.classList.remove('active');
            if (viewerEl) viewerEl.style.display = '';
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🌐', $content);

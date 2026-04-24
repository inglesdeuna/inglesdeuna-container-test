<?php
// Viewer for the "Order the Sentences" activity. Displays the activity in a student-facing view
// and supports drag‑and‑drop reordering. It also respects the display mode (text vs image)
// configured in the editor and shows media (video, audio, or TTS) if provided.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';

// Utilities copied from the editor to resolve and normalize activities
function os_default_title(): string { return 'Order the Sentences'; }

function os_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string) ($row['unit_id'] ?? '') : '';
}

function os_normalize(mixed $rawData): array
{
    $default = [
        'title'        => os_default_title(),
        'instructions' => 'Listen and put the sentences in the correct order.',
        'media_type'   => 'tts',
        'media_url'    => '',
        'tts_text'     => '',
        'sentences'    => [],
    ];
    if ($rawData === null || $rawData === '') return $default;
    $d = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($d)) return $default;
    $sentences = [];
    foreach ((array) ($d['sentences'] ?? []) as $s) {
        $text = trim((string) ($s['text'] ?? ''));
        $image = isset($s['image']) ? trim((string) $s['image']) : '';
        $display = isset($s['display']) && $s['display'] === 'image' ? 'image' : 'text';
        if ($text === '' && $image === '') continue;
        $sentences[] = [
            'id'      => trim((string) ($s['id'] ?? uniqid('os_'))),
            'text'    => $text,
            'image'   => $image,
            'display' => $display,
        ];
    }
    return [
        'title'        => trim((string) ($d['title'] ?? '')) ?: os_default_title(),
        'instructions' => trim((string) ($d['instructions'] ?? '')) ?: $default['instructions'],
        'media_type'   => in_array($d['media_type'] ?? '', ['tts','video','audio','none'], true)
                            ? $d['media_type'] : 'tts',
        'media_url'    => trim((string) ($d['media_url'] ?? '')),
        'tts_text'     => trim((string) ($d['tts_text'] ?? '')),
        'sentences'    => $sentences,
    ];
}

function os_load(PDO $pdo, string $unit, string $activityId): array
{
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='order_sentences' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:u AND type='order_sentences' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['u' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return ['id' => '', ...os_normalize(null)];
    $p = os_normalize($row['data'] ?? null);
    $p['id'] = (string) ($row['id'] ?? '');
    return $p;
}

// Identify activity by id or unit from GET parameters
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
if ($unit === '' && $activityId !== '') {
    $unit = os_resolve_unit($pdo, $activityId);
}
if ($unit === '') {
    die('Unit not specified');
}

$activity = os_load($pdo, $unit, $activityId);

// Shuffle sentences for the viewer so students must reorder them
$shuffled = $activity['sentences'];
shuffle($shuffled);

// Prepare TTS text fallback: if tts_text is empty, use concatenated sentences
$ttsText = $activity['tts_text'];
if ($ttsText === '') {
    $texts = [];
    foreach ($activity['sentences'] as $s) {
        if ($s['text'] !== '') $texts[] = $s['text'];
    }
    $ttsText = implode('. ', $texts);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
    body { background:#f0f2f5; margin:0; padding:20px; font-family:'Nunito','Segoe UI',sans-serif; }
    .os-view-card { max-width:860px; margin:0 auto; padding:16px; background:#f9fafb; border-radius:14px; border:1px solid #e5e7eb; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
    .os-view-card h2 { margin-top:0; font-size:28px; color:#0f172a; }
    .os-instruction { margin-bottom:16px; color:#475569; font-size:16px; font-weight:600; }
    .media-box { margin-bottom:20px; }
    .media-box video, .media-box audio { width:100%; max-height:350px; border-radius:10px; }
    #tts-play-btn { padding:10px 18px; border:none; border-radius:10px; font-size:14px; font-weight:700; background:linear-gradient(180deg,#0d9488,#0f766e); color:#fff; cursor:pointer; box-shadow:0 2px 6px rgba(13,148,136,.25); }
    #tts-play-btn:hover { filter:brightness(1.07); }
    .os-sentences-list { list-style:none; padding:0; margin:0; }
    .os-sentence-view-item { display:flex; align-items:center; justify-content:center; min-height:70px; margin-bottom:12px; padding:10px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; cursor:grab; transition:background .2s; }
    .os-sentence-view-item.image-mode { background:#fff7ed; border-color:#fdba74; }
    .os-sentence-view-item.over { border-color:#60a5fa; }
    .os-sentence-view-item img { max-height:80px; max-width:100%; border-radius:8px; }
    .os-sentence-view-item .text { font-size:16px; color:#1e293b; font-weight:600; }
    </style>
</head>
<body>
<div class="os-view-card">
    <h2><?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="os-instruction">
        <?= nl2br(htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8')) ?>
    </p>
    <?php if ($activity['media_type'] === 'video' && $activity['media_url'] !== ''): ?>
        <div class="media-box"><video controls src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>"></video></div>
    <?php elseif ($activity['media_type'] === 'audio' && $activity['media_url'] !== ''): ?>
        <div class="media-box"><audio controls src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>"></audio></div>
    <?php elseif ($activity['media_type'] === 'tts'): ?>
        <div class="media-box"><button id="tts-play-btn">🔊 Listen</button></div>
    <?php endif; ?>

    <ul id="os-view-sentences" class="os-sentences-list">
        <?php foreach ($shuffled as $s): ?>
        <li class="os-sentence-view-item<?= ($s['display'] === 'image') ? ' image-mode' : '' ?>" draggable="true">
            <?php if ($s['display'] === 'image' && !empty($s['image'])): ?>
                <img src="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
                <span class="text">
                    <?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
// Drag and drop functionality for sentence reorder in the viewer
var dragSrcEl = null;
function handleDragStart(e) {
    dragSrcEl = this;
    e.dataTransfer.effectAllowed = 'move';
    this.style.opacity = '0.4';
}
function handleDragOver(e) {
    if (e.preventDefault) e.preventDefault();
    return false;
}
function handleDragEnter(e) {
    this.classList.add('over');
}
function handleDragLeave(e) {
    this.classList.remove('over');
}
function handleDrop(e) {
    if (e.stopPropagation) e.stopPropagation();
    if (dragSrcEl !== this) {
        var list = document.getElementById('os-view-sentences');
        list.insertBefore(dragSrcEl, this);
    }
    return false;
}
function handleDragEnd(e) {
    this.style.opacity = '1';
    document.querySelectorAll('.os-sentence-view-item').forEach(function(item) {
        item.classList.remove('over');
    });
}
document.querySelectorAll('.os-sentence-view-item').forEach(function(item) {
    item.addEventListener('dragstart', handleDragStart, false);
    item.addEventListener('dragenter', handleDragEnter, false);
    item.addEventListener('dragover', handleDragOver, false);
    item.addEventListener('dragleave', handleDragLeave, false);
    item.addEventListener('drop', handleDrop, false);
    item.addEventListener('dragend', handleDragEnd, false);
});

// TTS playback using Web Speech API
<?php if ($activity['media_type'] === 'tts'): ?>
var ttsBtn = document.getElementById('tts-play-btn');
if (ttsBtn) {
    ttsBtn.addEventListener('click', function() {
        if (!window.speechSynthesis) return;
        var utterance = new SpeechSynthesisUtterance(<?= json_encode($ttsText) ?>);
        utterance.lang = 'en-US';
        window.speechSynthesis.speak(utterance);
    });
}
<?php endif; ?>
</script>
</body>
</html>

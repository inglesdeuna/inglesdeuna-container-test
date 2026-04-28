<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function os_viewer_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string) ($row['unit_id'] ?? '') : '';
}

function os_viewer_normalize($rawData): array
{
    $default = [
        'title'        => 'Order the Sentences',
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
        if (!is_array($s)) continue;
        $text  = trim((string) ($s['text']  ?? ''));
        $image = trim((string) ($s['image'] ?? ''));
        if ($text === '' && $image === '') continue;
        $sentences[] = [
            'id'    => trim((string) ($s['id'] ?? uniqid('os_'))),
            'text'  => $text,
            'image' => $image,
        ];
    }

    return [
        'title'        => trim((string) ($d['title']        ?? '')) ?: $default['title'],
        'instructions' => trim((string) ($d['instructions'] ?? '')) ?: $default['instructions'],
        'media_type'   => in_array($d['media_type'] ?? '', ['tts', 'video', 'audio', 'none'], true) ? $d['media_type'] : 'tts',
        'media_url'    => trim((string) ($d['media_url']    ?? '')),
        'tts_text'     => trim((string) ($d['tts_text']     ?? '')),
        'sentences'    => $sentences,
    ];
}

function os_viewer_load(PDO $pdo, string $activityId, string $unit): array
{
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id=:id AND type='order_sentences' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id=:u AND type='order_sentences' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['u' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return os_viewer_normalize(null);
    $p = os_viewer_normalize($row['data'] ?? null);
    $p['id'] = (string) ($row['id'] ?? '');
    return $p;
}

if ($unit === '' && $activityId !== '') {
    $unit = os_viewer_resolve_unit($pdo, $activityId);
}

$activity    = os_viewer_load($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? 'Order the Sentences');
$sentences   = (array)  ($activity['sentences'] ?? []);

if (count($sentences) === 0) {
    die('No sentences configured for this activity.');
}

$correctOrder = array_column($sentences, 'id');
$shuffled     = $sentences;
shuffle($shuffled);

ob_start();
?>
<style>
.os-stage {
    max-width: 860px;
    margin: 0 auto;
}

.os-intro {
    margin-bottom: 18px;
    padding: 20px 22px;
    border-radius: 20px;
    border: 1px solid #d9cff6;
    background: linear-gradient(135deg, #eef4ff 0%, #f8ebff 48%, #e8fff7 100%);
    box-shadow: 0 16px 34px rgba(15, 23, 42, .09);
}

.os-intro h2 {
    margin: 0 0 6px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 26px;
    line-height: 1.1;
    color: #4c1d95;
}

.os-intro p {
    margin: 0;
    color: #5b516f;
    font-size: 15px;
    line-height: 1.5;
}

.os-media {
    margin-bottom: 16px;
}

.os-media video,
.os-media audio {
    width: 100%;
    border-radius: 10px;
}

.os-sentence-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

.os-sentence-item {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    background: #f9fafb;
    padding: 10px 12px;
    cursor: grab;
    user-select: none;
    transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
}

.os-sentence-item:hover {
    border-color: #a78bfa;
    background: #faf5ff;
    box-shadow: 0 4px 12px rgba(124, 58, 237, .10);
}

.os-sentence-item.dragging {
    opacity: 0.45;
    border-color: #7c3aed;
}

.os-sentence-item.over {
    border: 2px dashed #7c3aed;
    background: #ede9fe;
}

.os-sentence-item img {
    max-width: 70px;
    max-height: 70px;
    border-radius: 8px;
    flex-shrink: 0;
}

.os-sentence-item .os-sentence-text {
    flex: 1;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 15px;
    color: #1e293b;
    line-height: 1.4;
}

.os-handle {
    color: #94a3b8;
    font-size: 16px;
    cursor: grab;
    flex-shrink: 0;
}

.os-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    margin-top: 6px;
}

.os-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 18px;
    border: none;
    border-radius: 999px;
    color: #fff;
    font-weight: 800;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 14px;
    min-width: 142px;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 10px 22px rgba(15, 23, 42, .12);
    transition: transform .15s ease, filter .15s ease;
}

.os-btn:hover {
    filter: brightness(1.04);
    transform: translateY(-1px);
}

.os-btn-check { background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%); }
.os-btn-tts   { background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%); }

.os-result {
    font-size: 16px;
    font-weight: 800;
    text-align: center;
    margin-top: 14px;
    min-height: 24px;
}

.os-result.good { color: #16a34a; }
.os-result.bad  { color: #dc2626; }

@media (max-width: 640px) {
    .os-controls { flex-direction: column; align-items: center; }
    .os-btn { width: 100%; max-width: 300px; }
}
</style>

<div class="os-stage">
    <div class="os-intro">
        <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= nl2br(htmlspecialchars($activity['instructions'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
    </div>

    <?php if (($activity['media_type'] ?? '') === 'video' && !empty($activity['media_url'])): ?>
    <div class="os-media">
        <video controls src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>"></video>
    </div>
    <?php elseif (($activity['media_type'] ?? '') === 'audio' && !empty($activity['media_url'])): ?>
    <div class="os-media">
        <audio controls src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>"></audio>
    </div>
    <?php elseif (($activity['media_type'] ?? '') === 'tts'): ?>
    <div class="os-media">
        <button type="button" id="os-tts-btn" class="os-btn os-btn-tts">🔊 Play Audio</button>
    </div>
    <?php endif; ?>

    <div id="os-sentence-list" class="os-sentence-list">
        <?php foreach ($shuffled as $s): ?>
        <div class="os-sentence-item" draggable="true" data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
            <span class="os-handle">☰</span>
            <?php if (!empty($s['image'])): ?>
                <img src="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php endif; ?>
            <?php if (!empty($s['text'])): ?>
                <span class="os-sentence-text"><?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="os-controls">
        <button type="button" id="os-check" class="os-btn os-btn-check">Check Order</button>
    </div>
    <p id="os-result" class="os-result"></p>
</div>

<script>
(function () {
    var list      = document.getElementById('os-sentence-list');
    var resultEl  = document.getElementById('os-result');
    var dragSrc   = null;

    var correctOrder   = <?= json_encode($correctOrder,   JSON_UNESCAPED_UNICODE) ?>;
    var OS_RETURN_TO   = <?= json_encode($returnTo,       JSON_UNESCAPED_UNICODE) ?>;
    var OS_ACTIVITY_ID = <?= json_encode($activityId,     JSON_UNESCAPED_UNICODE) ?>;
    var OS_TOTAL       = correctOrder.length;

    function items() { return list.querySelectorAll('.os-sentence-item'); }

    function handleDragStart(e) {
        dragSrc = this;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dataset.id);
        this.classList.add('dragging');
    }
    function handleDragEnd() {
        this.classList.remove('dragging');
        items().forEach(function (el) { el.classList.remove('over'); });
    }
    function handleDragOver(e)  { e.preventDefault(); return false; }
    function handleDragEnter()  { this.classList.add('over'); }
    function handleDragLeave()  { this.classList.remove('over'); }
    function handleDrop(e) {
        e.stopPropagation();
        var dragId = e.dataTransfer.getData('text/plain');
        var dropId = this.dataset.id;
        if (dragId !== dropId) {
            var dragEl = list.querySelector('[data-id="' + dragId + '"]');
            var dropEl = list.querySelector('[data-id="' + dropId + '"]');
            if (dragEl && dropEl) {
                var next = dragEl.nextSibling === dropEl ? dragEl : dragEl.nextSibling;
                list.insertBefore(dragEl, dropEl);
                list.insertBefore(dropEl, next);
            }
        }
        return false;
    }

    function attachDrag(el) {
        el.addEventListener('dragstart',  handleDragStart);
        el.addEventListener('dragend',    handleDragEnd);
        el.addEventListener('dragover',   handleDragOver);
        el.addEventListener('dragenter',  handleDragEnter);
        el.addEventListener('dragleave',  handleDragLeave);
        el.addEventListener('drop',       handleDrop);
    }

    items().forEach(attachDrag);

    document.getElementById('os-check').addEventListener('click', function () {
        var userOrder = [];
        items().forEach(function (el) { userOrder.push(el.dataset.id); });
        var isCorrect = JSON.stringify(userOrder) === JSON.stringify(correctOrder);
        resultEl.textContent = isCorrect ? '✅ Correct! Well done.' : '❌ Not correct. Try again.';
        resultEl.className   = 'os-result ' + (isCorrect ? 'good' : 'bad');

        if (isCorrect && OS_RETURN_TO && OS_ACTIVITY_ID && OS_TOTAL > 0) {
            var joiner  = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = OS_RETURN_TO + joiner +
                'activity_percent=100&activity_errors=0' +
                '&activity_total='  + encodeURIComponent(String(OS_TOTAL)) +
                '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
                '&activity_type=order_sentences';
            fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { if (!r.ok) throw new Error(); })
                .catch(function () {
                    try {
                        if (window.top && window.top !== window.self) {
                            window.top.location.href = saveUrl;
                            return;
                        }
                    } catch (ex) {}
                    window.location.href = saveUrl;
                });
        }
    });

    <?php if (($activity['media_type'] ?? '') === 'tts'): ?>
    var ttsBtn      = document.getElementById('os-tts-btn');
    var ttsText     = <?= json_encode(!empty($activity['tts_text']) ? $activity['tts_text'] : implode('. ', array_column($sentences, 'text')), JSON_UNESCAPED_UNICODE) ?>;
    var ttsUtter    = null;
    var ttsSpeaking = false;

    if (ttsBtn) {
        ttsBtn.addEventListener('click', function () {
            if (ttsSpeaking && speechSynthesis.speaking && !speechSynthesis.paused) {
                speechSynthesis.pause();
                ttsBtn.textContent = '▶ Resume';
                return;
            }
            if (speechSynthesis.paused) {
                speechSynthesis.resume();
                ttsBtn.textContent = '⏸ Pause';
                return;
            }
            speechSynthesis.cancel();
            ttsUtter        = new SpeechSynthesisUtterance(ttsText);
            ttsUtter.lang   = 'en-US';
            ttsUtter.rate   = 0.9;
            ttsUtter.onstart = function () { ttsSpeaking = true;  ttsBtn.textContent = '⏸ Pause'; };
            ttsUtter.onend   = function () { ttsSpeaking = false; ttsBtn.textContent = '🔊 Play Audio'; };
            ttsUtter.onerror = function () { ttsSpeaking = false; ttsBtn.textContent = '🔊 Play Audio'; };
            speechSynthesis.speak(ttsUtter);
        });
    }
    <?php endif; ?>
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

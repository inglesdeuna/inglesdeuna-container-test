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

function os_viewer_normalize(mixed $rawData): array
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
        $text    = trim((string) ($s['text']    ?? ''));
        $image   = trim((string) ($s['image']   ?? ''));
        $display = trim((string) ($s['display'] ?? 'both'));
        if (!in_array($display, ['text', 'image', 'both'], true)) $display = 'both';
        if ($text === '' && $image === '') continue;
        $sentences[] = [
            'id'      => trim((string) ($s['id'] ?? uniqid('os_'))),
            'text'    => $text,
            'image'   => $image,
            'display' => $display,
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
// Shuffle until the order differs from correct (avoid trivial already-correct start)
$attempts = 0;
do {
    shuffle($shuffled);
    $attempts++;
} while ($attempts < 10 && array_column($shuffled, 'id') === $correctOrder);

ob_start();
?>
<style>
.os-stage {
    max-width: 860px;
    margin: 0 auto;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
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

/* ── Sentence list ── */
.os-sentence-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 18px;
}

.os-sentence-item {
    display: flex;
    align-items: center;
    gap: 12px;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    background: #ffffff;
    padding: 12px 16px;
    cursor: grab;
    user-select: none;
    transition: border-color .15s ease, background .15s ease, box-shadow .15s ease, transform .1s ease;
    min-height: 72px;
}

.os-sentence-item:hover {
    border-color: #a78bfa;
    background: #faf5ff;
    box-shadow: 0 6px 18px rgba(124, 58, 237, .12);
}

.os-sentence-item.dragging {
    opacity: 0.4;
    border-color: #7c3aed;
    transform: scale(1.02);
}

.os-sentence-item.over {
    border: 2px dashed #7c3aed;
    background: #ede9fe;
}

.os-sentence-item.correct-pos {
    border-color: #16a34a;
    background: #f0fdf4;
}

.os-sentence-item.wrong-pos {
    border-color: #dc2626;
    background: #fef2f2;
}

/* Large image for preschool */
.os-sentence-item img {
    width: 120px;
    height: 120px;
    border-radius: 12px;
    flex-shrink: 0;
    object-fit: cover;
    box-shadow: 0 2px 8px rgba(0,0,0,.10);
}

.os-sentence-text {
    flex: 1;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.4;
}

.os-handle {
    color: #c4b5fd;
    font-size: 20px;
    cursor: grab;
    flex-shrink: 0;
}

/* ── Controls ── */
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

.os-btn:disabled {
    opacity: .5;
    cursor: default;
    transform: none;
    filter: none;
}

.os-btn-check  { background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%); }
.os-btn-tts    { background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%); }
.os-btn-show   { background: linear-gradient(180deg, #d8b4fe 0%, #a855f7 100%); }
.os-btn-next   { background: linear-gradient(180deg, #2dd4bf 0%, #0f766e 100%); }

/* ── Feedback ── */
#os-feedback {
    font-size: 20px;
    font-weight: 800;
    text-align: center;
    margin-top: 14px;
    min-height: 28px;
}

#os-feedback.good { color: #16a34a; }
#os-feedback.bad  { color: #dc2626; }

/* ── Completed screen ── */
.os-completed-screen {
    display: none;
    text-align: center;
    max-width: 560px;
    margin: 0 auto;
    padding: 40px 20px;
}

.os-completed-screen.active { display: block; }

.os-completed-icon  { font-size: 80px; margin-bottom: 20px; }

.os-completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 36px;
    font-weight: 700;
    color: #4c1d95;
    margin: 0 0 12px;
    line-height: 1.2;
}

.os-completed-text {
    font-size: 16px;
    color: #5b516f;
    line-height: 1.6;
    margin: 0 0 12px;
}

.os-score-text {
    font-weight: 800;
    font-size: 20px;
    color: #4c1d95;
    margin: 0 0 28px;
}

.os-restart-btn {
    display: inline-block;
    padding: 12px 28px;
    border: none;
    border-radius: 999px;
    background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    box-shadow: 0 10px 24px rgba(0,0,0,.14);
    transition: transform .18s ease, filter .18s ease;
}

.os-restart-btn:hover { transform: scale(1.05); filter: brightness(1.07); }

@media (max-width: 640px) {
    .os-controls { flex-direction: column; align-items: center; }
    .os-btn { width: 100%; max-width: 300px; }
    .os-sentence-item img { width: 90px; height: 90px; }
    .os-sentence-text { font-size: 15px; }
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

    <!-- Main activity (hidden when completed) -->
    <div id="os-activity-area">
        <div id="os-sentence-list" class="os-sentence-list">
            <?php foreach ($shuffled as $s):
                $disp = $s['display'] ?? 'both';
            ?>
            <div class="os-sentence-item" draggable="true" data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="os-handle">☰</span>
                <?php if ($disp !== 'text' && !empty($s['image'])): ?>
                    <img src="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php endif; ?>
                <?php if ($disp !== 'image' && !empty($s['text'])): ?>
                    <span class="os-sentence-text"><?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="os-controls">
            <button type="button" id="os-check"    class="os-btn os-btn-check">✔ Check Order</button>
            <button type="button" id="os-show-ans" class="os-btn os-btn-show">👁 Show Answer</button>
            <button type="button" id="os-next"     class="os-btn os-btn-next">Next ▶</button>
        </div>
        <p id="os-feedback"></p>
    </div>

    <!-- Completed screen -->
    <div id="os-completed" class="os-completed-screen">
        <div class="os-completed-icon">✅</div>
        <h2 class="os-completed-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="os-completed-text">You've completed this activity. Great job!</p>
        <p class="os-score-text" id="os-score-text"></p>
        <button type="button" class="os-restart-btn" onclick="osRestart()">↺ Try Again</button>
    </div>
</div>

<audio id="os-win-sound"  src="../../hangman/assets/win.mp3"      preload="auto"></audio>
<audio id="os-lose-sound" src="../../hangman/assets/lose.mp3"     preload="auto"></audio>
<audio id="os-done-sound" src="../../hangman/assets/win (1).mp3"  preload="auto"></audio>

<script>
(function () {

    /* ── Data from PHP ── */
    var correctOrder   = <?= json_encode($correctOrder,   JSON_UNESCAPED_UNICODE) ?>;
    var OS_RETURN_TO   = <?= json_encode($returnTo,       JSON_UNESCAPED_UNICODE) ?>;
    var OS_ACTIVITY_ID = <?= json_encode($activityId,     JSON_UNESCAPED_UNICODE) ?>;
    var OS_TOTAL       = correctOrder.length;   // one point per sentence in right place

    /* ── DOM refs ── */
    var list        = document.getElementById('os-sentence-list');
    var activityArea = document.getElementById('os-activity-area');
    var completedEl = document.getElementById('os-completed');
    var feedbackEl  = document.getElementById('os-feedback');
    var scoreTextEl = document.getElementById('os-score-text');
    var checkBtn    = document.getElementById('os-check');
    var showAnsBtn  = document.getElementById('os-show-ans');
    var nextBtn     = document.getElementById('os-next');

    var winSound  = document.getElementById('os-win-sound');
    var loseSound = document.getElementById('os-lose-sound');
    var doneSound = document.getElementById('os-done-sound');

    /* ── State ── */
    var attempts      = 0;
    var blockFinished = false;
    var correctCount  = 0;   // sentences in right place when scored

    /* ── Helpers ── */
    function playSound(el) {
        try { el.pause(); el.currentTime = 0; el.play(); } catch (e) {}
    }

    function items() {
        return Array.prototype.slice.call(list.querySelectorAll('.os-sentence-item'));
    }

    function userOrder() {
        return items().map(function (el) { return el.dataset.id; });
    }

    function countCorrect(order) {
        var n = 0;
        for (var i = 0; i < correctOrder.length; i++) {
            if (order[i] === correctOrder[i]) n++;
        }
        return n;
    }

    /* ── Score save ── */
    function persistScore(pct, errors, total) {
        if (!OS_RETURN_TO || !OS_ACTIVITY_ID) return Promise.resolve(false);
        var joiner  = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var saveUrl = OS_RETURN_TO + joiner +
            'activity_percent=' + pct +
            '&activity_errors=' + errors +
            '&activity_total='  + total +
            '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
            '&activity_type=order_sentences';
        return fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return !!(r && r.ok); })
            .catch(function () { return false; });
    }

    function navigateReturn(pct, errors, total) {
        if (!OS_RETURN_TO || !OS_ACTIVITY_ID) return;
        var joiner  = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var url = OS_RETURN_TO + joiner +
            'activity_percent=' + pct +
            '&activity_errors=' + errors +
            '&activity_total='  + total +
            '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
            '&activity_type=order_sentences';
        try {
            if (window.top && window.top !== window.self) { window.top.location.href = url; return; }
        } catch (e) {}
        window.location.href = url;
    }

    /* ── Completion ── */
    async function showCompleted() {
        blockFinished = true;
        activityArea.style.display  = 'none';
        completedEl.classList.add('active');
        playSound(doneSound);

        var pct    = OS_TOTAL > 0 ? Math.round((correctCount / OS_TOTAL) * 100) : 0;
        var errors = Math.max(0, OS_TOTAL - correctCount);
        scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + OS_TOTAL + ' (' + pct + '%)';

        var ok = await persistScore(pct, errors, OS_TOTAL);
        if (!ok) navigateReturn(pct, errors, OS_TOTAL);
    }

    /* ── Check ── */
    checkBtn.addEventListener('click', function () {
        if (blockFinished) return;
        attempts++;
        var order = userOrder();
        var n     = countCorrect(order);

        if (n === OS_TOTAL) {
            correctCount = n;
            feedbackEl.textContent = '✅ Correct! Well done!';
            feedbackEl.className   = 'good';
            playSound(winSound);
            markPositions(order);
            blockFinished = true;
            checkBtn.disabled = true;
        } else if (attempts >= 2) {
            correctCount = n;
            feedbackEl.textContent = '❌ Wrong (' + n + '/' + OS_TOTAL + ' in place). See correct order below.';
            feedbackEl.className   = 'bad';
            playSound(loseSound);
            markPositions(order);
            revealOrder();
            blockFinished = true;
            checkBtn.disabled = true;
            showAnsBtn.disabled = true;
        } else {
            feedbackEl.textContent = '❌ Not quite — try again! (' + n + '/' + OS_TOTAL + ' in place)';
            feedbackEl.className   = 'bad';
            playSound(loseSound);
            markPositions(order);
        }
    });

    /* ── Show Answer ── */
    showAnsBtn.addEventListener('click', function () {
        if (blockFinished) return;
        correctCount = 0;   // showed answer = 0 score
        revealOrder();
        feedbackEl.textContent = '👁 Correct order shown.';
        feedbackEl.className   = 'good';
        blockFinished = true;
        checkBtn.disabled    = true;
        showAnsBtn.disabled  = true;
    });

    /* ── Next ── */
    nextBtn.addEventListener('click', function () {
        if (!blockFinished) {
            // Force-score with current arrangement before proceeding
            correctCount = countCorrect(userOrder());
            blockFinished = true;
        }
        showCompleted();
    });

    /* ── Highlight right/wrong positions ── */
    function markPositions(order) {
        items().forEach(function (el, i) {
            el.classList.remove('correct-pos', 'wrong-pos');
            if (order[i] === correctOrder[i]) {
                el.classList.add('correct-pos');
            } else {
                el.classList.add('wrong-pos');
            }
        });
    }

    /* ── Reveal correct order by re-sorting the DOM ── */
    function revealOrder() {
        var itemMap = {};
        items().forEach(function (el) { itemMap[el.dataset.id] = el; });
        correctOrder.forEach(function (id) {
            if (itemMap[id]) list.appendChild(itemMap[id]);
        });
        markPositions(correctOrder);
    }

    /* ── Drag-and-drop ── */
    var dragSrc = null;

    function handleDragStart(e) {
        if (blockFinished) { e.preventDefault(); return; }
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
    function handleDragEnter()  { if (!blockFinished) this.classList.add('over'); }
    function handleDragLeave()  { this.classList.remove('over'); }
    function handleDrop(e) {
        e.stopPropagation();
        if (blockFinished) return false;
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
        // Clear position highlights when user re-drags
        items().forEach(function (el) { el.classList.remove('correct-pos', 'wrong-pos'); });
        feedbackEl.textContent = '';
        feedbackEl.className   = '';
        return false;
    }

    items().forEach(function (el) {
        el.addEventListener('dragstart',  handleDragStart.bind(el));
        el.addEventListener('dragend',    handleDragEnd.bind(el));
        el.addEventListener('dragover',   handleDragOver);
        el.addEventListener('dragenter',  handleDragEnter.bind(el));
        el.addEventListener('dragleave',  handleDragLeave.bind(el));
        el.addEventListener('drop',       handleDrop.bind(el));
    });

    /* ── Restart ── */
    window.osRestart = function () {
        attempts      = 0;
        blockFinished = false;
        correctCount  = 0;

        completedEl.classList.remove('active');
        activityArea.style.display = '';
        checkBtn.disabled    = false;
        showAnsBtn.disabled  = false;
        feedbackEl.textContent = '';
        feedbackEl.className   = '';

        // Shuffle items back
        var its = items();
        for (var i = its.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            list.appendChild(its[j]);
            its[j] = its[i];
        }
        its.forEach(function (el) { el.classList.remove('correct-pos', 'wrong-pos'); });
    };

    <?php if (($activity['media_type'] ?? '') === 'tts'): ?>
    /* ── TTS ── */
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
            ttsUtter.rate   = 0.85;
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

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
$attempt = 0;
do {
    shuffle($shuffled);
    $attempt++;
} while ($attempt < 10 && array_column($shuffled, 'id') === $correctOrder);

ob_start();
?>
<style>
.os-stage {
    max-width: 720px;
    margin: 0 auto;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
}

/* ── Header ── */
.os-intro {
    margin-bottom: 20px;
    padding: 20px 24px;
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

/* ── Media ── */
.os-media {
    margin-bottom: 20px;
    text-align: center;
}
.os-media video,
.os-media audio {
    width: 100%;
    border-radius: 14px;
}

/* ── Answer zone (where students build their sequence) ── */
.os-answer-zone {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: center;
    justify-content: center;
    min-height: 160px;
    padding: 16px;
    margin-bottom: 16px;
    border-radius: 20px;
    border: 2px dashed rgba(124, 58, 237, .28);
    background: rgba(245, 243, 255, .35);
    transition: border-color .18s ease, background .18s ease;
}
.os-answer-zone.drag-over {
    border-color: rgba(124, 58, 237, .55);
    background: rgba(237, 233, 254, .40);
}
.os-answer-placeholder {
    color: rgba(124, 58, 237, .38);
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    pointer-events: none;
    width: 100%;
    padding: 10px 0;
}

/* ── Bank (shuffled chips to drag from) ── */
.os-bank-zone {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    justify-content: center;
    align-items: center;
    padding: 8px 0 20px;
    min-height: 40px;
}

/* ── Chip: the draggable sentence unit ── */
.os-chip {
    cursor: grab;
    user-select: none;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    background: transparent;
    border: none;
    padding: 0;
    border-radius: 16px;
    transition: transform .2s cubic-bezier(.34, 1.4, .64, 1), opacity .15s ease;
    -webkit-backface-visibility: hidden;
    backface-visibility: hidden;
}
.os-chip:hover {
    transform: translateY(-4px) scale(1.04);
}
.os-chip.os-dragging {
    opacity: 0.35;
    transform: scale(1.06);
    cursor: grabbing;
}

/* Image chip: illustration is the full card */
.os-chip img {
    width: 130px;
    height: 130px;
    max-width: 100%;
    max-height: 100%;
    border-radius: 16px;
    object-fit: contain;
    display: block;
    box-shadow:
        0 6px 20px rgba(0, 0, 0, .14),
        0 2px 6px  rgba(0, 0, 0, .08);
    transition: box-shadow .2s ease, transform .2s ease;
    pointer-events: none;
}
.os-chip:hover img {
    box-shadow:
        0 14px 30px rgba(0, 0, 0, .18),
        0 4px 10px  rgba(0, 0, 0, .10);
}

/* Text chip: pill style matching drag_drop word bank */
.os-chip-text {
    display: inline-flex;
    align-items: center;
    padding: 10px 18px;
    background: linear-gradient(180deg, #fff 0%, #f5f3ff 100%);
    border: 1px solid #ddd6fe;
    border-radius: 999px;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #4c1d95;
    box-shadow: 0 6px 16px rgba(124, 58, 237, .12);
    pointer-events: none;
    white-space: nowrap;
    transition: box-shadow .2s ease;
}
.os-chip:hover .os-chip-text {
    box-shadow: 0 10px 24px rgba(124, 58, 237, .18);
}

/* Feedback states: ring on chip image / pill */
.os-chip.correct-pos img {
    box-shadow: 0 0 0 3px #16a34a,
                0 6px 20px rgba(22, 163, 74, .22);
}
.os-chip.wrong-pos img {
    box-shadow: 0 0 0 3px #dc2626,
                0 6px 20px rgba(220, 38, 38, .18);
}
.os-chip.correct-pos .os-chip-text {
    border-color: #16a34a;
    box-shadow: 0 0 0 2px #16a34a;
}
.os-chip.wrong-pos .os-chip-text {
    border-color: #dc2626;
    box-shadow: 0 0 0 2px #dc2626;
}

/* Touch-tap selected state */
.os-chip.os-selected img {
    box-shadow: 0 0 0 3px #7c3aed,
                0 8px 22px rgba(124, 58, 237, .28);
}
.os-chip.os-selected .os-chip-text {
    border-color: #7c3aed;
    box-shadow: 0 0 0 2px #7c3aed;
}

/* ── Controls ── */
.os-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    margin-top: 4px;
}
.os-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 18px;
    border: none;
    border-radius: 8px;
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
.os-btn:hover  { filter: brightness(1.04); transform: translateY(-1px); }
.os-btn:disabled { opacity: .5; cursor: default; transform: none; filter: none; }
.os-btn-check { background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%); }
.os-btn-tts   { background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%); }
.os-btn-show  { background: linear-gradient(180deg, #d8b4fe 0%, #a855f7 100%); }
.os-btn-next  { background: linear-gradient(180deg, #2dd4bf 0%, #0f766e 100%); }

/* ── Feedback ── */
#os-feedback {
    font-size: 18px;
    font-weight: 800;
    text-align: center;
    margin-top: 14px;
    min-height: 26px;
}
#os-feedback.good { color: #16a34a; }
#os-feedback.bad  { color: #dc2626; }

/* ── Completed screen ── */
.os-completed-screen {
    display: none;
    text-align: center;
    max-width: 520px;
    margin: 0 auto;
    padding: 44px 20px;
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
    border-radius: 8px;
    background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    box-shadow: 0 10px 24px rgba(0,0,0,.14);
    transition: transform .18s ease, filter .18s ease;
}
.os-restart-btn:hover { transform: scale(1.05); filter: brightness(1.07); }

/* ── Responsive ── */
@media (max-width: 600px) {
    .os-chip img         { width: 100px; height: 100px; border-radius: 12px; }
    .os-answer-zone      { gap: 10px; padding: 12px; }
    .os-bank-zone        { gap: 10px; }
    .os-controls         { flex-direction: column; align-items: center; }
    .os-btn              { width: 100%; max-width: 300px; }
}

/* ── Fullscreen / presentation: 1 cm margins — same as drag-drop kids ── */
body.fullscreen-embedded .activity-wrapper,
body.presentation-mode .activity-wrapper {
    padding: 10mm !important;
    box-sizing: border-box !important;
}

/* viewer-content: compact padding, rounded, no scroll */
body.embedded-mode .viewer-content,
body.fullscreen-embedded .viewer-content,
body.presentation-mode .viewer-content {
    padding: 6px 8px !important;
    border-radius: 14px !important;
    overflow: hidden !important;
}

/* os-stage: fill viewer-content via flex chain.
   In fullscreen/presentation the activity-wrapper supplies the outer margin,
   so os-stage needs no extra padding.
   In plain embedded-mode a small inner padding keeps things tidy. */
body.fullscreen-embedded .os-stage,
body.presentation-mode .os-stage {
    max-width: 100% !important;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 0 !important;
    box-sizing: border-box;
    overflow: hidden;
}

body.embedded-mode .os-stage {
    max-width: 100% !important;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px !important;
    box-sizing: border-box;
    overflow: hidden;
}

body.embedded-mode .act-header,
body.fullscreen-embedded .act-header,
body.presentation-mode .act-header {
    flex-shrink: 0 !important;
    padding: 8px 14px !important;
    margin-bottom: 0 !important;
    border-radius: 12px !important;
}

body.embedded-mode .act-header h2,
body.fullscreen-embedded .act-header h2,
body.presentation-mode .act-header h2 {
    font-size: 18px !important;
    margin-bottom: 2px !important;
}

body.embedded-mode .act-header p,
body.fullscreen-embedded .act-header p,
body.presentation-mode .act-header p {
    font-size: 13px !important;
}

body.embedded-mode .os-media,
body.fullscreen-embedded .os-media,
body.presentation-mode .os-media {
    flex-shrink: 0 !important;
    margin-bottom: 0 !important;
    text-align: center;
}

body.embedded-mode .os-chip img,
body.fullscreen-embedded .os-chip img,
body.presentation-mode .os-chip img {
    width: 80px !important;
    height: 80px !important;
    border-radius: 10px !important;
}

body.embedded-mode .os-media video,
body.fullscreen-embedded .os-media video,
body.presentation-mode .os-media video {
    max-height: 22vh !important;
    width: auto !important;
    max-width: 100% !important;
    border-radius: 10px;
}

body.embedded-mode #os-activity-area,
body.fullscreen-embedded #os-activity-area,
body.presentation-mode #os-activity-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    gap: 6px;
}

body.embedded-mode .os-answer-zone,
body.fullscreen-embedded .os-answer-zone,
body.presentation-mode .os-answer-zone {
    flex: 1 !important;
    min-height: 60px !important;
    margin-bottom: 0 !important;
    overflow-y: auto !important;
}

body.embedded-mode .os-bank-zone,
body.fullscreen-embedded .os-bank-zone,
body.presentation-mode .os-bank-zone {
    flex-shrink: 0 !important;
    padding: 4px 0 !important;
    min-height: 30px !important;
}

body.embedded-mode .os-controls,
body.fullscreen-embedded .os-controls,
body.presentation-mode .os-controls {
    flex-shrink: 0 !important;
    margin-top: 0 !important;
}

body.embedded-mode #os-feedback,
body.fullscreen-embedded #os-feedback,
body.presentation-mode #os-feedback {
    flex-shrink: 0 !important;
    margin-top: 0 !important;
    min-height: 16px !important;
    font-size: 14px !important;
}
</style>

<div class="os-stage">
    <?= render_activity_header($viewerTitle, (string)($activity['instructions'] ?? '')) ?>

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
        <button type="button" id="os-tts-btn" class="os-btn os-btn-tts">Listen</button>
    </div>
    <?php endif; ?>

    <div id="os-activity-area">

        <!-- Answer zone: student builds sequence here -->
        <div id="os-answer" class="os-answer-zone">
            <div id="os-answer-placeholder" class="os-answer-placeholder">
                Drag the pictures here in the correct order
            </div>
        </div>

        <!-- Bank: shuffled chips -->
        <div id="os-bank" class="os-bank-zone">
            <?php foreach ($shuffled as $s):
                $disp = $s['display'] ?? 'both';
            ?>
            <div class="os-chip"
                 data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($disp !== 'text' && !empty($s['image'])): ?>
                    <img src="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php endif; ?>
                <?php if ($disp !== 'image' && !empty($s['text'])): ?>
                    <span class="os-chip-text"><?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="os-controls">
            <button type="button" id="os-check"    class="os-btn os-btn-check" disabled>✔ Check Order</button>
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

/* ── PHP data ── */
var correctOrder   = <?= json_encode($correctOrder,   JSON_UNESCAPED_UNICODE) ?>;
var OS_RETURN_TO   = <?= json_encode($returnTo,       JSON_UNESCAPED_UNICODE) ?>;
var OS_ACTIVITY_ID = <?= json_encode($activityId,     JSON_UNESCAPED_UNICODE) ?>;
var OS_TOTAL       = correctOrder.length;

/* ── DOM ── */
var answerZone   = document.getElementById('os-answer');
var bankZone     = document.getElementById('os-bank');
var placeholder  = document.getElementById('os-answer-placeholder');
var activityArea = document.getElementById('os-activity-area');
var completedEl  = document.getElementById('os-completed');
var feedbackEl   = document.getElementById('os-feedback');
var scoreTextEl  = document.getElementById('os-score-text');
var checkBtn     = document.getElementById('os-check');
var showAnsBtn   = document.getElementById('os-show-ans');
var nextBtn      = document.getElementById('os-next');
var winSound     = document.getElementById('os-win-sound');
var loseSound    = document.getElementById('os-lose-sound');
var doneSound    = document.getElementById('os-done-sound');

/* ── State ── */
var attempts      = 0;
var blockFinished = false;
var correctCount  = 0;
var dragged       = null;   // currently dragged chip
var touchSelected = null;   // tap-to-move selection on touch devices

var isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

/* ── Sound ── */
function playSound(el) {
    try { el.pause(); el.currentTime = 0; el.play(); } catch (e) {}
}

/* ── Answer state helpers ── */
function answerChips() {
    return Array.prototype.slice.call(answerZone.querySelectorAll('.os-chip'));
}

function userOrder() {
    return answerChips().map(function (c) { return c.dataset.id; });
}

function countCorrect(order) {
    var n = 0;
    for (var i = 0; i < correctOrder.length; i++) {
        if ((order[i] || '') === correctOrder[i]) n++;
    }
    return n;
}

function updateUI() {
    var inAnswer = answerChips().length;
    placeholder.style.display = inAnswer > 0 ? 'none' : '';
    checkBtn.disabled = (inAnswer < OS_TOTAL) || blockFinished;
}

/* ── Score / navigation ── */
function persistScore(pct, errors, total) {
    if (!OS_RETURN_TO || !OS_ACTIVITY_ID) return Promise.resolve(false);
    var j   = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    var url = OS_RETURN_TO + j +
        'activity_percent=' + pct +
        '&activity_errors=' + errors +
        '&activity_total='  + total +
        '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
        '&activity_type=order_sentences';
    return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return !!(r && r.ok); })
        .catch(function ()  { return false; });
}

function navigateReturn(pct, errors, total) {
    if (!OS_RETURN_TO || !OS_ACTIVITY_ID) return;
    var j   = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    var url = OS_RETURN_TO + j +
        'activity_percent=' + pct +
        '&activity_errors=' + errors +
        '&activity_total='  + total +
        '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
        '&activity_type=order_sentences';
    try { if (window.top && window.top !== window.self) { window.top.location.href = url; return; } }
    catch (e) {}
    window.location.href = url;
}

/* ── Completion ── */
async function showCompleted() {
    blockFinished = true;
    activityArea.style.display = 'none';
    completedEl.classList.add('active');
    playSound(doneSound);

    var pct    = OS_TOTAL > 0 ? Math.round((correctCount / OS_TOTAL) * 100) : 0;
    var errors = Math.max(0, OS_TOTAL - correctCount);
    scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + OS_TOTAL + ' (' + pct + '%)';

    var ok = await persistScore(pct, errors, OS_TOTAL);
    if (!ok) navigateReturn(pct, errors, OS_TOTAL);
}

/* ── Feedback helpers ── */
function markPositions(order) {
    answerChips().forEach(function (chip, i) {
        chip.classList.remove('correct-pos', 'wrong-pos');
        chip.classList.add((order[i] === correctOrder[i]) ? 'correct-pos' : 'wrong-pos');
    });
}

function revealOrder() {
    var map = {};
    document.querySelectorAll('.os-chip').forEach(function (c) { map[c.dataset.id] = c; });
    correctOrder.forEach(function (id) {
        if (map[id]) answerZone.insertBefore(map[id], placeholder);
    });
    updateUI();
    markPositions(correctOrder);
}

/* ── Check button ── */
checkBtn.addEventListener('click', function () {
    if (blockFinished) return;
    if (answerChips().length < OS_TOTAL) {
        feedbackEl.textContent = 'Place all pictures in the answer area first.';
        feedbackEl.className   = 'bad';
        return;
    }

    attempts++;
    var order = userOrder();
    var n     = countCorrect(order);

    if (n === OS_TOTAL) {
        correctCount = n;
        feedbackEl.textContent = '✅ Correct! Well done!';
        feedbackEl.className   = 'good';
        playSound(winSound);
        markPositions(order);
        blockFinished    = true;
        checkBtn.disabled   = true;
        showAnsBtn.disabled = true;
    } else if (attempts >= 2) {
        correctCount = n;
        feedbackEl.textContent = '❌ Wrong (' + n + '/' + OS_TOTAL + ' correct). Showing the right order.';
        feedbackEl.className   = 'bad';
        playSound(loseSound);
        markPositions(order);
        revealOrder();
        blockFinished    = true;
        checkBtn.disabled   = true;
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
    correctCount = 0;
    revealOrder();
    feedbackEl.textContent = '👁 Correct order shown.';
    feedbackEl.className   = 'good';
    blockFinished    = true;
    checkBtn.disabled   = true;
    showAnsBtn.disabled = true;
});

/* ── Next ── */
nextBtn.addEventListener('click', function () {
    if (!blockFinished) {
        correctCount  = countCorrect(userOrder());
        blockFinished = true;
    }
    showCompleted();
});

/* ══════════════════════════════════════════
   DRAG-AND-DROP  (mirrors listen_order + drag_drop patterns)
   ══════════════════════════════════════════ */

/* Find where to insert dragged chip inside a flex zone based on cursor X.
   Matches listen_order's left-half / right-half insertion heuristic. */
function getDropTarget(zone, clientX) {
    var chips = Array.prototype.slice.call(zone.querySelectorAll('.os-chip'));
    for (var i = 0; i < chips.length; i++) {
        if (chips[i] === dragged) continue;
        var rect = chips[i].getBoundingClientRect();
        if (clientX < rect.left + rect.width / 2) {
            return chips[i];         // insert before this chip
        }
    }
    return placeholder || null;      // append (before placeholder sentinel)
}

/* Wire drag events onto a chip — called for every chip at init and after restart */
function attachChip(chip) {

    /* ── Mouse / pointer drag ── */
    chip.addEventListener('dragstart', function (e) {
        if (blockFinished) { e.preventDefault(); return; }
        dragged = chip;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', chip.dataset.id);
        setTimeout(function () { chip.classList.add('os-dragging'); }, 0);
    });

    chip.addEventListener('dragend', function () {
        chip.classList.remove('os-dragging');
        answerZone.classList.remove('drag-over');
        dragged = null;
        // Clear position colours when user drags again (before checking)
        if (!blockFinished) {
            chip.classList.remove('correct-pos', 'wrong-pos');
        }
        updateUI();
    });

    /* Chip-level dragover: live insertion within the same zone
       (replicates drag_drop's blank-level dragover for reordering) */
    chip.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragged || dragged === chip || blockFinished) return;
        var rect   = chip.getBoundingClientRect();
        var before = e.clientX < rect.left + rect.width / 2;
        chip.parentElement.insertBefore(dragged, before ? chip : chip.nextSibling);
    });

    /* ── Touch-tap to move (mirrors drag_drop touch select pattern) ── */
    chip.addEventListener('click', function () {
        if (blockFinished) return;

        if (isTouchDevice) {
            // If another chip is already selected, perform the swap/move
            if (touchSelected && touchSelected !== chip) {
                // Move touchSelected to just before this chip in its current zone,
                // or swap if both are already in the answer zone
                chip.parentElement.insertBefore(touchSelected, chip);
                touchSelected.classList.remove('os-selected');
                touchSelected = null;
                if (!blockFinished) clearPositionClasses();
                updateUI();
                return;
            }
            // Toggle selection
            if (touchSelected === chip) {
                chip.classList.remove('os-selected');
                touchSelected = null;
                return;
            }
            if (touchSelected) touchSelected.classList.remove('os-selected');
            touchSelected = chip;
            chip.classList.add('os-selected');
            return;
        }

        // Non-touch: single click moves chip between bank ↔ answer
        if (chip.parentElement === bankZone) {
            answerZone.insertBefore(chip, placeholder);
        } else if (chip.parentElement === answerZone) {
            bankZone.appendChild(chip);
        }
        if (!blockFinished) clearPositionClasses();
        updateUI();
    });
}

function clearPositionClasses() {
    document.querySelectorAll('.os-chip').forEach(function (c) {
        c.classList.remove('correct-pos', 'wrong-pos');
    });
    feedbackEl.textContent = '';
    feedbackEl.className   = '';
}

/* Answer zone: accepts drops from bank or reorders existing chips */
answerZone.addEventListener('dragover', function (e) {
    e.preventDefault();
    if (!dragged || blockFinished) return;
    answerZone.classList.add('drag-over');
    // Handle drop on empty zone or below last chip
    var target = e.target.closest ? e.target.closest('.os-chip') : null;
    if (!target || target === dragged) {
        answerZone.insertBefore(dragged, placeholder);
    }
});

answerZone.addEventListener('dragleave', function (e) {
    if (!answerZone.contains(e.relatedTarget)) {
        answerZone.classList.remove('drag-over');
    }
});

answerZone.addEventListener('drop', function (e) {
    e.preventDefault();
    answerZone.classList.remove('drag-over');
    if (!blockFinished) clearPositionClasses();
    updateUI();
});

/* Bank zone: accepts chips dropped back from answer */
bankZone.addEventListener('dragover', function (e) {
    e.preventDefault();
    if (!dragged || blockFinished) return;
    var target = e.target.closest ? e.target.closest('.os-chip') : null;
    if (!target || target === dragged) {
        bankZone.appendChild(dragged);
    }
});

bankZone.addEventListener('drop', function (e) {
    e.preventDefault();
    if (!blockFinished) clearPositionClasses();
    updateUI();
});

/* Attach drag to all initial chips */
document.querySelectorAll('.os-chip').forEach(function (chip) {
    chip.setAttribute('draggable', 'true');
    attachChip(chip);
});

/* ── Restart ── */
window.osRestart = function () {
    attempts      = 0;
    blockFinished = false;
    correctCount  = 0;
    touchSelected = null;

    completedEl.classList.remove('active');
    activityArea.style.display = '';
    showAnsBtn.disabled = false;
    feedbackEl.textContent = '';
    feedbackEl.className   = '';

    // Shuffle all chips back to bank
    var chips = Array.prototype.slice.call(document.querySelectorAll('.os-chip'));
    for (var i = chips.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = chips[i]; chips[i] = chips[j]; chips[j] = tmp;
    }
    chips.forEach(function (chip) {
        chip.classList.remove('correct-pos', 'wrong-pos', 'os-dragging', 'os-selected');
        bankZone.appendChild(chip);
    });

    updateUI();
};

/* Initial UI state */
updateUI();

<?php if (($activity['media_type'] ?? '') === 'tts'): ?>
/* ── TTS (mirrors listen_order playAudio pattern) ── */
var ttsBtn        = document.getElementById('os-tts-btn');
var ttsSourceText = <?= json_encode(!empty($activity['tts_text']) ? $activity['tts_text'] : implode('. ', array_column($sentences, 'text')), JSON_UNESCAPED_UNICODE) ?>;
var ttsUtter      = null;
var ttsIsSpeaking = false;
var ttsIsPaused   = false;
var ttsOffset     = 0;
var ttsSegStart   = 0;

function ttsGetPreferredVoice(lang) {
    var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
    if (!voices.length) return null;
    var prefix  = lang.split('-')[0].toLowerCase();
    var matched = voices.filter(function (v) {
        var vl = String(v.lang || '').toLowerCase();
        return vl === lang.toLowerCase() || vl.startsWith(prefix + '-') || vl.startsWith(prefix + '_');
    });
    if (!matched.length) return voices[0] || null;
    var hints  = ['female', 'woman', 'zira', 'samantha', 'karen', 'aria', 'jenny', 'emma', 'olivia', 'ava'];
    var female = matched.find(function (v) {
        var label = (String(v.name || '') + ' ' + String(v.voiceURI || '')).toLowerCase();
        return hints.some(function (h) { return label.indexOf(h) !== -1; });
    });
    return female || matched[0];
}

function ttsStartFromOffset() {
    var remaining = ttsSourceText.slice(Math.max(0, ttsOffset));
    if (!remaining.trim()) { ttsIsSpeaking = false; ttsIsPaused = false; ttsOffset = 0; return; }
    speechSynthesis.cancel();
    ttsSegStart = ttsOffset;
    ttsUtter = new SpeechSynthesisUtterance(remaining);
    ttsUtter.lang   = 'en-US';
    ttsUtter.rate   = 0.7;
    ttsUtter.pitch  = 1;
    ttsUtter.volume = 1;
    var pref = ttsGetPreferredVoice('en-US');
    if (pref) ttsUtter.voice = pref;
    ttsUtter.onstart    = function () { ttsIsSpeaking = true;  ttsIsPaused = false; };
    ttsUtter.onpause    = function () { ttsIsPaused   = true;  ttsIsSpeaking = true; };
    ttsUtter.onresume   = function () { ttsIsPaused   = false; ttsIsSpeaking = true; };
    ttsUtter.onboundary = function (ev) {
        if (typeof ev.charIndex === 'number')
            ttsOffset = Math.max(ttsSegStart, Math.min(ttsSourceText.length, ttsSegStart + ev.charIndex));
    };
    ttsUtter.onend  = function () { if (!ttsIsPaused) { ttsIsSpeaking = false; ttsIsPaused = false; ttsOffset = 0; } };
    ttsUtter.onerror = function () { ttsIsSpeaking = false; ttsIsPaused = false; ttsOffset = 0; };
    speechSynthesis.speak(ttsUtter);
}

if (ttsBtn) {
    ttsBtn.addEventListener('click', function () {
        if (!ttsSourceText.trim()) return;
        if (speechSynthesis.paused || ttsIsPaused) {
            speechSynthesis.resume();
            ttsIsSpeaking = true; ttsIsPaused = false;
            setTimeout(function () {
                if (!speechSynthesis.speaking && ttsOffset < ttsSourceText.length) ttsStartFromOffset();
            }, 80);
            return;
        }
        if (speechSynthesis.speaking && !speechSynthesis.paused) {
            speechSynthesis.pause();
            ttsIsSpeaking = true; ttsIsPaused = true;
            return;
        }
        speechSynthesis.cancel();
        ttsOffset = 0;
        ttsStartFromOffset();
    });
}
<?php endif; ?>

})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

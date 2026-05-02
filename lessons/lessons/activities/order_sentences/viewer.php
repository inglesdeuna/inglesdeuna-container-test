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

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

<style>
/* ══════════════════════════════════════════
   CSS VARIABLES — TEAL PALETTE
   ══════════════════════════════════════════ */
:root {
    --teal-50:   #E1F5EE;
    --teal-100:  #9FE1CB;
    --teal-200:  #5DCAA5;
    --teal-400:  #1D9E75;
    --teal-600:  #0F6E56;
    --teal-800:  #085041;
    --teal-900:  #04342C;
    --purple:    #7F77DD;
    --purple-d:  #534AB7;
    --red:       #dc2626;
    --green:     #16a34a;
    --chip-size: 80px;
}

/* ══════════════════════════════════════════
   RESET TEMPLATE CHROME
   ══════════════════════════════════════════ */
body {
    margin:      0 !important;
    padding:     0 !important;
    background:  #f0faf6 !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
    overflow:    hidden;
}

.activity-wrapper {
    max-width:      100% !important;
    margin:         0 !important;
    padding:        0 !important;
    min-height:     100vh;
    display:        flex !important;
    flex-direction: column !important;
    background:     transparent !important;
}

.top-row { display: none !important; }

.viewer-content {
    flex:           1 !important;
    display:        flex !important;
    flex-direction: column !important;
    padding:        0 !important;
    margin:         0 !important;
    background:     transparent !important;
    border:         none !important;
    box-shadow:     none !important;
    border-radius:  0 !important;
}

/* ══════════════════════════════════════════
   PAGE SHELL
   ══════════════════════════════════════════ */
.os-page {
    display:        flex;
    flex-direction: column;
    width:          100vw;
    height:         100vh;
    min-height:     0;
    background:     #f0faf6;
}

/* ── Top bar ── */
.os-topbar {
    flex-shrink:   0;
    height:        38px;
    background:    var(--teal-50);
    border-bottom: 1px solid var(--teal-100);
    display:       flex;
    align-items:   center;
    padding:       0 16px;
    gap:           12px;
}

.os-back-btn {
    background:    rgba(15,110,86,.12);
    border:        1px solid var(--teal-100);
    color:         var(--teal-800);
    font-size:     12px;
    font-weight:   800;
    font-family:   'Nunito', sans-serif;
    border-radius: 7px;
    padding:       4px 12px;
    cursor:        pointer;
    transition:    background .15s;
}
.os-back-btn:hover { background: var(--teal-100); }

body.presentation-mode .os-back-btn,
body.embedded-mode     .os-back-btn { display: none; }

.os-topbar-title {
    font-family:    'Nunito', sans-serif;
    font-size:      12px;
    font-weight:    800;
    color:          var(--teal-600);
    letter-spacing: .1em;
    text-transform: uppercase;
    margin:         0 auto;
}

/* ── Bottom bar ── */
.os-bottombar {
    flex-shrink: 0;
    height:      40px;
    background:  var(--teal-50);
    border-top:  1px solid var(--teal-100);
}

/* ══════════════════════════════════════════
   WHITE ACTIVITY CARD
   ══════════════════════════════════════════ */
.os-card {
    flex:           1;
    margin:         8px 12px;
    background:     #fff;
    border-radius:  14px;
    border:         1px solid var(--teal-100);
    display:        flex;
    flex-direction: column;
    overflow:       hidden;
    min-height:     0;
    position:       relative;
    box-shadow:     0 2px 16px rgba(29,158,117,.08);
}

/* ── Card header ── */
.os-card-header {
    flex-shrink:   0;
    background:    var(--teal-50);
    border-bottom: 1px solid var(--teal-100);
    padding:       11px 20px 9px;
}

.os-card-header h2 {
    font-family: 'Fredoka', sans-serif;
    font-size:   clamp(15px, 2vw, 20px);
    font-weight: 600;
    color:       var(--teal-800);
    margin:      0 0 2px;
    line-height: 1.2;
}

.os-card-header p {
    font-size:   12px;
    font-weight: 600;
    color:       var(--teal-600);
    margin:      0;
}

/* ── Media area ── */
.os-media-area {
    flex-shrink:     0;
    width:           100%;
    background:      #111;
    display:         flex;
    align-items:     center;
    justify-content: center;
    overflow:        hidden;
}

.os-media-area video {
    width:      100%;
    max-height: 42vh;
    object-fit: contain;
    display:    block;
    background: #000;
}

.os-media-area audio {
    width:      100%;
    background: var(--teal-50);
}

.os-media-area iframe {
    width:   100%;
    height:  42vh;
    border:  none;
    display: block;
}

.os-tts-area {
    flex-shrink:     0;
    width:           100%;
    background:      var(--teal-50);
    border-bottom:   1px solid var(--teal-100);
    display:         flex;
    align-items:     center;
    justify-content: center;
    padding:         12px;
}

/* ── Game zone ── */
.os-game-zone {
    flex:           1;
    display:        flex;
    flex-direction: column;
    padding:        10px 16px 8px;
    gap:            8px;
    overflow:       hidden;
    min-height:     0;
}

.os-zone-label {
    font-size:      10px;
    font-weight:    800;
    color:          var(--teal-600);
    letter-spacing: .1em;
    text-transform: uppercase;
    font-family:    'Nunito', sans-serif;
    margin-bottom:  4px;
    flex-shrink:    0;
}

/* ── Drop zone ── */
.os-dropzone {
    flex:          1;
    min-height:    80px;
    border:        2px dashed var(--teal-200);
    border-radius: 12px;
    background:    var(--teal-50);
    display:       flex;
    align-items:   center;
    flex-wrap:     wrap;
    gap:           8px;
    padding:       10px 12px;
    transition:    border-color .15s, background .15s;
    overflow-y:    auto;
    scrollbar-width: thin;
    scrollbar-color: var(--teal-200) var(--teal-50);
}

.os-dropzone::-webkit-scrollbar       { width: 4px; }
.os-dropzone::-webkit-scrollbar-thumb { background: var(--teal-200); border-radius: 2px; }

.os-dropzone.drag-over {
    border-color: var(--teal-400);
    background:   rgba(29,158,117,.08);
}

.os-dz-hint {
    width:          100%;
    text-align:     center;
    font-size:      13px;
    font-weight:    700;
    color:          var(--teal-100);
    pointer-events: none;
    font-family:    'Nunito', sans-serif;
}

/* ── Chip bank ── */
.os-bank {
    flex-shrink:     0;
    display:         flex;
    flex-wrap:       wrap;
    gap:             8px;
    justify-content: center;
    padding:         4px 0;
    overflow-x:      auto;
    scrollbar-width: thin;
    scrollbar-color: var(--teal-200) transparent;
}

/* ── Chips ── */
.os-chip {
    width:           var(--chip-size);
    height:          var(--chip-size);
    border-radius:   10px;
    background:      #fff;
    border:          2px solid var(--teal-100);
    display:         flex;
    align-items:     center;
    justify-content: center;
    cursor:          grab;
    user-select:     none;
    position:        relative;
    flex-shrink:     0;
    transition:      transform .15s cubic-bezier(.34,1.4,.64,1), border-color .15s, box-shadow .15s;
    overflow:        hidden;
}

.os-chip:hover {
    transform:    translateY(-3px) scale(1.06);
    border-color: var(--teal-400);
    box-shadow:   0 6px 18px rgba(29,158,117,.20);
}

.os-chip.os-dragging {
    opacity:   .35;
    transform: scale(1.04);
    cursor:    grabbing;
}

.os-chip.os-selected {
    border-color: var(--purple);
    box-shadow:   0 0 0 3px rgba(127,119,221,.25);
}

.os-chip img {
    width:          100%;
    height:         100%;
    object-fit:     contain;
    pointer-events: none;
    display:        block;
}

.os-chip-label {
    font-family: 'Nunito', sans-serif;
    font-size:   11px;
    font-weight: 800;
    color:       var(--teal-800);
    text-align:  center;
    padding:     3px 4px;
    line-height: 1.2;
    word-break:  break-word;
    hyphens:     auto;
}

.os-chip-badge {
    position:        absolute;
    top:             3px;
    left:            3px;
    width:           18px;
    height:          18px;
    background:      var(--teal-800);
    color:           #fff;
    border-radius:   50%;
    font-size:       10px;
    font-weight:     800;
    font-family:     'Nunito', sans-serif;
    display:         none;
    align-items:     center;
    justify-content: center;
    z-index:         2;
}

.os-chip.in-answer .os-chip-badge { display: flex; }

.os-chip.correct-pos {
    border-color: var(--green);
    box-shadow:   0 0 0 2px var(--green);
}

.os-chip.wrong-pos {
    border-color: var(--red);
    box-shadow:   0 0 0 2px var(--red);
}

/* ── Controls ── */
.os-controls {
    flex-shrink:     0;
    border-top:      1px solid var(--teal-100);
    padding:         8px 14px;
    display:         flex;
    align-items:     center;
    gap:             10px;
    flex-wrap:       wrap;
    justify-content: center;
}

.os-btn {
    display:         inline-flex;
    align-items:     center;
    justify-content: center;
    gap:             5px;
    padding:         9px 18px;
    border:          none;
    border-radius:   20px;
    font-family:     'Nunito', sans-serif;
    font-size:       13px;
    font-weight:     800;
    color:           #fff;
    cursor:          pointer;
    transition:      transform .12s, filter .12s;
    box-shadow:      0 3px 10px rgba(0,0,0,.12);
    white-space:     nowrap;
}
.os-btn:hover    { filter: brightness(1.07); transform: translateY(-1px); }
.os-btn:disabled { opacity: .45; cursor: default; transform: none; filter: none; }

.os-btn-check { background: var(--purple); }
.os-btn-show  { background: var(--teal-400); }
.os-btn-next  { background: var(--teal-600); }
.os-btn-tts   { background: var(--teal-400); padding: 10px 24px; font-size: 14px; border-radius: 20px; }

#os-feedback {
    font-family: 'Nunito', sans-serif;
    font-size:   13px;
    font-weight: 800;
    text-align:  center;
    min-height:  18px;
    width:       100%;
}
#os-feedback.good { color: var(--green); }
#os-feedback.bad  { color: var(--red); }

/* ══════════════════════════════════════════
   COMPLETED OVERLAY
   ══════════════════════════════════════════ */
.os-completed {
    display:         none;
    position:        absolute;
    inset:           0;
    background:      #fff;
    border-radius:   14px;
    flex-direction:  column;
    align-items:     center;
    justify-content: center;
    text-align:      center;
    padding:         40px 24px;
    z-index:         20;
}

.os-completed.active { display: flex; }

.os-completed-icon  { font-size: 64px; margin-bottom: 12px; line-height: 1; }

.os-completed-title {
    font-family: 'Fredoka', sans-serif;
    font-size:   30px;
    font-weight: 700;
    color:       var(--teal-800);
    margin:      0 0 8px;
}

.os-completed-text {
    font-size:   14px;
    font-weight: 600;
    color:       #5a7a6a;
    margin:      0 0 6px;
}

.os-score {
    font-family: 'Fredoka', sans-serif;
    font-size:   20px;
    font-weight: 600;
    color:       var(--teal-600);
    margin:      0 0 24px;
}

.os-restart-btn {
    background:    var(--teal-800);
    color:         #fff;
    border:        none;
    border-radius: 20px;
    padding:       11px 28px;
    font-family:   'Nunito', sans-serif;
    font-size:     14px;
    font-weight:   800;
    cursor:        pointer;
    transition:    background .15s, transform .15s;
    box-shadow:    0 4px 14px rgba(8,80,65,.25);
}
.os-restart-btn:hover { background: var(--teal-900); transform: scale(1.04); }

/* ══════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════ */
@media (max-width: 600px) {
    :root          { --chip-size: 64px; }
    .os-card       { margin: 6px 8px; border-radius: 10px; }
    .os-topbar     { height: 34px; }
    .os-bottombar  { height: 32px; }
    .os-card-header { padding: 8px 14px 6px; }
    .os-game-zone  { padding: 8px 10px 6px; gap: 6px; }
    .os-btn        { padding: 8px 14px; font-size: 12px; }
}
</style>

<div class="os-page">

    <div class="os-topbar">
        <button class="os-back-btn" onclick="history.back()">← Back</button>
        <span class="os-topbar-title">Activity</span>
    </div>

    <div class="os-card">

        <div class="os-card-header">
            <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string)($activity['instructions'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <?php if (($activity['media_type'] ?? '') === 'video' && !empty($activity['media_url'])): ?>
        <div class="os-media-area">
            <video controls preload="metadata"
                   src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>">
            </video>
        </div>

        <?php elseif (($activity['media_type'] ?? '') === 'audio' && !empty($activity['media_url'])): ?>
        <div class="os-tts-area">
            <audio controls src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>"></audio>
        </div>

        <?php elseif (($activity['media_type'] ?? '') === 'tts'): ?>
        <div class="os-tts-area">
            <button type="button" id="os-tts-btn" class="os-btn os-btn-tts">🔊 Listen</button>
        </div>
        <?php endif; ?>

        <div class="os-game-zone" id="os-game-zone">

            <div>
                <div class="os-zone-label">Your answer — drag here in order</div>
                <div class="os-dropzone" id="os-dropzone">
                    <span class="os-dz-hint" id="os-dz-hint">Drag the pictures here in the correct order</span>
                </div>
            </div>

            <div>
                <div class="os-zone-label">Sentences</div>
                <div class="os-bank" id="os-bank">
                    <?php foreach ($shuffled as $s):
                        $disp = $s['display'] ?? 'both';
                    ?>
                    <div class="os-chip"
                         draggable="true"
                         data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="os-chip-badge">?</div>
                        <?php if ($disp !== 'text' && !empty($s['image'])): ?>
                            <img src="<?= htmlspecialchars($s['image'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <?php if ($disp !== 'image' && !empty($s['text'])): ?>
                            <span class="os-chip-label"><?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="os-controls">
                <button type="button" class="os-btn os-btn-check" id="os-check" disabled>✔ Check Order</button>
                <button type="button" class="os-btn os-btn-show"  id="os-show">👁 Show Answer</button>
                <button type="button" class="os-btn os-btn-next"  id="os-next">Next ▶</button>
                <div id="os-feedback"></div>
            </div>

        </div>

        <div class="os-completed" id="os-completed">
            <div class="os-completed-icon">✅</div>
            <h2 class="os-completed-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="os-completed-text">You've completed this activity. Great job!</p>
            <p class="os-score" id="os-score"></p>
            <button type="button" class="os-restart-btn" onclick="osRestart()">↺ Try Again</button>
        </div>

    </div>

    <div class="os-bottombar"></div>

</div>

<audio id="os-win-sound"  src="../../hangman/assets/win.mp3"     preload="auto"></audio>
<audio id="os-lose-sound" src="../../hangman/assets/lose.mp3"    preload="auto"></audio>
<audio id="os-done-sound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
(function () {

var CORRECT_ORDER  = <?= json_encode($correctOrder,  JSON_UNESCAPED_UNICODE) ?>;
var OS_RETURN_TO   = <?= json_encode($returnTo,      JSON_UNESCAPED_UNICODE) ?>;
var OS_ACTIVITY_ID = <?= json_encode($activityId,    JSON_UNESCAPED_UNICODE) ?>;
var OS_TOTAL       = CORRECT_ORDER.length;

var dropzone    = document.getElementById('os-dropzone');
var bank        = document.getElementById('os-bank');
var hint        = document.getElementById('os-dz-hint');
var checkBtn    = document.getElementById('os-check');
var showBtn     = document.getElementById('os-show');
var nextBtn     = document.getElementById('os-next');
var feedbackEl  = document.getElementById('os-feedback');
var completedEl = document.getElementById('os-completed');
var scoreEl     = document.getElementById('os-score');
var winSound    = document.getElementById('os-win-sound');
var loseSound   = document.getElementById('os-lose-sound');
var doneSound   = document.getElementById('os-done-sound');

var attempts     = 0;
var done         = false;
var correctCount = 0;
var dragged      = null;
var touchSel     = null;
var isTouchDev   = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;

function playSound(el) {
    try { el.pause(); el.currentTime = 0; el.play(); } catch(e) {}
}

function answerChips() {
    return Array.from(dropzone.querySelectorAll('.os-chip'));
}

function userOrder() {
    return answerChips().map(function(c){ return c.dataset.id; });
}

function countCorrect(order) {
    var n = 0;
    for (var i = 0; i < CORRECT_ORDER.length; i++) {
        if ((order[i] || '') === CORRECT_ORDER[i]) n++;
    }
    return n;
}

function updateBadges() {
    answerChips().forEach(function(c, i) {
        var b = c.querySelector('.os-chip-badge');
        if (b) b.textContent = i + 1;
    });
}

function updateUI() {
    var n = answerChips().length;
    hint.style.display = n > 0 ? 'none' : '';
    checkBtn.disabled  = (n < OS_TOTAL) || done;
    updateBadges();
}

function clearFeedbackColors() {
    document.querySelectorAll('.os-chip').forEach(function(c) {
        c.classList.remove('correct-pos', 'wrong-pos');
    });
    feedbackEl.textContent = '';
    feedbackEl.className   = '';
}

function markPositions(order) {
    answerChips().forEach(function(c, i) {
        c.classList.remove('correct-pos', 'wrong-pos');
        c.classList.add(c.dataset.id === CORRECT_ORDER[i] ? 'correct-pos' : 'wrong-pos');
    });
}

function revealAnswer() {
    var map = {};
    document.querySelectorAll('.os-chip').forEach(function(c){ map[c.dataset.id] = c; });
    CORRECT_ORDER.forEach(function(id) {
        if (map[id]) {
            dropzone.appendChild(map[id]);
            map[id].classList.add('in-answer');
        }
    });
    updateUI();
    markPositions(CORRECT_ORDER);
}

function persistScore(pct, errors, total) {
    if (!OS_RETURN_TO || !OS_ACTIVITY_ID) return Promise.resolve(false);
    var sep = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    var url = OS_RETURN_TO + sep +
        'activity_percent=' + pct +
        '&activity_errors=' + errors +
        '&activity_total='  + total +
        '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
        '&activity_type=order_sentences';
    return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
        .then(function(r){ return !!(r && r.ok); })
        .catch(function(){ return false; });
}

function navigateReturn(pct, errors, total) {
    if (!OS_RETURN_TO || !OS_ACTIVITY_ID) return;
    var sep = OS_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
    var url = OS_RETURN_TO + sep +
        'activity_percent=' + pct +
        '&activity_errors=' + errors +
        '&activity_total='  + total +
        '&activity_id='     + encodeURIComponent(OS_ACTIVITY_ID) +
        '&activity_type=order_sentences';
    try {
        if (window.top && window.top !== window.self) { window.top.location.href = url; return; }
    } catch(e) {}
    window.location.href = url;
}

async function showCompleted() {
    done = true;
    completedEl.classList.add('active');
    playSound(doneSound);
    var pct    = OS_TOTAL > 0 ? Math.round((correctCount / OS_TOTAL) * 100) : 0;
    var errors = Math.max(0, OS_TOTAL - correctCount);
    scoreEl.textContent = 'Score: ' + correctCount + ' / ' + OS_TOTAL + ' (' + pct + '%)';
    var ok = await persistScore(pct, errors, OS_TOTAL);
    if (!ok) navigateReturn(pct, errors, OS_TOTAL);
}

checkBtn.addEventListener('click', function() {
    if (done) return;
    if (answerChips().length < OS_TOTAL) {
        feedbackEl.textContent = 'Place all pictures first.';
        feedbackEl.className   = 'bad';
        return;
    }
    attempts++;
    var order = userOrder();
    var n     = countCorrect(order);
    markPositions(order);

    if (n === OS_TOTAL) {
        correctCount = n;
        feedbackEl.textContent = '✅ Correct! Well done!';
        feedbackEl.className   = 'good';
        playSound(winSound);
        done = true;
        checkBtn.disabled = true;
        showBtn.disabled  = true;
    } else if (attempts >= 2) {
        correctCount = n;
        feedbackEl.textContent = '❌ ' + n + '/' + OS_TOTAL + ' correct — showing the right order.';
        feedbackEl.className   = 'bad';
        playSound(loseSound);
        revealAnswer();
        done = true;
        checkBtn.disabled = true;
        showBtn.disabled  = true;
    } else {
        feedbackEl.textContent = '❌ Not quite — ' + n + '/' + OS_TOTAL + ' in place. Try again!';
        feedbackEl.className   = 'bad';
        playSound(loseSound);
    }
});

showBtn.addEventListener('click', function() {
    if (done) return;
    correctCount = 0;
    revealAnswer();
    feedbackEl.textContent = '👁 Correct order shown.';
    feedbackEl.className   = 'good';
    done = true;
    checkBtn.disabled = true;
    showBtn.disabled  = true;
});

nextBtn.addEventListener('click', function() {
    if (!done) correctCount = countCorrect(userOrder());
    showCompleted();
});

function attachChip(chip) {
    chip.addEventListener('dragstart', function(e) {
        if (done) { e.preventDefault(); return; }
        dragged = chip;
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(function(){ chip.classList.add('os-dragging'); }, 0);
    });

    chip.addEventListener('dragend', function() {
        chip.classList.remove('os-dragging');
        dropzone.classList.remove('drag-over');
        dragged = null;
        if (!done) clearFeedbackColors();
        updateUI();
    });

    chip.addEventListener('dragover', function(e) {
        e.preventDefault();
        if (!dragged || dragged === chip || done) return;
        var r      = chip.getBoundingClientRect();
        var before = e.clientX < r.left + r.width / 2;
        chip.parentElement.insertBefore(dragged, before ? chip : chip.nextSibling);
        updateUI();
    });

    chip.addEventListener('click', function() {
        if (done) return;

        if (isTouchDev) {
            if (touchSel && touchSel !== chip) {
                chip.parentElement.insertBefore(touchSel, chip);
                touchSel.classList.remove('os-selected');
                touchSel = null;
                if (!done) clearFeedbackColors();
                updateUI();
                return;
            }
            if (touchSel === chip) {
                chip.classList.remove('os-selected');
                touchSel = null;
                return;
            }
            if (touchSel) touchSel.classList.remove('os-selected');
            touchSel = chip;
            chip.classList.add('os-selected');
            return;
        }

        if (chip.parentElement === bank) {
            dropzone.insertBefore(chip, hint.nextSibling || null);
            chip.classList.add('in-answer');
        } else {
            bank.appendChild(chip);
            chip.classList.remove('in-answer', 'correct-pos', 'wrong-pos');
        }
        if (!done) clearFeedbackColors();
        updateUI();
    });
}

dropzone.addEventListener('dragover', function(e) {
    e.preventDefault();
    if (!dragged || done) return;
    dropzone.classList.add('drag-over');
    var target = e.target.closest ? e.target.closest('.os-chip') : null;
    if (!target || target === dragged) {
        dropzone.appendChild(dragged);
        dragged.classList.add('in-answer');
        if (!done) clearFeedbackColors();
    }
    updateUI();
});

dropzone.addEventListener('dragleave', function(e) {
    if (!dropzone.contains(e.relatedTarget)) dropzone.classList.remove('drag-over');
});

dropzone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropzone.classList.remove('drag-over');
    updateUI();
});

bank.addEventListener('dragover', function(e) {
    e.preventDefault();
    if (!dragged || done) return;
    var target = e.target.closest ? e.target.closest('.os-chip') : null;
    if (!target || target === dragged) {
        bank.appendChild(dragged);
        dragged.classList.remove('in-answer', 'correct-pos', 'wrong-pos');
        if (!done) clearFeedbackColors();
    }
    updateUI();
});

bank.addEventListener('drop', function(e) {
    e.preventDefault();
    updateUI();
});

document.querySelectorAll('.os-chip').forEach(function(chip) {
    attachChip(chip);
});

window.osRestart = function() {
    attempts     = 0;
    done         = false;
    correctCount = 0;
    touchSel     = null;

    completedEl.classList.remove('active');
    checkBtn.disabled      = false;
    showBtn.disabled       = false;
    feedbackEl.textContent = '';
    feedbackEl.className   = '';

    var chips = Array.from(document.querySelectorAll('.os-chip'));
    for (var i = chips.length - 1; i > 0; i--) {
        var j   = Math.floor(Math.random() * (i + 1));
        var tmp = chips[i]; chips[i] = chips[j]; chips[j] = tmp;
    }
    chips.forEach(function(c) {
        c.classList.remove('correct-pos', 'wrong-pos', 'os-dragging', 'os-selected', 'in-answer');
        bank.appendChild(c);
    });
    updateUI();
};

updateUI();

<?php if (($activity['media_type'] ?? '') === 'tts'): ?>
var TTS_TEXT = <?= json_encode(
    !empty($activity['tts_text'])
        ? $activity['tts_text']
        : implode('. ', array_column($sentences, 'text')),
    JSON_UNESCAPED_UNICODE
) ?>;

var ttsBtn      = document.getElementById('os-tts-btn');
var ttsSpeaking = false;
var ttsPaused   = false;
var ttsOffset   = 0;
var ttsSegStart = 0;
var ttsUtter    = null;

function ttsPreferredVoice(lang) {
    var voices  = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
    if (!voices.length) return null;
    var pre     = lang.split('-')[0].toLowerCase();
    var matches = voices.filter(function(v) {
        var vl = String(v.lang || '').toLowerCase();
        return vl === lang.toLowerCase() || vl.startsWith(pre + '-') || vl.startsWith(pre + '_');
    });
    if (!matches.length) return voices[0] || null;
    var hints  = ['female','woman','zira','samantha','karen','aria','jenny','emma','olivia','ava'];
    var female = matches.find(function(v) {
        var label = (String(v.name||'')+' '+String(v.voiceURI||'')).toLowerCase();
        return hints.some(function(h){ return label.indexOf(h) !== -1; });
    });
    return female || matches[0];
}

function ttsStart() {
    var remaining = TTS_TEXT.slice(Math.max(0, ttsOffset));
    if (!remaining.trim()) { ttsSpeaking = false; ttsPaused = false; ttsOffset = 0; return; }
    speechSynthesis.cancel();
    ttsSegStart     = ttsOffset;
    ttsUtter        = new SpeechSynthesisUtterance(remaining);
    ttsUtter.lang   = 'en-US';
    ttsUtter.rate   = 0.7;
    ttsUtter.pitch  = 1;
    ttsUtter.volume = 1;
    var pref = ttsPreferredVoice('en-US');
    if (pref) ttsUtter.voice = pref;
    ttsUtter.onstart    = function(){ ttsSpeaking = true;  ttsPaused = false; };
    ttsUtter.onpause    = function(){ ttsPaused   = true;  ttsSpeaking = true; };
    ttsUtter.onresume   = function(){ ttsPaused   = false; ttsSpeaking = true; };
    ttsUtter.onboundary = function(ev) {
        if (typeof ev.charIndex === 'number')
            ttsOffset = Math.max(ttsSegStart, Math.min(TTS_TEXT.length, ttsSegStart + ev.charIndex));
    };
    ttsUtter.onend   = function(){ if (!ttsPaused){ ttsSpeaking = false; ttsPaused = false; ttsOffset = 0; } };
    ttsUtter.onerror = function(){ ttsSpeaking = false; ttsPaused = false; ttsOffset = 0; };
    speechSynthesis.speak(ttsUtter);
}

if (ttsBtn) {
    ttsBtn.addEventListener('click', function() {
        if (!TTS_TEXT.trim()) return;
        if (speechSynthesis.paused || ttsPaused) {
            speechSynthesis.resume();
            ttsSpeaking = true; ttsPaused = false;
            setTimeout(function(){
                if (!speechSynthesis.speaking && ttsOffset < TTS_TEXT.length) ttsStart();
            }, 80);
            return;
        }
        if (speechSynthesis.speaking && !speechSynthesis.paused) {
            speechSynthesis.pause();
            ttsSpeaking = true; ttsPaused = true;
            return;
        }
        speechSynthesis.cancel();
        ttsOffset = 0;
        ttsStart();
    });
}
<?php endif; ?>

})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

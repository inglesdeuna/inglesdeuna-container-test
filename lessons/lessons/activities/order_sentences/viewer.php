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
        'instructions' => 'Put the words in the correct order.',
        'media_type'   => 'tts',
        'media_url'    => '',
        'tts_text'     => '',
        'voice_id'     => 'nzFihrBIvB34imQBuxub',
        'sentences'    => [],
    ];

    if ($rawData === null || $rawData === '') return $default;

    $d = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($d)) return $default;

    $sentences = [];
    foreach ((array) ($d['sentences'] ?? []) as $s) {
        if (!is_array($s)) continue;

        $text = trim((string) ($s['text'] ?? ''));
        if ($text === '') continue;

        $sentences[] = [
            'id'   => trim((string) ($s['id'] ?? uniqid('os_'))),
            'text' => $text,
        ];
    }

    return [
        'title'        => trim((string) ($d['title']        ?? '')) ?: $default['title'],
        'instructions' => trim((string) ($d['instructions'] ?? '')) ?: $default['instructions'],
        'media_type'   => in_array($d['media_type'] ?? '', ['tts', 'video', 'audio', 'none'], true) ? $d['media_type'] : 'tts',
        'media_url'    => trim((string) ($d['media_url']    ?? '')),
        'tts_text'     => trim((string) ($d['tts_text']     ?? '')),
        'voice_id'     => trim((string) ($d['voice_id']     ?? 'nzFihrBIvB34imQBuxub')) ?: 'nzFihrBIvB34imQBuxub',
        'tts_audio_url'=> trim((string) ($d['tts_audio_url'] ?? '')),
        'sentences'    => $sentences,
    ];
}


function os_viewer_video_embed_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';

    // YouTube: watch?v=, youtu.be/, shorts/, embed/
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }

    // Vimeo
    if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~i', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }

    return '';
}

function os_viewer_is_direct_video(string $url): bool
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return false;
    return (bool) preg_match('~\.(mp4|webm|ogg|mov|m4v)$~i', $path);
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

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --os-orange: #F97316;
    --os-orange-dark: #C2580A;
    --os-orange-soft: #FFF0E6;
    --os-purple: #7F77DD;
    --os-purple-dark: #534AB7;
    --os-purple-soft: #EEEDFE;
    --os-white: #FFFFFF;
    --os-lila-border: #EDE9FA;
    --os-muted: #9B94BE;
    --os-ink: #271B5D;
    --os-green: #16a34a;
    --os-red: #dc2626;
}

html,
body {
    width: 100%;
    min-height: 100%;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
}

.activity-wrapper {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
}

.top-row {
    display: none !important;
}

.viewer-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

.os-page {
    width: 100%;
    min-height: 100vh;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #ffffff;
    box-sizing: border-box;
}

.os-app {
    width: min(860px, 100%);
    margin: 0 auto;
}

.os-topbar {
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    position: relative;
}

.os-topbar-title {
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.os-hero {
    text-align: center;
    margin-bottom: clamp(12px, 2vw, 18px);
}

.os-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.os-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1.03;
}

.os-hero p {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 8px 0 0;
}

.os-board {
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    width: min(760px, 100%);
    margin: 0 auto;
    box-sizing: border-box;
    position: relative;
}

.os-media-area,
.os-tts-area {
    width: 100%;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    background: #ffffff;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    overflow: hidden;
    margin-bottom: 16px;
    box-sizing: border-box;
}

.os-media-area {
    display: flex;
    align-items: center;
    justify-content: center;
}

.os-media-area video {
    width: 100%;
    height: auto;
    max-height: 320px;
    object-fit: contain;
    display: block;
    background: #000000;
}

.os-media-area iframe {
    width: 100%;
    aspect-ratio: 16 / 9;
    max-height: 320px;
    border: none;
    display: block;
    background: #000000;
}

.os-tts-area {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
}

.os-tts-area audio {
    width: 100%;
}

.os-listen-panel {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
}

.os-listen-text {
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    color: #9B94BE;
}

.os-zone-label {
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-family: 'Nunito', sans-serif;
    margin-bottom: 10px;
    text-align: center;
}

/* One sentence list only, like the CodePen. There is no separate drag zone. */
.os-list {
    list-style: none;
    margin: 0;
    padding: 4px 4px 4px 0;
    display: flex;
    flex-direction: column;
    gap: 9px;
    max-height: clamp(260px, 50vh, 480px);
    overflow-y: auto;
    overflow-x: hidden;
    scroll-behavior: smooth;
}

.os-list::-webkit-scrollbar {
    width: 6px;
}

.os-list::-webkit-scrollbar-track {
    background: #F4F2FD;
    border-radius: 999px;
}

.os-list::-webkit-scrollbar-thumb {
    background: #C4BFEE;
    border-radius: 999px;
}

.os-list::-webkit-scrollbar-thumb:hover {
    background: #7F77DD;
}

.os-chip {
    width: 100%;
    min-height: 48px;
    padding: 13px 46px 13px 18px;
    border-radius: 18px;
    background: #ffffff;
    border: 1px solid #EDE9FA;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    cursor: grab;
    user-select: none;
    position: relative;
    transition: transform .15s cubic-bezier(.34,1.4,.64,1), border-color .15s, box-shadow .15s, background .15s;
    box-shadow: 0 4px 14px rgba(127,119,221,.13);
    box-sizing: border-box;
}

.os-chip:hover {
    transform: translateY(-2px) scale(1.01);
    border-color: #7F77DD;
    box-shadow: 0 16px 28px rgba(127,119,221,.18);
}

.os-chip.os-dragging {
    opacity: .35;
    transform: scale(1.01);
    cursor: grabbing;
}

.os-chip.os-selected {
    border-color: #7F77DD;
    box-shadow: 0 0 0 3px rgba(127,119,221,.25);
}

.os-chip-label {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(17px, 2.4vw, 24px);
    font-weight: 600;
    color: #534AB7;
    text-align: left;
    line-height: 1.18;
    padding: 0;
    word-break: normal;
    overflow-wrap: anywhere;
}

.os-chip-badge {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    min-width: 26px;
    height: 26px;
    padding: 0 8px;
    background: #7F77DD;
    color: #ffffff;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}

.os-chip.correct-pos {
    border-color: #16a34a;
    box-shadow: 0 0 0 2px #16a34a;
}

.os-chip.wrong-pos {
    border-color: #dc2626;
    box-shadow: 0 0 0 2px #dc2626;
}

.os-controls {
    border-top: 1px solid #F0EEF8;
    margin-top: 16px;
    padding-top: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
}

.os-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 13px 20px;
    min-width: clamp(112px, 15vw, 150px);
    border-radius: 999px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    transition: transform .12s, filter .12s, box-shadow .12s;
    white-space: nowrap;
}

.os-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.os-btn:disabled {
    opacity: .45;
    cursor: default;
    transform: none;
    filter: none;
}

.os-btn-check {
    background: #F97316;
    color: #ffffff;
    border: none;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

.os-btn-show,
.os-btn-tts {
    background: #7F77DD;
    color: #ffffff;
    border: none;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.os-btn-next {
    background: #F97316;
    color: #ffffff;
    border: none;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

#os-feedback {
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    text-align: center;
    min-height: 18px;
    width: 100%;
}

#os-feedback.good {
    color: #16a34a;
}

#os-feedback.bad {
    color: #dc2626;
}

.os-completed {
    display: none;
    position: absolute;
    inset: 0;
    background: #ffffff;
    border-radius: 34px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 40px 24px;
    z-index: 20;
}

.os-completed.active {
    display: flex;
}

.os-completed-icon {
    font-size: 64px;
    margin-bottom: 12px;
    line-height: 1;
}

.os-completed-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0 0 8px;
}

.os-completed-text {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 0 0 6px;
}

.os-score {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(20px, 3vw, 28px);
    font-weight: 700;
    color: #534AB7;
    margin: 0 0 24px;
}

.os-restart-btn {
    background: #7F77DD;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 13px 24px;
    min-width: clamp(104px, 16vw, 146px);
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    transition: filter .15s, transform .15s;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.os-restart-btn:hover {
    filter: brightness(1.07);
    transform: scale(1.04);
}

@media (max-width: 640px) {
    .os-page {
        padding: 12px;
    }

    .os-topbar {
        height: 30px;
        margin-bottom: 4px;
    }

    .os-board {
        border-radius: 26px;
        padding: 14px;
        width: 100%;
    }

    .os-media-area,
    .os-tts-area {
        border-radius: 22px;
        margin-bottom: 12px;
    }

    .os-media-area video {
        max-height: 220px;
    }

    .os-media-area iframe {
        max-height: 220px;
    }

    .os-chip {
        min-height: 44px;
        padding: 11px 42px 11px 14px;
        border-radius: 16px;
    }

    .os-chip-label {
        font-size: clamp(15px, 4.4vw, 19px);
    }

    .os-controls {
        display: grid;
        grid-template-columns: 1fr;
        gap: 9px;
    }

    .os-btn {
        width: 100%;
    }

    .os-completed {
        border-radius: 26px;
    }
}
</style>

<div class="os-page">
    <div class="os-app">

        <div class="os-topbar">
            <span class="os-topbar-title">Sentence Ordering</span>
        </div>

        <div class="os-hero">
            <div class="os-kicker">Activity</div>
            <h1><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars((string)($activity['instructions'] ?? 'Put the words in the correct order.'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="os-board">

            <?php if (($activity['media_type'] ?? '') === 'video' && !empty($activity['media_url'])): ?>
                <?php
                    $osVideoUrl   = (string)($activity['media_url'] ?? '');
                    $osEmbedUrl   = os_viewer_video_embed_url($osVideoUrl);
                    $osDirectVideo = os_viewer_is_direct_video($osVideoUrl);
                ?>
                <div class="os-media-area">
                    <?php if ($osEmbedUrl !== ''): ?>
                        <iframe
                            src="<?= htmlspecialchars($osEmbedUrl, ENT_QUOTES, 'UTF-8') ?>"
                            title="Activity video"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen>
                        </iframe>
                    <?php else: ?>
                        <video controls preload="metadata"
                               src="<?= htmlspecialchars($osVideoUrl, ENT_QUOTES, 'UTF-8') ?>">
                        </video>
                    <?php endif; ?>
                </div>

            <?php elseif (($activity['media_type'] ?? '') === 'audio' && !empty($activity['media_url'])): ?>
                <div class="os-tts-area">
                    <audio controls src="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>"></audio>
                </div>

            <?php elseif (!empty($activity['tts_audio_url'])): ?>
                <div class="os-tts-area">
                    <audio id="os-tts-audio" src="<?= htmlspecialchars($activity['tts_audio_url'], ENT_QUOTES, 'UTF-8') ?>" controls preload="none" style="width:100%;height:42px"></audio>
                </div>

            <?php else: ?>
                <div class="os-tts-area">
                    <div class="os-listen-panel">
                        <button type="button" id="os-tts-btn" class="os-btn os-btn-tts">Listen</button>
                        <span class="os-listen-text">Listen and put the sentences in order.</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="os-zone-label">Drag the sentences to change their order</div>

            <ul class="os-list" id="os-list">
                <?php foreach ($shuffled as $index => $s): ?>
                    <li class="os-chip"
                        draggable="true"
                        data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="os-chip-label"><?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="os-chip-badge"><?= (int)($index + 1) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="os-controls">
                <button type="button" class="os-btn os-btn-check" id="os-check">Check Answers</button>
                <button type="button" class="os-btn os-btn-show"  id="os-show">Show Answer</button>
                <button type="button" class="os-btn os-btn-next"  id="os-next">Next</button>
                <div id="os-feedback"></div>
            </div>

            <div class="os-completed" id="os-completed">
                <div class="os-completed-icon">✅</div>
                <h2 class="os-completed-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="os-completed-text">You've completed this activity. Great job!</p>
                <p class="os-score" id="os-score"></p>
                <button type="button" class="os-restart-btn" onclick="osRestart()">Try Again</button>
            </div>

        </div>
    </div>
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
var OS_VOICE_ID    = <?= json_encode((string) ($activity['voice_id'] ?? 'nzFihrBIvB34imQBuxub'), JSON_UNESCAPED_UNICODE) ?>;
var OS_TTS_URL     = 'tts.php';

var listEl      = document.getElementById('os-list');
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

function sentenceCards() {
    return Array.from(listEl.querySelectorAll('.os-chip'));
}

function userOrder() {
    return sentenceCards().map(function(c){ return c.dataset.id; });
}

function countCorrect(order) {
    var n = 0;
    for (var i = 0; i < CORRECT_ORDER.length; i++) {
        if ((order[i] || '') === CORRECT_ORDER[i]) n++;
    }
    return n;
}

function updateBadges() {
    sentenceCards().forEach(function(c, i) {
        var b = c.querySelector('.os-chip-badge');
        if (b) b.textContent = i + 1;
    });
}

function clearFeedbackColors() {
    sentenceCards().forEach(function(c) {
        c.classList.remove('correct-pos', 'wrong-pos');
    });
    feedbackEl.textContent = '';
    feedbackEl.className   = '';
}

function markPositions(order) {
    sentenceCards().forEach(function(c, i) {
        c.classList.remove('correct-pos', 'wrong-pos');
        c.classList.add(c.dataset.id === CORRECT_ORDER[i] ? 'correct-pos' : 'wrong-pos');
    });
}

function revealAnswer() {
    var map = {};
    sentenceCards().forEach(function(c){ map[c.dataset.id] = c; });
    CORRECT_ORDER.forEach(function(id) {
        if (map[id]) listEl.appendChild(map[id]);
    });
    updateBadges();
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
        if (window.top && window.top !== window.self) {
            window.top.location.href = url;
            return;
        }
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

    attempts++;
    var order = userOrder();
    var n     = countCorrect(order);

    markPositions(order);

    if (n === OS_TOTAL) {
        correctCount = n;
        feedbackEl.textContent = 'Correct! Well done!';
        feedbackEl.className   = 'good';
        playSound(winSound);
        done = true;
        checkBtn.disabled = true;
        showBtn.disabled  = true;
    } else if (attempts >= 2) {
        correctCount = n;
        feedbackEl.textContent = n + '/' + OS_TOTAL + ' correct — showing the right order.';
        feedbackEl.className   = 'bad';
        playSound(loseSound);
        revealAnswer();
        done = true;
        checkBtn.disabled = true;
        showBtn.disabled  = true;
    } else {
        feedbackEl.textContent = 'Not quite — ' + n + '/' + OS_TOTAL + ' in place. Try again!';
        feedbackEl.className   = 'bad';
        playSound(loseSound);
    }
});

showBtn.addEventListener('click', function() {
    if (done) return;

    correctCount = 0;
    revealAnswer();
    feedbackEl.textContent = 'Correct order shown.';
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
        if (done) {
            e.preventDefault();
            return;
        }

        dragged = chip;
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(function(){ chip.classList.add('os-dragging'); }, 0);
    });

    chip.addEventListener('dragend', function() {
        chip.classList.remove('os-dragging');
        dragged = null;

        if (!done) clearFeedbackColors();
        updateBadges();
    });

    chip.addEventListener('dragover', function(e) {
        e.preventDefault();

        if (!dragged || dragged === chip || done) return;

        var r      = chip.getBoundingClientRect();
        var before = e.clientY < r.top + r.height / 2;

        listEl.insertBefore(dragged, before ? chip : chip.nextSibling);
        updateBadges();
    });

    chip.addEventListener('click', function() {
        if (done) return;

        if (isTouchDev) {
            if (touchSel && touchSel !== chip) {
                var r1 = touchSel.getBoundingClientRect();
                var r2 = chip.getBoundingClientRect();
                if (r1.top < r2.top) {
                    listEl.insertBefore(touchSel, chip.nextSibling);
                } else {
                    listEl.insertBefore(touchSel, chip);
                }
                touchSel.classList.remove('os-selected');
                touchSel = null;
                if (!done) clearFeedbackColors();
                updateBadges();
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
        }
    });
}

listEl.addEventListener('dragover', function(e) {
    e.preventDefault();

    if (!dragged || done) return;

    var afterElement = getDragAfterElement(listEl, e.clientY);
    if (afterElement == null) {
        listEl.appendChild(dragged);
    } else {
        listEl.insertBefore(dragged, afterElement);
    }

    updateBadges();
});

listEl.addEventListener('drop', function(e) {
    e.preventDefault();
    updateBadges();
});

function getDragAfterElement(container, y) {
    var draggableElements = Array.from(container.querySelectorAll('.os-chip:not(.os-dragging)'));

    return draggableElements.reduce(function(closest, child) {
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        }

        return closest;
    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
}

sentenceCards().forEach(function(chip) {
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

    var chips = sentenceCards();

    for (var i = chips.length - 1; i > 0; i--) {
        var j   = Math.floor(Math.random() * (i + 1));
        var tmp = chips[i];
        chips[i] = chips[j];
        chips[j] = tmp;
    }

    chips.forEach(function(c) {
        c.classList.remove('correct-pos', 'wrong-pos', 'os-dragging', 'os-selected');
        listEl.appendChild(c);
    });

    updateBadges();
};

(function shuffleListOnLoad() {
    var items = Array.from(listEl.children);

    for (var i = items.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var temp = items[i];
        items[i] = items[j];
        items[j] = temp;
    }

    items.forEach(function(el) {
        listEl.appendChild(el);
    });
})();

updateBadges();

var TTS_TEXT = <?= json_encode(
    !empty($activity['tts_audio_url']) ? ''
        : (!empty($activity['tts_text'])
            ? $activity['tts_text']
            : implode('. ', array_column($sentences, 'text'))),
    JSON_UNESCAPED_UNICODE
) ?>;
var ttsBtn      = document.getElementById('os-tts-btn');
var ttsAudio    = null;
var ttsAudioUrl = '';

function ttsCleanup() {
    if (ttsAudio) {
        try { ttsAudio.pause(); } catch(e) {}
        try { ttsAudio.currentTime = 0; } catch(e) {}
        ttsAudio = null;
    }
    if (ttsAudioUrl) {
        try { URL.revokeObjectURL(ttsAudioUrl); } catch(e) {}
        ttsAudioUrl = '';
    }
    if (ttsBtn) {
        ttsBtn.disabled = false;
        ttsBtn.textContent = 'Listen';
    }
}

if (ttsBtn) {
    ttsBtn.addEventListener('click', function() {
        if (!TTS_TEXT.trim()) return;

        if (ttsAudio) {
            if (!ttsAudio.paused) {
                ttsAudio.pause();
                ttsBtn.textContent = 'Resume';
            } else {
                ttsAudio.play().then(function(){
                    ttsBtn.textContent = 'Pause';
                }).catch(function(){});
            }
            return;
        }

        ttsBtn.disabled = true;
        ttsBtn.textContent = '...';

        var fd = new FormData();
        fd.append('text', TTS_TEXT);
        fd.append('voice_id', OS_VOICE_ID || 'nzFihrBIvB34imQBuxub');
        fd.append('response_type', 'stream');

        fetch(OS_TTS_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(res) {
                if (!res.ok) throw new Error('TTS error ' + res.status);
                return res.blob();
            })
            .then(function(blob) {
                ttsAudioUrl = URL.createObjectURL(blob);
                ttsAudio = new Audio(ttsAudioUrl);

                ttsAudio.onended = function() {
                    ttsCleanup();
                };

                ttsAudio.onpause = function() {
                    if (ttsAudio && ttsAudio.currentTime < (ttsAudio.duration || Infinity) && ttsBtn) {
                        ttsBtn.textContent = 'Resume';
                    }
                };

                ttsAudio.play().then(function() {
                    if (ttsBtn) {
                        ttsBtn.disabled = false;
                        ttsBtn.textContent = 'Pause';
                    }
                }).catch(function() {
                    ttsCleanup();
                });
            })
            .catch(function() {
                ttsCleanup();
            });
    });
}

})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

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

/* Reset template chrome */
html,
body {
    width: 100%;
    height: 100%;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
    overflow: hidden !important;
}

.activity-wrapper {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh;
    height: 100vh;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
}

.top-row {
    display: none !important;
}

.viewer-content {
    flex: 1 !important;
    height: 100vh !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    min-height: 0 !important;
}

/* Full-screen shell */
.os-page {
    width: 100vw;
    height: 100vh;
    padding: clamp(10px, 1.8vw, 20px);
    display: flex;
    align-items: stretch;
    justify-content: center;
    background: #ffffff;
    overflow: hidden;
    box-sizing: border-box;
}

.os-app {
    width: min(1120px, 100%);
    height: 100%;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

/* Top bar */
.os-topbar {
    height: 34px;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4px;
    position: relative;
}

.os-back-btn {
    position: absolute;
    left: 0;
    background: #EEEDFE;
    border: 1px solid #EDE9FA;
    color: #534AB7;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    border-radius: 999px;
    padding: 6px 13px;
    cursor: pointer;
    transition: filter .15s, transform .15s;
}

.os-back-btn:hover {
    filter: brightness(.96);
    transform: translateY(-1px);
}

body.presentation-mode .os-back-btn,
body.embedded-mode .os-back-btn {
    display: none;
}

.os-topbar-title {
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .1em;
    text-transform: uppercase;
}

/* Hero */
.os-hero {
    flex: 0 0 auto;
    text-align: center;
    margin-bottom: 8px;
}

.os-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 13px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.os-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(26px, 4.3vw, 48px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1;
}

.os-hero p {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(12px, 1.5vw, 16px);
    font-weight: 800;
    color: #9B94BE;
    margin: 5px 0 0;
}

/* Board */
.os-board {
    flex: 1 1 auto;
    min-height: 0;
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    padding: clamp(12px, 2vw, 20px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    width: 100%;
    margin: 0 auto;
    box-sizing: border-box;
    position: relative;
    display: grid;
    grid-template-rows: auto 1fr auto;
    gap: 10px;
}

/* Media / listening */
.os-media-area,
.os-tts-area {
    width: 100%;
    border: 1px solid #EDE9FA;
    border-radius: 26px;
    background: #ffffff;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    overflow: hidden;
    box-sizing: border-box;
}

.os-media-area {
    display: flex;
    align-items: center;
    justify-content: center;
}

.os-media-area video {
    width: 100%;
    max-height: 24vh;
    object-fit: contain;
    display: block;
    background: #000000;
}

.os-media-area iframe {
    width: 100%;
    height: 24vh;
    border: none;
    display: block;
    background: #000000;
}

.os-tts-area {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px;
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

/* Game zone */
.os-game-zone {
    min-height: 0;
    display: grid;
    grid-template-rows: minmax(90px, 0.9fr) minmax(110px, 1.1fr);
    gap: 10px;
}

.os-zone-block {
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.os-zone-label {
    flex: 0 0 auto;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-family: 'Nunito', sans-serif;
    margin-bottom: 6px;
}

/* Drop zone */
.os-dropzone {
    flex: 1 1 auto;
    min-height: 0;
    border: 2px dashed #EDE9FA;
    border-radius: 24px;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 7px;
    padding: 10px;
    transition: border-color .15s, background .15s, box-shadow .15s;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #C4B5FD #ffffff;
    box-sizing: border-box;
}

.os-dropzone::-webkit-scrollbar {
    width: 4px;
}

.os-dropzone::-webkit-scrollbar-thumb {
    background: #C4B5FD;
    border-radius: 2px;
}

.os-dropzone.drag-over {
    border-color: #7F77DD;
    background: #FAFAFE;
    box-shadow: 0 8px 24px rgba(127,119,221,.10);
}

.os-dz-hint {
    width: 100%;
    text-align: center;
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 900;
    color: #9B94BE;
    pointer-events: none;
    font-family: 'Nunito', sans-serif;
    padding: 16px 8px;
    box-sizing: border-box;
}

/* Sentence bank */
.os-bank {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 7px;
    align-items: stretch;
    overflow-y: auto;
    padding-right: 2px;
    scrollbar-width: thin;
    scrollbar-color: #C4B5FD #ffffff;
}

.os-bank::-webkit-scrollbar {
    width: 4px;
}

.os-bank::-webkit-scrollbar-thumb {
    background: #C4B5FD;
    border-radius: 2px;
}

/* Sentence cards */
.os-chip {
    width: 100%;
    min-height: 38px;
    padding: 9px 42px 9px 14px;
    border-radius: 16px;
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
    flex: 0 0 auto;
}

.os-chip:hover {
    transform: translateY(-2px) scale(1.005);
    border-color: #7F77DD;
    box-shadow: 0 16px 28px rgba(127,119,221,.18);
}

.os-chip.os-dragging {
    opacity: .35;
    transform: scale(1.005);
    cursor: grabbing;
}

.os-chip.os-selected {
    border-color: #7F77DD;
    box-shadow: 0 0 0 3px rgba(127,119,221,.25);
}

.os-chip.in-answer {
    border-color: #7F77DD;
}

.os-chip-label {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(15px, 1.8vw, 20px);
    font-weight: 600;
    color: #534AB7;
    text-align: left;
    line-height: 1.12;
    padding: 0;
    word-break: normal;
    overflow-wrap: anywhere;
}

.os-chip-badge {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    min-width: 24px;
    height: 24px;
    padding: 0 7px;
    background: #7F77DD;
    color: #ffffff;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    display: none;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}

.os-chip.in-answer .os-chip-badge {
    display: flex;
}

.os-chip.correct-pos {
    border-color: #16a34a;
    box-shadow: 0 0 0 2px #16a34a;
}

.os-chip.wrong-pos {
    border-color: #dc2626;
    box-shadow: 0 0 0 2px #dc2626;
}

/* Lower buttons */
.os-controls {
    flex: 0 0 auto;
    border-top: 1px solid #F0EEF8;
    padding-top: 10px;
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

.os-btn-show {
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

.os-btn-tts {
    background: #7F77DD;
    color: #ffffff;
    border: none;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
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

/* Completed overlay */
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

/* Responsive */
@media (max-width: 640px) {
    .os-page {
        padding: 8px;
    }

    .os-topbar {
        height: 30px;
        margin-bottom: 2px;
    }

    .os-back-btn {
        left: -2px;
        padding: 5px 10px;
        font-size: 11px;
    }

    .os-kicker {
        padding: 5px 11px;
        font-size: 11px;
        margin-bottom: 4px;
    }

    .os-hero {
        margin-bottom: 6px;
    }

    .os-hero h1 {
        font-size: clamp(23px, 8vw, 34px);
    }

    .os-board {
        border-radius: 26px;
        padding: 10px;
        gap: 8px;
    }

    .os-media-area,
    .os-tts-area {
        border-radius: 20px;
    }

    .os-media-area video,
    .os-media-area iframe {
        max-height: 19vh;
        height: 19vh;
    }

    .os-game-zone {
        grid-template-rows: minmax(82px, 0.9fr) minmax(100px, 1.1fr);
        gap: 8px;
    }

    .os-zone-label {
        font-size: 11px;
        margin-bottom: 5px;
    }

    .os-dropzone {
        border-radius: 20px;
        padding: 8px;
        gap: 6px;
    }

    .os-bank {
        gap: 6px;
    }

    .os-chip {
        min-height: 34px;
        padding: 8px 38px 8px 12px;
        border-radius: 14px;
    }

    .os-chip-label {
        font-size: clamp(14px, 4.2vw, 18px);
    }

    .os-controls {
        display: grid;
        grid-template-columns: 1fr;
        gap: 7px;
        padding-top: 8px;
    }

    .os-btn {
        width: 100%;
        padding: 11px 16px;
    }

    .os-completed {
        border-radius: 26px;
    }
}

@media (max-height: 680px) {
    .os-topbar {
        height: 28px;
    }

    .os-hero h1 {
        font-size: clamp(22px, 3.5vw, 38px);
    }

    .os-hero p {
        font-size: 12px;
        margin-top: 3px;
    }

    .os-kicker {
        padding: 4px 11px;
        margin-bottom: 4px;
    }

    .os-media-area video,
    .os-media-area iframe {
        max-height: 18vh;
        height: 18vh;
    }

    .os-chip {
        min-height: 32px;
        padding-top: 7px;
        padding-bottom: 7px;
    }

    .os-chip-label {
        font-size: clamp(14px, 1.6vw, 18px);
    }

    .os-btn {
        padding-top: 10px;
        padding-bottom: 10px;
    }
}
</style>

<div class="os-page">
    <div class="os-app">

        <div class="os-topbar">
            <button class="os-back-btn" onclick="history.back()">Back</button>
            <span class="os-topbar-title">Sentence Ordering</span>
        </div>

        <div class="os-hero">
            <div class="os-kicker">Activity</div>
            <h1><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars((string)($activity['instructions'] ?? 'Put the words in the correct order.'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="os-board">

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

            <?php else: ?>
                <div class="os-tts-area">
                    <div class="os-listen-panel">
                        <button type="button" id="os-tts-btn" class="os-btn os-btn-tts">Listen</button>
                        <span class="os-listen-text">Listen and order the sentences.</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="os-game-zone" id="os-game-zone">

                <div class="os-zone-block">
                    <div class="os-zone-label">Your answer — drag here in order</div>
                    <div class="os-dropzone" id="os-dropzone">
                        <span class="os-dz-hint" id="os-dz-hint">Drag the sentences here in the correct order</span>
                    </div>
                </div>

                <div class="os-zone-block">
                    <div class="os-zone-label">Sentences</div>
                    <div class="os-bank" id="os-bank">
                        <?php foreach ($shuffled as $s): ?>
                            <div class="os-chip"
                                 draggable="true"
                                 data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="os-chip-badge">?</div>
                                <span class="os-chip-label"><?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <div class="os-controls">
                <button type="button" class="os-btn os-btn-check" id="os-check" disabled>Check Answers</button>
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

    if (answerChips().length < OS_TOTAL) {
        feedbackEl.textContent = 'Place all sentences first.';
        feedbackEl.className   = 'bad';
        return;
    }

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
        dropzone.classList.remove('drag-over');
        dragged = null;

        if (!done) clearFeedbackColors();
        updateUI();
    });

    chip.addEventListener('dragover', function(e) {
        e.preventDefault();

        if (!dragged || dragged === chip || done) return;

        var r      = chip.getBoundingClientRect();
        var before = e.clientY < r.top + r.height / 2;

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
            dropzone.appendChild(chip);
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
        var tmp = chips[i];
        chips[i] = chips[j];
        chips[j] = tmp;
    }

    chips.forEach(function(c) {
        c.classList.remove('correct-pos', 'wrong-pos', 'os-dragging', 'os-selected', 'in-answer');
        bank.appendChild(c);
    });

    updateUI();
};

updateUI();

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
    var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
    if (!voices.length) return null;

    var pre = lang.split('-')[0].toLowerCase();

    var matches = voices.filter(function(v) {
        var vl = String(v.lang || '').toLowerCase();
        return vl === lang.toLowerCase() || vl.startsWith(pre + '-') || vl.startsWith(pre + '_');
    });

    if (!matches.length) return voices[0] || null;

    var hints = ['female','woman','zira','samantha','karen','aria','jenny','emma','olivia','ava'];

    var female = matches.find(function(v) {
        var label = (String(v.name || '') + ' ' + String(v.voiceURI || '')).toLowerCase();
        return hints.some(function(h){ return label.indexOf(h) !== -1; });
    });

    return female || matches[0];
}

function ttsStart() {
    var remaining = TTS_TEXT.slice(Math.max(0, ttsOffset));

    if (!remaining.trim()) {
        ttsSpeaking = false;
        ttsPaused   = false;
        ttsOffset   = 0;
        return;
    }

    speechSynthesis.cancel();

    ttsSegStart     = ttsOffset;
    ttsUtter        = new SpeechSynthesisUtterance(remaining);
    ttsUtter.lang   = 'en-US';
    ttsUtter.rate   = 0.7;
    ttsUtter.pitch  = 1;
    ttsUtter.volume = 1;

    var pref = ttsPreferredVoice('en-US');
    if (pref) ttsUtter.voice = pref;

    ttsUtter.onstart = function(){
        ttsSpeaking = true;
        ttsPaused   = false;
        if (ttsBtn) ttsBtn.textContent = 'Pause';
    };

    ttsUtter.onpause = function(){
        ttsPaused   = true;
        ttsSpeaking = true;
        if (ttsBtn) ttsBtn.textContent = 'Resume';
    };

    ttsUtter.onresume = function(){
        ttsPaused   = false;
        ttsSpeaking = true;
        if (ttsBtn) ttsBtn.textContent = 'Pause';
    };

    ttsUtter.onboundary = function(ev) {
        if (typeof ev.charIndex === 'number') {
            ttsOffset = Math.max(ttsSegStart, Math.min(TTS_TEXT.length, ttsSegStart + ev.charIndex));
        }
    };

    ttsUtter.onend = function(){
        if (!ttsPaused) {
            ttsSpeaking = false;
            ttsPaused   = false;
            ttsOffset   = 0;
            if (ttsBtn) ttsBtn.textContent = 'Listen';
        }
    };

    ttsUtter.onerror = function(){
        ttsSpeaking = false;
        ttsPaused   = false;
        ttsOffset   = 0;
        if (ttsBtn) ttsBtn.textContent = 'Listen';
    };

    speechSynthesis.speak(ttsUtter);
}

if (ttsBtn) {
    ttsBtn.addEventListener('click', function() {
        if (!TTS_TEXT.trim()) return;

        if (speechSynthesis.paused || ttsPaused) {
            speechSynthesis.resume();
            ttsSpeaking = true;
            ttsPaused   = false;
            ttsBtn.textContent = 'Pause';

            setTimeout(function(){
                if (!speechSynthesis.speaking && ttsOffset < TTS_TEXT.length) ttsStart();
            }, 80);

            return;
        }

        if (speechSynthesis.speaking && !speechSynthesis.paused) {
            speechSynthesis.pause();
            ttsSpeaking = true;
            ttsPaused   = true;
            ttsBtn.textContent = 'Resume';
            return;
        }

        speechSynthesis.cancel();
        ttsOffset = 0;
        ttsStart();
    });
}

})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

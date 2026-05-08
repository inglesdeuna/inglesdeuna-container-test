<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

/* Back URL */
$_fb_assignment  = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';
$_fb_source      = isset($_GET['source'])     ? trim((string)$_GET['source'])     : '';
$_fb_returnParam = isset($_GET['return_to'])  ? trim((string)$_GET['return_to'])  : '';
$_fb_isSafeRelative = $_fb_returnParam !== ''
    && !preg_match('#^[a-zA-Z][a-zA-Z0-9+\\-.]*://#', $_fb_returnParam)
    && strpos($_fb_returnParam, '//') !== 0;

if ($_fb_isSafeRelative) {
    $_fb_backUrl = $_fb_returnParam;
} elseif ($_fb_assignment !== '') {
    $_fb_backUrl = '../../academic/teacher_unit.php?assignment=' . urlencode($_fb_assignment) . '&unit=' . urlencode($unit);
} else {
    $_fb_backUrl = '../../academic/unit_view.php?unit=' . urlencode($unit);
    if ($_fb_source !== '') $_fb_backUrl .= '&source=' . urlencode($_fb_source);
}

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function fb_load(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = array(
        'id'           => '',
        'instructions' => 'Write the missing words in the blanks.',
        'blocks'       => array(),
        'wordbank'     => '',
        'media_type'   => 'none',
        'media_url'    => '',
        'tts_text'     => '',
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $data = json_decode($row['data'] ?? '', true);

    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = array(array(
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
            'image'   => '',
        ));
    } else {
        $blocks = isset($data['blocks']) ? $data['blocks'] : array();
    }

    return array(
        'id'           => (string)($row['id'] ?? ''),
        'instructions' => isset($data['instructions']) ? $data['instructions'] : $fallback['instructions'],
        'blocks'       => $blocks,
        'wordbank'     => isset($data['wordbank'])   ? $data['wordbank']   : '',
        'media_type'   => isset($data['media_type']) ? $data['media_type'] : 'none',
        'media_url'    => isset($data['media_url'])  ? $data['media_url']  : '',
        'tts_text'     => isset($data['tts_text'])   ? $data['tts_text']   : '',
        'tts_audio_url'=> isset($data['tts_audio_url']) ? $data['tts_audio_url'] : '',
    );
}

$activity = fb_load($pdo, $unit, $activityId);
$blocks   = $activity['blocks'];

if (empty($blocks)) {
    die('No activity blocks found.');
}

$renderedBlocks = array();
$firstImage = '';

foreach ($blocks as $bIdx => $block) {
    $text    = isset($block['text'])  ? $block['text']  : '';
    $image   = isset($block['image']) ? trim((string)$block['image']) : '';
    $answers = isset($block['answers']) && is_array($block['answers']) ? $block['answers'] : array();
    $blankN  = 0;

    if ($firstImage === '' && $image !== '') {
        $firstImage = $image;
    }

    $rendered = preg_replace_callback('/___+/', function ($m) use (&$blankN, $bIdx) {
        $blankN++;
        $sizerId = 's' . $bIdx . '_' . $blankN;
        $inputId = 'i' . $bIdx . '_' . $blankN;

        return
            '<span class="fb-blank-wrap">' .
                '<span class="fb-blank-sizer" id="' . $sizerId . '">...</span>' .
                '<input class="fb-blank"' .
                    ' id="' . $inputId . '"' .
                    ' data-sizer="' . $sizerId . '"' .
                    ' data-block="' . $bIdx . '"' .
                    ' data-n="' . $blankN . '"' .
                    ' placeholder="..."' .
                    ' autocomplete="off"' .
                    ' spellcheck="false">' .
            '</span>';
    }, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

    $renderedBlocks[] = array(
        'rendered'   => $rendered,
        'image'      => $image,
        'answers'    => $answers,
        'blankCount' => $blankN,
    );
}

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --fb-orange: #F97316;
    --fb-orange-dark: #C2580A;
    --fb-orange-soft: #FFF0E6;
    --fb-purple: #7F77DD;
    --fb-purple-dark: #534AB7;
    --fb-purple-soft: #EEEDFE;
    --fb-white: #FFFFFF;
    --fb-lila-border: #EDE9FA;
    --fb-muted: #9B94BE;
    --fb-ink: #271B5D;
    --fb-green: #16a34a;
    --fb-red: #dc2626;
}

/* Reset template chrome */
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

/* Page shell */
.fb-page {
    width: 100%;
    min-height: 100vh;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #ffffff;
    box-sizing: border-box;
}

.fb-app {
    width: min(920px, 100%);
    margin: 0 auto;
}

/* Top bar */
.fb-topbar {
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    position: relative;
}

.fb-back-btn {
    position: absolute;
    left: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #EEEDFE;
    border: 1px solid #EDE9FA;
    color: #534AB7;
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    border-radius: 999px;
    padding: 7px 14px;
    cursor: pointer;
    text-decoration: none;
    transition: filter .15s, transform .15s;
}

.fb-back-btn:hover {
    filter: brightness(.96);
    transform: translateY(-1px);
}

body.presentation-mode .fb-back-btn,
body.embedded-mode .fb-back-btn {
    display: none;
}

.fb-topbar-title {
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .1em;
    text-transform: uppercase;
}

/* Hero */
.fb-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}

.fb-kicker {
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
    margin-bottom: 10px;
}

.fb-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1.03;
}

.fb-hero p {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 8px 0 0;
}

/* Board */
.fb-card {
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    width: min(860px, 100%);
    margin: 0 auto;
    box-sizing: border-box;
    position: relative;
}

.fb-card-hd {
    display: none;
}

/* Media */
.fb-tts-bar {
    width: 100%;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    background: #ffffff;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    overflow: hidden;
    margin-bottom: 16px;
    padding: 18px;
    text-align: center;
    box-sizing: border-box;
}

.fb-tts-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #7F77DD;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 13px 24px;
    min-width: clamp(104px, 16vw, 146px);
    font-size: 13px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
    transition: transform .12s, filter .12s, box-shadow .12s;
}

.fb-tts-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.fb-tts-bar audio {
    width: 100%;
}

/* Word bank */
.fb-wordbank {
    background: #EEEDFE;
    border: 1px solid #EDE9FA;
    border-radius: 22px;
    padding: 13px 14px;
    margin-bottom: 16px;
}

.fb-wb-label {
    font-size: 12px;
    font-weight: 900;
    color: #534AB7;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-family: 'Nunito', sans-serif;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 9px;
}

.fb-wb-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.fb-wb-chip {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 999px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    color: #534AB7;
    padding: 7px 13px;
    white-space: nowrap;
    line-height: 1;
    box-shadow: 0 4px 14px rgba(127,119,221,.08);
}

/* Worksheet area */
.fb-block-area {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    padding: clamp(18px, 3vw, 28px);
    min-height: clamp(220px, 30vh, 330px);
    box-sizing: border-box;
}

.fb-worksheet-img {
    display: block;
    max-width: min(420px, 100%);
    max-height: 190px;
    object-fit: contain;
    border-radius: 20px;
    margin: 0 auto 20px;
    border: 1px solid #EDE9FA;
    background: #ffffff;
    box-shadow: 0 8px 24px rgba(127,119,221,.10);
}

.fb-worksheet-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.fb-block {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 10px 2px 11px;
    border-bottom: 1px solid #EDE9FA;
}

.fb-block:last-child {
    border-bottom: none;
}

.fb-sentence-num {
    flex: 0 0 auto;
    min-width: 28px;
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.6vw, 15px);
    font-weight: 800;
    color: #9B94BE;
    line-height: 1.8;
    text-align: right;
}

.fb-text {
    flex: 1 1 auto;
    font-family: 'Nunito', sans-serif;
    font-size: clamp(15px, 1.8vw, 18px);
    font-weight: 700;
    color: #534AB7;
    line-height: 1.8;
    text-align: left;
}

/* Blanks */
.fb-blank-wrap {
    display: inline-block;
    position: relative;
    vertical-align: baseline;
    margin: 0 4px;
    min-width: 74px;
}

.fb-blank-sizer {
    position: absolute;
    inset: 0;
    visibility: hidden;
    white-space: pre;
    font-size: clamp(15px, 1.8vw, 18px);
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    padding: 2px 10px;
    pointer-events: none;
    border-bottom: 3px solid transparent;
}

.fb-blank {
    display: inline-block;
    width: 100%;
    min-width: 74px;
    border: none;
    border-bottom: 3px solid #EDE9FA;
    background: transparent;
    padding: 2px 10px;
    font-size: clamp(15px, 1.8vw, 18px);
    font-weight: 800;
    font-family: 'Nunito', sans-serif;
    color: #534AB7;
    outline: none;
    text-align: center;
    vertical-align: baseline;
    transition: border-color .15s, box-shadow .15s, background .15s;
}

.fb-blank:hover {
    border-bottom-color: #7F77DD;
}

.fb-blank:focus {
    border-bottom-color: #7F77DD;
    box-shadow: 0 4px 0 rgba(127,119,221,.18);
}

.fb-blank.correct {
    border-bottom-color: #16a34a;
    color: #16a34a;
}

.fb-blank.wrong {
    border-bottom-color: #dc2626;
    color: #dc2626;
    animation: fbShake .3s ease;
}

.fb-blank.revealed {
    border-bottom-color: #7F77DD;
    color: #534AB7;
    background: #EEEDFE;
    border-radius: 10px;
}

@keyframes fbShake {
    0%,100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

/* Controls */
.fb-controls {
    border-top: 1px solid #F0EEF8;
    margin-top: 16px;
    padding-top: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    background: #ffffff;
    position: relative;
    z-index: 5;
}

.fb-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 13px 20px;
    min-width: clamp(104px, 16vw, 146px);
    border: none;
    border-radius: 999px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    color: #ffffff;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
    pointer-events: auto;
    transition: transform .12s, filter .12s, box-shadow .12s;
}

.fb-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.fb-btn:active {
    transform: scale(.98);
}

.fb-btn:disabled {
    opacity: .45;
    cursor: default;
    transform: none;
    filter: none;
    box-shadow: none;
}

#fb-check {
    background: #F97316;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

#fb-show {
    background: #7F77DD;
}

#fb-next {
    background: #F97316;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

#fb-feedback {
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    text-align: center;
    min-height: 18px;
    width: 100%;
}

#fb-feedback.good {
    color: #16a34a;
}

#fb-feedback.bad {
    color: #dc2626;
}

/* Completed */
.fb-completed {
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
    gap: 12px;
    z-index: 20;
}

.fb-completed.active {
    display: flex;
}

.fb-completed-icon {
    font-size: 64px;
    line-height: 1;
    margin-bottom: 4px;
}

.fb-completed-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
}

.fb-completed-msg {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 0;
}

.fb-score-ring {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: #EEEDFE;
    border: 3px solid #EDE9FA;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.fb-score-pct {
    font-family: 'Fredoka', sans-serif;
    font-size: 28px;
    font-weight: 700;
    color: #534AB7;
    line-height: 1;
}

.fb-score-lbl {
    font-size: 10px;
    font-weight: 900;
    color: #7F77DD;
    letter-spacing: .04em;
}

.fb-score-frac {
    font-size: 15px;
    font-weight: 900;
    color: #534AB7;
}

.fb-restart-btn {
    background: #7F77DD;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 13px 28px;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
    transition: filter .15s, transform .15s;
}

.fb-restart-btn:hover {
    filter: brightness(1.07);
    transform: scale(1.04);
}

/* Responsive */
@media (max-width: 640px) {
    .fb-page {
        padding: 12px;
    }

    .fb-topbar {
        height: 30px;
        margin-bottom: 4px;
    }

    .fb-back-btn {
        left: -2px;
        padding: 5px 10px;
        font-size: 11px;
    }

    .fb-hero {
        margin-bottom: 12px;
    }

    .fb-kicker {
        padding: 5px 11px;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .fb-hero h1 {
        font-size: clamp(26px, 8vw, 38px);
    }

    .fb-card {
        border-radius: 26px;
        padding: 14px;
        width: 100%;
    }

    .fb-tts-bar {
        border-radius: 22px;
        margin-bottom: 12px;
        padding: 14px;
    }

    .fb-wordbank {
        border-radius: 18px;
        margin-bottom: 12px;
    }

    .fb-block-area {
        border-radius: 22px;
        padding: 16px;
        min-height: 210px;
    }

    .fb-worksheet-img {
        max-height: 150px;
        margin-bottom: 16px;
    }

    .fb-block {
        gap: 8px;
        padding: 9px 0 10px;
    }

    .fb-sentence-num,
    .fb-text {
        font-size: clamp(14px, 4vw, 17px);
    }

    .fb-blank,
    .fb-blank-sizer {
        font-size: clamp(14px, 4vw, 17px);
    }

    .fb-controls {
        display: grid;
        grid-template-columns: 1fr;
        gap: 9px;
    }

    .fb-btn {
        width: 100%;
    }

    .fb-completed {
        border-radius: 26px;
    }
}
</style>

<div class="fb-page">
    <div class="fb-app">

        <div class="fb-topbar">
            <a class="fb-back-btn" href="<?php echo htmlspecialchars($_fb_backUrl, ENT_QUOTES, 'UTF-8'); ?>">Back</a>
            <span class="fb-topbar-title">Fill in the Blank</span>
        </div>

        <div class="fb-hero">
            <div class="fb-kicker">Activity</div>
            <h1>Fill in the Blank</h1>
            <p><?php echo htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <div class="fb-card">

            <?php if ($activity['media_type'] === 'tts' && !empty($activity['tts_text'])): ?>
            <div class="fb-tts-bar">
                <?php if (!empty($activity['tts_audio_url'])): ?>
                <audio id="fb-tts-audio" src="<?php echo htmlspecialchars($activity['tts_audio_url'], ENT_QUOTES, 'UTF-8'); ?>" controls preload="none" style="height:36px;flex:1;min-width:0"></audio>
                <?php else: ?>
                <button type="button" id="fb-tts-btn" class="fb-tts-btn">Listen</button>
                <?php endif; ?>
            </div>
            <?php elseif ($activity['media_type'] === 'audio' && !empty($activity['media_url'])): ?>
            <div class="fb-tts-bar">
                <audio controls src="<?php echo htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8'); ?>"></audio>
            </div>
            <?php endif; ?>

            <?php if (!empty($activity['wordbank'])): ?>
            <div class="fb-wordbank">
                <div class="fb-wb-label">Word bank</div>
                <div class="fb-wb-chips">
                    <?php
                    $_wb_raw = $activity['wordbank'];
                    $_wb_items = (strpos($_wb_raw, '|') !== false)
                        ? explode('|', $_wb_raw)
                        : explode(',', $_wb_raw);
                    $_wb_items = array_values(array_filter(array_map('trim', $_wb_items), 'strlen'));
                    foreach ($_wb_items as $_wbWord): ?>
                    <span class="fb-wb-chip"><?php echo htmlspecialchars($_wbWord, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="fb-block-area">
                <?php if ($firstImage !== ''): ?>
                <img class="fb-worksheet-img" src="<?php echo htmlspecialchars($firstImage, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php endif; ?>

                <div class="fb-worksheet-list">
                    <?php foreach ($renderedBlocks as $bIdx => $block): ?>
                    <div class="fb-block"
                         id="fb-block-<?php echo $bIdx; ?>"
                         data-answers="<?php echo htmlspecialchars(json_encode($block['answers']), ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="fb-sentence-num"><?php echo ($bIdx + 1); ?>.</span>
                        <div class="fb-text"><?php echo $block['rendered']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="fb-controls">
                <button type="button" class="fb-btn" id="fb-check">Check</button>
                <button type="button" class="fb-btn" id="fb-show">Show Answer</button>
                <button type="button" class="fb-btn" id="fb-next">Next</button>
                <div id="fb-feedback"></div>
            </div>

            <div class="fb-completed" id="fb-completed">
                <div class="fb-completed-icon">&#x2705;</div>
                <h2 class="fb-completed-title">Fill in the Blank</h2>
                <p class="fb-completed-msg">Activity completed. Great job!</p>
                <div class="fb-score-ring">
                    <span class="fb-score-pct" id="fb-score-pct">&#8212;</span>
                    <span class="fb-score-lbl">SCORE</span>
                </div>
                <div class="fb-score-frac" id="fb-score-frac"></div>
                <button type="button" class="fb-restart-btn" onclick="fbRestart()">Try Again</button>
            </div>

        </div>
    </div>
</div>

<audio id="fb-win-sound"  src="../../hangman/assets/win.mp3"     preload="auto"></audio>
<audio id="fb-lose-sound" src="../../hangman/assets/lose.mp3"    preload="auto"></audio>
<audio id="fb-done-sound" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
(function () {

var BLOCKS      = <?php echo json_encode(array_map(function($b) { return $b['answers']; }, $renderedBlocks), JSON_UNESCAPED_UNICODE); ?>;
var RETURN_TO   = <?php echo json_encode($returnTo,   JSON_UNESCAPED_UNICODE); ?>;
var ACTIVITY_ID = <?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
var TOTAL       = BLOCKS.length;

var done      = false;
var revealed  = {};

var winSound  = document.getElementById('fb-win-sound');
var losSound  = document.getElementById('fb-lose-sound');
var doneSound = document.getElementById('fb-done-sound');

var btnCheck = document.getElementById('fb-check');
var btnShow  = document.getElementById('fb-show');
var btnNext  = document.getElementById('fb-next');

btnCheck.addEventListener('click', function() { fbCheckAll(); });
btnShow.addEventListener('click',  function() { fbShowAll(); });
btnNext.addEventListener('click',  function() { fbFinish(); });

function playSound(el) {
    try { el.pause(); el.currentTime = 0; el.play(); } catch(e) {}
}

function resizeInput(inp) {
    var sizer = document.getElementById(inp.getAttribute('data-sizer'));
    if (!sizer) return;

    sizer.textContent = inp.value || inp.getAttribute('placeholder') || '...';

    var width = Math.max(74, sizer.offsetWidth + 12);
    inp.style.width = width + 'px';
}

function initResizers() {
    var blanks = document.querySelectorAll('.fb-blank');

    for (var i = 0; i < blanks.length; i++) {
        (function(inp) {
            resizeInput(inp);
            inp.addEventListener('input', function() { resizeInput(inp); });
        })(blanks[i]);
    }
}

function blockEl(b) {
    return document.getElementById('fb-block-' + b);
}

function blanksOf(b) {
    var el = blockEl(b);
    if (!el) return [];
    return Array.prototype.slice.call(el.querySelectorAll('.fb-blank'));
}

function allBlanks() {
    return Array.prototype.slice.call(document.querySelectorAll('.fb-blank'));
}

function answersOf(b) {
    var el = blockEl(b);
    if (!el) return [];
    try { return JSON.parse(el.getAttribute('data-answers') || '[]'); } catch(e) { return []; }
}

function normalizeAnswer(value) {
    return String(value || '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase();
}

function blankKey(b, i) {
    return b + '_' + i;
}

function setFb(msg, cls) {
    var f = document.getElementById('fb-feedback');
    f.textContent = msg;
    f.className = cls || '';
}

function clearFb() {
    setFb('', '');
}

function fbCheckAll() {
    if (done) return;

    var total = 0;
    var ok = 0;

    for (var b = 0; b < TOTAL; b++) {
        var ans  = answersOf(b);
        var inps = blanksOf(b);

        for (var i = 0; i < inps.length; i++) {
            total++;
            inps[i].classList.remove('correct', 'wrong', 'revealed');

            var v = normalizeAnswer(inps[i].value);
            var a = normalizeAnswer(ans[i] || '');

            if (v === a && v !== '') {
                inps[i].classList.add('correct');
                ok++;
            } else {
                inps[i].classList.add('wrong');
            }
        }
    }

    if (ok === total) {
        setFb('Correct! Well done!', 'good');
        playSound(winSound);
    } else {
        setFb(ok + ' / ' + total + ' correct — try again!', 'bad');
        playSound(losSound);
    }
}

function fbShowAll() {
    if (done) return;

    for (var b = 0; b < TOTAL; b++) {
        var ans  = answersOf(b);
        var inps = blanksOf(b);

        for (var i = 0; i < inps.length; i++) {
            inps[i].value = ans[i] || '';
            resizeInput(inps[i]);
            inps[i].classList.remove('correct', 'wrong');
            inps[i].classList.add('revealed');
            revealed[blankKey(b, i)] = true;
        }
    }

    setFb('Answers shown.', 'good');
    btnCheck.disabled = true;
    btnShow.disabled  = true;
}

function fbFinish() {
    if (done) return;
    done = true;

    var totalBlanks   = 0;
    var correctBlanks = 0;

    for (var b = 0; b < TOTAL; b++) {
        var ans  = answersOf(b);
        var inps = blanksOf(b);

        for (var i = 0; i < inps.length; i++) {
            totalBlanks++;

            if (!revealed[blankKey(b, i)]) {
                var v = normalizeAnswer(inps[i].value);
                var a = normalizeAnswer(ans[i] || '');
                if (v === a && v !== '') correctBlanks++;
            }
        }
    }

    var pct    = totalBlanks > 0 ? Math.round(correctBlanks / totalBlanks * 100) : 0;
    var errors = Math.max(0, totalBlanks - correctBlanks);

    document.getElementById('fb-score-pct').textContent  = pct + '%';
    document.getElementById('fb-score-frac').textContent = correctBlanks + ' / ' + totalBlanks + ' correct';
    document.getElementById('fb-completed').classList.add('active');
    playSound(doneSound);

    if (RETURN_TO && ACTIVITY_ID) {
        var sep = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var url = RETURN_TO + sep +
            'activity_percent=' + pct +
            '&activity_errors=' + errors +
            '&activity_total='  + totalBlanks +
            '&activity_id='     + encodeURIComponent(ACTIVITY_ID) +
            '&activity_type=fillblank';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.withCredentials = true;
        xhr.onload = function() {
            if (xhr.status < 200 || xhr.status >= 300) navigate(url);
        };
        xhr.onerror = function() { navigate(url); };
        xhr.send();
    }
}

function navigate(url) {
    try {
        if (window.top && window.top !== window.self) {
            window.top.location.href = url;
            return;
        }
    } catch(e) {}

    window.location.href = url;
}

window.fbRestart = function() {
    done     = false;
    revealed = {};

    document.getElementById('fb-completed').classList.remove('active');

    btnCheck.disabled = false;
    btnShow.disabled  = false;

    clearFb();

    var inps = allBlanks();
    for (var i = 0; i < inps.length; i++) {
        inps[i].value = '';
        inps[i].classList.remove('correct', 'wrong', 'revealed');
        resizeInput(inps[i]);
    }

    if (inps[0]) setTimeout(function() { inps[0].focus(); }, 80);
};

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;

    var active = document.activeElement;
    if (!active || !active.classList.contains('fb-blank')) return;

    e.preventDefault();

    var all = allBlanks();
    var idx = all.indexOf(active);

    if (idx !== -1 && idx < all.length - 1) {
        all[idx + 1].focus();
    } else {
        fbCheckAll();
    }
});

<?php if ($activity['media_type'] === 'tts' && !empty($activity['tts_text'])): ?>
var TTS_AUDIO_URL = <?php echo json_encode($activity['tts_audio_url'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
var TTS_TEXT    = TTS_AUDIO_URL ? '' : <?php echo json_encode($activity['tts_text'], JSON_UNESCAPED_UNICODE); ?>;
var ttsBtn      = TTS_AUDIO_URL ? null : document.getElementById('fb-tts-btn');
var ttsSpeaking = false;
var ttsPaused   = false;
var ttsOffset   = 0;
var ttsSegStart = 0;
var ttsUtter    = null;

function ttsVoice(lang) {
    var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
    if (!voices.length) return null;

    var pre = lang.split('-')[0].toLowerCase();
    var m = [];

    for (var i = 0; i < voices.length; i++) {
        var vl = String(voices[i].lang || '').toLowerCase();
        if (vl === lang.toLowerCase() || vl.indexOf(pre + '-') === 0 || vl.indexOf(pre + '_') === 0) {
            m.push(voices[i]);
        }
    }

    if (!m.length) return voices[0] || null;

    var hints = ['female','woman','zira','samantha','karen','aria','jenny','emma'];

    for (var j = 0; j < m.length; j++) {
        var label = (String(m[j].name || '') + ' ' + String(m[j].voiceURI || '')).toLowerCase();

        for (var k = 0; k < hints.length; k++) {
            if (label.indexOf(hints[k]) !== -1) return m[j];
        }
    }

    return m[0];
}

function ttsStart() {
    var rem = TTS_TEXT.slice(Math.max(0, ttsOffset));

    if (!rem.trim()) {
        ttsSpeaking = false;
        ttsPaused = false;
        ttsOffset = 0;
        if (ttsBtn) ttsBtn.textContent = 'Listen';
        return;
    }

    speechSynthesis.cancel();

    ttsSegStart = ttsOffset;
    ttsUtter    = new SpeechSynthesisUtterance(rem);
    ttsUtter.lang   = 'en-US';
    ttsUtter.rate   = 0.7;
    ttsUtter.pitch  = 1;
    ttsUtter.volume = 1;

    var pref = ttsVoice('en-US');
    if (pref) ttsUtter.voice = pref;

    ttsUtter.onstart = function() {
        ttsSpeaking = true;
        ttsPaused = false;
        if (ttsBtn) ttsBtn.textContent = 'Pause';
    };

    ttsUtter.onpause = function() {
        ttsPaused = true;
        ttsSpeaking = true;
        if (ttsBtn) ttsBtn.textContent = 'Resume';
    };

    ttsUtter.onresume = function() {
        ttsPaused = false;
        ttsSpeaking = true;
        if (ttsBtn) ttsBtn.textContent = 'Pause';
    };

    ttsUtter.onboundary = function(ev) {
        if (typeof ev.charIndex === 'number') {
            ttsOffset = Math.max(ttsSegStart, Math.min(TTS_TEXT.length, ttsSegStart + ev.charIndex));
        }
    };

    ttsUtter.onend = function() {
        if (!ttsPaused) {
            ttsSpeaking = false;
            ttsPaused = false;
            ttsOffset = 0;
            if (ttsBtn) ttsBtn.textContent = 'Listen';
        }
    };

    ttsUtter.onerror = function() {
        ttsSpeaking = false;
        ttsPaused = false;
        ttsOffset = 0;
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
            ttsPaused = false;
            ttsBtn.textContent = 'Pause';

            setTimeout(function() {
                if (!speechSynthesis.speaking && ttsOffset < TTS_TEXT.length) ttsStart();
            }, 80);

            return;
        }

        if (speechSynthesis.speaking && !speechSynthesis.paused) {
            speechSynthesis.pause();
            ttsSpeaking = true;
            ttsPaused = true;
            ttsBtn.textContent = 'Resume';
            return;
        }

        speechSynthesis.cancel();
        ttsOffset = 0;
        ttsStart();
    });
}
<?php endif; ?>

initResizers();

})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer('Fill-in-the-Blank', 'fa-solid fa-pen-to-square', $content);

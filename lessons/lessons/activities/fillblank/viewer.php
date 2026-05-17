<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

/* Back URL resolution (keep same logic as original) */
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

function fb2_load(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id'           => '',
        'instructions' => 'Write the missing words in the blanks.',
        'blocks'       => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $data = json_decode($row['data'] ?? '', true);

    if (!isset($data['blocks']) && isset($data['text'])) {
        $blocks = [[
            'text'    => $data['text'],
            'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
        ]];
    } else {
        $blocks = isset($data['blocks']) ? $data['blocks'] : [];
    }

    /* Parse wordbank: "nearby | closed | open" → ['nearby','closed','open'] */
    $wordbank = isset($data['wordbank']) ? trim((string)$data['wordbank']) : '';
    $options  = [];
    if ($wordbank !== '') {
        $options = array_values(array_filter(array_map('trim', explode('|', $wordbank))));
    }

    return [
        'id'           => (string)($row['id'] ?? ''),
        'instructions' => isset($data['instructions']) ? $data['instructions'] : $fallback['instructions'],
        'blocks'       => $blocks,
        'options'      => $options,
    ];
}

$activity = fb2_load($pdo, $unit, $activityId);
$blocks   = $activity['blocks'];

if (empty($blocks)) {
    die('No activity blocks found.');
}

/*
 * Map each block to {instruction, before, after, answer}
 * fillblank.js expects one question per block with a single blank.
 * We split the text at the first ___ occurrence.
 */
$jsQuestions = [];
$instruction = $activity['instructions'];

foreach ($blocks as $block) {
    $text    = isset($block['text']) ? $block['text'] : '';
    $answers = isset($block['answers']) && is_array($block['answers']) ? $block['answers'] : [];

    /* Split at first blank sequence */
    $parts = preg_split('/___+/', $text, 2);
    $before = isset($parts[0]) ? trim($parts[0]) : $text;
    $after  = isset($parts[1]) ? trim($parts[1]) : '';
    $answer = isset($answers[0]) ? trim((string)$answers[0]) : '';

    if ($answer === '') continue;

    $jsQuestions[] = [
        'instruction' => $instruction,
        'before'      => $before,
        'after'       => $after,
        'answer'      => $answer,
        'options'     => $activity['options'] ?? [],
    ];
}

if (empty($jsQuestions)) {
    die('No valid fill-blank questions found.');
}

$viewerTitle = 'Fill in the Blank';

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --orange: #F97316;
    --purple: #7F77DD;
    --muted: #9B8FCC;
    --light-purple-bg: #F9F8FF;
    --border: #EDE9FA;
    --kicker-bg: #FFF0E6;
    --chip-border: #B8B2E8;
    --medium-purple: #5A51C0;
    --inactive: #C5C1ED;
}
* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }
body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
.activity-wrapper { max-width: 100% !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; background: transparent !important; }
.top-row, .activity-header { display: none !important; }
.viewer-content { flex: 1 !important; display: flex !important; flex-direction: column !important; padding: 0 !important; margin: 0 !important; background: transparent !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; }

.fb-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #fff;
    box-sizing: border-box;
}
.fb-app {
    width: min(580px, 100%);
    margin: 0 auto;
}
.fb-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}
.fb-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 14px;
    border-radius: 99px;
    background: var(--kicker-bg);
    border: none;
    color: var(--orange);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 10px;
    font-family: 'Nunito', sans-serif;
}
.fb-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: 32px;
    font-weight: 700;
    color: var(--orange);
    margin: 0;
    line-height: 1;
}
.fb-hero p {
    font-size: 13px;
    font-weight: 400;
    color: var(--muted);
    margin: 8px 0 0;
    font-family: 'Nunito', sans-serif;
}

/* Progress */
.fb-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.fb-progress-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--muted);
    min-width: 48px;
    font-family: 'Nunito', sans-serif;
}
.fb-track {
    flex: 1;
    height: 6px;
    background: var(--border);
    border-radius: 99px;
    overflow: hidden;
}
.fb-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--orange), var(--purple));
    border-radius: 99px;
    transition: width 0.35s;
}
.fb-badge {
    min-width: auto;
    text-align: center;
    padding: 3px 12px;
    border-radius: 99px;
    background: var(--purple);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    font-family: 'Nunito', sans-serif;
}

/* Card */
.fb-card-shell {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(127, 119, 221, 0.08);
    margin-bottom: 16px;
}

/* Sentence */
#fb-sentence {
    background: var(--light-purple-bg);
    border-radius: 14px;
    padding: 18px 20px;
    font-size: 16px;
    font-weight: 600;
    color: var(--medium-purple);
    line-height: 1.6;
    text-align: center;
    margin-bottom: 1.25rem;
    min-height: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 6px;
    font-family: 'Nunito', sans-serif;
}

/* Blank empty state */
.fb-blank {
    display: inline-block;
    border-bottom: 2.5px solid var(--purple);
    min-width: 110px;
    height: 24px;
    margin: 0 6px;
    vertical-align: bottom;
}

/* Blank filled state */
.fb-blank-filled {
    display: inline-flex;
    align-items: center;
    background: var(--purple);
    color: #fff;
    border-radius: 8px;
    padding: 2px 12px;
    font-weight: 700;
    font-size: 14px;
    gap: 6px;
    vertical-align: bottom;
    margin: 0 6px;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
}

.fb-blank-filled .fb-blank-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    margin-left: 2px;
    cursor: pointer;
    font-size: 12px;
}

/* Word Bank */
.fb-wordbank {
    border: 1.5px dashed var(--inactive);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 1.25rem;
}

.fb-wb-label {
    font: 700 11px 'Nunito', sans-serif;
    color: var(--inactive);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin: 0 0 8px 0;
}

.fb-wb-words {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.fb-chip {
    font: 700 14px 'Nunito', sans-serif;
    padding: 7px 16px;
    border-radius: 10px;
    cursor: pointer;
    background: #fff;
    color: var(--medium-purple);
    border: 1.5px solid var(--chip-border);
    box-shadow: 0 3px 0 var(--chip-border);
    transition: transform 0.1s, box-shadow 0.1s;
}

.fb-chip:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 0 var(--chip-border);
}

.fb-chip.used {
    opacity: 0.35;
    cursor: default;
    box-shadow: none;
    text-decoration: line-through;
}

/* Buttons */
.fb-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}

.fb-btn {
    border: none;
    border-radius: 99px;
    padding: 10px 24px;
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 700;
    transition: 0.18s;
}

.fb-btn-check {
    background: var(--orange);
}

.fb-btn-check:hover {
    opacity: 0.9;
}

.fb-btn-check:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.fb-btn-show {
    background: var(--purple);
}

.fb-btn-show:hover {
    opacity: 0.9;
}

.fb-btn-show:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.fb-btn-next {
    background: var(--orange);
}

.fb-btn-next:hover:not(:disabled) {
    opacity: 0.9;
}

.fb-btn-next:disabled {
    background: var(--border);
    color: var(--inactive);
    cursor: not-allowed;
}

#fb-feedback { margin-top: 8px; }
#fb-completed { }

/* ── Word bank — Option A chip style (same as unscramble) ── */
#fb-wordbank-wrap {
    border: 1.5px dashed var(--border);
    border-radius: 16px;
    padding: 10px 14px 14px;
    margin-bottom: 16px;
    display: none; /* shown by JS when options exist */
}
.fb-wordbank-label {
    font-size: 10px;
    font-weight: 900;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 10px;
    display: block;
}
#fb-wordbank {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    min-height: 40px;
}
.fb-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 16px;
    border-radius: 10px;
    font-family: 'Nunito', sans-serif;
    font-size: clamp(14px, 1.8vw, 16px);
    font-weight: 900;
    cursor: pointer;
    user-select: none;
    background: #fff;
    border: 1.5px solid #7F77DD;
    color: #534AB7;
    box-shadow: 0 3px 0 #534AB7;
    transition: transform .12s, box-shadow .12s;
}
.fb-chip:hover  { transform: translateY(-1px); box-shadow: 0 4px 0 #534AB7; }
.fb-chip:active { transform: translateY(2px);  box-shadow: 0 1px 0 #534AB7; }

/* Inline chip shown inside the sentence when a word is selected */
.fb-blank-chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 14px;
    border-radius: 10px;
    background: #F5F3FF;
    border: 1.5px solid #7F77DD;
    color: #534AB7;
    box-shadow: 0 3px 0 #534AB7;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    font-size: clamp(15px, 2vw, 19px);
    cursor: pointer;
    vertical-align: middle;
    transition: transform .12s;
}
.fb-blank-chip:hover { transform: translateY(-1px); }

@media (max-width: 640px) {
    .fb-page { padding: 12px; }
    .fb-app { width: 100%; }
    .fb-card-shell { padding: 1rem; }
    .fb-actions { display: grid; grid-template-columns: 1fr; gap: 9px; }
    .fb-btn { width: 100%; }
    .fb-wb-words { gap: 6px; }
    .fb-chip { padding: 6px 12px; font-size: 12px; }
}
</style>

<div class="fb-page">
    <div class="fb-app">

        <div class="fb-hero">
            <div class="fb-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p><?php echo htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <div id="fb-activity">
            <div class="fb-card-shell">
                <div class="fb-progress">
                    <span class="fb-progress-label" id="fb-progress-label">1 / <?php echo count($jsQuestions); ?></span>
                    <div class="fb-track">
                        <div class="fb-fill" id="fb-progress-fill"></div>
                    </div>
                    <div class="fb-badge" id="fb-progress-badge">Q 1 of <?php echo count($jsQuestions); ?></div>
                </div>

                <div id="fb-sentence"></div>
                
                <div class="fb-wordbank">
                    <p class="fb-wb-label">Word bank</p>
                    <div class="fb-wb-words" id="fb-wb-words"></div>
                </div>

                <!-- Word bank — shown by JS when question has options -->
                <div id="fb-wordbank-wrap">
                    <span class="fb-wordbank-label">Word Bank</span>
                    <div id="fb-wordbank"></div>
                </div>

                <div class="fb-actions">
                    <button class="fb-btn fb-btn-check" id="fb-check">Check</button>
                    <button class="fb-btn fb-btn-show" id="fb-show">Show Answer</button>
                    <button class="fb-btn fb-btn-next" id="fb-next">Next</button>
                </div>
            </div>

            <div id="fb-feedback"></div>
        </div>

        <div id="fb-completed"></div>

    </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.FILLBLANK_DATA        = <?php echo json_encode($jsQuestions, JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_RETURN_TO   = <?php echo json_encode($returnTo,    JSON_UNESCAPED_UNICODE); ?>;
window.FILLBLANK_ACTIVITY_ID = <?php echo json_encode($activityId,  JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="fillblank.js?v=<?php echo filemtime(__FILE__); ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-pen-to-square', $content);

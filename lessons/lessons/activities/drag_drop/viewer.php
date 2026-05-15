<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function dd2_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function dd2_load(PDO $pdo, string $activityId, string $unit): array
{
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'drag_drop' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'drag_drop' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return ['id' => '', 'title' => 'Drag & Drop', 'blocks' => []];

    $decoded = json_decode($row['data'] ?? '', true);
    if (!is_array($decoded)) return ['id' => '', 'title' => 'Drag & Drop', 'blocks' => []];

    $title = trim((string) ($decoded['title'] ?? 'Drag & Drop')) ?: 'Drag & Drop';

    $blocksSource = isset($decoded['blocks']) && is_array($decoded['blocks'])
        ? $decoded['blocks']
        : $decoded;

    $blocks = [];
    foreach ($blocksSource as $block) {
        if (!is_array($block)) continue;
        $text = trim((string) ($block['text'] ?? $block['sentence'] ?? ''));
        if ($text === '') continue;
        $missing = [];
        if (isset($block['missing_words']) && is_array($block['missing_words'])) {
            foreach ($block['missing_words'] as $w) {
                $w = trim((string) $w);
                if ($w !== '') $missing[] = $w;
            }
        }
        $blocks[] = ['text' => $text, 'missing_words' => $missing];
    }

    return [
        'id'     => (string) ($row['id'] ?? ''),
        'title'  => $title,
        'blocks' => $blocks,
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = dd2_resolve_unit($pdo, $activityId);
}

$activity = dd2_load($pdo, $activityId, $unit);
$rawBlocks = $activity['blocks'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if (empty($rawBlocks)) {
    die('No sentences found for this unit');
}

/*
 * Map each block to the format drag_drop.js expects:
 * { instruction, slots: [{label, answer}], words: [string] }
 *
 * Strategy:
 * - instruction = the sentence text with missing words replaced by "___"
 * - slots       = one entry per missing word, with label = context snippet and answer = missing word
 * - words       = shuffled pool of the missing words (plus distractors if needed)
 *
 * If missing_words is empty we treat all words in the text as answers (whole-sentence mode).
 */
function dd2_build_js_question(array $block): ?array
{
    $text    = $block['text'];
    $missing = $block['missing_words'];

    if (empty($missing)) {
        /* Whole-sentence mode: every word is a slot */
        $words = preg_split('/\s+/', $text);
        $words = array_values(array_filter($words, 'strlen'));
        if (empty($words)) return null;

        $slots = [];
        foreach ($words as $i => $word) {
            $slots[] = ['label' => 'Word ' . ($i + 1), 'answer' => $word];
        }
        return [
            'instruction' => 'Build the sentence by placing the words in the correct order.',
            'slots'       => $slots,
            'words'       => $words,
        ];
    }

    /* Match missing words in text to build slot labels */
    $slots       = [];
    $instruction = $text;

    foreach ($missing as $word) {
        $escaped     = preg_quote($word, '/');
        $instruction = preg_replace('/\b' . $escaped . '\b/i', '___', $instruction, 1);
        $slots[]     = ['label' => $word, 'answer' => $word];
    }

    return [
        'instruction' => $instruction,
        'slots'       => $slots,
        'words'       => $missing,
    ];
}

$jsQuestions = [];
foreach ($rawBlocks as $block) {
    $q = dd2_build_js_question($block);
    if ($q !== null) $jsQuestions[] = $q;
}

if (empty($jsQuestions)) {
    die('No valid drag-drop questions found.');
}

$viewerTitle = (string) ($activity['title'] ?? 'Drag & Drop');

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --orange: #F97316;
    --purple: #7F77DD;
    --purple-dark: #534AB7;
    --muted: #9B94BE;
    --soft: #F4F2FD;
    --border: #ECE9FA;
    --green: #16a34a;
    --red: #dc2626;
}
* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }
body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
.activity-wrapper { max-width: 100% !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; background: transparent !important; }
.top-row, .activity-header { display: none !important; }
.viewer-content { flex: 1 !important; display: flex !important; flex-direction: column !important; padding: 0 !important; margin: 0 !important; background: transparent !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; }

.dd-page {
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
.dd-app {
    width: min(760px, 100%);
    margin: 0 auto;
}
.dd-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}
.dd-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.dd-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 54px);
    font-weight: 700;
    color: var(--orange);
    margin: 0;
    line-height: 1;
}
.dd-hero p {
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 700;
    color: var(--muted);
    margin: 8px 0 0;
}

/* Progress */
.dd-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.dd-progress-label {
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
    min-width: 48px;
}
.dd-track {
    flex: 1;
    height: 12px;
    background: var(--soft);
    border-radius: 999px;
    overflow: hidden;
}
.dd-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--orange), var(--purple));
    border-radius: 999px;
    transition: width .35s;
}
.dd-badge {
    min-width: 74px;
    text-align: center;
    padding: 7px 10px;
    border-radius: 999px;
    background: var(--purple);
    color: #fff;
    font-size: 12px;
    font-weight: 900;
}

/* Card */
.dd-card-shell {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 32px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.12);
    margin-bottom: 16px;
}

/* Instruction */
#dd-instruction {
    font-size: clamp(16px, 2.2vw, 20px);
    font-weight: 700;
    color: var(--purple-dark);
    text-align: center;
    margin-bottom: 16px;
    line-height: 1.6;
    background: var(--soft);
    border-radius: 16px;
    padding: 16px;
}

/* Slots area */
#dd-slots {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
}
.dd-slot {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 12px 16px;
}
.dd-slot__num {
    font-size: 13px;
    font-weight: 900;
    color: var(--muted);
    min-width: 20px;
}
.dd-slot__label {
    flex: 1;
    font-size: 15px;
    font-weight: 700;
    color: var(--purple-dark);
}
.dd-dropzone {
    min-width: 120px;
    min-height: 40px;
    border: 2px dashed var(--border);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: var(--muted);
    transition: border-color .15s, background .15s;
    padding: 6px 12px;
    cursor: default;
}
.dd-dropzone--over { border-color: var(--purple); background: var(--soft); }
.dd-dropzone--filled { border-style: solid; border-color: var(--purple); color: var(--purple-dark); background: var(--soft); }
.dd-dropzone--correct { border-color: var(--green); background: #f0fdf4; color: var(--green); }
.dd-dropzone--wrong { border-color: var(--red); background: #fef2f2; color: var(--red); }

/* Words area */
#dd-words {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    margin-bottom: 16px;
    min-height: 48px;
}
.dd-chip {
    padding: 11px 16px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid var(--border);
    color: var(--purple-dark);
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    cursor: grab;
    user-select: none;
    box-shadow: 0 6px 18px rgba(127,119,221,.12);
    transition: transform .12s, border-color .12s;
}
.dd-chip:hover { transform: translateY(-1px); border-color: var(--purple); }
.dd-chip.dd-chip--used { opacity: 0.35; cursor: default; }

/* Buttons */
.dd-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid var(--border);
    padding-top: 16px;
    margin-top: 8px;
}
.dd-btn {
    border: 0;
    border-radius: 999px;
    padding: 13px 20px;
    min-width: 120px;
    color: #fff;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    font-weight: 900;
    transition: .18s;
    box-shadow: 0 6px 18px rgba(127,119,221,.15);
}
.dd-btn:hover { transform: translateY(-1px); }
.dd-btn:disabled { opacity: .45; cursor: default; transform: none; }
.dd-btn-orange { background: var(--orange); box-shadow: 0 6px 18px rgba(249,115,22,.22); }
.dd-btn-purple { background: var(--purple); }

#dd-feedback { margin-top: 8px; }
#dd-completed { }

@media (max-width: 640px) {
    .dd-page { padding: 12px; }
    .dd-actions { display: grid; grid-template-columns: 1fr; gap: 9px; }
    .dd-btn { width: 100%; }
    .dd-slot { flex-wrap: wrap; }
    .dd-dropzone { min-width: 90px; }
}
</style>

<div class="dd-page">
    <div class="dd-app">

        <div class="dd-hero">
            <div class="dd-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Drag the words into the correct blanks.</p>
        </div>

        <div id="dd-activity">
            <div class="dd-card-shell">
                <div class="dd-progress">
                    <span class="dd-progress-label" id="dd-progress-label">1 / <?php echo count($jsQuestions); ?></span>
                    <div class="dd-track">
                        <div class="dd-fill" id="dd-progress-fill"></div>
                    </div>
                    <div class="dd-badge" id="dd-progress-badge">Q 1 of <?php echo count($jsQuestions); ?></div>
                </div>

                <div id="dd-instruction"></div>
                <div id="dd-slots"></div>
                <div id="dd-words"></div>

                <div class="dd-actions">
                    <button class="dd-btn dd-btn-orange" id="dd-check">Check</button>
                    <button class="dd-btn dd-btn-purple" id="dd-show">Show Answer</button>
                    <button class="dd-btn dd-btn-orange" id="dd-next">Next</button>
                </div>
            </div>

            <div id="dd-feedback"></div>
        </div>

        <div id="dd-completed"></div>

    </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.DRAGDROP_DATA        = <?php echo json_encode($jsQuestions,  JSON_UNESCAPED_UNICODE); ?>;
window.DRAGDROP_TITLE       = <?php echo json_encode($viewerTitle,  JSON_UNESCAPED_UNICODE); ?>;
window.DRAGDROP_RETURN_TO   = <?php echo json_encode($returnTo,     JSON_UNESCAPED_UNICODE); ?>;
window.DRAGDROP_ACTIVITY_ID = <?php echo json_encode($activityId,   JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="drag_drop.js"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-arrows-up-down-left-right', $content);

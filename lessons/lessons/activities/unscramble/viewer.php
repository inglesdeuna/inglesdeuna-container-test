<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function us2_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function us2_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['id' => '', 'title' => 'Unscramble the Sentence', 'sentences' => []];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'unscramble' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $decoded = json_decode($row['data'] ?? '', true);
    if (!is_array($decoded)) return $fallback;

    $title     = trim((string) ($decoded['title'] ?? 'Unscramble the Sentence')) ?: 'Unscramble the Sentence';
    $sentences = [];

    if (isset($decoded['sentences']) && is_array($decoded['sentences'])) {
        foreach ($decoded['sentences'] as $item) {
            if (!is_array($item)) continue;
            $sentence = trim((string) ($item['sentence'] ?? $item['text'] ?? ''));
            if ($sentence === '') continue;
            $sentences[] = $sentence;
        }
    }

    return [
        'id'        => (string) ($row['id'] ?? ''),
        'title'     => $title,
        'sentences' => $sentences,
    ];
}

if ($unit === '' && $activityId !== '') {
    $unit = us2_resolve_unit($pdo, $activityId);
}

$activity  = us2_load($pdo, $activityId, $unit);
$sentences = $activity['sentences'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if (empty($sentences)) {
    die('No sentences found for this activity');
}

/*
 * Map each sentence to {prompt, words, correct}
 * prompt   = empty string (no separate prompt text for unscramble)
 * correct  = words in correct order (split by whitespace)
 * words    = shuffled version of correct (JS will also shuffle, but we send them)
 */
function us2_shuffle(array $arr): array
{
    $a = $arr;
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j     = random_int(0, $i);
        $tmp   = $a[$i];
        $a[$i] = $a[$j];
        $a[$j] = $tmp;
    }
    return $a;
}

$jsQuestions = [];
foreach ($sentences as $sentence) {
    $correct = preg_split('/\s+/', $sentence);
    $correct = array_values(array_filter($correct, 'strlen'));
    if (empty($correct)) continue;

    $jsQuestions[] = [
        'prompt'  => '',
        'words'   => us2_shuffle($correct),
        'correct' => $correct,
    ];
}

if (empty($jsQuestions)) {
    die('No valid unscramble questions found.');
}

$viewerTitle = (string) ($activity['title'] ?? 'Unscramble the Sentence');

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
}
* { box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; background: #fff; font-family: 'Nunito', sans-serif; }
body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
.activity-wrapper { max-width: 100% !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; background: transparent !important; }
.top-row, .activity-header { display: none !important; }
.viewer-content { flex: 1 !important; display: flex !important; flex-direction: column !important; padding: 0 !important; margin: 0 !important; background: transparent !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; }

.us-page {
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
.us-app {
    width: min(760px, 100%);
    margin: 0 auto;
}
.us-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}
.us-kicker {
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
.us-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 54px);
    font-weight: 700;
    color: var(--orange);
    margin: 0;
    line-height: 1;
}
.us-hero p {
    font-size: clamp(13px, 1.8vw, 15px);
    font-weight: 700;
    color: var(--muted);
    margin: 8px 0 0;
}

/* Progress */
.us-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.us-progress-label {
    font-size: 12px;
    font-weight: 900;
    color: var(--muted);
    min-width: 48px;
}
.us-track {
    flex: 1;
    height: 12px;
    background: var(--soft);
    border-radius: 999px;
    overflow: hidden;
}
.us-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--orange), var(--purple));
    border-radius: 999px;
    transition: width .35s;
}
.us-badge {
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
.us-card-shell {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 32px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.12);
    margin-bottom: 16px;
}

/* Prompt */
#us-prompt {
    font-size: clamp(14px, 1.8vw, 16px);
    font-weight: 700;
    color: var(--muted);
    text-align: center;
    margin-bottom: 12px;
    min-height: 20px;
}

/* Answer area */
#us-answer {
    min-height: 64px;
    border: 2px dashed var(--border);
    border-radius: 20px;
    padding: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    background: var(--soft);
    transition: border-color .15s;
}
#us-answer.drag-over { border-color: var(--purple); }
#us-answer:empty::after {
    content: 'Drag words here to build the sentence…';
    color: var(--muted);
    font-size: 14px;
    font-weight: 700;
}

/* Words area */
#us-words {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    min-height: 52px;
    margin-bottom: 16px;
}

/* ── Option A chip style — depth shadow, rounded corners, purple border ── */
/* Applied to both .us-word (bank) and .us-answer-word (answer area) */
.us-word,
.us-answer-word,
.us-chip {
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
    transition: transform .12s, box-shadow .12s;
    background: #fff;
    border: 1.5px solid #7F77DD;
    color: #534AB7;
    box-shadow: 0 3px 0 #534AB7;
}
.us-word:hover,
.us-answer-word:hover,
.us-chip:hover         { transform: translateY(-1px); box-shadow: 0 4px 0 #534AB7; }
.us-word:active,
.us-answer-word:active,
.us-chip:active        { transform: translateY(2px);  box-shadow: 0 1px 0 #534AB7; }

/* Answer-area chips get a slightly different tint */
.us-answer-word        { background: #F5F3FF; }

/* Legacy modifier states */
.us-chip--bank  {}
.us-chip--built { background: #F5F3FF; }
.us-chip--correct { background: #f0fdf4 !important; border-color: #22c55e !important; color: #166534 !important; box-shadow: 0 3px 0 #16a34a !important; cursor: default !important; }
.us-chip--wrong   { background: #fef2f2 !important; border-color: #ef4444 !important; color: #991b1b !important; box-shadow: 0 3px 0 #dc2626 !important; cursor: default !important; }

/* Buttons */
.us-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid var(--border);
    padding-top: 16px;
    margin-top: 8px;
}
.us-btn {
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
.us-btn:hover { transform: translateY(-1px); }
.us-btn:disabled { opacity: .45; cursor: default; transform: none; }
.us-btn-orange { background: var(--orange); box-shadow: 0 6px 18px rgba(249,115,22,.22); }
.us-btn-purple { background: var(--purple); }

#us-feedback { margin-top: 8px; }
#us-completed { }

@media (max-width: 640px) {
    .us-page { padding: 12px; }
    .us-actions { display: grid; grid-template-columns: 1fr; gap: 9px; }
    .us-btn { width: 100%; }
    .us-chip { padding: 10px 14px; font-size: clamp(15px, 4vw, 17px); }
}
</style>

<div class="us-page">
    <div class="us-app">

        <div class="us-hero">
            <div class="us-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Unscramble the words to form the correct sentence.</p>
        </div>

        <div id="us-activity">
            <div class="us-card-shell">
                <div class="us-progress">
                    <span class="us-progress-label" id="us-progress-label">1 / <?php echo count($jsQuestions); ?></span>
                    <div class="us-track">
                        <div class="us-fill" id="us-progress-fill"></div>
                    </div>
                    <div class="us-badge" id="us-progress-badge">Q 1 of <?php echo count($jsQuestions); ?></div>
                </div>

                <div id="us-prompt"></div>
                <div id="us-answer"></div>
                <div id="us-words"></div>

                <div class="us-actions">
                    <button class="us-btn us-btn-orange" id="us-check">Check</button>
                    <button class="us-btn us-btn-purple" id="us-show">Show Answer</button>
                    <button class="us-btn us-btn-orange" id="us-next">Next</button>
                </div>
            </div>

            <div id="us-feedback"></div>
        </div>

        <div id="us-completed"></div>

    </div>
</div>

<script src="../../core/_activity_feedback.js"></script>
<script>
window.UNSCRAMBLE_DATA        = <?php echo json_encode($jsQuestions, JSON_UNESCAPED_UNICODE); ?>;
window.UNSCRAMBLE_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
window.UNSCRAMBLE_RETURN_TO   = <?php echo json_encode($returnTo,    JSON_UNESCAPED_UNICODE); ?>;
window.UNSCRAMBLE_ACTIVITY_ID = <?php echo json_encode($activityId,  JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="unscramble.js?v=<?php echo filemtime(__FILE__); ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'fa-solid fa-shuffle', $content);

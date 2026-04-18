<?php
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

/* ──────────────────────────────────────────────────────────────
   DATA LAYER
────────────────────────────────────────────────────────────── */

function wpv_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && isset($row['unit_id'])) ? (string) $row['unit_id'] : '';
}

function wpv_normalize_payload($rawData): array
{
    $default = [
        'title'       => 'Writing Practice',
        'description' => 'Read each prompt carefully and write your response.',
        'questions'   => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $allowedTypes = ['writing', 'fill_sentence', 'fill_paragraph', 'listen_write', 'video_writing'];
    $questions    = [];

    foreach ((array) ($decoded['questions'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = in_array($item['type'] ?? '', $allowedTypes, true) ? (string) $item['type'] : 'writing';

        $rawAnswers     = $item['correct_answers'] ?? [];
        $correctAnswers = [];
        if (is_array($rawAnswers)) {
            foreach ($rawAnswers as $ans) {
                $a = trim((string) $ans);
                if ($a !== '') {
                    $correctAnswers[] = $a;
                }
            }
        }

        $questions[] = [
            'id'              => trim((string) ($item['id']          ?? uniqid('wp_'))),
            'type'            => $type,
            'question'        => trim((string) ($item['question']    ?? '')),
            'instruction'     => trim((string) ($item['instruction'] ?? '')),
            'placeholder'     => trim((string) ($item['placeholder'] ?? 'Write your answer here...')),
            'media'           => trim((string) ($item['media']       ?? '')),
            'correct_answers' => $correctAnswers,
            'writing_rows'    => max(2, min(14, (int) ($item['writing_rows'] ?? 6))),
            'response_count'  => max(1, min(20, (int) ($item['response_count'] ?? 1))),
            'points'          => 1,
        ];
    }

    return [
        'title'       => trim((string) ($decoded['title']       ?? '')) ?: $default['title'],
        'description' => trim((string) ($decoded['description'] ?? '')) ?: $default['description'],
        'questions'   => $questions,
    ];
}

function wpv_load_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['id' => '', 'title' => 'Writing Practice', 'description' => '', 'questions' => []];
    $row      = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'writing_practice' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = wpv_normalize_payload($row['data'] ?? null);
    return [
        'id'          => (string) ($row['id'] ?? ''),
        'title'       => $payload['title'],
        'description' => $payload['description'],
        'questions'   => $payload['questions'],
    ];
}

/* ──────────────────────────────────────────────────────────────
   BOOTSTRAP
────────────────────────────────────────────────────────────── */

if ($unit === '' && $activityId !== '') {
    $unit = wpv_resolve_unit($pdo, $activityId);
}

if ($returnTo === '') {
    $returnTo = '../../academic/teacher_course.php?assignment='
        . urlencode((string) ($_GET['assignment'] ?? ''))
        . '&unit=' . urlencode($unit)
        . '&step=9999';
}

$activity    = wpv_load_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title']       ?? 'Writing Practice');
$description = (string) ($activity['description'] ?? '');
$questions   = (array)  ($activity['questions']   ?? []);

/* force standard card-by-card flow so Next/Show Answer/score behave like other activities */
$isVideoMode = false;

/* grab first video URL for the fixed video banner */
$videoMediaUrl = '';
foreach ($questions as $q) {
    if (!empty($q['media'])) { $videoMediaUrl = (string) $q['media']; break; }
}

ob_start();
$cssVer = file_exists(__DIR__ . '/../multiple_choice/multiple_choice.css')
    ? (string) filemtime(__DIR__ . '/../multiple_choice/multiple_choice.css')
    : (string) time();
?>
<style>
/* ── Writing Practice Viewer – card-by-card mode ─────────── */
.wp-q-sentence {
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 17px;
    margin-bottom: 12px;
    line-height: 1.7;
    color: #1e3a5f;
    font-weight: 700;
    text-align: center;
}
.wp-blank {
    display: inline-block;
    min-width: 80px;
    border-bottom: 2px solid #a855c8;
    color: #a855c8;
    font-weight: 800;
    text-align: center;
    padding: 0 4px;
}
.wp-video-wrap {
    margin-bottom: 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    width: 100%;
}
.wp-video-wrap video {
    display: block;
    width: 100%;
    max-height: 400px;
    border-radius: 12px;
}
.wp-video-wrap-iframe {
    position: relative;
    margin-bottom: 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    aspect-ratio: 16 / 9;
    max-height: 360px;
    width: 100%;
}
.wp-video-wrap-iframe iframe {
    position: absolute; top: 0; left: 0;
    width: 100%; height: 100%; border: none;
}
.wp-video-fixed-wrap {
    width: 100%;
    max-width: 920px;
    margin: 0 auto 12px;
}
.wp-video-fixed-wrap .wp-video-wrap,
.wp-video-fixed-wrap .wp-video-wrap-iframe {
    margin-bottom: 0;
}
.wp-audio-wrap { margin-bottom: 12px; text-align: center; }
.wp-audio-wrap audio { width: 100%; max-width: 500px; border-radius: 10px; outline: none; }
.wp-lw-player { display:flex; flex-direction:column; align-items:center; gap:10px; margin-bottom:14px; width:100%; }
.wp-lw-btn-row { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.wp-lw-pause-btn  { background:linear-gradient(180deg,#f59e0b,#d97706) !important; color:#fff !important; }
.wp-lw-replay-btn { background:linear-gradient(180deg,#94a3b8,#64748b) !important; color:#fff !important; }
.mc-btn-prev { background: linear-gradient(180deg, #f97316 0%, #c2410c 100%); }
.mc-btn-listen-wp { background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%); }
.wp-open-note {
    font-size: 13px; color: #7c3aed; font-weight: 700;
    background: #f5f3ff; border: 1px solid #ddd6fe;
    border-radius: 8px; padding: 8px 12px; margin-bottom: 10px;
    text-align: center;
}
.wp-writing-panel {
    width: 100%;
    max-width: 900px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid #dbeafe;
    border-radius: 18px;
    padding: 18px;
    box-sizing: border-box;
    margin-bottom: 14px;
    box-shadow: 0 14px 30px rgba(15,23,42,.10);
}
.wp-writing-count {
    font-size: 12px;
    font-weight: 800;
    color: #1d4ed8;
    margin-bottom: 8px;
    text-align: center;
}
.wp-writing-prompts {
    margin: 0;
    padding-left: 22px;
    color: #1e3a8a;
    font-weight: 700;
    line-height: 1.5;
}
.wp-writing-prompts li { margin: 0 0 4px; }
.wp-writing-list {
    width: 100%;
    max-width: 900px;
    display: grid;
    gap: 14px;
    margin-top: 10px;
}
.wp-writing-item {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #dbeafe;
    border-radius: 14px;
    padding: 14px;
    box-shadow: 0 6px 14px rgba(15,23,42,.05);
}
.wp-writing-item-label {
    display: block;
    font-size: 13px;
    font-weight: 800;
    color: #1e40af;
    margin-bottom: 8px;
}
.wp-writing-item textarea {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    font-size: 15px;
    line-height: 1.5;
}
.wp-writing-key-note {
    margin-top: 8px;
    font-size: 12px;
    color: #334155;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 8px 10px;
    text-align: center;
}
.wp-writing-coach {
    width: 100%; max-width: 760px; margin-top: 12px;
    background: #fffaf0; border: 1px solid #fde68a; border-radius: 14px;
    padding: 14px 16px; box-sizing: border-box;
    box-shadow: 0 8px 18px rgba(15,23,42,.05);
}
.wp-writing-coach h4 {
    margin: 0 0 8px; color: #92400e; font-size: 16px; font-weight: 800;
}
.wp-coach-summary {
    font-size: 13px; font-weight: 700; color: #78350f; margin-bottom: 8px;
}
.wp-coach-preview {
    background: #fff; border: 1px dashed #fbbf24; border-radius: 10px;
    padding: 10px 12px; color: #1f2937; line-height: 1.7; margin-bottom: 10px;
}
.wp-coach-preview mark {
    background: #fee2e2; color: #991b1b; padding: 1px 3px; border-radius: 4px;
}
.wp-coach-list {
    margin: 0 0 10px 18px; padding: 0; color: #374151;
}
.wp-coach-list li { margin-bottom: 6px; }
.wp-coach-rewrite label {
    display: block; margin-bottom: 6px; font-size: 13px; font-weight: 800; color: #92400e;
}
.wp-coach-rewrite small {
    display: block; margin-top: 6px; color: #78716c; font-size: 12px;
}
#wpRewrite {
    width: 100%; max-width: 100%; box-sizing: border-box; min-height: 120px;
}
#wpViewer { width: 100%; max-width: 100%; }
#wpCard { display: flex; flex-direction: column; align-items: center; justify-content: flex-start; overflow-x: hidden; }
.completed-screen { display: none; text-align: center; max-width: 600px; margin: 0 auto; padding: 40px 20px; }
.completed-screen.active { display: block; }
.completed-icon  { font-size: 80px; margin-bottom: 20px; }
.completed-title { font-family: 'Fredoka','Trebuchet MS',sans-serif; font-size: 36px; font-weight: 700; color: #a855c8; margin: 0 0 16px; line-height: 1.2; }
.completed-text  { font-size: 16px; color: #1f2937; line-height: 1.6; margin: 0 0 10px; }
.completed-button {
    display: inline-block; padding: 12px 24px; border: none; border-radius: 999px;
    background: linear-gradient(180deg, #a855f7 0%, #7c3aed 100%);
    color: #fff; font-weight: 700; font-size: 16px; cursor: pointer;
    box-shadow: 0 10px 24px rgba(0,0,0,.14); transition: transform .18s, filter .18s;
    margin-top: 14px;
}
.completed-button:hover { transform: scale(1.05); filter: brightness(1.07); }

/* answer reveal + answer box – shared across both modes */
.dict-answer-reveal { display: none; font-size: 14px; color: #7c3aed; font-weight: 700;
                      background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px;
                      padding: 8px 12px; margin-top: 6px; }
.dict-answer-reveal.show { display: block; }
.dict-answer-box { display: block; width: 100%; padding: 10px 12px;
                   border: 2px solid #cbd5e1; border-radius: 10px; font-size: 15px;
                   font-family: inherit; resize: vertical; box-sizing: border-box;
                   transition: border-color .2s, background .2s; }
.dict-answer-box:focus { outline: none; border-color: #a855f7;
                         box-shadow: 0 0 0 3px rgba(168,85,247,.15); }
.dict-answer-box.ok  { border-color: #22c55e !important; background: #f0fdf4 !important; }
.dict-answer-box.bad { border-color: #ef4444 !important; background: #fef2f2 !important; }
/* override Show Answer button to purple in card-by-card mode */
.mc-btn-show { background: linear-gradient(180deg, #a855f7 0%, #7c3aed 100%) !important; }

/* ── Inline fill inputs ─────────────────────────────── */
.wp-fill-sentence-box,
.wp-fill-paragraph-box {
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 14px 22px;
    font-size: clamp(16px, 2vw, 21px);
    line-height: 2.8;
    color: #1e3a5f;
    font-weight: 600;
    margin-bottom: 10px;
    text-align: center;
    word-break: break-word;
}
.wp-fill-paragraph-box {
    text-align: left;
    font-size: clamp(15px, 1.8vw, 18px);
    line-height: 2.6;
    white-space: pre-wrap;
}
.wp-fill-input {
    display: inline-block;
    min-width: 60px;
    border: none;
    border-bottom: 2.5px solid #a78bfa;
    background: transparent;
    color: #5b21b6;
    font-weight: 700;
    font-size: inherit;
    font-family: inherit;
    padding: 1px 8px 3px;
    text-align: center;
    outline: none;
    border-radius: 4px 4px 0 0;
    vertical-align: middle;
    transition: border-color .2s, background .2s, color .2s;
    margin: 0 4px;
}
.wp-fill-input:focus {
    border-bottom-color: #7c3aed;
    background: rgba(167,139,250,.1);
}
.wp-fill-input.ok  { border-bottom-color: #22c55e; background: rgba(34,197,94,.07);  color: #166534; }
.wp-fill-input.bad { border-bottom-color: #ef4444; background: rgba(239,68,68,.07);   color: #dc2626; }

.wp-instruction-top {
    width: 100%;
    max-width: 920px;
    margin: 0 0 10px;
    font-size: clamp(16px, 2.2vw, 20px);
    line-height: 1.45;
    color: #5b21b6;
    font-weight: 800;
    text-align: center;
}

.wp-answer-guide {
    width: 100%;
    max-width: 920px;
    margin: 6px 0 12px;
    background: linear-gradient(145deg, #ffffff 0%, #fef9ff 48%, #eef6ff 100%);
    border: 1px solid #ddd6fe;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(76, 29, 149, 0.12);
    padding: 12px 14px;
    box-sizing: border-box;
}

.wp-answer-guide-title {
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: #6d28d9;
    margin-bottom: 10px;
}

.wp-answer-guide-subtitle {
    font-size: 13px;
    color: #334155;
    margin-bottom: 10px;
    font-weight: 700;
}

.wp-answer-guide-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.wp-answer-chip {
    border: 1px solid #c4b5fd;
    background: linear-gradient(180deg, #f8f5ff 0%, #ede9fe 100%);
    color: #4c1d95;
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    line-height: 1.2;
    box-shadow: 0 4px 10px rgba(124, 58, 237, 0.15);
    transition: transform .12s ease, filter .12s ease;
    max-width: 100%;
    white-space: normal;
    word-break: break-word;
}

.wp-answer-chip:hover {
    transform: translateY(-1px);
    filter: brightness(1.03);
}

.wp-answer-chip:active {
    transform: translateY(0);
}

.wp-free-question-box {
    width: 100%;
    max-width: 920px;
    box-sizing: border-box;
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 14px 16px;
    margin: 0 0 10px;
    font-size: clamp(15px, 1.8vw, 19px);
    line-height: 1.6;
    color: #1e3a5f;
    font-weight: 700;
    text-align: left;
    white-space: pre-wrap;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.wp-free-answer-box {
    width: 100%;
    max-width: 920px;
    box-sizing: border-box;
    min-height: 140px;
    margin-top: 2px;
}

.wp-answer-guide.is-visual .wp-answer-chip {
    cursor: default;
    pointer-events: none;
    box-shadow: none;
}

.wp-answer-guide.is-visual .wp-answer-chip:hover,
.wp-answer-guide.is-visual .wp-answer-chip:active {
    transform: none;
    filter: none;
}

@media (max-width: 640px) {
    .wp-answer-guide {
        padding: 10px;
        border-radius: 14px;
    }

    .wp-answer-chip {
        font-size: 13px;
        padding: 7px 10px;
    }
}

/* ── Video Layout mode ───────────────────────────────────── */
.wpvl-wrap        { max-width: 1200px; margin: 0 auto; font-family: 'Nunito','Segoe UI',sans-serif; }
.wpvl-qs          { display: flex; flex-direction: column; gap: 14px; margin-bottom: 18px; }
.wpvl-card        { background: #fff; border: 1px solid #e9d5ff; border-radius: 14px;
                    padding: 16px 18px; box-shadow: 0 6px 16px rgba(15,23,42,.05); position: relative; overflow: hidden; }
.wpvl-card::before{ content:''; position:absolute; top:0; left:0; right:0; height:5px;
                    background: linear-gradient(90deg,#a855f7,#7c3aed); }
.wpvl-q-num       { font-size: 11px; font-weight: 800; color: #7c3aed; text-transform: uppercase;
                    letter-spacing: .06em; margin-bottom: 6px; }
.wpvl-q-text      { font-weight: 800; color: #f14902; font-size: clamp(15px,2vw,19px);
                    margin: 0 0 10px; line-height: 1.4; }
.wpvl-q-instr     { font-size: 13px; color: #7c3aed; font-weight: 700; margin: 0 0 10px; }
.wpvl-answer      { width: 100%; min-height: 80px; resize: vertical; box-sizing: border-box; }
.wpvl-reveal      { margin-top: 8px; }
.wpvl-controls    { display: flex; justify-content: center; margin-bottom: 16px; }
.wpvl-btn-submit  { background: linear-gradient(180deg,#a855f7,#7c3aed); color:#fff; border:none;
                    border-radius: 999px; padding: 12px 32px; font-size: 15px; font-weight: 800;
                    cursor: pointer; box-shadow: 0 10px 22px rgba(124,58,237,.3);
                    transition: transform .15s, filter .15s; font-family: inherit; }
.wpvl-btn-submit:hover  { filter: brightness(1.08); transform: translateY(-2px); }
.wpvl-btn-submit:disabled { opacity: .5; cursor: default; transform: none; }
.wpvl-card-footer { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.wpvl-btn-show  { background: linear-gradient(180deg, #a855f7, #7c3aed); color: #fff; border: none;
                  border-radius: 999px; padding: 6px 16px; font-size: 13px; font-weight: 800;
                  cursor: pointer; font-family: inherit; box-shadow: 0 4px 12px rgba(124,58,237,.2);
                  transition: filter .15s, transform .15s; }
.wpvl-btn-show:hover  { filter: brightness(1.08); transform: translateY(-1px); }
.wpvl-btn-show:disabled { opacity: .38; cursor: default; filter: none; transform: none; }

.wp-video-question {
    width: 100%;
    max-width: 620px;
    margin: 0 0 10px;
    font-size: clamp(16px, 2vw, 22px);
    line-height: 1.4;
    color: #f14902;
    font-weight: 800;
    text-align: center;
}

.wp-input-question {
    width: 100%;
    max-width: 620px;
    margin: 0 0 6px;
    font-size: 20px;
    line-height: 1.35;
    color: #f14902;
    font-weight: 800;
    text-align: center;
}

/* ─────────────────── PRESENTATION MODE ──────────────────── */
body.presentation-mode .wp-viewer-wrap,
body.presentation-mode .mc-viewer {
    max-width: 100% !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Video-writing two-column in presentation mode handled by video-two-col.css */
body.presentation-mode .wpvl-wrap.vtc-layout {
    max-width: 100% !important;
    margin: 0 !important;
}

body.presentation-mode .wpvl-q-text {
    font-size: clamp(22px, 2.5vw, 32px) !important;
    margin-bottom: 16px !important;
}

body.presentation-mode .wpvl-answer {
    font-size: 16px !important;
    min-height: 60px !important;
    padding: 12px !important;
}

body.presentation-mode .vtc-content-col .wpvl-controls {
    flex-shrink: 0 !important;
    padding: 12px 16px !important;
    background: #f8fbff !important;
    border-top: 1px solid #e5e7eb !important;
}

body.presentation-mode .mc-card {
    padding: 0 !important;
    flex: 1 !important;
    overflow-y: auto !important;
    background: #fff !important;
}

body.presentation-mode .mc-controls {
    flex-shrink: 0 !important;
    padding: 12px 16px !important;
    background: #f8fbff !important;
    border-top: 1px solid #e5e7eb !important;
}
</style>

<?php if (empty($questions)): ?>
    <p style="padding:20px;color:#b8551f;font-weight:700;">This activity has no questions yet. Open the editor to configure it.</p>
<?php elseif ($isVideoMode): ?>

<!-- ═══════ VIDEO LAYOUT MODE ═══════ -->
<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?= urlencode($cssVer) ?>">

<div class="wpvl-wrap vtc-layout" id="wpvlWrap">

    <!-- ── LEFT: video ── -->
    <div class="vtc-video-col">
    <?php
    $isCloudinaryOrMp4 = $videoMediaUrl !== '' && (
        preg_match('/\.(mp4|webm|ogg)(\?|$)/i', $videoMediaUrl) ||
        preg_match('/cloudinary\.com\/.+\/video\//i', $videoMediaUrl)
    );
    $ytMatch = [];
    $isYoutube = !$isCloudinaryOrMp4 && $videoMediaUrl !== '' && (
        preg_match('/youtu\.be\/([A-Za-z0-9_\-]{11})/', $videoMediaUrl, $ytMatch) ||
        preg_match('/youtube\.com\/watch\?(?:.*&)?v=([A-Za-z0-9_\-]{11})/', $videoMediaUrl, $ytMatch) ||
        preg_match('/youtube\.com\/embed\/([A-Za-z0-9_\-]+)/', $videoMediaUrl, $ytMatch)
    );
    $embedUrl = $isYoutube ? 'https://www.youtube-nocookie.com/embed/' . $ytMatch[1] : $videoMediaUrl;
    ?>

    <?php if ($isCloudinaryOrMp4 && $videoMediaUrl !== ''): ?>
        <div class="vtc-video-box">
            <video controls preload="metadata">
                <source src="<?= htmlspecialchars($videoMediaUrl, ENT_QUOTES, 'UTF-8') ?>">
            </video>
        </div>
    <?php elseif ($videoMediaUrl !== ''): ?>
        <div class="vtc-video-box is-iframe">
            <iframe src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen loading="lazy"></iframe>
        </div>
    <?php endif; ?>
    </div><!-- /.vtc-video-col -->

    <!-- ── RIGHT: questions + controls ── -->
    <div class="vtc-content-col">
    <div class="wpvl-qs" id="wpvlQs">
        <?php foreach ($questions as $i => $q): ?>
            <?php $qText = htmlspecialchars((string)($q['question'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            <div class="wpvl-card" id="wpvlCard<?= $i ?>">
                <div class="wpvl-q-num">Question <?= $i + 1 ?></div>
                <?php if ($qText !== ''): ?>
                    <p class="wpvl-q-text"><?= $qText ?></p>
                <?php endif; ?>
                <?php if (!empty($q['instruction'])): ?>
                    <p class="wpvl-q-instr"><?= htmlspecialchars((string)$q['instruction'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <textarea class="dict-answer-box wpvl-answer" id="wpvlAns<?= $i ?>"
                          placeholder="Write your answer here…"></textarea>
                <div class="wpvl-card-footer">
                    <?php if (!empty($q['correct_answers'])): ?>
                    <button type="button" class="wpvl-btn-show" id="wpvlShow<?= $i ?>"
                            onclick="wpvlShowAnswer(<?= $i ?>)" disabled>👁 Show Answer</button>
                    <?php endif; ?>
                    <div class="mc-feedback" id="wpvlFb<?= $i ?>"></div>
                </div>
                <div class="dict-answer-reveal wpvl-reveal" id="wpvlReveal<?= $i ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── submit ── -->
    <div class="wpvl-controls">
        <button type="button" class="wpvl-btn-submit" id="wpvlSubmit">✔ Check Answers</button>
    </div>

    <!-- ── completed ── -->
    <div id="wpvlCompleted" class="completed-screen">
        <div class="completed-icon">✍️</div>
        <h2 class="completed-title" id="wpvlCompTitle"></h2>
        <p class="completed-text" id="wpvlCompText"></p>
        <p class="completed-text" id="wpvlScoreText" style="font-weight:800;font-size:20px;color:#a855c8;"></p>
        <p class="completed-text" id="wpvlOpenNote" style="display:none;color:#7c3aed;font-size:14px;"></p>
        <button type="button" class="completed-button" id="wpvlRestart">Restart</button>
    </div>
    </div><!-- /.vtc-content-col -->
</div><!-- /.wpvl-wrap vtc-layout -->

<script>
window.WP_DATA        = <?= json_encode(array_values($questions), JSON_UNESCAPED_UNICODE) ?>;
window.WP_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_UNIT_ID     = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ASSIGNMENT_ID = <?= json_encode((string) ($_GET['assignment'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_TITLE       = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
window.WP_GLOBAL_VIDEO = <?= json_encode($videoMediaUrl, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var questions   = Array.isArray(window.WP_DATA) ? window.WP_DATA : [];
    var returnTo    = String(window.WP_RETURN_TO   || '');
    var activityId  = String(window.WP_ACTIVITY_ID || '');
    var unitId      = String(window.WP_UNIT_ID     || '');
    var assignId    = String(window.WP_ASSIGNMENT_ID || '');
    var actTitle    = String(window.WP_TITLE       || 'Writing Practice');
    var globalVideoUrl = String(window.WP_GLOBAL_VIDEO || '');
    if (!questions.length) { return; }

    var wrapEl      = document.getElementById('wpvlWrap');
    var qsEl        = document.getElementById('wpvlQs');
    var submitBtn   = document.getElementById('wpvlSubmit');
    var completedEl = document.getElementById('wpvlCompleted');
    var compTitleEl = document.getElementById('wpvlCompTitle');
    var compTextEl  = document.getElementById('wpvlCompText');
    var scoreTextEl = document.getElementById('wpvlScoreText');
    var openNoteEl  = document.getElementById('wpvlOpenNote');
    var restartBtn  = document.getElementById('wpvlRestart');

    var sndOk   = new Audio('../../hangman/assets/win.mp3');
    var sndBad  = new Audio('../../hangman/assets/lose.mp3');
    var sndDone = new Audio('../../hangman/assets/win (1).mp3');
    function playSound(s) { try { s.pause(); s.currentTime = 0; s.play(); } catch (e) {} }

    function normalize(s) {
        return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim().toLowerCase().replace(/[.,;:!?'"()]/g, '').replace(/\s+/g, ' ');
    }
    function checkCorrect(val, answers) {
        if (!Array.isArray(answers) || answers.length === 0) { return false; }
        var u = normalize(val);
        return answers.some(function (a) { return normalize(a) === u; });
    }
    function isAutoGraded(q) {
        return Array.isArray(q.correct_answers) && q.correct_answers.length > 0;
    }
    function persistScore(url) {
        if (!url) { return Promise.resolve(false); }
        return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return !!(r && r.ok); }).catch(function () { return false; });
    }
    function navigateTo(url) {
        if (!url) { return; }
        try { if (window.top && window.top !== window.self) { window.top.location.href = url; return; } } catch (e) {}
        window.location.href = url;
    }

    var submitted = false;
    var shownCards = {}; /* tracks which cards had Show Answer clicked */

    function wpvlShowAnswer(i) {
        var q = questions[i];
        if (!q || !Array.isArray(q.correct_answers) || q.correct_answers.length === 0) { return; }
        var shown   = q.correct_answers.slice(0, 2).join(' / ');
        var ansEl   = document.getElementById('wpvlAns'    + i);
        var fbEl    = document.getElementById('wpvlFb'     + i);
        var revealEl= document.getElementById('wpvlReveal' + i);
        var showBtn = document.getElementById('wpvlShow'   + i);

        /* lock card as wrong immediately */
        if (!shownCards[i]) {
            shownCards[i] = true;
            if (ansEl)  { ansEl.disabled = true; ansEl.className = 'dict-answer-box wpvl-answer bad'; }
            if (fbEl)   { fbEl.textContent = '\u2718 Wrong'; fbEl.className = 'mc-feedback bad'; }
            playSound(sndBad);
        }
        if (showBtn) { showBtn.disabled = true; }

        if (revealEl) {
            revealEl.textContent = 'Correct: ' + shown;
            revealEl.classList.add('show');
        }
    }

    async function handleSubmit() {
        if (submitted) { return; }
        submitted = true;
        submitBtn.disabled = true;

        var correctCount  = 0;
        var openResponses = [];

        questions.forEach(function (q, i) {
            var ansEl    = document.getElementById('wpvlAns'    + i);
            var fbEl     = document.getElementById('wpvlFb'     + i);
            var revealEl = document.getElementById('wpvlReveal' + i);
            var showBtn  = document.getElementById('wpvlShow'   + i);
            var val      = ansEl ? ansEl.value.trim() : '';

            if (ansEl)    { ansEl.disabled = true; }
            if (showBtn)  { showBtn.disabled = true; }

            /* if Show Answer was already clicked, card is already locked as wrong */
            if (shownCards[i]) {
                /* already counted as wrong — do nothing extra */
                if (fbEl && !fbEl.textContent) { fbEl.textContent = '\u2718 Wrong'; fbEl.className = 'mc-feedback bad'; }
                return;
            }

            if (isAutoGraded(q)) {
                var correct = checkCorrect(val, q.correct_answers);
                if (correct) {
                    correctCount++;
                    if (ansEl)  { ansEl.className  = 'dict-answer-box wpvl-answer ok'; }
                    if (fbEl)   { fbEl.textContent = '\u2714 Right'; fbEl.className = 'mc-feedback good'; }
                    playSound(sndOk);
                } else {
                    if (ansEl)  { ansEl.className  = 'dict-answer-box wpvl-answer bad'; }
                    if (fbEl)   { fbEl.textContent = '\u2718 Wrong'; fbEl.className = 'mc-feedback bad'; }
                    var shown = (q.correct_answers || []).slice(0, 2).join(' / ');
                    if (revealEl) { revealEl.textContent = 'Correct: ' + shown; revealEl.classList.add('show'); }
                    playSound(sndBad);
                }
            } else {
                if (ansEl) { ansEl.className = 'dict-answer-box wpvl-answer bad'; }
                if (fbEl)  { fbEl.textContent = 'No correct answers configured for this prompt.'; fbEl.className = 'mc-feedback bad'; }
            }
        });

        var totalCount = questions.length;
        var pct    = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
        var errors = Math.max(0, totalCount - correctCount);

        /* count words from open responses */
        var totalWords = 0;
        openResponses.forEach(function (r) {
            var t = String(r.response_text || '').replace(/\s+/g, ' ').trim();
            if (t) { totalWords += t.split(' ').length; }
        });

        /* save open-writing responses */
        if (openResponses.length > 0) {
            try {
                var fd = new FormData();
                fd.append('activity_id', activityId); fd.append('unit_id', unitId);
                fd.append('assignment_id', assignId); fd.append('responses', JSON.stringify(openResponses));
                await fetch('/lessons/lessons/activities/writing_practice/wp_save_response.php',
                            { method: 'POST', body: fd });
            } catch (e) {}
        }

        /* show completed */
        playSound(sndDone);
        if (qsEl)        { qsEl.style.marginBottom = '0'; }
        submitBtn.style.display = 'none';
        completedEl.classList.add('active');
        if (compTitleEl) { compTitleEl.textContent = actTitle; }
        if (compTextEl)  { compTextEl.textContent  = "You've completed " + actTitle + ". Great job!"; }
        if (scoreTextEl) { scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)'; }
        if (openNoteEl) {
            openNoteEl.style.display = '';
            var noteHtml = '';
            if (totalWords > 0) { noteHtml += '\uD83D\uDCCA ' + totalWords + ' palabras escritas'; }
            if (openResponses.length > 0) {
                if (noteHtml) { noteHtml += ' &nbsp;&middot;&nbsp; '; }
                noteHtml += '\u270D\uFE0F ' + openResponses.length + ' respuesta(s) enviadas para calificaci\u00F3n.';
            }
            if (noteHtml) { openNoteEl.innerHTML = noteHtml; } else { openNoteEl.style.display = 'none'; }
        }

        /* persist to return URL */
        if (returnTo) {
            var joiner  = returnTo.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = returnTo + joiner
                + 'activity_percent=' + encodeURIComponent(String(pct))
                + '&activity_errors='  + encodeURIComponent(String(errors))
                + '&activity_total='   + encodeURIComponent(String(totalCount))
                + '&activity_id='      + encodeURIComponent(activityId)
                + '&activity_type=writing_practice';
            var ok = await persistScore(saveUrl);
            if (!ok) { navigateTo(saveUrl); }
        }
    }

    submitBtn.addEventListener('click', handleSubmit);

    /* enable Show Answer per-card only after user has typed something */
    questions.forEach(function (q, i) {
        if (!Array.isArray(q.correct_answers) || q.correct_answers.length === 0) { return; }
        var ansEl   = document.getElementById('wpvlAns'  + i);
        var showBtn = document.getElementById('wpvlShow' + i);
        if (!ansEl || !showBtn) { return; }
        ansEl.addEventListener('input', function () {
            if (!shownCards[i] && !submitted) {
                showBtn.disabled = ansEl.value.trim() === '';
            }
        });
    });

    restartBtn.addEventListener('click', function () {
        submitted = false;
        submitBtn.disabled = false;
        submitBtn.style.display = '';
        completedEl.classList.remove('active');
        shownCards = {};
        questions.forEach(function (q, i) {
            var ansEl    = document.getElementById('wpvlAns'    + i);
            var fbEl     = document.getElementById('wpvlFb'     + i);
            var revealEl = document.getElementById('wpvlReveal' + i);
            var showBtn  = document.getElementById('wpvlShow'   + i);
            if (ansEl)    { ansEl.value = ''; ansEl.disabled = false; ansEl.className = 'dict-answer-box wpvl-answer'; }
            if (fbEl)     { fbEl.textContent = ''; fbEl.className = 'mc-feedback'; }
            if (revealEl) { revealEl.textContent = ''; revealEl.classList.remove('show'); }
            if (showBtn)  { showBtn.disabled = true; } /* re-disable: needs typing again to enable */
        });
        // Don't scroll in presentation mode
        if (!window.PRESENTATION_MODE) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
});
</script>

<?php else: ?>

<!-- ═══════ CARD-BY-CARD MODE (existing) ═══════ -->

<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?= urlencode($cssVer) ?>">

<div class="mc-viewer" id="wpViewer">
    <div class="mc-status" id="wpStatus"></div>
    <div id="wpVideoInstruction" class="wp-instruction-top" style="display:none;"></div>
    <div id="wpVideoFixed" class="wp-video-fixed-wrap" style="display:none;"></div>

    <div class="mc-card" id="wpCard">
        <!-- media injected by JS -->
        <div id="wpMediaArea"></div>
        <!-- dedicated per-card prompt for video + writing -->
        <div id="wpVideoQuestion" class="wp-video-question" style="display:none;"></div>
        <!-- instruction injected by JS -->
        <div id="wpInstruction" class="wp-instruction-top"></div>
        <!-- question text injected by JS -->
        <div id="wpQtext"></div>
        <div id="wpInputQuestion" class="wp-input-question" style="display:none;"></div>
        <!-- fill guide answers injected by JS -->
        <div id="wpAnswerGuide" class="wp-answer-guide" style="display:none;"></div>
        <!-- answer input -->
        <textarea id="wpAnswer" class="dict-answer-box" style="width:100%;max-width:620px;" placeholder="Write your answer here..."></textarea>
        <div id="wpWritingList" class="wp-writing-list" style="display:none;"></div>
        <div id="wpCoach" class="wp-writing-coach" style="display:none;">
            <h4>📝 Writing Helper</h4>
            <div id="wpCoachSummary" class="wp-coach-summary"></div>
            <div id="wpCoachPreview" class="wp-coach-preview"></div>
            <ul id="wpCoachList" class="wp-coach-list"></ul>
            <div id="wpCoachRewriteWrap" class="wp-coach-rewrite">
                <label for="wpRewrite">Rewrite your improved version here</label>
                <textarea id="wpRewrite" class="dict-answer-box" spellcheck="true" lang="en" placeholder="Rewrite your corrected paragraph here..."></textarea>
                <small>Use Review Text again to check the new version.</small>
            </div>
        </div>
        <!-- answer reveal -->
        <div id="wpReveal" class="dict-answer-reveal"></div>
    </div>

    <div class="mc-controls" id="wpControls">
        <button type="button" class="mc-btn mc-btn-prev" id="btnPrev">Prev</button>
        <button type="button" class="mc-btn mc-btn-show" id="btnShow">Show Answer</button>
        <button type="button" class="mc-btn mc-btn-next" id="btnNext">Next</button>
    </div>

    <div class="mc-feedback" id="wpFeedback"></div>

    <div id="wpCompleted" class="completed-screen">
        <div class="completed-icon">✍️</div>
        <h2 class="completed-title" id="wpCompTitle"></h2>
        <p class="completed-text" id="wpCompText"></p>
        <p class="completed-text" id="wpScoreText" style="font-weight:800;font-size:20px;color:#a855c8;"></p>
        <p class="completed-text" id="wpOpenNote" style="display:none;color:#7c3aed;font-size:14px;"></p>
        <button type="button" class="completed-button" id="btnRestart">Restart</button>
    </div>
</div>

<!-- PHP → JS data bridge -->
<script>
window.WP_DATA        = <?= json_encode(array_values($questions), JSON_UNESCAPED_UNICODE) ?>;
window.WP_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_UNIT_ID     = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ASSIGNMENT_ID = <?= json_encode((string) ($_GET['assignment'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_TITLE       = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /* ── data ─────────────────────────────────────────── */
    var questions   = Array.isArray(window.WP_DATA) ? window.WP_DATA : [];
    var returnTo    = String(window.WP_RETURN_TO   || '');
    var activityId  = String(window.WP_ACTIVITY_ID || '');
    var unitId      = String(window.WP_UNIT_ID     || '');
    var assignId    = String(window.WP_ASSIGNMENT_ID || '');
    var actTitle    = String(window.WP_TITLE       || 'Writing Practice');

    if (!questions.length) { return; }

    /* ── elements ─────────────────────────────────────── */
    var statusEl    = document.getElementById('wpStatus');
    var mediaArea   = document.getElementById('wpMediaArea');
    var qtextEl     = document.getElementById('wpQtext');
    var inputQuestionEl = document.getElementById('wpInputQuestion');
    var instrEl     = document.getElementById('wpInstruction');
    var videoQuestionEl = document.getElementById('wpVideoQuestion');
    var videoInstrEl = document.getElementById('wpVideoInstruction');
    var videoFixedEl = document.getElementById('wpVideoFixed');
    var answerGuideEl = document.getElementById('wpAnswerGuide');
    var answerEl    = document.getElementById('wpAnswer');
    var writingListEl = document.getElementById('wpWritingList');
    var revealEl    = document.getElementById('wpReveal');
    var feedbackEl  = document.getElementById('wpFeedback');
    var cardEl      = document.getElementById('wpCard');
    var controlsEl  = document.getElementById('wpControls');
    var completedEl = document.getElementById('wpCompleted');
    var compTitleEl = document.getElementById('wpCompTitle');
    var compTextEl  = document.getElementById('wpCompText');
    var scoreTextEl = document.getElementById('wpScoreText');
    var openNoteEl  = document.getElementById('wpOpenNote');
    var btnPrev     = document.getElementById('btnPrev');
    var btnShow     = document.getElementById('btnShow');
    var btnNext     = document.getElementById('btnNext');
    var btnRestart  = document.getElementById('btnRestart');
    var coachEl     = document.getElementById('wpCoach');
    var coachSummaryEl = document.getElementById('wpCoachSummary');
    var coachPreviewEl = document.getElementById('wpCoachPreview');
    var coachListEl    = document.getElementById('wpCoachList');
    var rewriteWrapEl  = document.getElementById('wpCoachRewriteWrap');
    var rewriteEl      = document.getElementById('wpRewrite');

    /* ── sounds ───────────────────────────────────────── */
    var sndOk   = new Audio('../../hangman/assets/win.mp3');
    var sndBad  = new Audio('../../hangman/assets/lose.mp3');
    var sndDone = new Audio('../../hangman/assets/win (1).mp3');

    function playSound(s) { try { s.pause(); s.currentTime = 0; s.play(); } catch (e) {} }

    /* ── state ────────────────────────────────────────── */
    var index             = 0;
    var finished          = false;
    var checkedCards      = {};   // index → true when locked
    var attemptsMap       = {};   // index → attempt count
    var correctCount      = 0;    // correct answers from scoreable items only
    var openResponses     = [];   // collected writing responses
    var currentFillInputs = [];   // inline <input> elements for fill_sentence / fill_paragraph
    var activeFillInput   = null; // focused fill input for guide chip insertion
    var currentWritingInputs = []; // textarea elements for free writing enumerated responses
    var writingReviewed   = {};   // index -> true after using the writing helper

    /* ── helpers ──────────────────────────────────────── */
    function getExpectedAnswerList(q) {
        return Array.isArray(q && q.correct_answers) ? q.correct_answers.map(function (a) {
            return String(a || '').trim();
        }).filter(function (a) {
            return a !== '';
        }) : [];
    }

    function isAutoGraded(q) {
        return String((q && q.type) || 'writing') !== 'writing';
    }

    function getQuestionInputTotal(q) {
        var qt = String((q && q.type) || 'writing');
        var answers = getExpectedAnswerList(q);
        if (qt === 'writing') {
            return getWritingExpectedList(q).length;
        }
        if ((qt === 'fill_paragraph' || qt === 'fill_sentence' || qt === 'listen_write') && answers.length > 0) {
            return answers.length;
        }
        return 1;
    }

    function getQuestionCorrectUnits(q, qi) {
        var qt = String((q && q.type) || 'writing');
        if (qt === 'writing') {
            var totalW = getWritingExpectedList(q).length;
            var savedCorrect = parseInt(checkedCards[qi + '_writing_correct'] || 0, 10);
            if (!isFinite(savedCorrect)) { savedCorrect = 0; }
            return Math.max(0, Math.min(totalW, savedCorrect));
        }
        if (qt === 'fill_paragraph' || qt === 'fill_sentence' || qt === 'listen_write') {
            var perInput = checkedCards[qi + '_perInput'] || [];
            return perInput.filter(Boolean).length;
        }
        return checkedCards[qi] === 'correct' ? 1 : 0;
    }

    function normalize(s) {
        return String(s || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim().toLowerCase()
            .replace(/[_–—-]+/g, ' ')
            .replace(/[.,;:!?'"()]/g, '')
            .replace(/\s+/g, ' ');
    }

    function checkCorrect(userVal, answers) {
        if (!Array.isArray(answers) || answers.length === 0) { return false; }
        var u = normalize(userVal);
        return answers.some(function (a) { return normalize(a) === u; });
    }

    function evaluateFillAnswers(values, answers) {
        var typed = Array.isArray(values) ? values.map(function (v) { return normalize(v); }) : [];
        var expected = Array.isArray(answers) ? answers.map(function (a) { return normalize(a); }).filter(function (a) {
            return a !== '';
        }) : [];
        var perInput = typed.map(function () { return false; });

        if (!typed.length || !expected.length) {
            return { allCorrect: false, perInput: perInput };
        }

        if (typed.length === expected.length) {
            perInput = typed.map(function (val, i) {
                return val !== '' && expected[i] !== '' && val === expected[i];
            });
            return {
                allCorrect: perInput.every(Boolean),
                perInput: perInput,
            };
        }

        var joined = normalize(typed.join(' '));
        var wholeCorrect = expected.some(function (ans) { return ans === joined; });
        return {
            allCorrect: wholeCorrect,
            perInput: typed.map(function (val) { return wholeCorrect && val !== ''; }),
        };
    }

    function revealFillParagraphAnswers(inputs, answers, perInput) {
        if (!Array.isArray(inputs) || inputs.length === 0) { return; }
        var safeAnswers = Array.isArray(answers) ? answers : [];
        var safePerInput = Array.isArray(perInput) ? perInput : [];
        inputs.forEach(function (inp, ii) {
            var ans = String(safeAnswers[ii] || '').trim();
            var wasCorrect = !!safePerInput[ii];
            if (ans !== '') {
                inp.value = ans;
            }
            inp.className = 'wp-fill-input ' + (wasCorrect ? 'ok' : 'bad');
            inp.disabled = true;
        });
    }

    function toEmbedUrl(url) {
        if (!url) { return ''; }
        if (/youtube\.com\/embed\/|player\.vimeo\.com\/video\//.test(url)) { return url; }
        var m = url.match(/youtu\.be\/([A-Za-z0-9_-]{11})/);
        if (m) { return 'https://www.youtube-nocookie.com/embed/' + m[1]; }
        var m2 = url.match(/youtube\.com\/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})/);
        if (m2) { return 'https://www.youtube-nocookie.com/embed/' + m2[1]; }
        return url;
    }

    function renderFixedVideo(mediaUrl) {
        var rawUrl = String(mediaUrl || '').trim();
        if (!videoFixedEl) { return; }
        if (rawUrl === '') {
            videoFixedEl.style.display = 'none';
            videoFixedEl.innerHTML = '';
            videoFixedEl.removeAttribute('data-src');
            return;
        }

        if (videoFixedEl.getAttribute('data-src') === rawUrl && videoFixedEl.innerHTML.trim() !== '') {
            videoFixedEl.style.display = '';
            return;
        }

        videoFixedEl.innerHTML = '';
        var embedUrl = toEmbedUrl(rawUrl);
        var isDirectVideo = /\.(mp4|webm|ogg)(\?|$)/i.test(rawUrl)
            || /cloudinary\.com\/.+\/video\//i.test(rawUrl);

        if (isDirectVideo) {
            var videoWrap = document.createElement('div');
            videoWrap.className = 'wp-video-wrap';
            var vid = document.createElement('video');
            vid.controls = true;
            vid.preload = 'metadata';
            var vs = document.createElement('source');
            vs.src = rawUrl;
            vid.appendChild(vs);
            videoWrap.appendChild(vid);
            videoFixedEl.appendChild(videoWrap);
        } else {
            var iframeWrap = document.createElement('div');
            iframeWrap.className = 'wp-video-wrap-iframe';
            var fr = document.createElement('iframe');
            fr.src = embedUrl;
            fr.loading = 'lazy';
            fr.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
            fr.allowFullscreen = true;
            iframeWrap.appendChild(fr);
            videoFixedEl.appendChild(iframeWrap);
        }

        videoFixedEl.setAttribute('data-src', rawUrl);
        videoFixedEl.style.display = '';
    }

    function esc(s) {
        return String(s || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function shuffleAnswers(list) {
        var out = Array.isArray(list) ? list.slice() : [];
        for (var i = out.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = out[i];
            out[i] = out[j];
            out[j] = t;
        }
        return out;
    }

    function getGuideTargetInput() {
        if (activeFillInput && currentFillInputs.indexOf(activeFillInput) !== -1 && !activeFillInput.disabled) {
            return activeFillInput;
        }
        for (var i = 0; i < currentFillInputs.length; i++) {
            if (!currentFillInputs[i].disabled && currentFillInputs[i].value.trim() === '') {
                return currentFillInputs[i];
            }
        }
        for (var j = 0; j < currentFillInputs.length; j++) {
            if (!currentFillInputs[j].disabled) {
                return currentFillInputs[j];
            }
        }
        return null;
    }

    function isFreeParagraphMode(q) {
        if (!q) { return false; }
        var t = String(q.type || '');
        if (t !== 'fill_paragraph') { return false; }
        var questionText = String(q.question || '');
        return !/_{2,}/.test(questionText);
    }

    function isInlineFillMode(q) {
        if (!q) { return false; }
        var t = String(q.type || 'writing');
        if (t === 'fill_sentence' || t === 'listen_write') { return true; }
        if (t === 'fill_paragraph') { return !isFreeParagraphMode(q); }
        return false;
    }

    function renderAnswerGuide(type, q, interactive) {
        if (!answerGuideEl) { return; }
        if (!(type === 'fill_sentence' || type === 'fill_paragraph')) {
            answerGuideEl.style.display = 'none';
            answerGuideEl.innerHTML = '';
            answerGuideEl.classList.remove('is-visual');
            return;
        }

        var answers = getExpectedAnswerList(q);
        if (answers.length === 0) {
            answerGuideEl.style.display = 'none';
            answerGuideEl.innerHTML = '';
            answerGuideEl.classList.remove('is-visual');
            return;
        }

        var allowInsert = interactive !== false;
        var shuffled = shuffleAnswers(answers);
        var title = type === 'fill_paragraph' ? 'Answer Guide - Paragraph' : 'Answer Guide - Sentence';
        var chipsHtml = shuffled.map(function (ans) {
            if (allowInsert) {
                return '<button type="button" class="wp-answer-chip" data-answer="' + esc(ans) + '">' + esc(ans) + '</button>';
            }
            return '<span class="wp-answer-chip">' + esc(ans) + '</span>';
        }).join('');

        answerGuideEl.innerHTML = ''
            + '<div class="wp-answer-guide-title">' + title + '</div>'
            + '<div class="wp-answer-guide-subtitle">' + (allowInsert
                ? 'Click a word to place it in a blank. Answers are shuffled as a hint.'
                : 'Reference guide only. Use it to compare your answer.') + '</div>'
            + '<div class="wp-answer-guide-chips">' + chipsHtml + '</div>';
        answerGuideEl.style.display = '';
        answerGuideEl.classList.toggle('is-visual', !allowInsert);

        if (!allowInsert) {
            return;
        }

        Array.prototype.forEach.call(answerGuideEl.querySelectorAll('.wp-answer-chip'), function (chip) {
            chip.addEventListener('click', function () {
                var target = getGuideTargetInput();
                if (!target) { return; }
                target.value = String(chip.getAttribute('data-answer') || '');
                target.dispatchEvent(new Event('input', { bubbles: true }));
                target.focus();
            });
        });
    }

    var PLACEHOLDERS = {
        writing:        'Write your response here\u2026',
        listen_write:   'Write what you hear\u2026',
        fill_sentence:  'Complete the sentence\u2026',
        fill_paragraph: 'Complete the paragraph\u2026',
        video_writing:  'Write about what you saw\u2026',
    };

    function getWritingRows(q) {
        var rows = parseInt((q && q.writing_rows), 10);
        if (!isFinite(rows)) { rows = 6; }
        return Math.max(2, Math.min(14, rows));
    }

    function stripLeadingNumbering(text) {
        return String(text || '').replace(/^\s*\d+[\.)-]?\s*/, '').trim();
    }

    function getWritingExpectedList(q) {
        return getExpectedAnswerList(q).map(stripLeadingNumbering).filter(function (v) { return v !== ''; });
    }

    function getWritingCount(q) {
        var c = parseInt((q && q.response_count), 10);
        if (!isFinite(c)) { c = 1; }
        var expectedLen = getWritingExpectedList(q).length;
        if (expectedLen > c) { c = expectedLen; }
        return Math.max(1, Math.min(20, c));
    }

    function parseEnumeratedPrompt(questionText) {
        var text = String(questionText || '').replace(/\r\n?/g, '\n').trim();
        if (text === '') { return []; }
        var inlineMatches = [];
        var inlineRe = /(\d+)\s*[\.)-]\s*([^\n]+?)(?=(?:\s+\d+\s*[\.)-]\s*)|$)/g;
        var m;
        while ((m = inlineRe.exec(text)) !== null) {
            var chunk = String(m[2] || '').trim();
            if (chunk !== '') { inlineMatches.push(chunk); }
        }
        if (inlineMatches.length > 1) { return inlineMatches; }

        var lines = text.split('\n').map(function (line) { return line.trim(); }).filter(Boolean);
        var parsed = [];
        lines.forEach(function (line) {
            var m = line.match(/^\d+[\.)-]?\s*(.+)$/);
            if (m && m[1]) {
                parsed.push(m[1].trim());
            } else {
                parsed.push(line.replace(/^[-*]\s+/, '').trim());
            }
        });
        return parsed.filter(Boolean);
    }

    function getPrimaryPrompt(questionText) {
        var prompts = parseEnumeratedPrompt(questionText);
        if (prompts.length > 0) { return prompts[0]; }
        return String(questionText || '').trim();
    }

    function getWritingValues() {
        return currentWritingInputs.map(function (inp) { return String(inp.value || '').trim(); });
    }

    function hasWritingValue() {
        return getWritingValues().some(function (t) { return t !== ''; });
    }

    function similarity(a, b) {
        var s1 = normalize(a);
        var s2 = normalize(b);
        if (s1 === '' && s2 === '') { return 1; }
        if (s1 === '' || s2 === '') { return 0; }
        var m = s1.length;
        var n = s2.length;
        var dp = new Array(m + 1);
        for (var i = 0; i <= m; i++) {
            dp[i] = new Array(n + 1);
            dp[i][0] = i;
        }
        for (var j = 0; j <= n; j++) { dp[0][j] = j; }
        for (var ii = 1; ii <= m; ii++) {
            for (var jj = 1; jj <= n; jj++) {
                var cost = s1.charAt(ii - 1) === s2.charAt(jj - 1) ? 0 : 1;
                dp[ii][jj] = Math.min(
                    dp[ii - 1][jj] + 1,
                    dp[ii][jj - 1] + 1,
                    dp[ii - 1][jj - 1] + cost
                );
            }
        }
        var dist = dp[m][n];
        return 1 - (dist / Math.max(m, n));
    }

    function resetWritingCoach() {
        if (coachEl) { coachEl.style.display = 'none'; }
        if (coachSummaryEl) { coachSummaryEl.textContent = ''; }
        if (coachPreviewEl) { coachPreviewEl.innerHTML = ''; }
        if (coachListEl) { coachListEl.innerHTML = ''; }
        if (rewriteWrapEl) { rewriteWrapEl.style.display = 'block'; }
        if (rewriteEl) { rewriteEl.value = ''; rewriteEl.disabled = false; }
    }

    function escapeRegExp(s) {
        return String(s || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function analyzeWritingText(text) {
        var source = String(text || '').replace(/\r\n?/g, '\n');
        var trimmed = source.trim();
        var issues = [];
        var marks = [];

        function addIssue(type, message, start, end) {
            issues.push({ type: type, message: message });
            if (typeof start === 'number' && typeof end === 'number' && end > start) {
                marks.push({ start: start, end: end, type: type });
            }
        }

        if (trimmed === '') {
            issues.push({ type: 'warning', message: 'Write a short paragraph first so the checker can review it.' });
            return { ok: false, issues: issues, marks: marks, wordCount: 0 };
        }

        var match;
        var doubleSpaceRe = / {2,}/g;
        while ((match = doubleSpaceRe.exec(source)) !== null) {
            addIssue('spacing', 'Remove extra spaces and leave only one space between words.', match.index, match.index + match[0].length);
        }

        var pronounRe = /(^|[\s"(\[])(i)(?=[\s,.!?;:]|$)/g;
        while ((match = pronounRe.exec(source)) !== null) {
            var pronounStart = match.index + match[1].length;
            addIssue('capitalization', 'Use uppercase "I" when referring to yourself.', pronounStart, pronounStart + 1);
        }

        var repeatedRe = /\b([A-Za-z]+)\s+\1\b/gi;
        while ((match = repeatedRe.exec(source)) !== null) {
            addIssue('repetition', 'Avoid repeating the same word twice in a row.', match.index, match.index + match[0].length);
        }

        var sentenceRe = /(^|[.!?]\s+)([a-z])/g;
        while ((match = sentenceRe.exec(source)) !== null) {
            var sentStart = match.index + match[1].length;
            addIssue('capitalization', 'Start each sentence with a capital letter.', sentStart, sentStart + 1);
        }

        var commonFixes = {
            dont: "Use don't.", doesnt: "Use doesn't.", didnt: "Use didn't.", cant: "Use can't.",
            wont: "Use won't.", im: "Write I'm.", ive: "Write I've.", ill: "Write I'll.",
            youre: "Write you're.", theyre: "Write they're.", weve: "Write we've.",
            alot: "Write a lot.", becouse: "Use because.", becasue: "Use because.",
            recieve: "Use receive.", seperate: "Use separate.", definately: "Use definitely."
        };
        Object.keys(commonFixes).forEach(function (word) {
            var re = new RegExp('(^|[^A-Za-z])(' + escapeRegExp(word) + ')(?=[^A-Za-z]|$)', 'gi');
            while ((match = re.exec(source)) !== null) {
                var start = match.index + match[1].length;
                addIssue('spelling', commonFixes[word], start, start + match[2].length);
            }
        });

        if (/[A-Za-z0-9]$/.test(trimmed)) {
            issues.push({ type: 'punctuation', message: 'Add a period, question mark, or exclamation mark at the end.' });
        }

        var longSentenceRe = /[^.!?\n]{120,}/g;
        while ((match = longSentenceRe.exec(source)) !== null) {
            issues.push({ type: 'grammar', message: 'This sentence is very long. Consider splitting it into two shorter sentences.' });
        }

        return {
            ok: issues.length === 0,
            issues: issues,
            marks: marks,
            wordCount: trimmed ? trimmed.split(/\s+/).length : 0,
        };
    }

    function renderHighlightedText(text, marks) {
        var source = String(text || '');
        if (!source) { return ''; }
        if (!Array.isArray(marks) || marks.length === 0) { return esc(source).replace(/\n/g, '<br>'); }
        marks.sort(function (a, b) { return a.start - b.start || b.end - a.end; });
        var html = '';
        var cursor = 0;
        marks.forEach(function (mark) {
            if (mark.start < cursor) { return; }
            html += esc(source.slice(cursor, mark.start));
            html += '<mark>' + esc(source.slice(mark.start, mark.end)) + '</mark>';
            cursor = mark.end;
        });
        html += esc(source.slice(cursor));
        return html.replace(/\n/g, '<br>');
    }

    function getWritingLatestText() {
        var revised = rewriteEl ? String(rewriteEl.value || '').trim() : '';
        if (revised !== '' && rewriteEl && !rewriteEl.disabled) { return revised; }
        if (currentWritingInputs.length > 0) {
            return getWritingValues().filter(Boolean).join('\n');
        }
        return String(answerEl.value || '').trim();
    }

    function reviewWriting(forceFocus) {
        var q = questions[index] || {};
        var expectedList = getWritingExpectedList(q);
        var blocks = currentWritingInputs.length > 0 ? getWritingValues() : [getWritingLatestText()];

        if (expectedList.length === 0) {
            feedbackEl.textContent = 'This free-writing review needs answer keys from the editor (one answer per line).';
            feedbackEl.className = 'mc-feedback bad';
            if (coachEl) { coachEl.style.display = 'none'; }
            return false;
        }

        if (!blocks.some(function (t) { return String(t || '').trim() !== ''; })) {
            feedbackEl.textContent = 'Write your paragraph first.';
            feedbackEl.className   = 'mc-feedback bad';
            if (forceFocus && currentWritingInputs.length > 0) { currentWritingInputs[0].focus(); }
            else if (forceFocus && answerEl) { answerEl.focus(); }
            return false;
        }

        var resultRows = [];
        var correctCount = 0;
        var total = Math.max(expectedList.length, blocks.length);
        for (var i = 0; i < total; i++) {
            var userText = String(blocks[i] || '').trim();
            var expectedText = String(expectedList[i] || '').trim();
            if (expectedText === '' && userText === '') {
                continue;
            }
            var ok = expectedText !== '' && checkCorrect(userText, [expectedText]);
            var sim = expectedText !== '' ? similarity(userText, expectedText) : 0;
            if (ok) { correctCount++; }
            resultRows.push({
                idx: i + 1,
                ok: ok,
                similarity: sim,
                userText: userText,
                expectedText: expectedText
            });
        }

        var pct = resultRows.length > 0 ? Math.round((correctCount / resultRows.length) * 100) : 0;
        writingReviewed[index] = true;
        checkedCards[index + '_writing_correct'] = correctCount;
        checkedCards[index + '_writing_total'] = resultRows.length;
        checkedCards[index + '_writing_pct'] = pct;

        if (coachEl) { coachEl.style.display = ''; }
        if (coachSummaryEl) {
            coachSummaryEl.textContent = 'Review completed.';
        }
        if (coachPreviewEl) {
            coachPreviewEl.innerHTML = resultRows.map(function (row) {
                var status = row.ok ? '<span style="color:#166534;font-weight:800;">Match</span>' : '<span style="color:#b91c1c;font-weight:800;">No match</span>';
                var expected = row.expectedText !== '' ? esc(row.expectedText) : 'No key configured';
                var user = row.userText !== '' ? esc(row.userText) : '(empty)';
                var near = (!row.ok && row.expectedText !== '' && row.similarity >= 0.6)
                    ? '<div style="margin-top:4px;color:#92400e;font-size:12px;">Close answer detected. Check spelling or missing words.</div>'
                    : '';
                return '<div style="margin-bottom:10px;padding:8px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">'
                    + '<div><strong>' + row.idx + '.</strong> ' + status + '</div>'
                    + '<div style="margin-top:4px;"><strong>Your answer:</strong> ' + user + '</div>'
                    + '<div style="margin-top:2px;"><strong>Expected:</strong> ' + expected + '</div>'
                    + near
                    + '</div>';
            }).join('') || 'No text to review yet.';
        }
        if (coachListEl) {
            coachListEl.innerHTML = '';
        }
        if (rewriteEl) {
            rewriteEl.value = getWritingLatestText();
            rewriteEl.disabled = currentWritingInputs.length > 0;
        }
        if (rewriteWrapEl) { rewriteWrapEl.style.display = currentWritingInputs.length > 0 ? 'none' : 'block'; }

        feedbackEl.textContent = pct === 100
            ? 'All responses match the keys from the editor.'
            : 'Some responses do not match the keys. Review and try again.';
        feedbackEl.className = 'mc-feedback ' + (pct === 100 ? 'good' : 'bad');
        if (forceFocus && currentWritingInputs.length > 0) {
            currentWritingInputs[0].focus();
        } else if (forceFocus && rewriteEl) {
            rewriteEl.focus();
        }
        return pct === 100;
    }

    /* ── createFillInput helper ───────────────────────── */
    function createFillInput(blankIdx, q) {
        var answers     = q.correct_answers || [];
        var expectedAns = answers[blankIdx] ? String(answers[blankIdx]) : '';
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'wp-fill-input';
        inp.setAttribute('autocomplete',   'off');
        inp.setAttribute('autocorrect',    'off');
        inp.setAttribute('autocapitalize', 'off');
        inp.setAttribute('spellcheck',     'false');
        inp.placeholder = '…';
        inp.style.width = Math.max(60, (expectedAns.length || 7) * 11 + 20) + 'px';
        inp.addEventListener('input', function () {
            if (!checkedCards[index] && !finished) {
                var anyFilled = currentFillInputs.some(function (fi) { return fi.value.trim() !== ''; });
                btnShow.disabled = !anyFilled;
            }
        });
        inp.addEventListener('focus', function () {
            activeFillInput = inp;
        });
        inp.addEventListener('blur',    function ()  { autoCheck(); });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); autoCheck(); }
        });
        return inp;
    }

    /* ── loadCard ─────────────────────────────────────── */
    function loadCard() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        var freeParagraphMode = isFreeParagraphMode(q);

        // Stop any active listen_write audio or TTS from previous card
        if (window.wpLwAudio) { window.wpLwAudio.pause(); window.wpLwAudio = null; }
        if (window.speechSynthesis) { speechSynthesis.cancel(); }

        finished = false;
        completedEl.classList.remove('active');
        cardEl.style.display    = '';
        controlsEl.style.display = '';
        cardEl.classList.toggle('wp-free-mode', freeParagraphMode);

        /* status */
        statusEl.textContent = (index + 1) + ' / ' + questions.length;

        /* clear previous content */
        currentFillInputs     = [];
        activeFillInput       = null;
        currentWritingInputs  = [];
        mediaArea.innerHTML   = '';
        qtextEl.innerHTML     = '';
        if (inputQuestionEl) {
            inputQuestionEl.textContent = '';
            inputQuestionEl.style.display = 'none';
        }
        if (videoQuestionEl) {
            videoQuestionEl.textContent = '';
            videoQuestionEl.style.display = 'none';
        }
        if (videoInstrEl) {
            videoInstrEl.textContent = '';
            videoInstrEl.style.display = 'none';
        }
        instrEl.innerHTML     = '';
        if (answerGuideEl) {
            answerGuideEl.style.display = 'none';
            answerGuideEl.innerHTML = '';
        }
        if (writingListEl) {
            writingListEl.innerHTML = '';
            writingListEl.style.display = 'none';
        }
        answerEl.value        = '';
        answerEl.style.display = '';
        answerEl.className    = freeParagraphMode ? 'dict-answer-box wp-free-answer-box' : 'dict-answer-box';
        answerEl.disabled     = false;
        answerEl.placeholder  = PLACEHOLDERS[type] || PLACEHOLDERS.writing;
        answerEl.spellcheck   = (type === 'writing');
        answerEl.autocapitalize = (type === 'writing') ? 'sentences' : 'off';
        answerEl.setAttribute('lang', type === 'writing' ? 'en' : '');
        feedbackEl.textContent = '';
        feedbackEl.className   = 'mc-feedback';
        revealEl.classList.remove('show');
        revealEl.textContent   = '';
        resetWritingCoach();

        /* ── open-writing notice ── */
        if (type === 'writing') {
            var promptItems = parseEnumeratedPrompt(q.question || '');
            var responseCount = getWritingCount(q);
            var rowsCount = getWritingRows(q);
            var promptText = getPrimaryPrompt(q.question || '');
            if (promptText !== '') {
                var panel = document.createElement('div');
                panel.className = 'wp-writing-panel';
                panel.innerHTML = '<div style="font-size:19px;font-weight:800;color:#1e3a8a;line-height:1.45;">' + esc(promptText) + '</div>';
                mediaArea.appendChild(panel);
            }

            answerEl.style.display = 'none';
            if (writingListEl) {
                writingListEl.style.display = '';
                for (var wi = 0; wi < responseCount; wi++) {
                    var itemWrap = document.createElement('div');
                    itemWrap.className = 'wp-writing-item';

                    var itemLabel = document.createElement('label');
                    itemLabel.className = 'wp-writing-item-label';
                    itemLabel.textContent = 'Response ' + (wi + 1);
                    itemWrap.appendChild(itemLabel);

                    var ta = document.createElement('textarea');
                    ta.className = 'dict-answer-box';
                    ta.rows = rowsCount;
                    ta.placeholder = 'Write your response ' + (wi + 1) + ' here...';
                    ta.spellcheck = true;
                    ta.setAttribute('lang', 'en');
                    ta.setAttribute('autocapitalize', 'sentences');
                    ta.addEventListener('input', function () {
                        if (btnShow.style.display !== 'none' && !checkedCards[index] && !finished) {
                            btnShow.disabled = !hasWritingValue();
                        }
                    });
                    ta.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            reviewWriting(true);
                        }
                    });
                    itemWrap.appendChild(ta);
                    writingListEl.appendChild(itemWrap);
                    currentWritingInputs.push(ta);
                }
            }
        }

        /* ── listen_write: MP3/TTS player + optional fill-in blanks ── */
        if (type === 'listen_write') {
            var lwWrap = document.createElement('div');
            lwWrap.className = 'wp-lw-player';

            var lwNote = document.createElement('div');
            lwNote.className   = 'wp-open-note';
            lwNote.textContent = '\uD83C\uDFA7 Escucha y completa los espacios en blanco.';
            lwWrap.appendChild(lwNote);

            var lwBtnRow = document.createElement('div');
            lwBtnRow.className = 'wp-lw-btn-row';

            var lwPlay   = document.createElement('button');
            lwPlay.type  = 'button'; lwPlay.className = 'mc-btn mc-btn-listen-wp';
            lwPlay.innerHTML = '\u25B6 Escuchar';

            var lwPause  = document.createElement('button');
            lwPause.type = 'button'; lwPause.className = 'mc-btn wp-lw-pause-btn';
            lwPause.innerHTML = '\u23F8 Pausar'; lwPause.style.display = 'none';

            var lwReplay = document.createElement('button');
            lwReplay.type = 'button'; lwReplay.className = 'mc-btn wp-lw-replay-btn';
            lwReplay.innerHTML = '\u21A9 Repetir'; lwReplay.style.display = 'none';

            function lwSetState(s) {
                lwPlay.style.display   = (s === 'idle' || s === 'paused') ? '' : 'none';
                lwPause.style.display  = (s === 'playing') ? '' : 'none';
                lwReplay.style.display = (s === 'done') ? '' : 'none';
                lwPlay.innerHTML = (s === 'paused') ? '\u25B6 Continuar' : '\u25B6 Escuchar';
            }

            if (q.media) {
                /* — MP3 file — */
                var lwAudio = new Audio(String(q.media));
                lwAudio.preload = 'auto';
                window.wpLwAudio = lwAudio;
                lwPlay.addEventListener('click', function () {
                    lwAudio.play().catch(function () {});
                    lwSetState('playing');
                });
                lwPause.addEventListener('click', function () {
                    lwAudio.pause(); lwSetState('paused');
                });
                lwReplay.addEventListener('click', function () {
                    lwAudio.currentTime = 0;
                    lwAudio.play().catch(function () {});
                    lwSetState('playing');
                });
                lwAudio.addEventListener('ended', function () {
                    window.wpLwAudio = null; lwSetState('done');
                });
            } else if (window.speechSynthesis) {
                /* — TTS fallback — */
                var lwTtsPaused = false;
                function lwSpeak() {
                    var _bi = 0;
                    var text = String(q.question || '').replace(/_{2,}/g, function () {
                        return String((q.correct_answers || [])[_bi++] || '...');
                    });
                    if (!text) { return; }
                    speechSynthesis.cancel(); lwTtsPaused = false;
                    var u = new SpeechSynthesisUtterance(text);
                    u.lang = 'en-US'; u.rate = 0.85;
                    u.onstart = function () { lwSetState('playing'); };
                    u.onend   = function () { lwTtsPaused = false; lwSetState('done'); };
                    u.onerror = function () { lwTtsPaused = false; lwSetState('idle'); };
                    speechSynthesis.speak(u);
                }
                lwPlay.addEventListener('click', function () {
                    if (lwTtsPaused && speechSynthesis.paused) {
                        speechSynthesis.resume(); lwTtsPaused = false; lwSetState('playing'); return;
                    }
                    lwSpeak();
                });
                lwPause.addEventListener('click', function () {
                    if (speechSynthesis.speaking && !speechSynthesis.paused) {
                        speechSynthesis.pause(); lwTtsPaused = true; lwSetState('paused');
                    }
                });
                lwReplay.addEventListener('click', function () { lwSpeak(); });
            }

            lwBtnRow.appendChild(lwPlay);
            lwBtnRow.appendChild(lwPause);
            lwBtnRow.appendChild(lwReplay);
            lwWrap.appendChild(lwBtnRow);
            mediaArea.appendChild(lwWrap);

            /* — fill-in blanks — ALWAYS shown for listen_write — */
            var lwRawText = String(q.question || '');
            answerEl.style.display = 'none';
            var lwFillBox = document.createElement('div');
            lwFillBox.className = 'wp-fill-paragraph-box';
            if (/_{2,}/.test(lwRawText)) {
                /* Explicit ___ markers → embed an input at each blank position */
                lwRawText.split(/_{2,}/).forEach(function (seg, si, arr) {
                    if (seg) {
                        seg.split('\n').forEach(function (line, li) {
                            if (li > 0) { lwFillBox.appendChild(document.createElement('br')); }
                            if (line)   { lwFillBox.appendChild(document.createTextNode(line)); }
                        });
                    }
                    if (si < arr.length - 1) {
                        var lwInp = createFillInput(si, q);
                        lwFillBox.appendChild(lwInp);
                        currentFillInputs.push(lwInp);
                    }
                });
            } else {
                /* No ___ markers → replace each answer word inline within the paragraph */
                var lwAns2 = Array.isArray(q.correct_answers) ? q.correct_answers : [];
                if (lwAns2.length > 0 && lwRawText) {
                    /* Walk through the text, find each answer word in order and swap it for an input */
                    var lwRemaining = lwRawText;
                    var lwSegs = []; /* [{type:'text',val:string}|{type:'input',idx:number}] */
                    for (var lwai = 0; lwai < lwAns2.length; lwai++) {
                        var lwWord  = String(lwAns2[lwai] || '');
                        var lwEsc   = lwWord.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var lwRe2   = new RegExp('(?<![\\w\\\'])' + lwEsc + '(?![\\w\\\'])', 'i');
                        var lwMatch = lwRe2.exec(lwRemaining);
                        if (lwMatch) {
                            if (lwMatch.index > 0) { lwSegs.push({type: 'text', val: lwRemaining.substring(0, lwMatch.index)}); }
                            lwSegs.push({type: 'input', idx: lwai});
                            lwRemaining = lwRemaining.substring(lwMatch.index + lwMatch[0].length);
                        } else {
                            /* word not found – input will appear at the end */
                            if (lwRemaining) {
                                lwSegs.push({type: 'text', val: lwRemaining});
                                lwRemaining = '';
                            }
                            lwSegs.push({type: 'input', idx: lwai});
                        }
                    }
                    if (lwRemaining) { lwSegs.push({type: 'text', val: lwRemaining}); }
                    lwSegs.forEach(function (seg) {
                        if (seg.type === 'text') {
                            seg.val.split('\n').forEach(function (line, li) {
                                if (li > 0) { lwFillBox.appendChild(document.createElement('br')); }
                                if (line)   { lwFillBox.appendChild(document.createTextNode(line)); }
                            });
                        } else {
                            var lwInpFb = createFillInput(seg.idx, q);
                            lwFillBox.appendChild(lwInpFb);
                            currentFillInputs.push(lwInpFb);
                        }
                    });
                } else {
                    /* No answers defined – show full text + one blank at end */
                    if (lwRawText) {
                        lwRawText.split('\n').forEach(function (line, li) {
                            if (li > 0) { lwFillBox.appendChild(document.createElement('br')); }
                            if (line)   { lwFillBox.appendChild(document.createTextNode(line)); }
                        });
                        lwFillBox.appendChild(document.createTextNode('\u00a0'));
                    }
                    var lwInpFb = createFillInput(0, q);
                    lwFillBox.appendChild(lwInpFb);
                    currentFillInputs.push(lwInpFb);
                }
            }
            qtextEl.appendChild(lwFillBox);
        }

        /* ── video_writing: embed ── */
        if (type === 'video_writing') {
            var videoForCard = String(q.media || '').trim();
            if (videoForCard === '') {
                videoForCard = globalVideoUrl;
            }
            renderFixedVideo(videoForCard);
        } else {
            renderFixedVideo('');
        }

        /* ── question text ── */
        if (type === 'fill_sentence' || (type === 'fill_paragraph' && !freeParagraphMode)) {
            answerEl.style.display = 'none';
            var rawText = String(q.question || '');
            var fillBox = document.createElement('div');
            fillBox.className = type === 'fill_paragraph' ? 'wp-fill-paragraph-box' : 'wp-fill-sentence-box';
            if (/_{2,}/.test(rawText)) {
                /* Explicit ___ blanks → embed an input at each position */
                rawText.split(/_{2,}/).forEach(function (seg, si, arr) {
                    if (seg) {
                        seg.split('\n').forEach(function (line, li) {
                            if (li > 0) { fillBox.appendChild(document.createElement('br')); }
                            if (line)   { fillBox.appendChild(document.createTextNode(line)); }
                        });
                    }
                    if (si < arr.length - 1) {
                        var fillInp = createFillInput(si, q);
                        fillBox.appendChild(fillInp);
                        currentFillInputs.push(fillInp);
                    }
                });
            } else {
                /* No explicit ___ blanks → find each answer word inline and replace with input */
                var fpAnswers = Array.isArray(q.correct_answers) ? q.correct_answers : [];
                if (fpAnswers.length > 0 && rawText) {
                    var fpRemaining = rawText;
                    var fpSegs = [];
                    for (var fpai = 0; fpai < fpAnswers.length; fpai++) {
                        var fpWord = String(fpAnswers[fpai] || '');
                        var fpEsc  = fpWord.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var fpRe   = new RegExp('(?<![\\w\\\'])' + fpEsc + '(?![\\w\\\'])', 'i');
                        var fpM    = fpRe.exec(fpRemaining);
                        if (fpM) {
                            if (fpM.index > 0) { fpSegs.push({type: 'text', val: fpRemaining.substring(0, fpM.index)}); }
                            fpSegs.push({type: 'input', idx: fpai});
                            fpRemaining = fpRemaining.substring(fpM.index + fpM[0].length);
                        } else {
                            if (fpRemaining) {
                                fpSegs.push({type: 'text', val: fpRemaining});
                                fpRemaining = '';
                            }
                            fpSegs.push({type: 'input', idx: fpai});
                        }
                    }
                    if (fpRemaining) { fpSegs.push({type: 'text', val: fpRemaining}); }
                    fpSegs.forEach(function (seg) {
                        if (seg.type === 'text') {
                            seg.val.split('\n').forEach(function (line, li) {
                                if (li > 0) { fillBox.appendChild(document.createElement('br')); }
                                if (line)   { fillBox.appendChild(document.createTextNode(line)); }
                            });
                        } else {
                            var fpInp = createFillInput(seg.idx, q);
                            fillBox.appendChild(fpInp);
                            currentFillInputs.push(fpInp);
                        }
                    });
                } else {
                    /* No answers defined – show full text + one blank at end */
                    if (rawText) {
                        rawText.split('\n').forEach(function (line, li) {
                            if (li > 0) { fillBox.appendChild(document.createElement('br')); }
                            if (line)   { fillBox.appendChild(document.createTextNode(line)); }
                        });
                        fillBox.appendChild(document.createTextNode('\u00a0'));
                    }
                    var fillInp = createFillInput(0, q);
                    fillBox.appendChild(fillInp);
                    currentFillInputs.push(fillInp);
                }
            }
            qtextEl.appendChild(fillBox);
        } else if (type === 'fill_paragraph' && freeParagraphMode) {
            answerEl.style.display = '';
            answerEl.className = 'dict-answer-box wp-free-answer-box';
            answerEl.placeholder = 'Write your paragraph here...';
            answerEl.spellcheck = true;
            answerEl.autocapitalize = 'sentences';
            answerEl.setAttribute('lang', 'en');
            if (q.question) {
                var freePanel = document.createElement('div');
                freePanel.className = 'wp-free-question-box';
                freePanel.textContent = String(q.question);
                qtextEl.appendChild(freePanel);
            }
        } else {
            /* listen_write: question text is read aloud by TTS – do NOT show it visually */
            if (type === 'video_writing') {
                var videoPrompt = String(q.question || '').trim();
                if (videoPrompt === '') {
                    videoPrompt = 'Question ' + (index + 1);
                }
                if (videoQuestionEl) {
                    videoQuestionEl.textContent = videoPrompt;
                    videoQuestionEl.style.display = 'block';
                }
                if (inputQuestionEl) {
                    inputQuestionEl.textContent = videoPrompt;
                    inputQuestionEl.style.display = 'block';
                }
                statusEl.textContent = (index + 1) + ' / ' + questions.length + ' - ' + videoPrompt;
            } else if (q.question && type !== 'listen_write' && type !== 'writing') {
                var qp = document.createElement('div');
                qp.style.cssText = 'font-weight:800;color:#f14902;font-size:clamp(16px,2vw,22px);margin-bottom:10px;line-height:1.4;text-align:center;';
                qp.textContent = String(q.question);
                qtextEl.appendChild(qp);
            }
        }

        /* ── instruction ── */
        if (q.instruction) {
            if (type === 'video_writing' && videoInstrEl) {
                videoInstrEl.style.display = '';
                videoInstrEl.textContent = String(q.instruction);
                instrEl.textContent = '';
            } else {
                if (videoInstrEl) {
                    videoInstrEl.style.display = 'none';
                    videoInstrEl.textContent = '';
                }
                instrEl.style.cssText = 'font-size:clamp(16px,2.2vw,20px);color:#5b21b6;font-weight:800;margin-bottom:12px;text-align:center;line-height:1.45;';
                instrEl.textContent = String(q.instruction);
            }
        } else {
            instrEl.textContent = '';
            if (videoInstrEl) {
                videoInstrEl.style.display = 'none';
                videoInstrEl.textContent = '';
            }
        }

        renderAnswerGuide(type, q, !freeParagraphMode);

        /* ── buttons state ── */
        btnPrev.disabled = (index === 0);
        btnNext.textContent = (index < questions.length - 1) ? 'Next' : 'Finish';
        btnShow.style.display = (isAutoGraded(q) || type === 'writing') ? '' : 'none';
        btnShow.textContent = (type === 'writing') ? 'Review Answer' : 'Show Answer';
        btnShow.disabled = !checkedCards[index] && (type === 'writing' ? !hasWritingValue() : isAutoGraded(q));

        /* restore state if user navigated back */
        if (checkedCards[index]) {
            var isFillType     = isInlineFillMode(q);
            var wasCardCorrect = checkedCards[index] === 'correct';
            if (isFillType && currentFillInputs.length > 0) {
                var savedVals = checkedCards[index + '_inputs'] || [];
                var savedPerInput = checkedCards[index + '_perInput'] || [];
                currentFillInputs.forEach(function (inp, ii) {
                    inp.value    = savedVals[ii] || '';
                    inp.disabled = true;
                    var thisOk   = wasCardCorrect ? true : !!savedPerInput[ii];
                    inp.className = 'wp-fill-input ' + (thisOk ? 'ok' : 'bad');
                });
                feedbackEl.textContent = wasCardCorrect ? '\u2714 Right' : '\u2718 Wrong';
                feedbackEl.className   = 'mc-feedback ' + (wasCardCorrect ? 'good' : 'bad');
                revealEl.textContent   = checkedCards[index + '_reveal'] || '';
                if (revealEl.textContent) { revealEl.classList.add('show'); }
            } else {
                answerEl.value = checkedCards[index + '_text'] || '';
                answerEl.disabled = true;
                if (isAutoGraded(q)) {
                    answerEl.className     = 'dict-answer-box ' + (wasCardCorrect ? 'ok' : 'bad');
                    feedbackEl.textContent = wasCardCorrect ? '\u2714 Right' : '\u2718 Wrong';
                    feedbackEl.className   = 'mc-feedback ' + (wasCardCorrect ? 'good' : 'bad');
                    revealEl.textContent   = checkedCards[index + '_reveal'] || '';
                    if (revealEl.textContent) { revealEl.classList.add('show'); }
                } else if (type === 'writing') {
                    var rwTotal = Math.max(0, parseInt(checkedCards[index + '_writing_total'] || getWritingExpectedList(q).length, 10));
                    var rwCorrect = Math.max(0, parseInt(checkedCards[index + '_writing_correct'] || 0, 10));
                    feedbackEl.textContent = '\u2714 Writing saved. Score by keys: ' + rwCorrect + ' / ' + rwTotal + '.';
                    feedbackEl.className   = 'mc-feedback ' + (rwTotal > 0 && rwCorrect >= rwTotal ? 'good' : 'bad');
                    if (coachEl) { coachEl.style.display = ''; }
                    if (coachSummaryEl) { coachSummaryEl.textContent = 'Last saved practice version'; }
                    if (coachPreviewEl) { coachPreviewEl.innerHTML = renderHighlightedText(answerEl.value, []); }
                    if (coachListEl) { coachListEl.innerHTML = '<li><strong>Scoring:</strong> this free-writing item is scored by the keys defined in the editor.</li>'; }
                    if (rewriteEl) { rewriteEl.value = answerEl.value; rewriteEl.disabled = true; }
                    if (Array.isArray(checkedCards[index + '_lines']) && currentWritingInputs.length > 0) {
                        var savedLines = checkedCards[index + '_lines'];
                        var rwExpected = getWritingExpectedList(q);
                        currentWritingInputs.forEach(function (inp, li) {
                            inp.value = savedLines[li] || '';
                            inp.disabled = true;
                            var okLine = rwExpected[li] ? checkCorrect(String(inp.value || ''), [rwExpected[li]]) : false;
                            inp.className = 'dict-answer-box ' + (okLine ? 'ok' : 'bad');
                        });
                    }
                } else {
                    feedbackEl.textContent = '\u2714 Submitted for review';
                    feedbackEl.className   = 'mc-feedback good';
                }
            }
        }

        if (currentFillInputs.length > 0) {
            currentFillInputs[0].focus();
        } else if (currentWritingInputs.length > 0) {
            currentWritingInputs[0].focus();
        } else {
            answerEl.focus();
        }
    }

    /* ── checkAnswer ──────────────────────────────────── */
    function checkAnswer() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        if (!isAutoGraded(q)) { return; }
        if (checkedCards[index]) { return; }

        var expectedAnswers = getExpectedAnswerList(q);
        if (expectedAnswers.length === 0) {
            feedbackEl.textContent = 'This activity has no correct answers configured yet.';
            feedbackEl.className   = 'mc-feedback bad';
            return;
        }

        var isFill = isInlineFillMode(q) && currentFillInputs.length > 0;

        if (isFill) {
            var vals    = currentFillInputs.map(function (fi) { return fi.value.trim(); });
            var answers = expectedAnswers;
            if (vals.every(function (v) { return v === ''; })) {
                feedbackEl.textContent = 'Fill in the blank first.';
                feedbackEl.className   = 'mc-feedback bad';
                return;
            }
            var fillAttempts = (attemptsMap[index] || 0) + 1;
            attemptsMap[index] = fillAttempts;
            var matchState = evaluateFillAnswers(vals, answers);
            if (matchState.allCorrect) {
                feedbackEl.textContent = '\u2714 Right';
                feedbackEl.className   = 'mc-feedback good';
                currentFillInputs.forEach(function (fi, ii) {
                    fi.className = 'wp-fill-input ' + (matchState.perInput[ii] ? 'ok' : 'bad');
                    fi.disabled = true;
                });
                playSound(sndOk);
                checkedCards[index]               = 'correct';
                checkedCards[index + '_inputs']   = vals;
                checkedCards[index + '_perInput'] = matchState.perInput;
                checkedCards[index + '_correct']  = matchState.perInput.filter(Boolean).length;
                correctCount += matchState.perInput.filter(Boolean).length;
            } else if (fillAttempts >= 2) {
                feedbackEl.textContent = '\u2718 Wrong (2/2)';
                feedbackEl.className   = 'mc-feedback bad';
                if (type === 'fill_paragraph') {
                    revealFillParagraphAnswers(currentFillInputs, answers, matchState.perInput);
                } else {
                    currentFillInputs.forEach(function (fi, ii) {
                        fi.className = 'wp-fill-input ' + (matchState.perInput[ii] ? 'ok' : 'bad');
                        fi.disabled  = true;
                    });
                }
                playSound(sndBad);
                var shownFill = answers.join(', ');
                revealEl.textContent = 'Correct: ' + shownFill;
                revealEl.classList.add('show');
                checkedCards[index]               = 'wrong';
                checkedCards[index + '_inputs']   = vals;
                checkedCards[index + '_perInput'] = matchState.perInput;
                checkedCards[index + '_reveal']   = 'Correct: ' + shownFill;
                checkedCards[index + '_correct']  = matchState.perInput.filter(Boolean).length;
            } else {
                feedbackEl.textContent = '\u2718 Some blanks still need correction (1/2)';
                feedbackEl.className   = 'mc-feedback bad';
                currentFillInputs.forEach(function (fi, ii) {
                    fi.className = 'wp-fill-input ' + (matchState.perInput[ii] ? 'ok' : 'bad');
                });
                playSound(sndBad);
            }
            return;
        }

        /* ── textarea path ── */
        var val = answerEl.value.trim();
        if (val === '') {
            feedbackEl.textContent = 'Write an answer first.';
            feedbackEl.className   = 'mc-feedback bad';
            return;
        }
        var attempts = (attemptsMap[index] || 0) + 1;
        attemptsMap[index] = attempts;
        var correct = checkCorrect(val, expectedAnswers);
        if (correct) {
            feedbackEl.textContent = '\u2714 Right';
            feedbackEl.className   = 'mc-feedback good';
            answerEl.className     = 'dict-answer-box ok';
            answerEl.disabled      = true;
            playSound(sndOk);
            checkedCards[index]   = 'correct';
            correctCount++;
        } else if (attempts >= 2) {
            feedbackEl.textContent = '\u2718 Wrong';
            feedbackEl.className   = 'mc-feedback bad';
            answerEl.className     = 'dict-answer-box bad';
            answerEl.disabled      = true;
            playSound(sndBad);
            var shown = (q.correct_answers || []).slice(0, 2).join(' / ');
            revealEl.textContent = 'Correct: ' + shown;
            revealEl.classList.add('show');
            checkedCards[index]             = 'wrong';
            checkedCards[index + '_reveal'] = 'Correct: ' + shown;
        } else {
            feedbackEl.textContent = '\u2718 Wrong (1/2) \u2013 try again';
            feedbackEl.className   = 'mc-feedback bad';
            answerEl.className     = 'dict-answer-box bad';
            playSound(sndBad);
        }
    }

    /* ── autoCheck (on blur / Enter) ──────────────────── */
    function autoCheck() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        if (!isAutoGraded(q) || checkedCards[index]) { return; }
        var isFill = isInlineFillMode(q) && currentFillInputs.length > 0;
        if (isFill) {
            if (currentFillInputs.every(function (fi) { return fi.value.trim() !== ''; })) { checkAnswer(); }
        } else {
            if (answerEl.value.trim() !== '') { checkAnswer(); }
        }
    }

    /* ── goNext ───────────────────────────────────────── */
    function goNext() {
        if (finished) { return; }
        var q    = questions[index];
        var type = String(q.type || 'writing');

        if (isAutoGraded(q)) {
            /* must check first */
            var isFillNext = isInlineFillMode(q) && currentFillInputs.length > 0;
            if (!checkedCards[index]) {
                if (isFillNext) {
                    if (currentFillInputs.some(function (fi) { return fi.value.trim() !== ''; })) { checkAnswer(); }
                } else if (answerEl.value.trim() !== '') {
                    checkAnswer();
                }
            }
            if (!checkedCards[index]) { return; }
        } else if (type === 'writing') {
            /* free writing – scoreable by editor keys */
            var writingLines = currentWritingInputs.length > 0
                ? getWritingValues()
                : [getWritingLatestText()];
            var val = getWritingLatestText();
            if (!writingReviewed[index] && !checkedCards[index]) {
                reviewWriting(true);
                return;
            }
            if (!checkedCards[index]) {
                var expectedLines = getWritingExpectedList(q);
                var wTotal = Math.max(0, parseInt(checkedCards[index + '_writing_total'] || expectedLines.length, 10));
                var wCorrect = Math.max(0, parseInt(checkedCards[index + '_writing_correct'] || 0, 10));
                checkedCards[index] = (wTotal > 0 && wCorrect >= wTotal) ? 'correct' : 'wrong';
                checkedCards[index + '_text'] = val;
                checkedCards[index + '_lines'] = writingLines;
                if (val !== '') {
                    openResponses.push({
                        question_id:   String(q.id || index),
                        question_text: String(q.question || ''),
                        question_type: String(q.type || 'writing'),
                        response_text: val,
                        response_lines: writingLines,
                        max_points:    Math.max(1, wTotal),
                    });
                }
                feedbackEl.textContent = '\u2714 Writing saved. Score by keys: ' + wCorrect + ' / ' + wTotal + '.';
                feedbackEl.className   = 'mc-feedback ' + (wTotal > 0 && wCorrect >= wTotal ? 'good' : 'bad');
                answerEl.value         = val;
                answerEl.disabled      = true;
                if (currentWritingInputs.length > 0) {
                    currentWritingInputs.forEach(function (inp, wi) {
                        inp.disabled = true;
                        var okLine = expectedLines[wi] ? checkCorrect(String(inp.value || ''), [expectedLines[wi]]) : false;
                        inp.className = 'dict-answer-box ' + (okLine ? 'ok' : 'bad');
                    });
                }
                if (rewriteEl) { rewriteEl.value = val; rewriteEl.disabled = true; }
            }
        } else {
            /* other open responses keep the normal review flow */
            var openVal = answerEl.value.trim();
            if (!checkedCards[index]) {
                checkedCards[index] = 'open';
                checkedCards[index + '_text'] = openVal;
                correctCount++;
                if (openVal !== '') {
                    openResponses.push({
                        question_id:   String(q.id || index),
                        question_text: String(q.question || ''),
                        question_type: String(q.type || 'video_writing'),
                        response_text: openVal,
                        max_points:    1,
                    });
                }
                feedbackEl.textContent = '\u2714 Submitted for review';
                feedbackEl.className   = 'mc-feedback good';
                answerEl.value         = openVal;
                answerEl.disabled      = true;
            }
        }

        if (index < questions.length - 1) {
            index++;
            loadCard();
        } else {
            showCompleted();
        }
    }

    /* ── goPrev ───────────────────────────────────────── */
    function goPrev() {
        if (index > 0) { index--; loadCard(); }
    }

    /* ── showAnswer ───────────────────────────────────── */
    function showAnswer() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        if (type === 'writing') {
            var writingAnswers = getWritingExpectedList(q);
            if (writingAnswers.length === 0) {
                feedbackEl.textContent = 'This activity has no correct answers configured yet.';
                feedbackEl.className = 'mc-feedback bad';
                return;
            }

            var writingLines = currentWritingInputs.length > 0
                ? getWritingValues()
                : [getWritingLatestText()];
            var val = getWritingLatestText();

            if (!checkedCards[index]) {
                checkedCards[index] = 'wrong';
                checkedCards[index + '_text'] = val;
                checkedCards[index + '_lines'] = writingLines;
                checkedCards[index + '_writing_correct'] = 0;
                checkedCards[index + '_writing_total'] = writingAnswers.length;
                checkedCards[index + '_writing_pct'] = 0;
                writingReviewed[index] = true;

                if (currentWritingInputs.length > 0) {
                    currentWritingInputs.forEach(function (inp, wi) {
                        var ans = writingAnswers[wi] || '';
                        if (ans !== '') {
                            inp.value = ans;
                        }
                        inp.disabled = true;
                        inp.className = 'dict-answer-box bad';
                    });
                } else {
                    answerEl.value = writingAnswers.slice(0, 2).join(' / ');
                    answerEl.disabled = true;
                    answerEl.className = 'dict-answer-box bad';
                }

                feedbackEl.textContent = '\u2718 Wrong';
                feedbackEl.className = 'mc-feedback bad';
                playSound(sndBad);
            }

            revealEl.textContent = 'Correct: ' + writingAnswers.slice(0, 2).join(' / ');
            revealEl.classList.add('show');
            return;
        }
        if (!isAutoGraded(q)) { return; }
        var answers = getExpectedAnswerList(q);
        if (answers.length === 0) {
            feedbackEl.textContent = 'This activity has no correct answers configured yet.';
            feedbackEl.className   = 'mc-feedback bad';
            return;
        }

        var isFill = isInlineFillMode(q) && currentFillInputs.length > 0;
        if (isFill) {
            var shownFill = answers.join(', ');
            if (!checkedCards[index]) {
                var savedVals2 = currentFillInputs.map(function (fi) { return fi.value.trim(); });
                var matchState2 = evaluateFillAnswers(savedVals2, answers);
                checkedCards[index]               = 'wrong';
                checkedCards[index + '_inputs']   = savedVals2;
                checkedCards[index + '_perInput'] = matchState2.perInput;
                checkedCards[index + '_reveal']   = 'Correct: ' + shownFill;
                if (type === 'fill_paragraph') {
                    revealFillParagraphAnswers(currentFillInputs, answers, matchState2.perInput);
                } else {
                    currentFillInputs.forEach(function (fi, ii) {
                        fi.className = 'wp-fill-input ' + (matchState2.perInput[ii] ? 'ok' : 'bad');
                        fi.disabled = true;
                    });
                }
                feedbackEl.textContent = '\u2718 Wrong';
                feedbackEl.className   = 'mc-feedback bad';
                playSound(sndBad);
            }
            revealEl.textContent = 'Correct: ' + shownFill;
            revealEl.classList.add('show');
            return;
        }

        /* ── textarea path ── */
        var shown = answers.slice(0, 2).join(' / ');
        if (!checkedCards[index]) {
            checkedCards[index]             = 'wrong';
            checkedCards[index + '_reveal'] = 'Correct: ' + shown;
            answerEl.disabled  = true;
            answerEl.className = 'dict-answer-box bad';
            feedbackEl.textContent = '\u2718 Wrong';
            feedbackEl.className   = 'mc-feedback bad';
            playSound(sndBad);
        }
        if (answerEl.value.trim() !== '') {
            revealEl.textContent = 'Correct: ' + shown;
        } else {
            revealEl.textContent = 'Correct: ' + shown;
        }
        revealEl.classList.add('show');
    }

    /* ── persist score (fire & forget) ───────────────── */
    function persistScore(url) {
        if (!url) { return Promise.resolve(false); }
        return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return !!(r && r.ok); })
            .catch(function () { return false; });
    }

    function navigateTo(url) {
        if (!url) { return; }
        try {
            if (window.top && window.top !== window.self) { window.top.location.href = url; return; }
        } catch (e) {}
        window.location.href = url;
    }

    /* ── showCompleted ────────────────────────────────── */
    async function showCompleted() {
        finished = true;
        renderFixedVideo('');
        if (videoInstrEl) {
            videoInstrEl.style.display = 'none';
            videoInstrEl.textContent = '';
        }
        cardEl.style.display     = 'none';
        controlsEl.style.display = 'none';
        feedbackEl.textContent   = '';
        statusEl.textContent     = 'Completed';
        completedEl.classList.add('active');
        playSound(sndDone);

        /* titles */
        if (compTitleEl) { compTitleEl.textContent = actTitle; }
        if (compTextEl)  { compTextEl.textContent  = "You've completed " + actTitle + ". Great job!"; }

        /* score summary: all scoreable blocks, including free writing keys, count by input slot */
        var totalCount = questions.reduce(function (sum, qq) {
            return sum + getQuestionInputTotal(qq);
        }, 0);

        var scoredCorrect = questions.reduce(function (sum, qq, qi) {
            return sum + getQuestionCorrectUnits(qq, qi);
        }, 0);

        var pct    = totalCount > 0 ? Math.round((scoredCorrect / totalCount) * 100) : 100;
        var errors = Math.max(0, totalCount - scoredCorrect);

        /* count total words written across all responses */
        var totalWords = 0;
        openResponses.forEach(function (r) {
            var t = String(r.response_text || '').replace(/\s+/g, ' ').trim();
            if (t) { totalWords += t.split(' ').length; }
        });

        if (scoreTextEl) {
            scoreTextEl.textContent = totalCount > 0
                ? 'Score: ' + scoredCorrect + ' / ' + totalCount + ' (' + pct + '%)'
                : 'Practice completed — free writing has no score.';
        }
        if (totalWords > 0 && openNoteEl) {
            openNoteEl.style.display = '';
            openNoteEl.innerHTML     = '\uD83D\uDCCA ' + totalWords + ' palabras escritas';
            if (openResponses.length > 0) {
                openNoteEl.innerHTML += ' &nbsp;&middot;&nbsp; \u270D\uFE0F ' + openResponses.length + ' response(s) reviewed.';
            }
        } else if (openNoteEl && openResponses.length > 0) {
            openNoteEl.style.display = '';
            openNoteEl.textContent   = '\u270D\uFE0F ' + openResponses.length + ' response(s) reviewed.';
        }

        /* save open-writing responses */
        if (openResponses.length > 0) {
            try {
                var fd = new FormData();
                fd.append('activity_id',   activityId);
                fd.append('unit_id',       unitId);
                fd.append('assignment_id', assignId);
                fd.append('responses',     JSON.stringify(openResponses));
                await fetch('/lessons/lessons/activities/writing_practice/wp_save_response.php', {
                    method: 'POST', body: fd,
                });
            } catch (e) { /* non-critical */ }
        }

        /* persist score to return URL */
        if (returnTo) {
            var joiner  = returnTo.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = returnTo
                + joiner
                + 'activity_percent=' + encodeURIComponent(String(pct))
                + '&activity_errors='  + encodeURIComponent(String(errors))
                + '&activity_total='   + encodeURIComponent(String(totalCount))
                + '&activity_id='      + encodeURIComponent(activityId)
                + '&activity_type=writing_practice';

            var ok = await persistScore(saveUrl);
            if (!ok) { navigateTo(saveUrl); }
        }
    }

    /* ── restart ──────────────────────────────────────── */
    function restart() {
        checkedCards      = {};
        attemptsMap       = {};
        openResponses     = [];
        writingReviewed   = {};
        correctCount      = 0;
        index             = 0;
        finished          = false;
        currentFillInputs = [];
        if (window.speechSynthesis) { speechSynthesis.cancel(); }
        if (window.wpLwAudio) { window.wpLwAudio.pause(); window.wpLwAudio = null; }
        loadCard();
    }

    /* ── event listeners ──────────────────────────────── */
    btnPrev.addEventListener('click', goPrev);
    btnNext.addEventListener('click', goNext);
    btnShow.addEventListener('click', showAnswer);
    btnRestart.addEventListener('click', restart);

    answerEl.addEventListener('input', function () {
        if (btnShow.style.display !== 'none' && !checkedCards[index] && !finished) {
            btnShow.disabled = answerEl.value.trim() === '';
        }
    });
    answerEl.addEventListener('blur', autoCheck);
    answerEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            var q = questions[index];
            if (isAutoGraded(q)) {
                autoCheck();
            } else if (String(q.type || 'writing') === 'writing') {
                reviewWriting(true);
            } else {
                goNext();
            }
        }
    });

    if (rewriteEl) {
        rewriteEl.addEventListener('input', function () {
            if (btnShow.style.display !== 'none' && !checkedCards[index] && !finished) {
                btnShow.disabled = rewriteEl.value.trim() === '' && answerEl.value.trim() === '';
            }
        });
        rewriteEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                reviewWriting(false);
            }
        });
    }

    /* ── init ─────────────────────────────────────────── */
    loadCard();
});
</script>

<?php endif; /* end if/elseif/else */ ?>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);

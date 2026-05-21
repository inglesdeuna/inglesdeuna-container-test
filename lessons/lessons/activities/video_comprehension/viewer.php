<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("\n        SELECT unit_id\n        FROM activities\n        WHERE id = :id\n        LIMIT 1\n    ");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_video_comprehension_title(): string
{
    return 'Video Comprehension';
}

function normalize_embed_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $url) && preg_match('/^(www\.)?[a-z0-9.-]+\.[a-z]{2,}(\/|$)/i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    if (!preg_match('/^https?:\/\//i', $url)) {
        return '';
    }

    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '') {
        return '';
    }

    $host = strtolower($host);
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) parse_url($url, PHP_URL_QUERY);

    if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
        $videoId = '';

        if (strpos($host, 'youtu.be') !== false) {
            $videoId = trim((string) preg_replace('/\?.*$/', '', trim($path, '/')));
        } elseif (preg_match('~^/(shorts|embed|live)/([^/?#]+)~i', $path, $m)) {
            $videoId = trim((string) $m[2]);
        } elseif ($query !== '') {
            parse_str($query, $queryParams);
            if (!empty($queryParams['v'])) {
                $videoId = trim((string) $queryParams['v']);
            }
        }

        if ($videoId !== '') {
            return 'https://www.youtube.com/embed/' . rawurlencode($videoId);
        }
    }

    if (strpos($host, 'vimeo.com') !== false) {
        $videoId = trim($path, '/');
        if ($videoId !== '' && ctype_digit($videoId)) {
            return 'https://player.vimeo.com/video/' . $videoId;
        }
    }

    return $url;
}

function normalize_video_comprehension_payload($rawData): array
{
    $default = [
        'title' => default_video_comprehension_title(),
        'mode' => 'quiz',
        'iframe_url' => '',
        'instructions' => 'Watch the video and answer each question.',
        'questions' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? ''));
    $mode = trim((string) ($decoded['mode'] ?? 'quiz'));
    $iframeUrl = normalize_embed_url((string) ($decoded['iframe_url'] ?? ''));
    $instructions = trim((string) ($decoded['instructions'] ?? ''));

    if ($mode !== 'video_only') {
        $mode = 'quiz';
    }

    $questions = [];
    $source = isset($decoded['questions']) && is_array($decoded['questions']) ? $decoded['questions'] : [];

    foreach ($source as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];

        $questions[] = [
            'question' => trim((string) ($item['question'] ?? '')),
            'options' => [
                trim((string) ($options[0] ?? '')),
                trim((string) ($options[1] ?? '')),
                trim((string) ($options[2] ?? '')),
            ],
            'correct' => max(0, min(2, (int) ($item['correct'] ?? 0))),
            'explanation' => trim((string) ($item['explanation'] ?? '')),
        ];
    }

    return [
        'title' => $title !== '' ? $title : default_video_comprehension_title(),
        'mode' => $mode,
        'iframe_url' => $iframeUrl,
        'instructions' => $instructions !== '' ? $instructions : $default['instructions'],
        'questions' => $questions,
    ];
}

function load_video_comprehension_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'title' => default_video_comprehension_title(),
        'mode' => 'quiz',
        'iframe_url' => '',
        'instructions' => 'Watch the video and answer each question.',
        'questions' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("\n            SELECT data\n            FROM activities\n            WHERE id = :id\n              AND type = 'video_comprehension'\n            LIMIT 1\n        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("\n            SELECT data\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'video_comprehension'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    return normalize_video_comprehension_payload($row['data'] ?? null);
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_video_comprehension_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? default_video_comprehension_title());
$activityMode = (string) ($activity['mode'] ?? 'quiz');
$iframeUrl = trim((string) ($activity['iframe_url'] ?? ''));
$instructions = trim((string) ($activity['instructions'] ?? 'Watch the video and answer each question.'));
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : [];

$isEditorSession = false;
if ($iframeUrl === '' && $activityId !== '') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $isEditorSession = !empty($_SESSION['admin_logged']) || !empty($_SESSION['academic_logged']);
}

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --vc-orange:#F97316;
    --vc-orange-dark:#C2580A;
    --vc-orange-soft:#FFF0E6;
    --vc-purple:#7F77DD;
    --vc-purple-dark:#534AB7;
    --vc-purple-soft:#EEEDFE;
    --vc-muted:#9B94BE;
    --vc-border:#EDE9FA;
    --vc-ink:#271B5D;
}

.vc-viewer{max-width:1180px;margin:0 auto}
.vc-intro{margin-bottom:16px;padding:24px 26px;border-radius:26px;border:1px solid var(--vc-border);background:#ffffff;box-shadow:0 8px 40px rgba(127,119,221,.13)}
.vc-intro h2{margin:0 0 8px;color:var(--vc-orange);font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:30px;line-height:1.1}
.vc-intro p{margin:0;color:var(--vc-muted);font-size:15px;line-height:1.55;font-weight:800;font-family:'Nunito','Segoe UI',sans-serif}
.vc-layout{gap:16px}
.vc-panel{background:#fff;border:1px solid var(--vc-border);border-radius:22px;box-shadow:0 8px 40px rgba(127,119,221,.13);overflow:hidden}
.vc-panel.vtc-content-col{overflow-y:auto;overflow-x:hidden}
.vc-video-only{background:#fff;border:1px solid var(--vc-border);border-radius:22px;box-shadow:0 8px 40px rgba(127,119,221,.13);overflow:hidden}
.vc-video-wrap{padding:16px;background:#ffffff}
.vc-video{width:100%;aspect-ratio:16/9;border:none;border-radius:14px;background:#000}
.vc-video-copy{padding:16px 18px 18px;border-top:1px solid var(--vc-border);background:#ffffff}
.vc-video-copy h3{margin:0 0 8px;color:var(--vc-orange);font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px}
.vc-video-copy p{margin:0;color:var(--vc-muted);line-height:1.6;font-size:15px;font-weight:800}
.vc-panel-header{padding:14px 16px;border-bottom:1px solid var(--vc-border);background:#ffffff}
.vc-panel-header strong{color:var(--vc-purple-dark);font-size:15px;font-weight:900}
.vc-questions{padding:14px 16px}
.vc-question-count{font-size:13px;font-weight:900;color:var(--vc-muted);margin-bottom:8px}
.vc-question{font-size:20px;line-height:1.35;color:var(--vc-orange);font-weight:700;margin-bottom:12px;font-family:'Fredoka','Trebuchet MS',sans-serif}
.vc-options{display:grid;gap:8px}
.vc-option{border:1px solid var(--vc-border);background:#fff;border-radius:12px;padding:10px 12px;text-align:left;cursor:pointer;font-weight:800;color:var(--vc-ink);transition:all .15s ease;font-family:'Nunito','Segoe UI',sans-serif}
.vc-option:hover{border-color:var(--vc-purple);background:var(--vc-purple-soft)}
.vc-option.active{border-color:var(--vc-purple);background:var(--vc-purple-soft);color:var(--vc-purple-dark)}
.vc-option.correct{border-color:#86efac;background:#ecfdf5;color:#166534}
.vc-option.wrong{border-color:#fca5a5;background:#fef2f2;color:#991b1b}
.vc-controls{display:flex;gap:10px;flex-wrap:wrap;padding:0 16px 14px}
.vc-btn{display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:999px;padding:11px 18px;font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;min-width:142px;line-height:1;cursor:pointer;transition:transform .15s ease,filter .15s ease}
.vc-btn:hover{filter:brightness(1.04);transform:translateY(-1px)}
.vc-btn-check{background:var(--vc-purple);color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.vc-btn-next{background:var(--vc-orange);color:#fff;box-shadow:0 6px 18px rgba(249,115,22,.22)}
.vc-btn-restart{background:var(--vc-purple-dark);color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.vc-feedback{min-height:46px;margin:0 16px 16px;padding:10px 12px;border-radius:12px;font-weight:800;font-size:14px;display:flex;align-items:center;background:#ffffff;border:1px solid var(--vc-border);color:var(--vc-muted)}
.vc-feedback.success{background:#ecfdf5;border-color:#86efac;color:#166534}
.vc-feedback.error{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.vc-empty{padding:26px;text-align:center;font-weight:800;color:#b91c1c}
.vc-activity.is-hidden{display:none}
.vc-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}
.vc-score-grid.visible{display:grid}
.vc-score-card{background:#FAFAFE;border:1px solid var(--vc-border);border-radius:14px;padding:12px;text-align:center}
.vc-score-num{font-family:'Fredoka One',sans-serif;font-size:26px;line-height:1;font-weight:400}
.vc-score-num.c{color:#16a34a}
.vc-score-num.w{color:#dc2626}
.vc-score-num.p{color:var(--vc-purple)}
.vc-score-lbl{margin-top:5px;font-size:10px;font-weight:900;color:var(--vc-muted);text-transform:uppercase;letter-spacing:.08em}

.vc-complete-page{display:none;max-width:940px;margin:0 auto;padding:14px;background:#F8F7FE;border-radius:18px}
.vc-complete-page.active{display:block}

.vc-complete-hero{text-align:center;margin-bottom:16px}
.vc-complete-kicker{display:inline-flex;align-items:center;justify-content:center;padding:6px 14px;border-radius:999px;background:#FFF0E6;color:#F97316;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
.vc-complete-hero h1{margin:0;font-family:'Fredoka One',sans-serif;font-size:clamp(30px,4.8vw,44px);color:#F97316;line-height:1.06;font-weight:400}
.vc-complete-hero p{margin:8px 0 0;color:#9B94BE;font-size:14px;font-weight:700}

.vc-complete-stage{width:min(860px,100%);margin:0 auto;background:#fff;border:1px solid #EDE9FA;border-radius:24px;box-shadow:0 8px 40px rgba(127,119,221,.13);padding:18px}
.vc-complete-progress{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px;margin-bottom:14px}
.vc-complete-progress-label{color:#7F77DD;font-size:13px;font-weight:800}
.vc-complete-progress-track{height:7px;border-radius:99px;background:#EDE9FA;overflow:hidden}
.vc-complete-progress-fill{height:100%;width:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#7F77DD)}
.vc-complete-progress-badge{background:#7F77DD;color:#fff;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:800;white-space:nowrap}

.vc-completed-screen{display:none;text-align:center;padding:24px 12px}
.vc-completed-screen.active{display:block}
.vc-completed-title{margin:0;color:var(--vc-orange);font-family:'Fredoka One',sans-serif;font-size:32px;font-weight:400}
.vc-completed-text{color:var(--vc-muted);font-size:14px;font-weight:700}
.vc-score-text{color:#666;font-size:14px;font-weight:800}
.vc-restart-btn{border:none;border-radius:999px;color:#fff;min-width:128px;padding:11px 20px;font-size:14px;font-weight:700;font-family:'Nunito',sans-serif;cursor:pointer;background:var(--vc-purple)}
.vc-restart-btn:hover{filter:brightness(1.06)}
@media (max-width:860px){.vc-intro{padding:20px 18px}.vc-intro h2{font-size:26px}.vc-question{font-size:18px}}
@media(max-width:760px){.vc-score-grid{grid-template-columns:1fr}}
@media(max-width:480px){.vc-restart-btn{width:100%}}

/* ── Embedded / fullscreen / presentation ── */

/* 1 cm margins in fullscreen — same as drag-drop kids */
body.fullscreen-embedded .activity-wrapper,
body.presentation-mode .activity-wrapper {
    padding: 10mm !important;
    box-sizing: border-box !important;
}

/* viewer-content: compact padding, white, rounded */
body.embedded-mode .viewer-content,
body.fullscreen-embedded .viewer-content,
body.presentation-mode .viewer-content {
    padding: 6px 8px !important;
    background: #fff !important;
    border-radius: 14px !important;
    overflow: hidden !important;
}

/* vc-viewer: fill via flex chain — no position:absolute */
body.embedded-mode .vc-viewer,
body.fullscreen-embedded .vc-viewer,
body.presentation-mode .vc-viewer {
    flex: 1 !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    max-width: none !important;
    margin: 0 !important;
    background: #fff !important;
}

/* Hide header, intro, and "Watch And Focus" copy */
body.embedded-mode .act-header,
body.fullscreen-embedded .act-header,
body.presentation-mode .act-header,
body.embedded-mode .vc-intro,
body.fullscreen-embedded .vc-intro,
body.presentation-mode .vc-intro,
body.embedded-mode .vc-video-copy,
body.fullscreen-embedded .vc-video-copy,
body.presentation-mode .vc-video-copy {
    display: none !important;
}

/* Quiz two-column grid: fill vc-viewer height; keep columns at natural height
   so the video preserves its 16:9 aspect-ratio (align-items:start, not stretch) */
body.embedded-mode .vtc-layout,
body.fullscreen-embedded .vtc-layout,
body.presentation-mode .vtc-layout {
    flex: 1 !important;
    min-height: 0 !important;
    align-items: start !important;
    gap: 0 !important;
    overflow: hidden !important;
}

/* Left video panel: flush edges */
body.embedded-mode .vtc-video-col.vc-panel,
body.fullscreen-embedded .vtc-video-col.vc-panel,
body.presentation-mode .vtc-video-col.vc-panel {
    border-radius: 0 !important;
    box-shadow: none !important;
    border: none !important;
    border-right: 1px solid #e2e8f0 !important;
}

/* Right questions panel: scrollable, flush */
body.embedded-mode .vtc-content-col.vc-panel,
body.fullscreen-embedded .vtc-content-col.vc-panel,
body.presentation-mode .vtc-content-col.vc-panel {
    border-radius: 0 !important;
    box-shadow: none !important;
    border: none !important;
    max-height: 100vh !important;
    overflow-y: auto !important;
}

/* ── Video-only mode ── */
body.embedded-mode .vc-video-only,
body.fullscreen-embedded .vc-video-only,
body.presentation-mode .vc-video-only {
    flex: 1 !important;
    min-height: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: #000 !important;
    overflow: hidden !important;
}

/* Video-only: wrap fills the section */
body.embedded-mode .vc-video-only .vc-video-wrap,
body.fullscreen-embedded .vc-video-only .vc-video-wrap,
body.presentation-mode .vc-video-only .vc-video-wrap {
    flex: 1 !important;
    min-height: 0 !important;
    padding: 0 !important;
    background: #000 !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Video-only: iframe fills the wrap; drop 16:9 so flex fills space */
body.embedded-mode .vc-video-only .vc-video,
body.fullscreen-embedded .vc-video-only .vc-video,
body.presentation-mode .vc-video-only .vc-video {
    flex: 1 !important;
    width: 100% !important;
    height: 100% !important;
    aspect-ratio: unset !important;
    border-radius: 0 !important;
    border: none !important;
}
</style>

<?= render_activity_header($viewerTitle, $instructions) ?>
<div class="vc-viewer" id="vc-app">
    <?php
    $hasVideo = $iframeUrl !== '';
    $isVideoOnly = $hasVideo && $activityMode === 'video_only';
    $isEmptyQuiz = $hasVideo && !$isVideoOnly && empty($questions);
    $hasQuiz = $hasVideo && !$isVideoOnly && !empty($questions);
    ?>

    <?php if (!$hasVideo) { ?>
        <div class="vc-panel">
            <?php if ($isEditorSession): ?>
                <?php
                $editorParams = http_build_query(array_filter([
                    'id'         => $activityId,
                    'unit'       => $unit,
                    'source'     => isset($_GET['source']) ? trim((string) $_GET['source']) : '',
                    'assignment' => isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '',
                ]));
                ?>
                <div class="vc-empty" style="display:flex;flex-direction:column;align-items:center;gap:14px;padding:32px 24px;">
                    <span>Este video aún no está configurado.</span>
                    <a href="editor.php?<?= htmlspecialchars($editorParams, ENT_QUOTES, 'UTF-8') ?>"
                       style="display:inline-block;padding:10px 22px;background:linear-gradient(180deg,#3b82f6,#1d4ed8);color:#fff;font-weight:800;border-radius:999px;text-decoration:none;font-size:14px;">
                        Configurar video →
                    </a>
                </div>
            <?php else: ?>
                <div class="vc-empty">No hay video configurado para esta actividad.</div>
            <?php endif; ?>
        </div>
    <?php } ?>

    <?php if ($isVideoOnly) { ?>
        <section class="vc-video-only">
            <div class="vc-video-wrap">
                <iframe class="vc-video" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" title="Video comprehension" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
            </div>
            <div class="vc-video-copy">
                <h3>Watch And Focus</h3>
                <p><?= htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </section>
    <?php } ?>

    <?php if ($isEmptyQuiz) { ?>
        <div class="vtc-layout vc-layout">
            <section class="vc-panel vtc-video-col">
                <div class="vc-video-wrap">
                    <iframe class="vc-video" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" title="Video comprehension" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                </div>
            </section>
            <section class="vc-panel vtc-content-col">
                <div class="vc-empty">No questions configured yet.</div>
            </section>
        </div>
    <?php } ?>

    <?php if ($hasQuiz) { ?>
        <div class="vtc-layout vc-layout vc-activity" id="vc-activity">
            <section class="vc-panel vtc-video-col">
                <div class="vc-video-wrap">
                    <iframe class="vc-video" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" title="Video comprehension" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                </div>
            </section>

            <section class="vc-panel vtc-content-col">
                <div class="vc-panel-header"><strong>Comprehension Questions</strong></div>

                <div id="vc-quizShell">
                    <div class="vc-questions">
                        <div class="vc-question-count" id="vc-count"></div>
                        <div class="vc-question" id="vc-question"></div>
                        <div class="vc-options" id="vc-options"></div>
                    </div>

                    <div class="vc-controls">
                        <button type="button" class="vc-btn vc-btn-check" id="vc-check">Check Answer</button>
                        <button type="button" class="vc-btn vc-btn-next" id="vc-next">Next</button>
                        <button type="button" class="vc-btn vc-btn-restart" id="vc-restart">Restart</button>
                    </div>

                    <div class="vc-feedback" id="vc-feedback"></div>
                </div>

            </section>
        </div>

        <div id="vc-complete-page" class="vc-complete-page">
            <div class="vc-complete-hero">
                <div class="vc-complete-kicker">Activity</div>
                <h1 id="vc-complete-hero-title"></h1>
                <p>Watch the video and answer each question.</p>
            </div>

            <section class="vc-complete-stage">
                <div class="vc-complete-progress">
                    <div class="vc-complete-progress-label" id="vc-complete-progress-label"></div>
                    <div class="vc-complete-progress-track"><div class="vc-complete-progress-fill" id="vc-complete-progress-fill"></div></div>
                    <div class="vc-complete-progress-badge" id="vc-complete-progress-badge"></div>
                </div>

                <div id="vc-score-grid" class="vc-score-grid">
                    <div class="vc-score-card">
                        <div class="vc-score-num c" id="vc-score-correct">0</div>
                        <div class="vc-score-lbl">Correct</div>
                    </div>
                    <div class="vc-score-card">
                        <div class="vc-score-num w" id="vc-score-wrong">0</div>
                        <div class="vc-score-lbl">Wrong</div>
                    </div>
                    <div class="vc-score-card">
                        <div class="vc-score-num p" id="vc-score-pct">0%</div>
                        <div class="vc-score-lbl">Score</div>
                    </div>
                </div>

                <div id="vc-complete" class="vc-completed-screen">
                    <div class="vc-completed-icon">✅</div>
                    <h2 class="vc-completed-title" id="vc-completed-title"></h2>
                    <p class="vc-completed-text" id="vc-completed-text"></p>
                    <p class="vc-score-text" id="vc-score-text" style="font-weight:900;font-size:15px;color:#534AB7;"></p>
                    <button type="button" class="vc-restart-btn" id="vc-completed-restart">Restart</button>
                </div>
            </section>
        </div>

        <script src="../../core/_activity_feedback.js"></script>
        <script>
        (function () {
            var AF = window.ActivityFeedback;
            const data = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
            if (!AF || !Array.isArray(data) || data.length === 0) return;
            const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
            const RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
            const ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

            const countEl = document.getElementById('vc-count');
            const questionEl = document.getElementById('vc-question');
            const optionsEl = document.getElementById('vc-options');
            const feedbackEl = document.getElementById('vc-feedback');
            const checkBtn = document.getElementById('vc-check');
            const nextBtn = document.getElementById('vc-next');
            const restartBtn = document.getElementById('vc-restart');
            const activityEl = document.getElementById('vc-activity');
            const shellEl = document.getElementById('vc-quizShell');
            const completedPageEl = document.getElementById('vc-complete-page');
            const completeEl = document.getElementById('vc-complete');
            const completeHeroTitleEl = document.getElementById('vc-complete-hero-title');
            const completeProgressLabelEl = document.getElementById('vc-complete-progress-label');
            const completeProgressFillEl = document.getElementById('vc-complete-progress-fill');
            const completeProgressBadgeEl = document.getElementById('vc-complete-progress-badge');
            const completedTitleEl = document.getElementById('vc-completed-title');
            const completedTextEl = document.getElementById('vc-completed-text');
            const scoreTextEl = document.getElementById('vc-score-text');
            const completedRestartBtn = document.getElementById('vc-completed-restart');
            const scoreCorrectEl = document.getElementById('vc-score-correct');
            const scoreWrongEl = document.getElementById('vc-score-wrong');
            const scorePctEl = document.getElementById('vc-score-pct');
            const scoreGridEl = document.getElementById('vc-score-grid');

            let index = 0;
            let selectedIndex = -1;
            let checked = false;
            let answeredCurrent = false;
            let scoreVisible = false;
            let scores = data.map(function () { return null; });

            if (completedTitleEl) {
                completedTitleEl.textContent = activityTitle || 'Video Comprehension';
            }

            if (completeHeroTitleEl) {
                completeHeroTitleEl.textContent = activityTitle || 'Video Comprehension';
            }

            if (completedTextEl) {
                completedTextEl.textContent = "You've completed " + (activityTitle || 'Video Comprehension') + '. Great job practicing.';
            }

            function getCurrent() {
                return data[index] || { question: '', options: ['', '', ''], correct: 0, explanation: '' };
            }

            function computeScore() {
                var correct = 0;
                var wrong = 0;

                scores.forEach(function (value) {
                    if (value === 1) {
                        correct += 1;
                    } else if (value === 0) {
                        wrong += 1;
                    }
                });

                var total = data.length;
                var scorable = correct + wrong;
                var percent = scorable > 0 ? Math.round((correct / scorable) * 100) : 0;

                return {
                    correct: correct,
                    wrong: wrong,
                    total: total,
                    errors: wrong,
                    percent: percent
                };
            }

            function updateScoreCards(show) {
                if (typeof show === 'boolean') {
                    scoreVisible = show;
                }

                var score = computeScore();

                if (scoreCorrectEl) scoreCorrectEl.textContent = String(score.correct);
                if (scoreWrongEl) scoreWrongEl.textContent = String(score.wrong);
                if (scorePctEl) scorePctEl.textContent = score.percent + '%';
                if (scoreGridEl) scoreGridEl.classList.toggle('visible', !!scoreVisible);
            }

            function persistScoreSilently(targetUrl) {
                if (!targetUrl) return Promise.resolve(false);
                return fetch(targetUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                }).then(function (response) {
                    return !!(response && response.ok);
                }).catch(function () {
                    return false;
                });
            }

            function navigateToReturn(targetUrl) {
                if (!targetUrl) return;
                try {
                    if (window.top && window.top !== window.self) {
                        window.top.location.href = targetUrl;
                        return;
                    }
                } catch (e) {}
                window.location.href = targetUrl;
            }

            function render() {
                const current = getCurrent();
                selectedIndex = -1;
                checked = false;
                answeredCurrent = false;

                countEl.textContent = 'Question ' + (index + 1) + ' of ' + data.length;
                questionEl.textContent = current.question || 'Question';
                optionsEl.innerHTML = '';
                AF.clearFeedback(feedbackEl);

                (current.options || ['', '', '']).forEach((optionText, optionIndex) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'vc-option';
                    btn.textContent = optionText !== '' ? optionText : ('Option ' + (optionIndex + 1));
                    btn.addEventListener('click', function () {
                        if (checked) return;
                        selectedIndex = optionIndex;
                        Array.from(optionsEl.children).forEach(node => node.classList.remove('active'));
                        btn.classList.add('active');
                    });
                    optionsEl.appendChild(btn);
                });
                nextBtn.textContent = index + 1 >= data.length ? 'Finish' : 'Next';
            }

            function evaluateCurrent() {
                if (checked) return;

                const current = getCurrent();
                const correctIndex = Number(current.correct || 0);
                const optionNodes = Array.from(optionsEl.children);
                const correctAnswerText = (current.options && current.options[correctIndex]) ? current.options[correctIndex] : '';
                const isCorrect = selectedIndex >= 0 && selectedIndex === correctIndex;

                checked = true;
                answeredCurrent = true;
                AF.clearHighlights(optionsEl);
                AF.highlightOption(optionNodes[correctIndex], 'correct');

                if (selectedIndex < 0) {
                    scores[index] = 0;
                    updateScoreCards(true);
                    AF.showFeedback(feedbackEl, false, correctAnswerText, false);
                    return;
                }

                if (!isCorrect) {
                    AF.highlightOption(optionNodes[selectedIndex], 'wrong');
                }

                scores[index] = isCorrect ? 1 : 0;
                updateScoreCards(true);
                AF.showFeedback(feedbackEl, isCorrect, correctAnswerText, false);
            }

            async function showCompletion() {
                if (shellEl) shellEl.style.display = 'none';
                if (completeEl) {
                    completeEl.classList.add('active');
                }
                if (completedPageEl) {
                    completedPageEl.classList.add('active');
                }
                if (activityEl) {
                    activityEl.classList.add('is-hidden');
                }

                completeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

                const score = computeScore();
                const pct = score.percent;
                const errors = score.wrong;
                const total = score.total;

                if (completeProgressLabelEl) {
                    completeProgressLabelEl.textContent = total + ' / ' + total;
                }
                if (completeProgressBadgeEl) {
                    completeProgressBadgeEl.textContent = 'Q ' + total + ' of ' + total;
                }
                if (completeProgressFillEl) {
                    completeProgressFillEl.style.width = '100%';
                }

                updateScoreCards(true);

                if (scoreTextEl) {
                    scoreTextEl.textContent = score.correct + ' correct · ' + errors + ' wrong · ' + pct + '%';
                }

                if (RETURN_TO && ACTIVITY_ID) {
                    const joiner = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
                    const saveUrl = RETURN_TO
                        + joiner + 'activity_percent=' + pct
                        + '&activity_errors=' + errors
                        + '&activity_total=' + total
                        + '&activity_id=' + encodeURIComponent(ACTIVITY_ID)
                        + '&activity_type=video_comprehension';

                    const ok = await persistScoreSilently(saveUrl);
                    if (!ok) {
                        navigateToReturn(saveUrl);
                    }
                }
            }

            function restartQuiz() {
                index = 0;
                selectedIndex = -1;
                checked = false;
                answeredCurrent = false;
                scoreVisible = false;
                scores = data.map(function () { return null; });

                if (scoreGridEl) scoreGridEl.classList.remove('visible');
                if (shellEl) shellEl.style.display = '';
                if (completeEl) completeEl.classList.remove('active');
                if (completedPageEl) completedPageEl.classList.remove('active');
                if (activityEl) activityEl.classList.remove('is-hidden');

                render();
            }

            checkBtn.addEventListener('click', function () {
                evaluateCurrent();
            });

            nextBtn.addEventListener('click', async function () {
                if (!answeredCurrent) {
                    if (feedbackEl) {
                        feedbackEl.textContent = 'Select an option first.';
                        feedbackEl.className = 'vc-feedback error';
                    }
                    return;
                }

                if (index + 1 >= data.length) {
                    await showCompletion();
                    return;
                }

                index += 1;
                render();
            });

            restartBtn.addEventListener('click', restartQuiz);

            if (completedRestartBtn) {
                completedRestartBtn.addEventListener('click', restartQuiz);
            }

            updateScoreCards(false);
            render();
        })();
        </script>
    <?php } ?>
</div>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎬', $content);

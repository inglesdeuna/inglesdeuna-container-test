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
    $iframeUrl = trim((string) ($decoded['iframe_url'] ?? ''));
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

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

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
.completed-screen{display:none;text-align:center;max-width:600px;margin:0 auto;padding:40px 20px}
.completed-screen.active{display:block}
.completed-icon{font-size:80px;margin-bottom:20px}
.completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:36px;font-weight:700;color:var(--vc-orange);margin:0 0 16px;line-height:1.2}
.completed-text{font-size:16px;color:var(--vc-muted);line-height:1.6;margin:0 0 32px;font-weight:800}
.completed-button{display:inline-block;padding:12px 24px;border:none;border-radius:999px;background:var(--vc-orange);color:#fff;font-weight:900;font-size:16px;cursor:pointer;box-shadow:0 6px 18px rgba(249,115,22,.22);transition:transform .18s ease,filter .18s ease}
.completed-button:hover{transform:scale(1.05);filter:brightness(1.07)}
@media (max-width:860px){.vc-intro{padding:20px 18px}.vc-intro h2{font-size:26px}.vc-question{font-size:18px}}

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

                    <div class="vc-feedback" id="vc-feedback">Select an option to begin.</div>
                </div>
            </section>
        </div>

        <div id="vc-complete" class="completed-screen">
            <div class="completed-icon">✅</div>
            <h2 class="completed-title" id="vc-completed-title"></h2>
            <p class="completed-text" id="vc-completed-text"></p>
            <p class="completed-text" id="vc-score-text" style="font-weight:800;font-size:20px;color:#534AB7;"></p>
            <button type="button" class="completed-button" id="vc-completed-restart">Restart</button>
        </div>

        <script>
        (function () {
            const data = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
            if (!Array.isArray(data) || data.length === 0) return;
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
            const completeEl = document.getElementById('vc-complete');
            const activityEl = document.getElementById('vc-activity');
            const shellEl = document.getElementById('vc-quizShell');
            const completedTitleEl = document.getElementById('vc-completed-title');
            const completedTextEl = document.getElementById('vc-completed-text');
            const scoreTextEl = document.getElementById('vc-score-text');
            const completedRestartBtn = document.getElementById('vc-completed-restart');

            let index = 0;
            let selectedIndex = -1;
            let checked = false;
            let results = Array(data.length).fill(null);

            if (completedTitleEl) {
                completedTitleEl.textContent = activityTitle || 'Video Comprehension';
            }

            if (completedTextEl) {
                completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
            }

            function getCurrent() {
                return data[index] || { question: '', options: ['', '', ''], correct: 0, explanation: '' };
            }

            function setFeedback(message, kind) {
                feedbackEl.textContent = message;
                feedbackEl.classList.remove('success', 'error');
                if (kind === 'success') feedbackEl.classList.add('success');
                if (kind === 'error') feedbackEl.classList.add('error');
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

                countEl.textContent = `Question ${index + 1} of ${data.length}`;
                questionEl.textContent = current.question || 'Question';
                optionsEl.innerHTML = '';

                (current.options || ['', '', '']).forEach((optionText, optionIndex) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'vc-option';
                    btn.textContent = optionText !== '' ? optionText : `Option ${optionIndex + 1}`;
                    btn.addEventListener('click', function () {
                        if (checked) return;
                        selectedIndex = optionIndex;
                        Array.from(optionsEl.children).forEach(node => node.classList.remove('active'));
                        btn.classList.add('active');
                        setFeedback('Answer selected. Press Check Answer.', '');
                    });
                    optionsEl.appendChild(btn);
                });

                setFeedback('Select an option to begin.', '');
            }

            function evaluateCurrent() {
                if (checked) return;

                const current = getCurrent();
                const correctIndex = Number(current.correct || 0);
                const optionNodes = Array.from(optionsEl.children);

                checked = true;

                if (selectedIndex < 0) {
                    optionNodes.forEach(function (node, nodeIndex) {
                        if (nodeIndex === correctIndex) {
                            node.classList.add('correct');
                        }
                    });
                    if (results[index] === null) {
                        results[index] = false;
                    }
                    setFeedback('Incorrect. ' + (current.explanation || ''), 'error');
                    return;
                }

                optionNodes.forEach(function (node, nodeIndex) {
                    node.classList.remove('active');
                    if (nodeIndex === correctIndex) {
                        node.classList.add('correct');
                    } else if (nodeIndex === selectedIndex) {
                        node.classList.add('wrong');
                    }
                });

                if (selectedIndex === correctIndex) {
                    if (results[index] === null) {
                        results[index] = true;
                    }
                    setFeedback('Correct! ' + (current.explanation || ''), 'success');
                } else {
                    if (results[index] === null) {
                        results[index] = false;
                    }
                    setFeedback('Incorrect. ' + (current.explanation || ''), 'error');
                }
            }

            async function showCompletion() {
                if (activityEl) activityEl.classList.add('is-hidden');
                completeEl.classList.add('active');
                completeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

                const score = results.filter(function (r) { return r === true; }).length;
                const total = data.length;
                const pct = total > 0 ? Math.round((score / total) * 100) : 0;
                const errors = Math.max(0, total - score);

                if (scoreTextEl) {
                    scoreTextEl.textContent = 'Score: ' + score + ' / ' + total + ' (' + pct + '%)';
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
                results = Array(data.length).fill(null);
                shellEl.style.display = 'block';
                if (activityEl) activityEl.classList.remove('is-hidden');
                completeEl.classList.remove('active');
                render();
            }

            checkBtn.addEventListener('click', function () {
                evaluateCurrent();
            });

            nextBtn.addEventListener('click', async function () {
                if (!checked) {
                    evaluateCurrent();
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

            render();
        })();
        </script>
    <?php } ?>
</div>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎬', $content);

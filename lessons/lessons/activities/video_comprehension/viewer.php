<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

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

ob_start();
?>

<style>
.vc-viewer{max-width:1180px;margin:0 auto}
.vc-intro{margin-bottom:16px;padding:24px 26px;border-radius:26px;border:1px solid #dbeafe;background:linear-gradient(135deg,#eff6ff 0%,#f5f3ff 45%,#fff7ed 100%);box-shadow:0 16px 34px rgba(15,23,42,.08)}
.vc-intro h2{margin:0 0 8px;color:#1d4ed8;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:30px;line-height:1.1}
.vc-intro p{margin:0;color:#475569;font-size:15px;line-height:1.55}
.vc-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(340px,.9fr);gap:16px;align-items:stretch}
.vc-panel{background:#fff;border:1px solid #dbeafe;border-radius:22px;box-shadow:0 12px 26px rgba(15,23,42,.08);overflow:hidden}
.vc-video-only{background:#fff;border:1px solid #dbeafe;border-radius:22px;box-shadow:0 12px 26px rgba(15,23,42,.08);overflow:hidden}
.vc-video-wrap{padding:16px;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%)}
.vc-video{width:100%;aspect-ratio:16/9;border:none;border-radius:14px;background:#000}
.vc-video-copy{padding:16px 18px 18px;border-top:1px solid #e2e8f0;background:linear-gradient(180deg,#fffdf9 0%,#ffffff 100%)}
.vc-video-copy h3{margin:0 0 8px;color:#0f172a;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px}
.vc-video-copy p{margin:0;color:#475569;line-height:1.6;font-size:15px}
.vc-panel-header{padding:14px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc}
.vc-panel-header strong{color:#0f172a;font-size:15px}
.vc-questions{padding:14px 16px}
.vc-question-count{font-size:13px;font-weight:800;color:#64748b;margin-bottom:8px}
.vc-question{font-size:20px;line-height:1.35;color:#0f172a;font-weight:800;margin-bottom:12px;font-family:'Fredoka','Trebuchet MS',sans-serif}
.vc-options{display:grid;gap:8px}
.vc-option{border:1px solid #cbd5e1;background:#fff;border-radius:12px;padding:10px 12px;text-align:left;cursor:pointer;font-weight:700;color:#1f2937;transition:all .15s ease}
.vc-option:hover{border-color:#93c5fd;background:#eff6ff}
.vc-option.active{border-color:#2563eb;background:#dbeafe;color:#1e3a8a}
.vc-option.correct{border-color:#16a34a;background:#dcfce7;color:#166534}
.vc-option.wrong{border-color:#dc2626;background:#fee2e2;color:#991b1b}
.vc-controls{display:flex;gap:10px;flex-wrap:wrap;padding:0 16px 14px}
.vc-btn{border:none;border-radius:999px;padding:10px 14px;font-weight:800;font-size:13px;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,.11);transition:transform .15s ease,filter .15s ease}
.vc-btn:hover{filter:brightness(1.05);transform:translateY(-1px)}
.vc-btn-check{background:linear-gradient(180deg,#3b82f6,#1d4ed8);color:#fff}
.vc-btn-next{background:linear-gradient(180deg,#34d399,#059669);color:#fff}
.vc-btn-restart{background:linear-gradient(180deg,#fbbf24,#f59e0b);color:#7c2d12}
.vc-feedback{min-height:46px;margin:0 16px 16px;padding:10px 12px;border-radius:12px;font-weight:800;font-size:14px;display:flex;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;color:#475569}
.vc-feedback.success{background:#ecfdf5;border-color:#86efac;color:#166534}
.vc-feedback.error{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.vc-empty{padding:26px;text-align:center;font-weight:800;color:#b91c1c}
.vc-activity.is-hidden{display:none}
.completed-screen{display:none;text-align:center;max-width:600px;margin:0 auto;padding:40px 20px}
.completed-screen.active{display:block}
.completed-icon{font-size:80px;margin-bottom:20px}
.completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:36px;font-weight:700;color:#1d4ed8;margin:0 0 16px;line-height:1.2}
.completed-text{font-size:16px;color:#475569;line-height:1.6;margin:0 0 32px}
.completed-button{display:inline-block;padding:12px 24px;border:none;border-radius:999px;background:linear-gradient(180deg,#3b82f6,#1d4ed8);color:#fff;font-weight:700;font-size:16px;cursor:pointer;box-shadow:0 10px 24px rgba(0,0,0,.14);transition:transform .18s ease,filter .18s ease}
.completed-button:hover{transform:scale(1.05);filter:brightness(1.07)}
@media (max-width:1040px){.vc-layout{grid-template-columns:1fr}.vc-intro{padding:20px 18px}.vc-intro h2{font-size:26px}.vc-question{font-size:18px}}
</style>

<div class="vc-viewer" id="vc-app">
    <section class="vc-intro">
        <h2>Video Comprehension</h2>
        <p><?= htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <?php
    $hasVideo = $iframeUrl !== '';
    $isVideoOnly = $hasVideo && $activityMode === 'video_only';
    $isEmptyQuiz = $hasVideo && !$isVideoOnly && empty($questions);
    $hasQuiz = $hasVideo && !$isVideoOnly && !empty($questions);
    ?>

    <?php if (!$hasVideo) { ?>
        <div class="vc-panel">
            <div class="vc-empty">No video iframe URL configured for this activity.</div>
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
        <div class="vc-layout">
            <section class="vc-panel">
                <div class="vc-video-wrap">
                    <iframe class="vc-video" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" title="Video comprehension" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                </div>
            </section>
            <section class="vc-panel">
                <div class="vc-empty">No questions configured yet.</div>
            </section>
        </div>
    <?php } ?>

    <?php if ($hasQuiz) { ?>
        <div class="vc-layout vc-activity" id="vc-activity">
            <section class="vc-panel">
                <div class="vc-video-wrap">
                    <iframe class="vc-video" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" title="Video comprehension" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                </div>
            </section>

            <section class="vc-panel">
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
            <button type="button" class="completed-button" id="vc-completed-restart">Restart</button>
        </div>

        <script>
        (function () {
            const data = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
            if (!Array.isArray(data) || data.length === 0) return;
            const activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;

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
            const completedRestartBtn = document.getElementById('vc-completed-restart');

            let index = 0;
            let selectedIndex = -1;
            let score = 0;
            let checked = false;

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

            function showCompletion() {
                if (activityEl) activityEl.classList.add('is-hidden');
                completeEl.classList.add('active');
                completeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            function restartQuiz() {
                index = 0;
                score = 0;
                selectedIndex = -1;
                checked = false;
                shellEl.style.display = 'block';
                if (activityEl) activityEl.classList.remove('is-hidden');
                completeEl.classList.remove('active');
                render();
            }

            checkBtn.addEventListener('click', function () {
                if (checked) return;
                if (selectedIndex < 0) {
                    setFeedback('Please choose an answer first.', 'error');
                    return;
                }

                checked = true;
                const current = getCurrent();
                const correctIndex = Number(current.correct || 0);
                const optionNodes = Array.from(optionsEl.children);

                optionNodes.forEach(function (node, nodeIndex) {
                    node.classList.remove('active');
                    if (nodeIndex === correctIndex) {
                        node.classList.add('correct');
                    } else if (nodeIndex === selectedIndex) {
                        node.classList.add('wrong');
                    }
                });

                if (selectedIndex === correctIndex) {
                    score += 1;
                    setFeedback('Correct! ' + (current.explanation || ''), 'success');
                } else {
                    setFeedback('Try again. ' + (current.explanation || ''), 'error');
                }
            });

            nextBtn.addEventListener('click', function () {
                if (!checked) {
                    setFeedback('Check your answer before going to the next question.', 'error');
                    return;
                }

                if (index + 1 >= data.length) {
                    showCompletion();
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

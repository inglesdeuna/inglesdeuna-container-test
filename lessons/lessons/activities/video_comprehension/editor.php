<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

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

    if (!preg_match('/^https?:\/\//i', $url)) {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }

    $host = strtolower((string) $parts['host']);
    $path = isset($parts['path']) ? (string) $parts['path'] : '';

    if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
        $videoId = '';

        if (strpos($host, 'youtu.be') !== false) {
            $videoId = trim($path, '/');
        } elseif (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $queryParams);
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
            'id' => isset($item['id']) && trim((string) $item['id']) !== '' ? trim((string) $item['id']) : uniqid('vc_'),
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

function encode_video_comprehension_payload(array $payload): string
{
    return json_encode([
        'title' => trim((string) ($payload['title'] ?? '')) !== '' ? trim((string) $payload['title']) : default_video_comprehension_title(),
        'mode' => (string) ($payload['mode'] ?? '') === 'video_only' ? 'video_only' : 'quiz',
        'iframe_url' => normalize_embed_url((string) ($payload['iframe_url'] ?? '')),
        'instructions' => trim((string) ($payload['instructions'] ?? '')),
        'questions' => isset($payload['questions']) && is_array($payload['questions']) ? array_values($payload['questions']) : [],
    ], JSON_UNESCAPED_UNICODE);
}

function load_video_comprehension_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id' => '',
        'title' => default_video_comprehension_title(),
        'mode' => 'quiz',
        'iframe_url' => '',
        'instructions' => 'Watch the video and answer each question.',
        'questions' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("\n            SELECT id, data\n            FROM activities\n            WHERE id = :id\n              AND type = 'video_comprehension'\n            LIMIT 1\n        ");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("\n            SELECT id, data\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'video_comprehension'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_video_comprehension_payload($row['data'] ?? null);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? default_video_comprehension_title()),
        'mode' => (string) ($payload['mode'] ?? 'quiz'),
        'iframe_url' => (string) ($payload['iframe_url'] ?? ''),
        'instructions' => (string) ($payload['instructions'] ?? ''),
        'questions' => isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : [],
    ];
}

function save_video_comprehension_activity(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json = encode_video_comprehension_payload($payload);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("\n            SELECT id\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'video_comprehension'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("\n            UPDATE activities\n            SET data = :data\n            WHERE id = :id\n              AND type = 'video_comprehension'\n        ");
        $stmt->execute([
            'data' => $json,
            'id' => $targetId,
        ]);

        return $targetId;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO activities (unit_id, type, data, position, created_at)\n        VALUES (\n            :unit_id,\n            'video_comprehension',\n            :data,\n            (\n                SELECT COALESCE(MAX(position), 0) + 1\n                FROM activities\n                WHERE unit_id = :unit_id2\n            ),\n            CURRENT_TIMESTAMP\n        )\n        RETURNING id\n    ");
    $stmt->execute([
        'unit_id' => $unit,
        'unit_id2' => $unit,
        'data' => $json,
    ]);

    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_video_comprehension_activity($pdo, $unit, $activityId);
$activityTitle = (string) ($activity['title'] ?? default_video_comprehension_title());
$activityMode = (string) ($activity['mode'] ?? 'quiz');
$iframeUrl = (string) ($activity['iframe_url'] ?? '');
$instructions = (string) ($activity['instructions'] ?? 'Watch the video and answer each question.');
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : [];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string) ($_POST['activity_title'] ?? ''));
    $postedMode = (string) ($_POST['activity_mode'] ?? 'quiz') === 'video_only' ? 'video_only' : 'quiz';
    $postedIframeUrl = normalize_embed_url((string) ($_POST['iframe_url'] ?? ''));
    $postedInstructions = trim((string) ($_POST['instructions'] ?? ''));

    $questionIds = isset($_POST['question_id']) && is_array($_POST['question_id']) ? $_POST['question_id'] : [];
    $questionTexts = isset($_POST['question']) && is_array($_POST['question']) ? $_POST['question'] : [];
    $optionA = isset($_POST['option_a']) && is_array($_POST['option_a']) ? $_POST['option_a'] : [];
    $optionB = isset($_POST['option_b']) && is_array($_POST['option_b']) ? $_POST['option_b'] : [];
    $optionC = isset($_POST['option_c']) && is_array($_POST['option_c']) ? $_POST['option_c'] : [];
    $corrects = isset($_POST['correct']) && is_array($_POST['correct']) ? $_POST['correct'] : [];
    $explanations = isset($_POST['explanation']) && is_array($_POST['explanation']) ? $_POST['explanation'] : [];

    $sanitizedQuestions = [];

    foreach ($questionTexts as $index => $questionRaw) {
        $questionText = trim((string) $questionRaw);
        $a = trim((string) ($optionA[$index] ?? ''));
        $b = trim((string) ($optionB[$index] ?? ''));
        $c = trim((string) ($optionC[$index] ?? ''));
        $explanation = trim((string) ($explanations[$index] ?? ''));
        $correct = max(0, min(2, (int) ($corrects[$index] ?? 0)));
        $qid = isset($questionIds[$index]) && trim((string) $questionIds[$index]) !== ''
            ? trim((string) $questionIds[$index])
            : uniqid('vc_');

        if ($questionText === '' && $a === '' && $b === '' && $c === '' && $explanation === '') {
            continue;
        }

        $sanitizedQuestions[] = [
            'id' => $qid,
            'question' => $questionText,
            'options' => [$a, $b, $c],
            'correct' => $correct,
            'explanation' => $explanation,
        ];
    }

    $savedActivityId = save_video_comprehension_activity($pdo, $unit, $activityId, [
        'title' => $postedTitle,
        'mode' => $postedMode,
        'iframe_url' => $postedIframeUrl,
        'instructions' => $postedInstructions,
        'questions' => $sanitizedQuestions,
    ]);

    $params = [
        'unit=' . urlencode($unit),
        'saved=1',
    ];

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
    } elseif ($activityId !== '') {
        $params[] = 'id=' . urlencode($activityId);
    }

    if ($assignment !== '') {
        $params[] = 'assignment=' . urlencode($assignment);
    }

    if ($source !== '') {
        $params[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $params));
    exit;
}

ob_start();
?>

<style>
.vc-form{max-width:980px;margin:0 auto;text-align:left}
.vc-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 10px 24px rgba(15,23,42,.07);overflow:hidden}
.vc-hero{padding:20px 22px;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#eff6ff 0%,#f5f3ff 45%,#fff7ed 100%)}
.vc-hero h2{margin:0 0 6px;color:#1d4ed8;font-size:27px;font-weight:800}
.vc-hero p{margin:0;color:#475569;font-size:14px}
.vc-body{padding:20px}
.vc-group{margin-bottom:14px}
.vc-group label{display:block;font-weight:700;margin-bottom:6px;color:#1f2937}
.vc-input,.vc-textarea,.vc-select{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff}
.vc-textarea{min-height:72px;resize:vertical}
.vc-help{font-size:12px;color:#64748b;margin-top:6px}
.vc-preview-wrap{margin-bottom:18px}
.vc-preview{width:100%;aspect-ratio:16/9;border:1px solid #dbeafe;border-radius:14px;background:#000}
.vc-mode-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.vc-mode-card{border:1px solid #cbd5e1;border-radius:14px;padding:12px;background:#fff;cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease,background .15s ease}
.vc-mode-card:has(input:checked){border-color:#2563eb;background:#eff6ff;box-shadow:0 8px 18px rgba(37,99,235,.12)}
.vc-mode-card input{margin-right:8px}
.vc-mode-title{font-weight:800;color:#0f172a}
.vc-mode-text{display:block;margin-top:6px;font-size:12px;color:#64748b;line-height:1.45}
.vc-question{background:#f8fafc;border:1px solid #dbe5f3;border-radius:14px;padding:14px;margin-bottom:12px}
.vc-question-block.is-hidden{display:none}
.vc-add-wrap.is-hidden{display:none}
.vc-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:10px}
.vc-btn{border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer}
.vc-add{background:#2563eb;color:#fff}
.vc-remove{background:#ef4444;color:#fff}
@media (max-width:760px){.vc-body{padding:14px}.vc-hero h2{font-size:23px}.vc-mode-row{grid-template-columns:1fr}}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:#166534;font-weight:800;margin-bottom:12px;">✔ Saved successfully</p>
<?php } ?>

<form class="vc-form" id="videoComprehensionForm" method="post">
    <div class="vc-card">
        <div class="vc-hero">
            <h2>Video Comprehension Editor</h2>
            <p>Create a video-based comprehension activity with multiple choice questions.</p>
        </div>

        <div class="vc-body">
            <div class="vc-group">
                <label for="activity_title">Activity title</label>
                <input id="activity_title" class="vc-input" type="text" name="activity_title" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="vc-group">
                <label>Display mode</label>
                <div class="vc-mode-row">
                    <label class="vc-mode-card">
                        <input type="radio" name="activity_mode" value="quiz" <?= $activityMode !== 'video_only' ? 'checked' : '' ?>>
                        <span class="vc-mode-title">Video + Multiple Choice</span>
                        <span class="vc-mode-text">Show the video with comprehension questions and scoring.</span>
                    </label>
                    <label class="vc-mode-card">
                        <input type="radio" name="activity_mode" value="video_only" <?= $activityMode === 'video_only' ? 'checked' : '' ?>>
                        <span class="vc-mode-title">Video Only</span>
                        <span class="vc-mode-text">Show only the embedded video and instructions, without questions.</span>
                    </label>
                </div>
            </div>

            <div class="vc-group">
                <label for="iframe_url">Video iframe URL</label>
                <input id="iframe_url" class="vc-input" type="url" name="iframe_url" value="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://www.youtube.com/watch?v=..." required>
                <div class="vc-help">You can paste a YouTube/Vimeo link and it will be normalized to embed format.</div>
            </div>

            <div class="vc-group">
                <label for="instructions">Instructions</label>
                <textarea id="instructions" class="vc-textarea" name="instructions" placeholder="Watch the video and answer each question."><?= htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="vc-preview-wrap">
                <iframe class="vc-preview" id="videoPreview" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8') ?>" title="Video preview" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
            </div>

            <div class="vc-question-block<?= $activityMode === 'video_only' ? ' is-hidden' : '' ?>" id="questionsBlock">
            <div id="questionsContainer">
                <?php foreach ($questions as $question) { ?>
                    <div class="vc-question">
                        <input type="hidden" name="question_id[]" value="<?= htmlspecialchars((string) ($question['id'] ?? uniqid('vc_')), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="vc-group">
                            <label>Question</label>
                            <textarea class="vc-textarea" name="question[]" required><?= htmlspecialchars((string) ($question['question'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <div class="vc-group">
                            <label>Option A</label>
                            <input class="vc-input" type="text" name="option_a[]" value="<?= htmlspecialchars((string) (($question['options'][0] ?? '')), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="vc-group">
                            <label>Option B</label>
                            <input class="vc-input" type="text" name="option_b[]" value="<?= htmlspecialchars((string) (($question['options'][1] ?? '')), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="vc-group">
                            <label>Option C</label>
                            <input class="vc-input" type="text" name="option_c[]" value="<?= htmlspecialchars((string) (($question['options'][2] ?? '')), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="vc-group">
                            <label>Correct answer</label>
                            <select class="vc-select" name="correct[]">
                                <option value="0" <?= ((int) ($question['correct'] ?? 0) === 0) ? 'selected' : '' ?>>Option A</option>
                                <option value="1" <?= ((int) ($question['correct'] ?? 0) === 1) ? 'selected' : '' ?>>Option B</option>
                                <option value="2" <?= ((int) ($question['correct'] ?? 0) === 2) ? 'selected' : '' ?>>Option C</option>
                            </select>
                        </div>

                        <div class="vc-group">
                            <label>Feedback (optional)</label>
                            <textarea class="vc-textarea" name="explanation[]" placeholder="Short explanation for the answer"><?= htmlspecialchars((string) ($question['explanation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <button type="button" class="vc-btn vc-remove" onclick="removeQuestion(this)">Remove question</button>
                    </div>
                <?php } ?>
            </div>
            </div>

            <div class="vc-actions">
                <div id="addQuestionWrap" class="vc-add-wrap<?= $activityMode === 'video_only' ? ' is-hidden' : '' ?>">
                    <button type="button" class="vc-btn vc-add" onclick="addQuestion()">+ Add Question</button>
                </div>
                <button type="submit" class="save-btn">💾 Save</button>
            </div>
        </div>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function updateVideoPreview() {
    const input = document.getElementById('iframe_url');
    const preview = document.getElementById('videoPreview');
    if (!input || !preview) return;
    preview.src = input.value.trim();
}

function syncModeVisibility() {
    const selectedMode = document.querySelector('input[name="activity_mode"]:checked');
    const questionsBlock = document.getElementById('questionsBlock');
    const addWrap = document.getElementById('addQuestionWrap');
    const isVideoOnly = Boolean(selectedMode && selectedMode.value === 'video_only');
    if (!questionsBlock) return;
    questionsBlock.classList.toggle('is-hidden', isVideoOnly);
    if (addWrap) {
        addWrap.classList.toggle('is-hidden', isVideoOnly);
    }

    if (!isVideoOnly) {
        const container = document.getElementById('questionsContainer');
        if (container && container.children.length === 0) {
            addQuestion();
        }
    }
}

function removeQuestion(button) {
    const card = button.closest('.vc-question');
    if (card) {
        card.remove();
        markChanged();
    }
}

function addQuestion() {
    const container = document.getElementById('questionsContainer');
    const card = document.createElement('div');
    card.className = 'vc-question';
    card.innerHTML = `
        <input type="hidden" name="question_id[]" value="vc_${Date.now()}_${Math.floor(Math.random() * 1000)}">

        <div class="vc-group">
            <label>Question</label>
            <textarea class="vc-textarea" name="question[]" required></textarea>
        </div>

        <div class="vc-group">
            <label>Option A</label>
            <input class="vc-input" type="text" name="option_a[]" required>
        </div>

        <div class="vc-group">
            <label>Option B</label>
            <input class="vc-input" type="text" name="option_b[]" required>
        </div>

        <div class="vc-group">
            <label>Option C</label>
            <input class="vc-input" type="text" name="option_c[]" required>
        </div>

        <div class="vc-group">
            <label>Correct answer</label>
            <select class="vc-select" name="correct[]">
                <option value="0">Option A</option>
                <option value="1">Option B</option>
                <option value="2">Option C</option>
            </select>
        </div>

        <div class="vc-group">
            <label>Feedback (optional)</label>
            <textarea class="vc-textarea" name="explanation[]" placeholder="Short explanation for the answer"></textarea>
        </div>

        <button type="button" class="vc-btn vc-remove" onclick="removeQuestion(this)">Remove question</button>
    `;

    container.appendChild(card);
    bindChangeTracking(card);
    markChanged();
}

function bindChangeTracking(scope) {
    const elements = scope.querySelectorAll('input, textarea, select');
    elements.forEach(function (el) {
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindChangeTracking(document);
    syncModeVisibility();

    const urlInput = document.getElementById('iframe_url');
    if (urlInput) {
        urlInput.addEventListener('input', updateVideoPreview);
        urlInput.addEventListener('change', updateVideoPreview);
    }

    document.querySelectorAll('input[name="activity_mode"]').forEach(function (input) {
        input.addEventListener('change', function () {
            syncModeVisibility();
            markChanged();
        });
    });

    const form = document.getElementById('videoComprehensionForm');
    if (form) {
        form.addEventListener('submit', function () {
            formSubmitted = true;
            formChanged = false;
        });
    }
});

window.addEventListener('beforeunload', function (event) {
    if (formChanged && !formSubmitted) {
        event.preventDefault();
        event.returnValue = '';
    }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor('🎬 Video Comprehension Editor', '🎬', $content);

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

/* ──────────────────────────────────────────────────────────────
   DATA LAYER
────────────────────────────────────────────────────────────── */

function wp_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && isset($row['unit_id'])) ? (string) $row['unit_id'] : '';
}

function wp_default_payload(): array
{
    return [
        'title'       => 'Writing Practice',
        'description' => 'Read each prompt carefully and write your response.',
        'questions'   => [],
    ];
}

function wp_normalize_payload($rawData): array
{
    $default = wp_default_payload();

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $allowedTypes = ['writing', 'listen_write', 'fill_sentence', 'fill_paragraph', 'video_writing'];
    $questions    = [];

    foreach ((array) ($decoded['questions'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = in_array($item['type'] ?? '', $allowedTypes, true) ? (string) $item['type'] : 'writing';

        // Normalise correct_answers – accept array or newline-separated string
        $rawAnswers     = $item['correct_answers'] ?? [];
        $correctAnswers = [];
        if (is_array($rawAnswers)) {
            foreach ($rawAnswers as $ans) {
                $a = trim((string) $ans);
                if ($a !== '') {
                    $correctAnswers[] = $a;
                }
            }
        } elseif (is_string($rawAnswers) && $rawAnswers !== '') {
            $correctAnswers = array_values(array_filter(array_map('trim', explode("\n", $rawAnswers))));
        }

        $questions[] = [
            'id'              => trim((string) ($item['id'] ?? uniqid('wp_'))),
            'type'            => $type,
            'question'        => trim((string) ($item['question']    ?? '')),
            'instruction'     => trim((string) ($item['instruction'] ?? '')),
            'placeholder'     => trim((string) ($item['placeholder'] ?? '')),
            'media'           => trim((string) ($item['media']       ?? '')),
            'correct_answers' => $correctAnswers,
            'points'          => max(1, (int) ($item['points'] ?? 10)),
        ];
    }

    return [
        'title'       => trim((string) ($decoded['title']       ?? '')) ?: $default['title'],
        'description' => trim((string) ($decoded['description'] ?? '')) ?: $default['description'],
        'questions'   => $questions,
    ];
}

function wp_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = ['id' => '', 'payload' => wp_default_payload()];
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

    return [
        'id'      => (string) ($row['id'] ?? ''),
        'payload' => wp_normalize_payload($row['data'] ?? null),
    ];
}

function wp_save_activity(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json     = json_encode(wp_normalize_payload($payload), JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'writing_practice'");
        $stmt->execute(['data' => $json, 'id' => $targetId]);
        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (
            :unit_id,
            'writing_practice',
            :data,
            (SELECT COALESCE(MAX(position), 0) + 1 FROM activities WHERE unit_id = :unit_id2),
            CURRENT_TIMESTAMP
        )
        RETURNING id
    ");
    $stmt->execute(['unit_id' => $unit, 'unit_id2' => $unit, 'data' => $json]);
    return (string) $stmt->fetchColumn();
}

/* ──────────────────────────────────────────────────────────────
   BOOTSTRAP
────────────────────────────────────────────────────────────── */

if ($unit === '' && $activityId !== '') {
    $unit = wp_resolve_unit($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$loaded     = wp_load_activity($pdo, $unit, $activityId);
$payload    = $loaded['payload'];
if ($activityId === '' && !empty($loaded['id'])) {
    $activityId = (string) $loaded['id'];
}

/* ──────────────────────────────────────────────────────────────
   HANDLE POST
────────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim((string) ($_POST['wp_title']       ?? ''));
    $description = trim((string) ($_POST['wp_description'] ?? ''));
    $questionsJson = trim((string) ($_POST['questions_json'] ?? '[]'));

    $decodedQuestions = json_decode($questionsJson, true);
    if (!is_array($decodedQuestions)) {
        $decodedQuestions = [];
    }

    $savedId = wp_save_activity($pdo, $unit, $activityId, [
        'title'       => $title,
        'description' => $description,
        'questions'   => $decodedQuestions,
    ]);

    $params = ['unit=' . urlencode($unit), 'saved=1'];
    if ($savedId !== '')    $params[] = 'id='         . urlencode($savedId);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source !== '')     $params[] = 'source='     . urlencode($source);

    header('Location: editor.php?' . implode('&', $params));
    exit;
}

$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

/* ──────────────────────────────────────────────────────────────
   VIEW
────────────────────────────────────────────────────────────── */

ob_start();
?>
<style>
/* ── Writing Practice Editor ─────────────────────────────── */
.wp-editor { display: flex; flex-direction: column; gap: 14px; }

.wp-field label {
    display: block;
    font-weight: 700;
    margin-bottom: 6px;
    color: #0f172a;
}

.wp-field input,
.wp-field textarea,
.wp-field select {
    width: 100%;
    border: 1px solid #d6e4ff;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
    transition: border-color .15s;
}

.wp-field input:focus,
.wp-field textarea:focus,
.wp-field select:focus {
    outline: none;
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, .1);
}

.wp-field textarea { min-height: 80px; resize: vertical; }

.wp-card {
    border: 1px solid #dbeafe;
    border-radius: 14px;
    padding: 14px 16px;
    background: #f8fbff;
    margin-bottom: 6px;
    box-shadow: 0 4px 10px rgba(37, 99, 235, .06);
}

.wp-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.wp-type-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 800;
    background: #eddeff;
    color: #7c3aed;
    margin-left: 8px;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.wp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 10px;
    padding: 10px 14px;
    font-weight: 700;
    cursor: pointer;
    font-size: 14px;
    font-family: inherit;
    transition: filter .15s, transform .15s;
}

.wp-btn:hover { filter: brightness(1.07); transform: translateY(-1px); }

.wp-btn-add  { background: linear-gradient(180deg, #3d73ee, #2563eb); color: #fff; }
.wp-btn-remove {
    background: #fee2e2;
    color: #b91c1c;
    padding: 7px 10px;
    font-size: 13px;
}

.wp-btn-save {
    background: linear-gradient(180deg, #3d73ee, #2563eb);
    color: #fff;
    padding: 12px 24px;
    font-size: 15px;
    box-shadow: 0 8px 18px rgba(37, 99, 235, .22);
}

.wp-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.wp-grid-3 {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 10px;
}

.wp-hint {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.5;
}

.wp-section-sep {
    border: none;
    border-top: 1px dashed #c7d2fe;
    margin: 10px 0 8px;
}

.wp-media-row { display: none; }

@media (max-width: 768px) {
    .wp-grid, .wp-grid-3 { grid-template-columns: 1fr; }
}
</style>

<div class="wp-editor">
    <?php if ($saved): ?>
        <div class="alert alert-success" style="border-radius:10px;">
            ✔ Writing Practice saved successfully.
        </div>
    <?php endif; ?>

    <!-- Activity metadata -->
    <div class="wp-field">
        <label for="wp_title">Activity title</label>
        <input
            id="wp_title"
            name="wp_title"
            form="wpForm"
            value="<?= htmlspecialchars((string) ($payload['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Writing Practice"
        >
    </div>

    <div class="wp-field">
        <label for="wp_description">Description / general instruction</label>
        <textarea
            id="wp_description"
            name="wp_description"
            form="wpForm"
            rows="2"
            placeholder="e.g. Read each prompt and write your best response."
        ><?= htmlspecialchars((string) ($payload['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <!-- Question list header -->
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h5 style="margin:0;font-weight:800;">Questions</h5>
        <button type="button" class="wp-btn wp-btn-add" id="btnAddWPQ">+ Add question</button>
    </div>

    <div id="wpQuestionsContainer"></div>

    <!-- Hidden form – questions are serialized to JSON by JS before submit -->
    <form id="wpForm" method="post">
        <input type="hidden" name="questions_json" id="wp_questions_json" value="[]">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:4px;">
            <button type="submit" class="wp-btn wp-btn-save">Save activity</button>
            <span style="font-size:13px;color:#64748b;">All questions are saved together.</span>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const container = document.getElementById('wpQuestionsContainer');
    const addBtn    = document.getElementById('btnAddWPQ');
    const form      = document.getElementById('wpForm');
    const hidden    = document.getElementById('wp_questions_json');

    // Initial data from PHP
    const initial = <?= json_encode($payload['questions'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

    const TYPE_LABELS = {
        writing:        'Open Writing',
        listen_write:   'Listen & Write',
        fill_sentence:  'Fill the Sentence',
        fill_paragraph: 'Fill the Paragraph',
        video_writing:  'Video + Writing',
    };

    /* ── Tiny HTML escaper ─────────────────────────────── */
    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Build a question card DOM node ────────────────── */
    function buildCard(item, index) {
        const q          = (item && typeof item === 'object') ? item : {};
        const type       = q.type || 'writing';
        const answersStr = Array.isArray(q.correct_answers) ? q.correct_answers.join('\n') : '';

        const wrap = document.createElement('div');
        wrap.className = 'wp-card';
        wrap.innerHTML = `
          <div class="wp-card-head">
            <strong>
              Question ${index + 1}
              <span class="wp-type-badge" data-type-label="${esc(TYPE_LABELS[type] || type)}">${esc(TYPE_LABELS[type] || type)}</span>
            </strong>
            <button type="button" class="wp-btn wp-btn-remove" title="Remove question">✕ Remove</button>
          </div>

          <div class="wp-grid-3">
            <div class="wp-field">
              <label>Question type</label>
              <select class="q-type">
                <option value="writing">Open Writing</option>
                <option value="listen_write">Listen &amp; Write</option>
                <option value="fill_sentence">Fill the Sentence</option>
                <option value="fill_paragraph">Fill the Paragraph</option>
                <option value="video_writing">Video + Writing</option>
              </select>
            </div>
            <div class="wp-field">
              <label>Points</label>
              <input type="number" class="q-points" min="1" max="100" value="${esc(String(q.points || 10))}">
            </div>
            <div class="wp-field">
              <label>Placeholder text</label>
              <input type="text" class="q-placeholder" value="${esc(q.placeholder || '')}" placeholder="Write your answer here...">
            </div>
          </div>

          <div class="wp-field">
            <label>Question / prompt</label>
            <textarea class="q-question" rows="2" placeholder="e.g. Describe your daily routine.">${esc(q.question || '')}</textarea>
            <div class="wp-hint">For fill-in-blank types, use <strong>___</strong> (three underscores) to mark blanks in the sentence.</div>
          </div>

          <div class="wp-field">
            <label>Instruction <span style="font-weight:400;">(optional)</span></label>
            <input type="text" class="q-instruction" value="${esc(q.instruction || '')}" placeholder="e.g. Write at least 3 complete sentences.">
          </div>

          <hr class="wp-section-sep">

          <!-- Media URL – shown only for listen_write and video_writing -->
          <div class="wp-field wp-media-row">
            <label class="q-media-label">Media URL</label>
            <input type="url" class="q-media" value="${esc(q.media || '')}" placeholder="https://...">
            <div class="wp-hint q-media-hint">For Listen &amp; Write: paste a direct MP3 / OGG URL.&nbsp; For Video + Writing: paste a YouTube URL or direct MP4 URL.</div>
          </div>

          <div class="wp-field">
            <label>
              Correct answers
              <span style="font-weight:400;font-size:12px;">(one per line — leave empty for open-ended / full-credit)</span>
            </label>
            <textarea class="q-answers" rows="3" placeholder="Answer 1&#10;Alternate spelling&#10;Another valid answer">${esc(answersStr)}</textarea>
            <div class="wp-hint">
              Comparison ignores punctuation, uppercase, and extra spaces.
              Leave empty to award full points for any non-empty response (open-ended / free writing).
            </div>
          </div>
        `;

        /* ── Wire up type selector ──────────────────────────── */
        const typeSelect = wrap.querySelector('.q-type');
        const badge      = wrap.querySelector('[data-type-label]');
        const mediaRow   = wrap.querySelector('.wp-media-row');
        const mediaLabel = wrap.querySelector('.q-media-label');
        const mediaHint  = wrap.querySelector('.q-media-hint');

        function syncType(val) {
            badge.textContent = TYPE_LABELS[val] || val;
            badge.setAttribute('data-type-label', TYPE_LABELS[val] || val);
            const needsMedia = val === 'listen_write' || val === 'video_writing';
            mediaRow.style.display = needsMedia ? '' : 'none';
            if (val === 'listen_write') {
                mediaLabel.textContent = 'Audio URL';
                mediaHint.innerHTML    = 'Paste a direct MP3 or OGG audio file URL.';
            } else if (val === 'video_writing') {
                mediaLabel.textContent = 'Video URL';
                mediaHint.innerHTML    = 'YouTube watch URL, YouTube embed URL, or direct MP4 URL.';
            }
        }

        typeSelect.value = type;
        syncType(type);
        typeSelect.addEventListener('change', function () { syncType(this.value); });

        /* ── Remove button ───────────────────────────────────── */
        wrap.querySelector('.wp-btn-remove').addEventListener('click', function () {
            wrap.remove();
            refreshTitles();
        });

        return wrap;
    }

    /* ── Renumber question cards after add/remove ────────── */
    function refreshTitles() {
        [...container.querySelectorAll('.wp-card')].forEach(function (card, i) {
            const head = card.querySelector('.wp-card-head strong');
            if (!head) { return; }
            const badge    = head.querySelector('[data-type-label]');
            const badgeHtml = badge ? badge.outerHTML : '';
            head.innerHTML = 'Question ' + (i + 1) + ' ' + badgeHtml;
        });
    }

    /* ── Collect all card data into a plain-object array ─── */
    function collect() {
        const rows = [];

        [...container.querySelectorAll('.wp-card')].forEach(function (card) {
            const type        = card.querySelector('.q-type')?.value        || 'writing';
            const question    = (card.querySelector('.q-question')?.value   || '').trim();
            const instruction = (card.querySelector('.q-instruction')?.value || '').trim();
            const placeholder = (card.querySelector('.q-placeholder')?.value || '').trim();
            const media       = (card.querySelector('.q-media')?.value      || '').trim();
            const points      = Math.max(1, parseInt(card.querySelector('.q-points')?.value || '10', 10));
            const rawAns      = (card.querySelector('.q-answers')?.value    || '')
                .split('\n')
                .map(function (s) { return s.trim(); })
                .filter(Boolean);

            // Skip completely empty cards
            if (!question && !instruction) { return; }

            rows.push({
                id:              'wp_' + Date.now() + '_' + Math.random().toString(16).slice(2, 8),
                type:            type,
                question:        question,
                instruction:     instruction,
                placeholder:     placeholder,
                media:           media,
                correct_answers: rawAns,
                points:          points,
            });
        });

        return rows;
    }

    /* ── Event listeners ──────────────────────────────────── */
    addBtn.addEventListener('click', function () {
        const index = container.querySelectorAll('.wp-card').length;
        container.appendChild(buildCard({}, index));
    });

    form.addEventListener('submit', function () {
        hidden.value = JSON.stringify(collect());
    });

    /* ── Render existing questions on load ────────────────── */
    if (Array.isArray(initial) && initial.length > 0) {
        initial.forEach(function (q, i) { container.appendChild(buildCard(q, i)); });
    } else {
        // Start with one blank question card
        container.appendChild(buildCard({}, 0));
    }
})();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Writing Practice Editor', 'fas fa-pen-nib', $content);

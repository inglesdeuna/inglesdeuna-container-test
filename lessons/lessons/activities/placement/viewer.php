<?php
/**
 * placement/viewer.php — Student-facing Placement Test
 * Access via:  ?t={token}              — token from eval_links
 *              ?level=A2|B1|B2         — auto-finds active group link
 * No authentication required.
 */

// ─── Session start ─────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ─── Input params ─────────────────────────────────────────────────────────────
$token     = trim($_GET['t'] ?? '');
$levelGet  = strtoupper(trim($_GET['level'] ?? ''));
$step      = $_GET['step'] ?? 'welcome';
$resultId  = (int) ($_GET['rid'] ?? 0);

// ─── Resolve link via ?level=XX (group auto-resolve) ─────────────────────────
$link = null;
if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT l.*, e.title AS exam_title, e.time_limit_min, e.cefr_level AS exam_cefr,
                e.id AS exam_id_val, e.status AS exam_status, e.is_placement
         FROM eval_links l
         JOIN eval_exams e ON e.id = l.exam_id
         WHERE l.token = ?
           AND (l.expires_at IS NULL OR l.expires_at > NOW())
           AND l.uses_count < l.max_uses
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$link && in_array($levelGet, ['A2','B1','B2'], true)) {
    // Auto-find newest valid group link for the requested level
    $stmt = $pdo->prepare(
        "SELECT l.*, e.title AS exam_title, e.time_limit_min, e.cefr_level AS exam_cefr,
                e.id AS exam_id_val, e.status AS exam_status
         FROM eval_links l
         JOIN eval_exams e ON e.id = l.exam_id
         WHERE e.cefr_level = ?
           AND (e.is_placement = TRUE OR e.is_placement IS NULL)
           AND l.link_type = 'group'
           AND (l.expires_at IS NULL OR l.expires_at > NOW())
           AND l.uses_count < l.max_uses
         ORDER BY l.created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$levelGet]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($link) $token = $link['token'];
}

// ─── Validate ─────────────────────────────────────────────────────────────────
if (!$link) {
    // Check if admin is previewing
    $isAdmin = !empty($_SESSION['admin_logged']) || !empty($_SESSION['academic_logged']);

    // If level specified but no link, show preview mode for admin
    if ($isAdmin && in_array($levelGet, ['A2','B1','B2'], true)) {
        $previewStmt = $pdo->prepare(
            "SELECT e.id AS exam_id_val, e.title AS exam_title, e.time_limit_min,
                    e.cefr_level AS exam_cefr, e.status AS exam_status
             FROM eval_exams e WHERE e.cefr_level=? AND (e.is_placement=TRUE OR e.is_placement IS NULL) LIMIT 1"
        );
        $previewStmt->execute([$levelGet]);
        $examRow = $previewStmt->fetch(PDO::FETCH_ASSOC);
        if ($examRow) {
            // Build a fake link for preview
            $link = array_merge($examRow, [
                'id'           => null,
                'token'        => 'PREVIEW_' . $levelGet,
                'link_type'    => 'group',
                'student_name' => '',
                'student_doc'  => '',
                'student_phone'=> '',
                'student_email'=> '',
                'max_uses'     => 9999,
                'uses_count'   => 0,
                'expires_at'   => null,
            ]);
            $token = $link['token'];
        }
    }

    if (!$link) {
        http_response_code(404);
        ?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Enlace inválido</title>
<link rel="stylesheet" href="placement.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(160deg,#EDE9FA,#FFF0E6);">
<div style="text-align:center;padding:40px;">
    <div style="font-size:56px;margin-bottom:16px;">⚠️</div>
    <h1 style="font-family:'Fredoka',sans-serif;font-size:28px;color:#2d2b55;margin-bottom:10px;">Enlace inválido o expirado</h1>
    <p style="color:#7c7aa0;font-size:15px;max-width:380px;margin:0 auto;">
        Este enlace de placement test no es válido, ya expiró o alcanzó su límite de usos.<br>
        Contacta a ONES para obtener un nuevo enlace.
    </p>
</div>
</body></html><?php
        exit;
    }
}

$examId   = (int) ($link['exam_id_val'] ?? $link['exam_id'] ?? 0);
$cefrLevel = $link['exam_cefr'] ?? $levelGet;
$examTitle = $link['exam_title'] ?? ('Placement Test — Nivel ' . h($cefrLevel));
$timeLimitMin = (int) ($link['time_limit_min'] ?? 45);
$isIndividual = ($link['link_type'] ?? 'group') === 'individual';
$isPreview = str_starts_with($token, 'PREVIEW_');

// ─── Level visual config ──────────────────────────────────────────────────────
$levelBadgeClass = 'level-badge-' . ($cefrLevel ?: 'A2');
$levelLabels = [
    'A2' => 'A2 — Básico',
    'B1' => 'B1 — Intermedio',
    'B2' => 'B2 — Intermedio Alto',
];
$levelLabel = $levelLabels[$cefrLevel] ?? $cefrLevel;

// WhatsApp enrollment placeholder
$waNumber = '573XXXXXXXXX'; // Replace with real number
$waMsg    = urlencode("Hola! Acabo de hacer el placement test ONES nivel $cefrLevel y quiero inscribirme.");
$waUrl    = "https://wa.me/{$waNumber}?text={$waMsg}";

// Session key for this attempt
$sessKey = 'placement_info_' . $examId;

// ─── Load questions from DB ───────────────────────────────────────────────────
function loadPlacementQuestions(PDO $pdo, int $examId): array {
    $stmt = $pdo->prepare(
        "SELECT q.id, q.skill, q.question_text, q.position,
                a.answer_text, a.is_correct, a.order_index
         FROM eval_questions q
         LEFT JOIN eval_answers a ON a.question_id = q.id
         WHERE q.exam_id = ?
         ORDER BY q.position ASC, q.id ASC, a.order_index ASC"
    );
    $stmt->execute([$examId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $qid = $row['id'];
        if (!isset($grouped[$qid])) {
            $grouped[$qid] = [
                'id'      => $qid,
                'skill'   => $row['skill'],
                'text'    => $row['question_text'],
                'options' => [],
                'correct' => null,
            ];
        }
        if ($row['answer_text'] !== null) {
            $grouped[$qid]['options'][] = $row['answer_text'];
            if ($row['is_correct']) {
                $grouped[$qid]['correct'] = $row['answer_text'];
            }
        }
    }
    return array_values($grouped);
}

// ─── POST: Save student info (step=info → redirect to test) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_info'])) {
    $sName    = trim($_POST['student_name'] ?? '');
    $sDoc     = trim($_POST['student_doc']  ?? '');
    $sPhone   = trim($_POST['student_phone'] ?? '');
    $sEmail   = trim($_POST['student_email'] ?? '');
    $sCity    = trim($_POST['student_city']  ?? '');
    $sSource  = trim($_POST['student_source'] ?? '');

    if ($sName === '') {
        $errorMsg = 'Por favor ingresa tu nombre completo.';
    } else {
        $_SESSION[$sessKey] = [
            'name'   => $sName,
            'doc'    => $sDoc,
            'phone'  => $sPhone,
            'email'  => $sEmail,
            'city'   => $sCity,
            'source' => $sSource,
        ];
        header('Location: viewer.php?t=' . urlencode($token) . '&step=test');
        exit;
    }
}

// ─── POST: Submit test answers ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    $studentInfo = $_SESSION[$sessKey] ?? [];
    $sName  = $studentInfo['name']  ?? trim($_POST['student_name'] ?? 'Estudiante');
    $sDoc   = $studentInfo['doc']   ?? '';
    $sPhone = $studentInfo['phone'] ?? '';
    $sEmail = $studentInfo['email'] ?? '';

    $answers   = (array) ($_POST['answers'] ?? []);
    $questions = loadPlacementQuestions($pdo, $examId);

    // Score
    $totalScore  = 0;
    $maxScore    = count($questions);
    $skillScores = [];
    $answersLog  = [];

    foreach ($questions as $i => $q) {
        $given     = trim((string) ($answers[$q['id']] ?? ''));
        $correct   = (string) ($q['correct'] ?? '');
        $isCorrect = ($given !== '' && $given === $correct);
        $skill     = $q['skill'] ?? 'grammar';

        if ($isCorrect) $totalScore++;

        $skillScores[$skill] = $skillScores[$skill] ?? ['score'=>0,'total'=>0];
        $skillScores[$skill]['score'] += $isCorrect ? 1 : 0;
        $skillScores[$skill]['total'] += 1;

        $answersLog[] = [
            'q'          => $i,
            'qid'        => $q['id'],
            'skill'      => $skill,
            'given'      => $given,
            'correct'    => $correct,
            'is_correct' => $isCorrect,
        ];
    }

    $pct = $maxScore > 0 ? round($totalScore / $maxScore * 100, 2) : 0;

    // CEFR suggestion based on placement level and score
    $cefrSuggested = determinePlacementCefr($cefrLevel, $pct);

    // Insert result
    try {
        $insStmt = $pdo->prepare(
            "INSERT INTO eval_results
                (exam_id, link_id, student_name, student_doc, student_phone, student_email,
                 modality, score, max_score, pct, cefr_suggested,
                 answers_json, skill_scores, status, started_at, submitted_at)
             VALUES (?,?,?,?,?,?,'online',?,?,?,?,?,?,'submitted',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
             RETURNING id"
        );
        $insStmt->execute([
            $examId,
            $isPreview ? null : (((int)($link['id'] ?? 0)) ?: null),
            $sName, $sDoc, $sPhone, $sEmail,
            $totalScore, $maxScore, $pct, $cefrSuggested,
            json_encode($answersLog), json_encode($skillScores),
        ]);
        $newResultId = (int) $insStmt->fetchColumn();

        // Increment uses_count
        if (!$isPreview && !empty($link['id'])) {
            $pdo->prepare("UPDATE eval_links SET uses_count=uses_count+1 WHERE id=?")
                ->execute([$link['id']]);
        }

        // Clear session info
        unset($_SESSION[$sessKey]);

        header('Location: viewer.php?t=' . urlencode($token) . '&step=result&rid=' . $newResultId);
        exit;
    } catch (Throwable $e) {
        $errorMsg = 'Error al guardar resultados. Intenta de nuevo.';
    }
}

// ─── Determine CEFR level from score ─────────────────────────────────────────
function determinePlacementCefr(string $targetLevel, float $pct): string {
    $map = [
        'A2' => [['A1',0,34], ['A2',35,65], ['B1',66,100]],
        'B1' => [['A2',0,34], ['B1',35,65], ['B2',66,100]],
        'B2' => [['B1',0,34], ['B2',35,65], ['C1',66,100]],
    ];
    foreach (($map[$targetLevel] ?? $map['A2']) as [$lvl, $min, $max]) {
        if ($pct >= $min && $pct <= $max) return $lvl;
    }
    return 'A1';
}

// ─── Load result if on result step ───────────────────────────────────────────
$resultRow  = null;
$skillScores = [];
if ($step === 'result' && $resultId > 0) {
    $rStmt = $pdo->prepare("SELECT * FROM eval_results WHERE id=? LIMIT 1");
    $rStmt->execute([$resultId]);
    $resultRow = $rStmt->fetch(PDO::FETCH_ASSOC);
    if ($resultRow) {
        $ssRaw = $resultRow['skill_scores'] ?? '';
        $skillScores = is_string($ssRaw) ? (json_decode($ssRaw, true) ?? []) : (is_array($ssRaw) ? $ssRaw : []);
    }
}

// ─── Load questions for test step ────────────────────────────────────────────
$questions = [];
if ($step === 'test') {
    $questions = loadPlacementQuestions($pdo, $examId);
}

$errorMsg = $errorMsg ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($examTitle) ?> — ONES</title>
<link rel="stylesheet" href="placement.css">
<style>
body { margin: 0; }
</style>
</head>
<body>

<?php /* ══════════════════════════════════════════════════════════════
        STEP: WELCOME
   ══════════════════════════════════════════════════════════════ */ ?>

<?php if ($step === 'welcome'): ?>

<div class="pt-welcome" style="position:relative;overflow:hidden;">
    <div class="pt-welcome-deco" aria-hidden="true">ONES</div>
    <div class="pt-welcome-inner">

        <div class="pt-welcome-logo">
            <span class="logo-ones">ONES</span>
            <span class="logo-ingle">inglesdeuna</span>
        </div>

        <span class="level-badge <?= $levelBadgeClass ?>" style="margin-bottom:18px;display:inline-block;font-size:15px;padding:7px 22px;">
            Placement Test — <?= h($levelLabel) ?>
        </span>

        <h1>
            Your gateway to the<br>
            <span class="highlight">perfect English program</span>
        </h1>

        <p class="pt-welcome-tagline">
            This short test helps us place you in the right program level.
            There are no wrong answers — just answer as best you can!
        </p>

        <div class="pt-welcome-info-grid">
            <div class="pt-welcome-info-item">
                <span class="icon">⏱️</span>
                <span class="label">Time</span>
                <span class="value"><?= $timeLimitMin ?> min</span>
            </div>
            <div class="pt-welcome-info-item">
                <span class="icon">📝</span>
                <span class="label">Questions</span>
                <span class="value">20</span>
            </div>
            <div class="pt-welcome-info-item">
                <span class="icon">🎯</span>
                <span class="label">Skills</span>
                <span class="value">3 areas</span>
            </div>
        </div>

        <a class="pt-btn pt-btn-primary"
           href="viewer.php?t=<?= h($token) ?>&step=info"
           style="font-size:18px;padding:16px 44px;border-radius:18px;">
            Start Test →
        </a>

        <p style="font-size:13px;color:#7c7aa0;margin-top:18px;">
            Grammar · Vocabulary · Reading Comprehension
        </p>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════════
        STEP: INFO (Student registration)
   ══════════════════════════════════════════════════════════════ */ ?>

<?php elseif ($step === 'info'): ?>

<div class="pt-info-wrap">
    <div class="pt-info-card">
        <div style="text-align:center;margin-bottom:24px;">
            <span class="logo-ones" style="font-family:'Fredoka',sans-serif;font-size:32px;color:#F97316;display:block;line-height:1;">ONES</span>
            <span style="font-size:12px;font-weight:800;color:#7F77DD;text-transform:uppercase;letter-spacing:.08em;">inglesdeuna</span>
        </div>

        <span class="level-badge <?= $levelBadgeClass ?>" style="display:block;text-align:center;margin-bottom:20px;">
            <?= h($levelLabel) ?>
        </span>

        <h2 class="pt-card-title" style="text-align:center;margin-bottom:6px;">Tell us about you</h2>
        <p class="pt-card-sub" style="text-align:center;margin-bottom:22px;">
            Please complete your information before starting the test.
        </p>

        <?php if ($errorMsg): ?>
            <div class="pt-alert pt-alert-error"><?= h($errorMsg) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="save_info" value="1">

            <div class="pt-form-group">
                <label>Full Name *</label>
                <input type="text" name="student_name" required
                       placeholder="Your full name"
                       value="<?= h($_POST['student_name'] ?? ($link['student_name'] ?? '')) ?>">
            </div>

            <div class="pt-form-row">
                <div class="pt-form-group">
                    <label>Cédula (CC) *</label>
                    <input type="text" name="student_doc" required
                           placeholder="Número de cédula"
                           value="<?= h($_POST['student_doc'] ?? ($link['student_doc'] ?? '')) ?>">
                </div>
                <div class="pt-form-group">
                    <label>Phone / WhatsApp *</label>
                    <input type="tel" name="student_phone" required
                           placeholder="3XXXXXXXXX"
                           value="<?= h($_POST['student_phone'] ?? ($link['student_phone'] ?? '')) ?>">
                </div>
            </div>

            <div class="pt-form-group">
                <label>Email</label>
                <input type="email" name="student_email"
                       placeholder="your@email.com"
                       value="<?= h($_POST['student_email'] ?? ($link['student_email'] ?? '')) ?>">
            </div>

            <div class="pt-form-row">
                <div class="pt-form-group">
                    <label>Ciudad</label>
                    <input type="text" name="student_city" placeholder="Medellín, Bogotá...">
                </div>
                <div class="pt-form-group">
                    <label>¿Cómo nos conociste?</label>
                    <select name="student_source">
                        <option value="">— Selecciona —</option>
                        <option value="instagram">Instagram</option>
                        <option value="facebook">Facebook</option>
                        <option value="tiktok">TikTok</option>
                        <option value="referido">Referido / Amigo</option>
                        <option value="google">Google</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="pt-btn pt-btn-primary pt-btn-block" style="margin-top:8px;font-size:16px;padding:14px;">
                🚀 Start Test
            </button>
        </form>

        <p style="font-size:12px;color:#7c7aa0;text-align:center;margin-top:14px;">
            Your information is used only for placement purposes.
        </p>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════════
        STEP: TEST
   ══════════════════════════════════════════════════════════════ */ ?>

<?php elseif ($step === 'test'): ?>

<?php
$studentInfo = $_SESSION[$sessKey] ?? [];
$totalQs     = count($questions);
$skillLabels = ['grammar'=>'Grammar','vocabulary'=>'Vocabulary','reading'=>'Reading Comprehension'];
$skillIcons  = ['grammar'=>'📐','vocabulary'=>'📚','reading'=>'📖'];

// Group questions by skill
$bySkill = [];
foreach ($questions as $q) {
    $bySkill[$q['skill']][] = $q;
}
?>

<div class="pt-test-header">
    <div>
        <h1 class="pt-test-title"><?= h($examTitle) ?></h1>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <div class="pt-timer" id="pt-timer">
            ⏱️ <span id="timer-display"><?= $timeLimitMin ?>:00</span>
        </div>
        <span class="level-badge <?= $levelBadgeClass ?>"><?= h($cefrLevel) ?></span>
    </div>
</div>

<div class="pt-page">

    <?php if (!$studentInfo): ?>
        <div class="pt-alert pt-alert-info">
            <a href="viewer.php?t=<?= h($token) ?>&step=info" style="color:#1d4ed8;font-weight:800;">
                ← Regresa a completar tus datos
            </a>
        </div>
    <?php endif; ?>

    <!-- Progress -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:13px;font-weight:700;color:#7c7aa0;">
            Progress
        </span>
        <span style="font-size:13px;font-weight:800;color:#7F77DD;" id="progress-label">0 / <?= $totalQs ?></span>
    </div>
    <div class="pt-progress-wrap">
        <div class="pt-progress-bar" id="progress-bar" style="width:0%"></div>
    </div>

    <form method="POST" id="pt-test-form">
        <input type="hidden" name="submit_test" value="1">
        <?php if ($studentInfo): ?>
            <input type="hidden" name="student_name" value="<?= h($studentInfo['name'] ?? '') ?>">
        <?php endif; ?>

        <?php foreach (['grammar','vocabulary','reading'] as $skillKey):
            $qs = $bySkill[$skillKey] ?? [];
            if (empty($qs)) continue;
        ?>
            <div class="pt-section-title">
                <?= $skillIcons[$skillKey] ?? '📝' ?> <?= $skillLabels[$skillKey] ?? ucfirst($skillKey) ?>
            </div>

            <?php foreach ($qs as $qNum => $q): ?>
                <?php $letters = ['A','B','C','D','E']; ?>
                <div class="pt-question-card" id="qcard-<?= (int)$q['id'] ?>">
                    <div class="pt-q-meta">
                        <?= h(ucfirst($skillKey)) ?> — Q <?= $qNum + 1 ?>
                    </div>
                    <div class="pt-q-text"><?= h($q['text']) ?></div>
                    <div class="pt-options">
                        <?php foreach ($q['options'] as $idx => $opt): ?>
                            <label class="pt-option-label" id="opt-<?= (int)$q['id'] ?>-<?= $idx ?>">
                                <input type="radio"
                                       name="answers[<?= (int)$q['id'] ?>]"
                                       value="<?= h($opt) ?>"
                                       onchange="markAnswered(<?= (int)$q['id'] ?>)">
                                <span class="pt-opt-letter"><?= $letters[$idx] ?? chr(65+$idx) ?></span>
                                <span><?= h($opt) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div style="text-align:center;margin-top:32px;">
            <button type="submit" class="pt-btn pt-btn-primary" style="font-size:17px;padding:16px 48px;border-radius:20px;" id="submit-btn">
                📤 Submit Test
            </button>
            <p style="font-size:13px;color:#7c7aa0;margin-top:12px;">
                Answered: <span id="answered-count">0</span> / <?= $totalQs ?> — The test will auto-submit when the timer ends.
            </p>
        </div>
    </form>

</div>

<script>
// ─── Timer ─────────────────────────────────────────────────────────────────
(function() {
    const timerKey = 'pt_timer_<?= addslashes($token) ?>';
    const duration = <?= $timeLimitMin * 60 ?> * 1000; // ms

    let startTime = parseInt(localStorage.getItem(timerKey) || '0', 10);
    if (!startTime || startTime < (Date.now() - duration - 60000)) {
        startTime = Date.now();
        localStorage.setItem(timerKey, startTime);
    }

    const timerEl   = document.getElementById('timer-display');
    const timerWrap = document.getElementById('pt-timer');

    function updateTimer() {
        const elapsed   = Date.now() - startTime;
        const remaining = Math.max(0, duration - elapsed);
        const mins      = Math.floor(remaining / 60000);
        const secs      = Math.floor((remaining % 60000) / 1000);

        timerEl.textContent = String(mins).padStart(2,'0') + ':' + String(secs).padStart(2,'0');

        if (remaining <= 0) {
            timerEl.textContent = '00:00';
            localStorage.removeItem(timerKey);
            document.getElementById('pt-test-form').submit();
            return;
        }

        if (remaining < 5 * 60 * 1000) {
            timerWrap.classList.add('danger');
            timerWrap.classList.remove('warning');
        } else if (remaining < 15 * 60 * 1000) {
            timerWrap.classList.add('warning');
        }

        setTimeout(updateTimer, 500);
    }
    updateTimer();
})();

// ─── Progress tracking ─────────────────────────────────────────────────────
let answered = new Set();
const total  = <?= $totalQs ?>;

function markAnswered(qid) {
    const card = document.getElementById('qcard-' + qid);
    if (card) card.classList.add('answered');
    answered.add(qid);
    document.getElementById('answered-count').textContent = answered.size;
    const pct = (answered.size / total) * 100;
    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('progress-label').textContent = answered.size + ' / ' + total;
}

// Option selection visual
document.querySelectorAll('.pt-option-label').forEach(function(lbl) {
    lbl.addEventListener('click', function() {
        const name = this.querySelector('input').getAttribute('name');
        document.querySelectorAll('[name="' + CSS.escape(name) + '"]').forEach(function(inp) {
            inp.closest('.pt-option-label').classList.remove('selected');
        });
        this.classList.add('selected');
    });
});

// Submit confirmation
document.getElementById('pt-test-form').addEventListener('submit', function(e) {
    const unanswered = total - answered.size;
    if (unanswered > 0) {
        if (!confirm('You have ' + unanswered + ' unanswered question(s). Submit anyway?')) {
            e.preventDefault();
        }
    }
});
</script>

<?php /* ══════════════════════════════════════════════════════════════
        STEP: RESULT
   ══════════════════════════════════════════════════════════════ */ ?>

<?php elseif ($step === 'result' && $resultRow): ?>

<?php
$pct   = round((float)($resultRow['pct'] ?? 0));
$cefr  = $resultRow['cefr_suggested'] ?? 'A2';
$sName = $resultRow['student_name'] ?? 'Estudiante';

// Personalized message based on target level vs achieved level
$cefrOrder  = ['A1'=>1,'A2'=>2,'B1'=>3,'B2'=>4,'C1'=>5,'C2'=>6];
$targetOrd  = $cefrOrder[$cefrLevel] ?? 2;
$achievedOrd = $cefrOrder[$cefr] ?? 2;

if ($achievedOrd < $targetOrd) {
    $recommendation = "We recommend starting with our <strong>" . h($cefr) . " Foundation program</strong> to build your skills before advancing to $cefrLevel.";
    $verdict = "Keep going — $cefr level first!";
    $verdictColor = '#3b82f6';
} elseif ($achievedOrd === $targetOrd) {
    $recommendation = "Great news! You're ready for our <strong>" . h($cefrLevel) . " program</strong>. This is the perfect level for you right now!";
    $verdict = "You're ready for " . h($cefrLevel) . "! 🎉";
    $verdictColor = '#22c55e';
} else {
    $recommendation = "Excellent! Your level shows you may be ready for our <strong>" . h($cefr) . " program</strong> — a step ahead of $cefrLevel!";
    $verdict = "Above " . h($cefrLevel) . " — impressive! 🌟";
    $verdictColor = '#7F77DD';
}

$skillLabelMap = ['grammar'=>'Grammar','vocabulary'=>'Vocabulary','reading'=>'Reading'];
?>

<div class="pt-result-wrap">
    <div style="text-align:center;padding:28px 0 0;">
        <span class="logo-ones" style="font-family:'Fredoka',sans-serif;font-size:32px;color:#F97316;">ONES</span>
        <span style="font-size:12px;font-weight:800;color:#7F77DD;display:block;text-transform:uppercase;letter-spacing:.08em;">inglesdeuna</span>
    </div>

    <div class="pt-result-card" style="margin-top:20px;">
        <span class="level-badge <?= $levelBadgeClass ?>" style="margin-bottom:8px;display:inline-block;">
            <?= h($levelLabel) ?>
        </span>

        <h2 style="font-family:'Fredoka',sans-serif;font-size:22px;color:#7F77DD;margin-bottom:0;">
            🎓 Your Results, <?= h($sName) ?>
        </h2>

        <div class="pt-result-score"><?= $pct ?>%</div>

        <div class="pt-result-level" style="color:<?= h($verdictColor) ?>;">
            <?= $verdict ?>
        </div>

        <div style="margin:12px 0;">
            <span class="level-badge level-badge-<?= h($cefr) ?>" style="font-size:16px;padding:8px 24px;">
                <?= h($cefr) ?> — Your level
            </span>
        </div>

        <div class="pt-result-msg">
            <?= $recommendation ?>
        </div>

        <!-- Skill breakdown -->
        <?php if (!empty($skillScores)): ?>
        <div style="text-align:left;margin-bottom:20px;">
            <p style="font-weight:800;color:#7c7aa0;font-size:12px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">
                Skill Breakdown
            </p>
            <?php foreach ($skillScores as $sk => $sv): ?>
                <?php
                $skTotal = (float)($sv['total'] ?? 1);
                $skScore = (float)($sv['score'] ?? 0);
                $skPct   = $skTotal > 0 ? round($skScore / $skTotal * 100) : 0;
                ?>
                <div class="pt-skill-row">
                    <span class="pt-skill-name"><?= h($skillLabelMap[$sk] ?? ucfirst($sk)) ?></span>
                    <div class="pt-skill-bar-wrap">
                        <div class="pt-skill-bar" style="width:<?= $skPct ?>%"></div>
                    </div>
                    <span class="pt-skill-pct"><?= $skPct ?>%</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ONES program info -->
        <div style="background:#f8f7ff;border-radius:16px;padding:18px;margin-bottom:20px;text-align:left;">
            <p style="font-weight:800;color:#7F77DD;font-size:14px;margin-bottom:8px;">📚 ONES English Programs</p>
            <p style="font-size:13px;color:#7c7aa0;line-height:1.6;margin:0;">
                ONES ofrece programas de inglés en A2, B1 y B2 con metodología comunicativa,
                clases en vivo y materiales digitales completos. ¡Empieza tu camino al inglés hoy!
            </p>
        </div>

        <!-- CTA Buttons -->
        <div style="display:flex;flex-direction:column;gap:12px;">
            <a class="pt-btn pt-btn-green pt-btn-block"
               href="<?= h($waUrl) ?>"
               target="_blank"
               style="font-size:16px;padding:16px;border-radius:18px;">
                💬 I want to enroll! (WhatsApp)
            </a>
            <a class="pt-btn pt-btn-ghost pt-btn-block"
               href="javascript:window.print();"
               style="font-size:14px;">
                🖨️ Print my result
            </a>
        </div>

        <p style="font-size:12px;color:#7c7aa0;margin-top:16px;">
            inglesdeuna.com — ONES Online English Solution
        </p>
    </div>
</div>

<?php else: ?>
    <div class="pt-page">
        <div class="pt-alert pt-alert-error">
            Paso no reconocido o resultado no encontrado.
            <a href="viewer.php?t=<?= h($token) ?>&step=welcome">← Volver al inicio</a>
        </div>
    </div>
<?php endif; ?>

</body>
</html>

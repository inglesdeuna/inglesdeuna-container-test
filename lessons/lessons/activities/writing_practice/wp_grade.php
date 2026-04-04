<?php
/**
 * wp_grade.php
 * Teacher page to view and score open-writing responses.
 *
 * URL params:
 *   activity_id   - activity UUID
 *   student       - student_id to grade
 *   assignment    - assignment_id
 *   unit          - unit_id
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

/* ── Auth: teacher only ─────────────────────────────────── */
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}
$teacherId = trim((string) ($_SESSION['teacher_id'] ?? $_SESSION['admin_id'] ?? 'unknown'));

$activityId   = trim((string) ($_GET['activity_id'] ?? ''));
$studentId    = trim((string) ($_GET['student']     ?? ''));
$assignmentId = trim((string) ($_GET['assignment']  ?? ''));
$unitId       = trim((string) ($_GET['unit']        ?? ''));

if ($activityId === '' || $studentId === '') {
    die('Parámetros insuficientes. Proporciona activity_id y student.');
}

/* ── Ensure tables exist ─────────────────────────────────── */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS writing_practice_responses (
            id            TEXT        NOT NULL,
            activity_id   TEXT        NOT NULL,
            student_id    TEXT        NOT NULL,
            assignment_id TEXT        NOT NULL DEFAULT '',
            unit_id       TEXT        NOT NULL DEFAULT '',
            question_id   TEXT        NOT NULL,
            question_text TEXT        NOT NULL DEFAULT '',
            response_text TEXT        NOT NULL DEFAULT '',
            score         INTEGER     DEFAULT NULL,
            max_points    INTEGER     NOT NULL DEFAULT 10,
            graded_by     TEXT        DEFAULT NULL,
            graded_at     TIMESTAMPTZ DEFAULT NULL,
            created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            PRIMARY KEY (activity_id, student_id, assignment_id, question_id)
        )
    ");
} catch (Throwable $ignored) {}

/* ── Handle POST (save scores) ───────────────────────────── */
$savedOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scores  = isset($_POST['score'])       && is_array($_POST['score'])       ? $_POST['score']       : [];
    $qIds    = isset($_POST['question_id']) && is_array($_POST['question_id']) ? $_POST['question_id'] : [];
    $maxPts  = isset($_POST['max_points'])  && is_array($_POST['max_points'])  ? $_POST['max_points']  : [];

    $pdo->beginTransaction();
    try {
        $totalEarned = 0;
        $totalMax    = 0;

        foreach ($qIds as $i => $qId) {
            $qId      = trim((string) $qId);
            $raw      = trim((string) ($scores[$i]  ?? ''));
            $max      = max(1, (int) ($maxPts[$i]   ?? 10));
            $score    = ($raw !== '') ? max(0, min($max, (int) $raw)) : null;

            $totalMax    += $max;
            $totalEarned += ($score !== null) ? (int) $score : 0;

            $stmt = $pdo->prepare("
                UPDATE writing_practice_responses
                SET score     = :score,
                    graded_by = :graded_by,
                    graded_at = NOW(),
                    updated_at = NOW()
                WHERE activity_id   = :activity_id
                  AND student_id    = :student_id
                  AND assignment_id = :assignment_id
                  AND question_id   = :question_id
            ");
            $stmt->execute([
                'score'         => $score,
                'graded_by'     => $teacherId,
                'activity_id'   => $activityId,
                'student_id'    => $studentId,
                'assignment_id' => $assignmentId,
                'question_id'   => $qId,
            ]);
        }

        /* Update student_activity_results with the graded score */
        if ($totalMax > 0 && $unitId !== '' && $assignmentId !== '') {
            $percent = min(100, (int) round(($totalEarned / $totalMax) * 100));
            $stmtSar = $pdo->prepare("
                INSERT INTO student_activity_results
                    (student_id, assignment_id, unit_id, activity_id, activity_type,
                     completion_percent, errors_count, total_count, attempts_count, updated_at)
                VALUES
                    (:student_id, :assignment_id, :unit_id, :activity_id, 'writing_practice',
                     :pct, 0, :total, 1, NOW())
                ON CONFLICT (student_id, assignment_id, unit_id, activity_id)
                DO UPDATE SET
                    completion_percent = EXCLUDED.completion_percent,
                    total_count        = EXCLUDED.total_count,
                    updated_at         = NOW()
            ");
            $stmtSar->execute([
                'student_id'    => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id'       => $unitId,
                'activity_id'   => $activityId,
                'pct'           => $percent,
                'total'         => count($qIds),
            ]);
        }

        $pdo->commit();
        $savedOk = true;
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}

/* ── Load responses ──────────────────────────────────────── */
$responses = [];
try {
    $stmt = $pdo->prepare("
        SELECT question_id, question_text, response_text, score, max_points, graded_by, graded_at, created_at
        FROM writing_practice_responses
        WHERE activity_id   = :activity_id
          AND student_id    = :student_id
          AND assignment_id = :assignment_id
        ORDER BY created_at ASC
    ");
    $stmt->execute([
        'activity_id'   => $activityId,
        'student_id'    => $studentId,
        'assignment_id' => $assignmentId,
    ]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── Load student name ───────────────────────────────────── */
$studentName = $studentId;
try {
    $stmtN = $pdo->prepare("SELECT name FROM students WHERE id = :id LIMIT 1");
    $stmtN->execute(['id' => $studentId]);
    $row = $stmtN->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['name'])) { $studentName = (string) $row['name']; }
} catch (Throwable $e) {}

/* ── Load activity title ─────────────────────────────────── */
$activityTitle = 'Writing Practice';
try {
    $stmtA = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmtA->execute(['id' => $activityId]);
    $rowData = $stmtA->fetch(PDO::FETCH_ASSOC);
    if ($rowData && !empty($rowData['data'])) {
        $decoded = json_decode((string) $rowData['data'], true);
        if (is_array($decoded) && !empty($decoded['title'])) {
            $activityTitle = (string) $decoded['title'];
        }
    }
} catch (Throwable $e) {}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$backUrl = '/lessons/lessons/academic/teacher_student_progress.php?'
    . 'student=' . urlencode($studentId)
    . '&assignment=' . urlencode($assignmentId);

ob_start();
?>
<style>
.wg-wrap {
    max-width: 900px;
    margin: 0 auto;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    padding-bottom: 40px;
}
.wg-hero {
    background: linear-gradient(135deg, #fdf4ff 0%, #f0e8ff 52%, #fdf4ff 100%);
    border: 1px solid #e9d5ff;
    border-radius: 18px;
    padding: 18px 20px;
    margin-bottom: 14px;
    box-shadow: 0 10px 22px rgba(15,23,42,.07);
}
.wg-hero h2 { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: #0f172a; }
.wg-hero p  { margin: 0; color: #475569; font-size: 14px; }
.wg-card {
    background: #fff;
    border: 1px solid #e9d5ff;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 12px;
    box-shadow: 0 6px 16px rgba(15,23,42,.05);
}
.wg-q-num  { font-size: 11px; font-weight: 800; color: #7c3aed; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
.wg-q-text { font-weight: 800; color: #f14902; font-size: 16px; margin: 0 0 10px; }
.wg-resp-label { font-size: 12px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.wg-response-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 15px;
    color: #1e293b;
    line-height: 1.7;
    white-space: pre-wrap;
    margin-bottom: 10px;
}
.wg-score-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.wg-score-row label { font-weight: 800; font-size: 13px; color: #0f172a; }
.wg-score-input {
    width: 80px;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #a855f7;
    font-size: 15px;
    font-weight: 800;
    font-family: inherit;
    text-align: center;
}
.wg-max-label { color: #7c3aed; font-weight: 700; font-size: 13px; }
.wg-already-graded { color: #16a34a; font-size: 12px; font-weight: 700; }
.wg-empty { padding: 20px; border: 1px solid #e9d5ff; border-radius: 12px; background: #fdf4ff; color: #7c3aed; font-weight: 700; text-align: center; }
.wg-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 16px; }
.wg-btn-save {
    background: #7c3aed; color: #fff; border: none; border-radius: 10px;
    padding: 12px 20px; font-weight: 800; font-size: 15px; cursor: pointer; font-family: inherit;
    transition: filter .15s, transform .15s;
}
.wg-btn-save:hover { filter: brightness(1.08); transform: translateY(-1px); }
.wg-btn-back {
    color: #7c3aed; background: #f5f3ff; border: 1px solid #ddd6fe;
    border-radius: 10px; padding: 12px 16px; font-weight: 800; font-size: 14px;
    text-decoration: none; display: inline-block; transition: filter .15s;
}
.wg-btn-back:hover { filter: brightness(.96); }
.wg-saved-notice {
    margin-bottom: 14px; padding: 10px 14px; border-radius: 10px;
    border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; font-weight: 800;
}
</style>

<div class="wg-wrap">
    <div class="wg-hero">
        <h2>✏️ Calificar: <?= h($activityTitle) ?></h2>
        <p>Estudiante: <strong><?= h($studentName) ?></strong></p>
    </div>

    <?php if ($savedOk): ?>
        <p class="wg-saved-notice">✔ Puntajes guardados correctamente.</p>
    <?php endif; ?>

    <?php if (empty($responses)): ?>
        <div class="wg-empty">
            Este estudiante aún no ha enviado respuestas para esta actividad.
        </div>
    <?php else: ?>
        <form method="post">
            <?php foreach ($responses as $i => $resp): ?>
                <?php $alreadyGraded = $resp['score'] !== null; ?>
                <div class="wg-card">
                    <div class="wg-q-num">Pregunta <?= $i + 1 ?></div>

                    <?php if (!empty($resp['question_text'])): ?>
                        <p class="wg-q-text"><?= h($resp['question_text']) ?></p>
                    <?php endif; ?>

                    <div class="wg-resp-label">Respuesta del estudiante</div>
                    <div class="wg-response-box"><?= h($resp['response_text']) ?></div>

                    <div class="wg-score-row">
                        <label for="score_<?= $i ?>">Puntaje:</label>
                        <input
                            type="number"
                            id="score_<?= $i ?>"
                            name="score[]"
                            class="wg-score-input"
                            min="0"
                            max="<?= (int) ($resp['max_points'] ?? 10) ?>"
                            value="<?= $resp['score'] !== null ? (int) $resp['score'] : '' ?>"
                            placeholder="—"
                        >
                        <span class="wg-max-label">/ <?= (int) ($resp['max_points'] ?? 10) ?> pts</span>

                        <?php if ($alreadyGraded): ?>
                            <span class="wg-already-graded">✔ Ya calificado</span>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="question_id[]" value="<?= h($resp['question_id']) ?>">
                    <input type="hidden" name="max_points[]"  value="<?= (int) ($resp['max_points'] ?? 10) ?>">
                </div>
            <?php endforeach; ?>

            <div class="wg-actions">
                <button type="submit" class="wg-btn-save">💾 Guardar puntajes</button>
                <a href="<?= h($backUrl) ?>" class="wg-btn-back">← Volver al progreso</a>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
render_activity_editor('Calificar Writing Practice', 'fas fa-pen-nib', $content);

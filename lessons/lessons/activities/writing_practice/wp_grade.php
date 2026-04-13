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

/* ── Add grader_notes / question_type columns if missing ─── */
try {
    $pdo->exec("ALTER TABLE writing_practice_responses ADD COLUMN IF NOT EXISTS grader_notes TEXT DEFAULT NULL");
} catch (Throwable $ignored) {}
try {
    $pdo->exec("ALTER TABLE writing_practice_responses ADD COLUMN IF NOT EXISTS question_type TEXT NOT NULL DEFAULT ''");
} catch (Throwable $ignored) {}

/* ── Load word scoring settings from activity ────────────── */
$wordScoring = ['enabled' => false, 'penalty_spelling' => 1.0, 'penalty_grammar' => 1.0, 'penalty_punctuation' => 1.0];
$activityTitle = 'Writing Practice';
$questionTypes = [];
try {
    $stmtA = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmtA->execute(['id' => $activityId]);
    $rowData = $stmtA->fetch(PDO::FETCH_ASSOC);
    if ($rowData && !empty($rowData['data'])) {
        $decodedAct = json_decode((string) $rowData['data'], true);
        if (is_array($decodedAct)) {
            if (!empty($decodedAct['title'])) { $activityTitle = (string) $decodedAct['title']; }
            if (!empty($decodedAct['questions']) && is_array($decodedAct['questions'])) {
                foreach ($decodedAct['questions'] as $qItem) {
                    if (!is_array($qItem)) { continue; }
                    $mapId = trim((string) ($qItem['id'] ?? ''));
                    if ($mapId === '') { continue; }
                    $questionTypes[$mapId] = trim((string) ($qItem['type'] ?? 'writing')) ?: 'writing';
                }
            }
            if (isset($decodedAct['word_scoring']) && is_array($decodedAct['word_scoring'])) {
                $ws = $decodedAct['word_scoring'];
                $wordScoring = [
                    'enabled'             => (bool)  ($ws['enabled']             ?? false),
                    'penalty_spelling'    => max(0.0, (float) ($ws['penalty_spelling']    ?? 1)),
                    'penalty_grammar'     => max(0.0, (float) ($ws['penalty_grammar']     ?? 1)),
                    'penalty_punctuation' => max(0.0, (float) ($ws['penalty_punctuation'] ?? 1)),
                ];
            }
        }
    }
} catch (Throwable $e) {}

$wsEnabled = (bool) $wordScoring['enabled'];
$pSpell    = (float) $wordScoring['penalty_spelling'];
$pGram     = (float) $wordScoring['penalty_grammar'];
$pPunct    = (float) $wordScoring['penalty_punctuation'];

/* ── Helper: count words in a string ──────────────────────── */
function wpg_word_count(string $text): int {
    $text = preg_replace('/\s+/', ' ', trim($text));
    if ($text === '') { return 0; }
    return count(explode(' ', $text));
}

/* ── Handle POST (save scores) ───────────────────────────── */
$savedOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qIds = isset($_POST['question_id']) && is_array($_POST['question_id']) ? $_POST['question_id'] : [];

    $pdo->beginTransaction();
    try {
        $totalEarned   = 0;
        $totalMax      = 0;
        $scoreableItems = 0;

        if ($wsEnabled) {
            /* ── Word-count scoring mode ─── */
            $wordCounts  = isset($_POST['word_count'])   && is_array($_POST['word_count'])   ? $_POST['word_count']   : [];
            $errSpelling = isset($_POST['err_spelling']) && is_array($_POST['err_spelling']) ? $_POST['err_spelling'] : [];
            $errGrammar  = isset($_POST['err_grammar'])  && is_array($_POST['err_grammar'])  ? $_POST['err_grammar']  : [];
            $errPunct    = isset($_POST['err_punct'])    && is_array($_POST['err_punct'])    ? $_POST['err_punct']    : [];

            foreach ($qIds as $i => $qId) {
                $qId = trim((string) $qId);
                $qType = trim((string) ($questionTypes[$qId] ?? '')) ?: 'writing';

                if ($qType === 'writing') {
                    $notes = json_encode(['practice_only' => true, 'message' => 'Free writing is reviewed without a score.']);
                    $stmt = $pdo->prepare("
                        UPDATE writing_practice_responses
                        SET score        = NULL,
                            max_points   = 0,
                            grader_notes = :notes,
                            graded_by    = :graded_by,
                            graded_at    = NOW(),
                            updated_at   = NOW()
                        WHERE activity_id   = :activity_id
                          AND student_id    = :student_id
                          AND assignment_id = :assignment_id
                          AND question_id   = :question_id
                    ");
                    $stmt->execute([
                        'notes'         => $notes,
                        'graded_by'     => $teacherId,
                        'activity_id'   => $activityId,
                        'student_id'    => $studentId,
                        'assignment_id' => $assignmentId,
                        'question_id'   => $qId,
                    ]);
                    continue;
                }

                $base   = max(0, (int) ($wordCounts[$i]  ?? 0));
                $eSpel  = max(0, (int) ($errSpelling[$i] ?? 0));
                $eGram  = max(0, (int) ($errGrammar[$i]  ?? 0));
                $ePunct = max(0, (int) ($errPunct[$i]    ?? 0));
                $deduct = (int) round($eSpel * $pSpell + $eGram * $pGram + $ePunct * $pPunct);
                $final  = max(0, $base - $deduct);
                $notes  = json_encode([
                    'base_words'   => $base,
                    'err_spelling' => $eSpel,
                    'err_grammar'  => $eGram,
                    'err_punct'    => $ePunct,
                    'deduction'    => $deduct,
                    'final'        => $final,
                ]);

                $totalMax      += $base;
                $totalEarned   += $final;
                $scoreableItems++;

                $stmt = $pdo->prepare("
                    UPDATE writing_practice_responses
                    SET score        = :score,
                        max_points   = :max_points,
                        grader_notes = :notes,
                        graded_by    = :graded_by,
                        graded_at    = NOW(),
                        updated_at   = NOW()
                    WHERE activity_id   = :activity_id
                      AND student_id    = :student_id
                      AND assignment_id = :assignment_id
                      AND question_id   = :question_id
                ");
                $stmt->execute([
                    'score'         => $final,
                    'max_points'    => max(1, $base),
                    'notes'         => $notes,
                    'graded_by'     => $teacherId,
                    'activity_id'   => $activityId,
                    'student_id'    => $studentId,
                    'assignment_id' => $assignmentId,
                    'question_id'   => $qId,
                ]);
            }
        } else {
            /* ── Manual / regular scoring mode ─── */
            $scores  = isset($_POST['score'])      && is_array($_POST['score'])      ? $_POST['score']      : [];
            $maxPts  = isset($_POST['max_points']) && is_array($_POST['max_points']) ? $_POST['max_points'] : [];

            foreach ($qIds as $i => $qId) {
                $qId   = trim((string) $qId);
                $qType = trim((string) ($questionTypes[$qId] ?? '')) ?: 'writing';

                if ($qType === 'writing') {
                    $notes = json_encode(['practice_only' => true, 'message' => 'Free writing is reviewed without a score.']);
                    $stmt = $pdo->prepare("
                        UPDATE writing_practice_responses
                        SET score        = NULL,
                            max_points   = 0,
                            grader_notes = :notes,
                            graded_by    = :graded_by,
                            graded_at    = NOW(),
                            updated_at   = NOW()
                        WHERE activity_id   = :activity_id
                          AND student_id    = :student_id
                          AND assignment_id = :assignment_id
                          AND question_id   = :question_id
                    ");
                    $stmt->execute([
                        'notes'         => $notes,
                        'graded_by'     => $teacherId,
                        'activity_id'   => $activityId,
                        'student_id'    => $studentId,
                        'assignment_id' => $assignmentId,
                        'question_id'   => $qId,
                    ]);
                    continue;
                }

                $raw   = trim((string) ($scores[$i]  ?? ''));
                $max   = max(1, (int) ($maxPts[$i]   ?? 10));
                $score = ($raw !== '') ? max(0, min($max, (int) $raw)) : null;

                $totalMax      += $max;
                $totalEarned   += ($score !== null) ? (int) $score : 0;
                $scoreableItems++;

                $stmt = $pdo->prepare("
                    UPDATE writing_practice_responses
                    SET score        = :score,
                        max_points   = :max_points,
                        graded_by    = :graded_by,
                        graded_at    = NOW(),
                        updated_at   = NOW()
                    WHERE activity_id   = :activity_id
                      AND student_id    = :student_id
                      AND assignment_id = :assignment_id
                      AND question_id   = :question_id
                ");
                $stmt->execute([
                    'score'         => $score,
                    'max_points'    => $max,
                    'graded_by'     => $teacherId,
                    'activity_id'   => $activityId,
                    'student_id'    => $studentId,
                    'assignment_id' => $assignmentId,
                    'question_id'   => $qId,
                ]);
            }
        }

        /* Update student_activity_results with the graded score */
        if ($unitId !== '' && $assignmentId !== '') {
            $percent = $totalMax > 0 ? min(100, (int) round(($totalEarned / $totalMax) * 100)) : 100;
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
                'total'         => $scoreableItems,
            ]);

            /* Re-aggregate all activity scores for this unit into student_unit_results */
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS student_unit_results (
                        student_id         TEXT NOT NULL,
                        assignment_id      TEXT NOT NULL,
                        unit_id            TEXT NOT NULL,
                        completion_percent INTEGER NOT NULL DEFAULT 0,
                        quiz_errors        INTEGER NOT NULL DEFAULT 0,
                        quiz_total         INTEGER NOT NULL DEFAULT 0,
                        updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                        PRIMARY KEY (student_id, assignment_id, unit_id)
                    )
                ");
            } catch (Throwable $ignored) {}

            $stmtAgg = $pdo->prepare("
                SELECT SUM(errors_count) AS errors_sum, SUM(total_count) AS total_sum
                FROM student_activity_results
                WHERE student_id    = :student_id
                  AND assignment_id = :assignment_id
                  AND unit_id       = :unit_id
            ");
            $stmtAgg->execute([
                'student_id'    => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id'       => $unitId,
            ]);
            $aggRow    = $stmtAgg->fetch(PDO::FETCH_ASSOC);
            $aggTotal  = max(0, (int) ($aggRow['total_sum']  ?? 0));
            $aggErrors = max(0, (int) ($aggRow['errors_sum'] ?? 0));
            if ($aggErrors > $aggTotal) { $aggErrors = $aggTotal; }
            $aggPct = $aggTotal > 0
                ? max(0, min(100, (int) round((($aggTotal - $aggErrors) / $aggTotal) * 100)))
                : 0;

            $stmtUni = $pdo->prepare("
                INSERT INTO student_unit_results
                    (student_id, assignment_id, unit_id, completion_percent, quiz_errors, quiz_total, updated_at)
                VALUES
                    (:student_id, :assignment_id, :unit_id, :completion_percent, :quiz_errors, :quiz_total, NOW())
                ON CONFLICT (student_id, assignment_id, unit_id)
                DO UPDATE SET
                    completion_percent = EXCLUDED.completion_percent,
                    quiz_errors        = EXCLUDED.quiz_errors,
                    quiz_total         = EXCLUDED.quiz_total,
                    updated_at         = NOW()
            ");
            $stmtUni->execute([
                'student_id'         => $studentId,
                'assignment_id'      => $assignmentId,
                'unit_id'            => $unitId,
                'completion_percent' => $aggPct,
                'quiz_errors'        => $aggErrors,
                'quiz_total'         => $aggTotal,
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
        SELECT question_id, question_type, question_text, response_text, score, max_points,
               graded_by, graded_at, created_at,
               COALESCE(grader_notes, '') AS grader_notes
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

/* $activityTitle already loaded above when reading word_scoring settings */

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$backUrl = '/lessons/lessons/academic/teacher_student_progress.php?'
    . 'student=' . urlencode($studentId)
    . '&assignment=' . urlencode($assignmentId);

ob_start();
?>
<style>
.wg-wrap { max-width: 900px; margin: 0 auto; font-family: 'Nunito','Segoe UI',sans-serif; padding-bottom: 40px; }
.wg-hero {
    background: linear-gradient(135deg, #fdf4ff 0%, #f0e8ff 52%, #fdf4ff 100%);
    border: 1px solid #e9d5ff; border-radius: 18px; padding: 18px 20px;
    margin-bottom: 14px; box-shadow: 0 10px 22px rgba(15,23,42,.07);
}
.wg-hero h2 { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: #0f172a; }
.wg-hero p  { margin: 0; color: #475569; font-size: 14px; }
.wg-mode-badge {
    display: inline-block; margin-top: 8px; padding: 3px 10px;
    border-radius: 999px; font-size: 12px; font-weight: 800;
    background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd;
}
.wg-mode-badge.ws { background: #dcfce7; color: #166534; border-color: #86efac; }
.wg-card {
    background: #fff; border: 1px solid #e9d5ff; border-radius: 14px;
    padding: 16px 18px; margin-bottom: 12px;
    box-shadow: 0 6px 16px rgba(15,23,42,.05);
    position: relative; overflow: hidden;
}
.wg-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: linear-gradient(90deg, #a855f7, #7c3aed);
}
.wg-card.ws-mode::before { background: linear-gradient(90deg, #22c55e, #16a34a); }
.wg-q-num  { font-size: 11px; font-weight: 800; color: #7c3aed; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
.wg-card.ws-mode .wg-q-num { color: #16a34a; }
.wg-q-text { font-weight: 800; color: #f14902; font-size: 16px; margin: 0 0 10px; }
.wg-resp-label { font-size: 12px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.wg-response-box {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 12px 14px; font-size: 15px; color: #1e293b; line-height: 1.7;
    white-space: pre-wrap; margin-bottom: 10px;
}
.wg-wc-row {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-bottom: 10px;
}
.wg-wc-row label { font-weight: 800; font-size: 13px; color: #166534; }
.wg-wc-badge {
    background: #dcfce7; color: #166534; border: 1px solid #86efac;
    border-radius: 8px; padding: 5px 12px; font-size: 14px; font-weight: 800;
}
.wg-wc-input {
    width: 80px; padding: 7px 10px; border-radius: 8px;
    border: 1px solid #86efac; font-size: 14px; font-weight: 800;
    font-family: inherit; text-align: center; background: #f0fdf4; color: #166534;
}
.wg-err-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 10px;
}
.wg-err-col label { display: block; font-size: 12px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
.wg-err-col .wg-err-val { font-size: 11px; color: #64748b; margin-left: 4px; font-weight: 600; }
.wg-err-input {
    width: 100%; padding: 8px 10px; border-radius: 8px;
    border: 1px solid #fca5a5; font-size: 14px; font-weight: 800;
    font-family: inherit; text-align: center; background: #fff5f5; color: #dc2626;
    box-sizing: border-box;
}
.wg-result-bar {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px;
    padding: 10px 14px; font-size: 14px; font-weight: 700; color: #166534;
    margin-bottom: 6px;
}
.wg-result-bar span { color: #0f172a; font-weight: 800; }
.wg-result-pct { font-size: 16px; font-weight: 900; color: #15803d; }
.wg-score-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.wg-score-row label { font-weight: 800; font-size: 13px; color: #0f172a; }
.wg-score-input {
    width: 80px; padding: 8px 10px; border-radius: 8px;
    border: 1px solid #a855f7; font-size: 15px; font-weight: 800;
    font-family: inherit; text-align: center;
}
.wg-max-label { color: #7c3aed; font-weight: 700; font-size: 13px; }
.wg-already-graded { color: #16a34a; font-size: 12px; font-weight: 700; }
.wg-practice-note {
    background: #fff7ed; border: 1px solid #fdba74; color: #9a3412;
    border-radius: 10px; padding: 10px 12px; font-size: 13px; font-weight: 700;
}
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
.wg-saved-notice { margin-bottom: 14px; padding: 10px 14px; border-radius: 10px; border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; font-weight: 800; }
@media (max-width: 600px) { .wg-err-grid { grid-template-columns: 1fr; } }
</style>

<?php
$backUrl = '/lessons/lessons/academic/teacher_student_progress.php?'
    . 'student=' . urlencode($studentId)
    . '&assignment=' . urlencode($assignmentId);
?>

<div class="wg-wrap">
    <div class="wg-hero">
        <h2>&#9998; Calificar: <?= h($activityTitle) ?></h2>
        <p>Estudiante: <strong><?= h($studentName) ?></strong></p>
        <span class="wg-mode-badge <?= $wsEnabled ? 'ws' : '' ?>">
            <?= $wsEnabled ? '&#128200; Puntuación por palabras' : '&#9998; Puntaje manual' ?>
        </span>
    </div>

    <?php if ($savedOk): ?>
        <p class="wg-saved-notice">&#10004; Puntajes guardados correctamente.</p>
    <?php endif; ?>

    <?php if (empty($responses)): ?>
        <div class="wg-empty">
            Este estudiante aún no ha enviado respuestas para esta actividad.
        </div>
    <?php else: ?>
        <form method="post">
            <?php foreach ($responses as $i => $resp): ?>
                <?php
                $alreadyGraded = $resp['score'] !== null;
                $respText      = (string) ($resp['response_text'] ?? '');
                $qType         = trim((string) ($resp['question_type'] ?? '')) ?: (string) ($questionTypes[$resp['question_id']] ?? 'writing');
                $practiceOnly  = ($qType === 'writing');
                $autoWc        = wpg_word_count($respText);

                /* decode saved breakdown if available */
                $savedNotes    = [];
                $notesRaw      = trim((string) ($resp['grader_notes'] ?? ''));
                if ($notesRaw !== '') { $savedNotes = json_decode($notesRaw, true) ?: []; }
                $prevBase   = (int) ($savedNotes['base_words']   ?? $autoWc);
                $prevESpel  = (int) ($savedNotes['err_spelling'] ?? 0);
                $prevEGram  = (int) ($savedNotes['err_grammar']  ?? 0);
                $prevEPunct = (int) ($savedNotes['err_punct']    ?? 0);
                $prevDeduct = (int) ($savedNotes['deduction']    ?? 0);
                $prevFinal  = (int) ($savedNotes['final']        ?? ($alreadyGraded ? (int)$resp['score'] : max(0,$autoWc)));
                ?>
                <div class="wg-card <?= ($wsEnabled && !$practiceOnly) ? 'ws-mode' : '' ?>">
                    <div class="wg-q-num">Pregunta <?= $i + 1 ?></div>

                    <?php if (!empty($resp['question_text'])): ?>
                        <p class="wg-q-text"><?= h($resp['question_text']) ?></p>
                    <?php endif; ?>

                    <div class="wg-resp-label">Respuesta del estudiante</div>
                    <div class="wg-response-box" id="respBox<?= $i ?>"><?= h($respText) ?></div>

                    <input type="hidden" name="question_id[]" value="<?= h($resp['question_id']) ?>">

                    <?php if ($practiceOnly): ?>
                        <div class="wg-practice-note">
                            ✍️ Esta respuesta es de <strong>escritura libre</strong>. El sistema la conserva como práctica de corrección y <strong>no genera puntaje</strong>.
                        </div>
                        <?php if ($alreadyGraded): ?>
                            <span class="wg-already-graded">✔ Revisado sin nota</span>
                        <?php endif; ?>
                    <?php elseif ($wsEnabled): ?>
                    <!-- ── Word-count scoring UI ── -->
                    <div class="wg-wc-row">
                        <label>Palabras (base):</label>
                        <span class="wg-wc-badge" id="autoWcBadge<?= $i ?>">
                            &#128200; Auto: <?= $autoWc ?> palabras
                        </span>
                        <input type="number" name="word_count[]" id="wcInp<?= $i ?>"
                               class="wg-wc-input"
                               min="0" value="<?= $prevBase ?>"
                               title="Puedes ajustar el conteo manualmente"
                               oninput="wgRecalc(<?= $i ?>)">
                    </div>

                    <div class="wg-err-grid">
                        <div class="wg-err-col">
                            <label>
                                Ortografía
                                <span class="wg-err-val">&times;<?= $pSpell ?> p.</span>
                            </label>
                            <input type="number" name="err_spelling[]" id="eSpel<?= $i ?>"
                                   class="wg-err-input" min="0" value="<?= $prevESpel ?>"
                                   oninput="wgRecalc(<?= $i ?>)">
                        </div>
                        <div class="wg-err-col">
                            <label>
                                Gramática
                                <span class="wg-err-val">&times;<?= $pGram ?> p.</span>
                            </label>
                            <input type="number" name="err_grammar[]" id="eGram<?= $i ?>"
                                   class="wg-err-input" min="0" value="<?= $prevEGram ?>"
                                   oninput="wgRecalc(<?= $i ?>)">
                        </div>
                        <div class="wg-err-col">
                            <label>
                                Puntuación
                                <span class="wg-err-val">&times;<?= $pPunct ?> p.</span>
                            </label>
                            <input type="number" name="err_punct[]" id="ePunct<?= $i ?>"
                                   class="wg-err-input" min="0" value="<?= $prevEPunct ?>"
                                   oninput="wgRecalc(<?= $i ?>)">
                        </div>
                    </div>

                    <div class="wg-result-bar" id="wgResult<?= $i ?>">
                        Cargando...
                    </div>

                    <?php if ($alreadyGraded): ?>
                        <span class="wg-already-graded">&#10004; Ya calificado</span>
                    <?php endif; ?>

                    <?php else: ?>
                    <!-- ── Manual scoring UI ── -->
                    <div class="wg-score-row">
                        <label for="score_<?= $i ?>">Puntaje:</label>
                        <input type="number" id="score_<?= $i ?>" name="score[]"
                               class="wg-score-input" min="0"
                               max="<?= (int) ($resp['max_points'] ?? 10) ?>"
                               value="<?= $resp['score'] !== null ? (int) $resp['score'] : '' ?>"
                               placeholder="—">
                        <span class="wg-max-label">/ <?= (int) ($resp['max_points'] ?? 10) ?> pts</span>
                        <?php if ($alreadyGraded): ?>
                            <span class="wg-already-graded">&#10004; Ya calificado</span>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="max_points[]" value="<?= (int) ($resp['max_points'] ?? 10) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="wg-actions">
                <button type="submit" class="wg-btn-save">&#128190; Guardar puntajes</button>
                <a href="<?= h($backUrl) ?>" class="wg-btn-back">&larr; Volver al progreso</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    var WS_ENABLED = <?= $wsEnabled ? 'true' : 'false' ?>;
    var P_SPELL    = <?= json_encode((float) $pSpell) ?>;
    var P_GRAM     = <?= json_encode((float) $pGram) ?>;
    var P_PUNCT    = <?= json_encode((float) $pPunct) ?>;

    window.wgRecalc = function (idx) {
        if (!WS_ENABLED) { return; }
        var base   = Math.max(0, parseInt(document.getElementById('wcInp'  + idx).value)  || 0);
        var eSpel  = Math.max(0, parseInt(document.getElementById('eSpel'  + idx).value)  || 0);
        var eGram  = Math.max(0, parseInt(document.getElementById('eGram'  + idx).value)  || 0);
        var ePunct = Math.max(0, parseInt(document.getElementById('ePunct' + idx).value)  || 0);
        var deduct = Math.round(eSpel * P_SPELL + eGram * P_GRAM + ePunct * P_PUNCT);
        var final  = Math.max(0, base - deduct);
        var pct    = base > 0 ? Math.min(100, Math.round((final / base) * 100)) : 0;
        var resEl  = document.getElementById('wgResult' + idx);
        if (!resEl) { return; }
        resEl.innerHTML =
            'Palabras: <span>' + base + '</span>'
            + ' &nbsp;&mdash;&nbsp; Descuento: <span style="color:#dc2626">' + deduct + '</span>'
            + ' &nbsp;=&nbsp; Puntaje final: <span>' + final + '</span>'
            + ' &nbsp;<span class="wg-result-pct">(' + pct + '%)</span>';
    };

    /* Init all cards on load */
    if (WS_ENABLED) {
        var n = document.querySelectorAll('.wg-card.ws-mode').length;
        for (var i = 0; i < n; i++) { wgRecalc(i); }
    }
})();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Calificar Writing Practice', 'fas fa-pen-nib', $content);

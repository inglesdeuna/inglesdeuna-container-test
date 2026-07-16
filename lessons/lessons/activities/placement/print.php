<?php
/**
 * placement/print.php — Printable Placement Test
 * Access: ?level=A2|B1|B2 (requires admin/teacher session)
 *         ?t={token}       (valid eval_links token)
 * Works without JavaScript.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ─── Auth Check ───────────────────────────────────────────────────────────────
$isAdmin   = !empty($_SESSION['admin_logged']);
$isTeacher = !empty($_SESSION['academic_logged']);
$token     = trim($_GET['t'] ?? '');
$levelGet  = strtoupper(trim($_GET['level'] ?? ''));

$examId    = 0;
$examRow   = null;
$authOk    = false;

// Admin or teacher session
if ($isAdmin || $isTeacher) {
    $authOk = true;
    if (in_array($levelGet, ['A2','B1','B2'], true)) {
        $stmt = $pdo->prepare(
            "SELECT id, title, cefr_level, time_limit_min FROM eval_exams
             WHERE cefr_level=? AND (is_placement=TRUE OR is_placement IS NULL)
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$levelGet]);
        $examRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $examId  = (int) ($examRow['id'] ?? 0);
    }
} elseif ($token !== '') {
    // Token-based access
    $lStmt = $pdo->prepare(
        "SELECT l.*, e.id AS exam_id_val, e.title AS exam_title,
                e.cefr_level AS exam_cefr, e.time_limit_min
         FROM eval_links l
         JOIN eval_exams e ON e.id = l.exam_id
         WHERE l.token=?
           AND (l.expires_at IS NULL OR l.expires_at > NOW())
           AND l.uses_count < l.max_uses
         LIMIT 1"
    );
    $lStmt->execute([$token]);
    $lRow = $lStmt->fetch(PDO::FETCH_ASSOC);
    if ($lRow) {
        $authOk  = true;
        $examId  = (int) $lRow['exam_id_val'];
        $examRow = [
            'id'            => $examId,
            'title'         => $lRow['exam_title'],
            'cefr_level'    => $lRow['exam_cefr'],
            'time_limit_min'=> $lRow['time_limit_min'],
        ];
    }
}

if (!$authOk || !$examRow) {
    http_response_code(403);
    ?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Acceso denegado</title>
<link rel="stylesheet" href="placement.css">
</head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8f7ff;">
<div style="text-align:center;padding:40px;">
    <h1 style="font-family:'Fredoka',sans-serif;color:#7F77DD;">Acceso denegado</h1>
    <p style="color:#7c7aa0;">Se requiere sesión de administrador/docente o un token válido.</p>
    <p><a href="../../academic/dashboard.php" style="color:#7F77DD;font-weight:700;">← Volver al dashboard</a></p>
</div>
</body></html><?php
    exit;
}

// ─── Load questions ───────────────────────────────────────────────────────────
$allRows = [];
if ($examId > 0) {
    $qStmt = $pdo->prepare(
        "SELECT q.id, q.skill, q.question_text, q.position,
                a.answer_text, a.is_correct, a.order_index
         FROM eval_questions q
         LEFT JOIN eval_answers a ON a.question_id = q.id
         WHERE q.exam_id=?
         ORDER BY q.position ASC, q.id ASC, a.order_index ASC"
    );
    $qStmt->execute([$examId]);
    $allRows = $qStmt->fetchAll(PDO::FETCH_ASSOC);
}

$questions = [];
foreach ($allRows as $row) {
    $qid = $row['id'];
    if (!isset($questions[$qid])) {
        $questions[$qid] = [
            'id'      => $qid,
            'skill'   => $row['skill'],
            'text'    => $row['question_text'],
            'options' => [],
            'correct' => null,
        ];
    }
    if ($row['answer_text'] !== null) {
        $questions[$qid]['options'][] = $row['answer_text'];
        if ($row['is_correct']) $questions[$qid]['correct'] = $row['answer_text'];
    }
}
$questions = array_values($questions);

$cefrLevel = $examRow['cefr_level'] ?? ($levelGet ?: 'A2');
$examTitle = $examRow['title'] ?? ('Placement Test — Nivel ' . $cefrLevel);
$timeLimit = (int) ($examRow['time_limit_min'] ?? 45);

$skillNames = ['grammar'=>'Grammar','vocabulary'=>'Vocabulary','reading'=>'Reading Comprehension'];
$letters    = ['A','B','C','D','E'];

// Group questions by skill
$bySkill = [];
foreach ($questions as $i => $q) {
    $bySkill[$q['skill']][] = array_merge($q, ['global_num' => $i + 1]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($examTitle) ?> — Versión Imprimible</title>
<link rel="stylesheet" href="placement.css">
<style>
body { background: #fff; font-family: 'Nunito', Arial, sans-serif; color: #000; }

/* Screen-only elements */
.no-print { padding: 12px 20px; background: #EDE9FA; border-bottom: 2px solid #7F77DD; display: flex; align-items: center; gap: 14px; }
.no-print a, .no-print button { font-weight: 700; color: #7F77DD; text-decoration: none; font-size: 14px; padding: 8px 16px; border-radius: 10px; background: #fff; border: 1.5px solid #7F77DD; cursor: pointer; }
.no-print a:hover, .no-print button:hover { background: #7F77DD; color: #fff; }

.print-container { max-width: 780px; margin: 0 auto; padding: 28px 24px 40px; }

/* Header */
.print-header { text-align: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 3px solid #2d2b55; }
.print-logo-text { font-family: 'Fredoka', sans-serif; font-size: 30px; line-height: 1; margin-bottom: 4px; }
.print-logo-text .logo-ones   { color: #F97316; }
.print-logo-text .logo-ingle  { color: #7F77DD; }
.print-title { font-family: 'Fredoka', sans-serif; font-size: 24px; color: #2d2b55; margin: 8px 0 4px; }
.print-meta  { font-size: 13px; color: #555; }
.print-level-badge { display: inline-block; padding: 4px 14px; border-radius: 999px; font-weight: 800; font-size: 13px; margin-top: 6px; }
.badge-A2 { background: #dcfce7; color: #15803d; border: 1.5px solid #86efac; }
.badge-B1 { background: #dbeafe; color: #1d4ed8; border: 1.5px solid #93c5fd; }
.badge-B2 { background: #ede9fe; color: #6d28d9; border: 1.5px solid #c4b5fd; }

/* Student fields */
.print-student-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px 32px;
    margin: 20px 0 28px;
    padding: 18px;
    border: 1.5px solid #ddd;
    border-radius: 10px;
    background: #fafafa;
}
.print-field label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #555; margin-bottom: 8px; }
.print-field-line  { border: none; border-bottom: 1.5px solid #333; height: 26px; width: 100%; display: block; background: transparent; }

/* Instructions box */
.print-instructions {
    border: 1.5px solid #7F77DD;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 24px;
    background: #f5f4ff;
}
.print-instructions p { margin: 0; font-size: 13px; color: #2d2b55; line-height: 1.6; }

/* Section titles */
.print-section-title {
    font-family: 'Fredoka', sans-serif;
    font-size: 17px;
    color: #7F77DD;
    margin: 24px 0 12px;
    padding: 6px 12px;
    border-left: 4px solid #7F77DD;
    background: #f5f4ff;
    border-radius: 0 8px 8px 0;
}

/* Questions */
.print-question { margin-bottom: 22px; page-break-inside: avoid; }
.print-q-header { display: flex; gap: 8px; margin-bottom: 6px; }
.print-q-num    { font-weight: 800; font-size: 14px; min-width: 28px; color: #F97316; }
.print-q-text   { font-size: 14px; line-height: 1.5; flex: 1; }

.print-options  { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; padding-left: 36px; margin-top: 8px; }
.print-option   { font-size: 13px; display: flex; align-items: flex-start; gap: 6px; }
.print-opt-letter { font-weight: 800; color: #7F77DD; min-width: 18px; }
.print-opt-circle { width: 16px; height: 16px; border: 1.5px solid #888; border-radius: 50%; display: inline-block; flex-shrink: 0; margin-top: 1px; }

/* Answer sheet */
.answer-sheet-title {
    font-family: 'Fredoka', sans-serif;
    font-size: 20px;
    color: #2d2b55;
    text-align: center;
    margin-bottom: 6px;
}
.answer-sheet-sub { text-align: center; font-size: 13px; color: #555; margin-bottom: 18px; }

.print-answer-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
.print-answer-item { border: 1px solid #ddd; border-radius: 8px; padding: 8px 6px; text-align: center; page-break-inside: avoid; }
.answer-item-num   { font-weight: 800; font-size: 12px; color: #F97316; display: block; margin-bottom: 6px; }
.answer-bubbles    { display: flex; justify-content: center; gap: 5px; }
.answer-bubble     { width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid #555; display: flex; align-items: center; justify-content: center; font-size: 8px; font-weight: 800; color: #555; }

/* Footer */
.print-footer { text-align: center; font-size: 11px; color: #777; margin-top: 32px; padding-top: 12px; border-top: 1px solid #ddd; }

@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .print-container { padding: 0; max-width: 100%; }
    .print-answer-sheet { page-break-before: always; }
    .print-section-new-page { page-break-before: auto; }
    a { color: inherit !important; text-decoration: none !important; }

    @page {
        margin: 18mm 14mm;
        size: A4 portrait;
    }
}
</style>
</head>
<body>

<!-- Screen-only toolbar -->
<div class="no-print">
    <strong style="color:#7F77DD;font-family:'Fredoka',sans-serif;font-size:16px;">
        🖨️ Placement Test — <?= h($cefrLevel) ?>
    </strong>
    <button onclick="window.print()">Imprimir / Guardar PDF</button>
    <a href="index.php?level=<?= h($cefrLevel) ?>">← Volver al admin</a>
    <?php if ($isAdmin || $isTeacher): ?>
        <a href="viewer.php?level=<?= h($cefrLevel) ?>" target="_blank">Vista estudiante</a>
    <?php endif; ?>
</div>

<div class="print-container">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="print-header">
        <div class="print-logo-text">
            <span class="logo-ones">ONES</span><span class="logo-ingle"> inglesdeuna</span>
        </div>
        <div class="print-title">PLACEMENT TEST — NIVEL <?= h(strtoupper($cefrLevel)) ?></div>
        <div class="print-meta">
            inglesdeuna.com &nbsp;·&nbsp; Tiempo: <?= $timeLimit ?> minutos &nbsp;·&nbsp;
            Total: <?= count($questions) ?> preguntas
        </div>
        <div>
            <span class="print-level-badge badge-<?= h($cefrLevel) ?>">
                <?= h($cefrLevel) ?>
            </span>
        </div>
    </div>

    <!-- ── Student Info Fields ─────────────────────────────────────────── -->
    <div class="print-student-fields">
        <div class="print-field">
            <label>Nombre completo</label>
            <span class="print-field-line"></span>
        </div>
        <div class="print-field">
            <label>Cédula (CC)</label>
            <span class="print-field-line"></span>
        </div>
        <div class="print-field">
            <label>Teléfono / WhatsApp</label>
            <span class="print-field-line"></span>
        </div>
        <div class="print-field">
            <label>Email</label>
            <span class="print-field-line"></span>
        </div>
        <div class="print-field">
            <label>Ciudad</label>
            <span class="print-field-line"></span>
        </div>
        <div class="print-field">
            <label>Fecha</label>
            <span class="print-field-line"></span>
        </div>
    </div>

    <!-- ── Instructions ───────────────────────────────────────────────── -->
    <div class="print-instructions">
        <p>
            <strong>Instructions:</strong> Choose the best answer (A, B, C or D) for each question.
            Circle your answer in the answer sheet at the end.
            You have <strong><?= $timeLimit ?> minutes</strong> to complete the test.
            Do not use a dictionary or any other resources.
        </p>
    </div>

    <!-- ── Questions by section ───────────────────────────────────────── -->
    <?php foreach (['grammar','vocabulary','reading'] as $skillKey):
        $sqs = $bySkill[$skillKey] ?? [];
        if (empty($sqs)) continue;
    ?>
        <div class="print-section-title">
            <?= h($skillNames[$skillKey] ?? ucfirst($skillKey)) ?>
            <span style="font-size:13px;font-weight:600;color:#7c7aa0;font-family:'Nunito',sans-serif;">
                (<?= count($sqs) ?> question<?= count($sqs) > 1 ? 's' : '' ?>)
            </span>
        </div>

        <?php foreach ($sqs as $q): ?>
            <div class="print-question">
                <div class="print-q-header">
                    <span class="print-q-num"><?= (int)$q['global_num'] ?>.</span>
                    <span class="print-q-text"><?= h($q['text']) ?></span>
                </div>
                <div class="print-options">
                    <?php foreach ($q['options'] as $idx => $opt): ?>
                        <div class="print-option">
                            <span class="print-opt-circle"></span>
                            <span class="print-opt-letter"><?= h($letters[$idx] ?? chr(65+$idx)) ?></span>
                            <span><?= h($opt) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endforeach; ?>

    <!-- ── Answer Sheet ────────────────────────────────────────────────── -->
    <div class="print-answer-sheet" style="margin-top:40px;padding-top:24px;border-top:2px solid #2d2b55;">
        <h2 class="answer-sheet-title">Answer Sheet / Hoja de Respuestas</h2>
        <p class="answer-sheet-sub">
            Circle the letter of your answer for each question.
        </p>

        <div class="print-answer-grid">
            <?php for ($n = 1; $n <= count($questions); $n++): ?>
                <div class="print-answer-item">
                    <span class="answer-item-num"><?= $n ?></span>
                    <div class="answer-bubbles">
                        <?php foreach (['A','B','C','D'] as $lt): ?>
                            <div class="answer-bubble"><?= $lt ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- ── Footer ─────────────────────────────────────────────────────── -->
    <div class="print-footer">
        <strong>ONES — Online English Solution</strong> &nbsp;·&nbsp;
        inglesdeuna.com &nbsp;·&nbsp;
        Placement Test Nivel <?= h($cefrLevel) ?> &nbsp;·&nbsp;
        Tiempo: <?= $timeLimit ?> minutos &nbsp;·&nbsp;
        <?= count($questions) ?> preguntas
    </div>

</div><!-- /print-container -->

</body>
</html>

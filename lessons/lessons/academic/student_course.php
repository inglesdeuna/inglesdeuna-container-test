<?php
session_start();

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password'])) {
    header('Location: change_password_student.php');
    exit;
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$studentId = trim((string) ($_SESSION['student_id'] ?? ''));

if ($assignmentId === '') {
    die('Asignación no especificada.');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    static $cachedPdo = null;
    static $loaded = false;

    if ($loaded) {
        return $cachedPdo;
    }

    $loaded = true;

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        if (isset($pdo) && $pdo instanceof PDO) {
            $cachedPdo = $pdo;
        }
    } catch (Throwable $e) {
        return null;
    }

    return $cachedPdo;
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_student_performance_tables(PDO $pdo): void
{
    try {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_unit_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              quiz_errors INTEGER NOT NULL DEFAULT 0,\n              quiz_total INTEGER NOT NULL DEFAULT 0,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id)\n            )\n        ");
    } catch (Throwable $e) {
    }
}

function save_student_unit_performance(PDO $pdo, string $studentId, string $assignmentId, string $unitId, int $completionPercent, int $quizErrors, int $quizTotal): void
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO student_unit_results (student_id, assignment_id, unit_id, completion_percent, quiz_errors, quiz_total, updated_at)\n            VALUES (:student_id, :assignment_id, :unit_id, :completion_percent, :quiz_errors, :quiz_total, NOW())\n            ON CONFLICT (student_id, assignment_id, unit_id)\n            DO UPDATE SET\n              completion_percent = EXCLUDED.completion_percent,\n              quiz_errors = EXCLUDED.quiz_errors,\n              quiz_total = EXCLUDED.quiz_total,\n              updated_at = NOW()\n        ");

        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
            'completion_percent' => max(0, min(100, $completionPercent)),
            'quiz_errors' => max(0, $quizErrors),
            'quiz_total' => max(0, $quizTotal),
        ]);
    } catch (Throwable $e) {
    }
}

function load_student_unit_results(PDO $pdo, string $studentId, string $assignmentId): array
{
    if ($studentId === '' || $assignmentId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT unit_id, completion_percent, quiz_errors, quiz_total\n            FROM student_unit_results\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mapped = [];
        foreach ($rows as $row) {
            $unitId = (string) ($row['unit_id'] ?? '');
            if ($unitId === '') {
                continue;
            }
            $mapped[$unitId] = [
                'completion_percent' => (int) ($row['completion_percent'] ?? 0),
                'quiz_errors' => (int) ($row['quiz_errors'] ?? 0),
                'quiz_total' => (int) ($row['quiz_total'] ?? 0),
            ];
        }

        return $mapped;
    } catch (Throwable $e) {
        return [];
    }
}

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    try {
        $stmt = $pdo->prepare("\n            SELECT sa.id, sa.student_id, sa.teacher_id, sa.course_id, sa.period, sa.program, sa.unit_id, sa.level_id,\n                   t.name AS teacher_name,\n                   c.name AS course_name\n            FROM student_assignments sa\n            LEFT JOIN teachers t ON t.id = sa.teacher_id\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            WHERE sa.id = :id\n            LIMIT 1\n        ");
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_units_for_assignment(PDO $pdo, array $assignment): array
{
    $courseId = trim((string) ($assignment['course_id'] ?? ''));
    if ($courseId === '') {
        return [];
    }

    $program = trim((string) ($assignment['program'] ?? ''));

    try {
        $orderBy = table_has_column($pdo, 'units', 'position') ? 'ORDER BY position ASC, id ASC' : 'ORDER BY id ASC';

        if ($program === 'english' && table_has_column($pdo, 'units', 'phase_id')) {
            $stmt = $pdo->prepare("SELECT id, name FROM units WHERE phase_id::text = :course_id {$orderBy}");
            $stmt->execute(['course_id' => $courseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($rows)) {
                return $rows;
            }
        }

        $stmt = $pdo->prepare("SELECT id, name FROM units WHERE course_id::text = :course_id {$orderBy}");
        $stmt->execute(['course_id' => $courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_activities_for_unit(PDO $pdo, string $unitId): array
{
    if ($unitId === '') {
        return [];
    }

    try {
        $orderBy = table_has_column($pdo, 'activities', 'position')
            ? 'ORDER BY COALESCE(position, 0) ASC, id ASC'
            : 'ORDER BY id ASC';

        $stmt = $pdo->prepare("SELECT id, type FROM activities WHERE unit_id::text = :unit_id {$orderBy}");
        $stmt->execute(['unit_id' => $unitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_activity_base_path(string $type): ?string
{
    if (!preg_match('/^[a-z0-9_]+$/i', $type)) {
        return null;
    }

    $absolute = __DIR__ . '/../activities/' . $type;
    if (!is_dir($absolute)) {
        return null;
    }

    return '../activities/' . rawurlencode($type);
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

ensure_student_performance_tables($pdo);

$assignment = load_assignment($pdo, $assignmentId);
if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
    die('No tienes acceso a este curso.');
}

$selectedUnitId = trim((string) ($_GET['unit'] ?? (string) ($assignment['unit_id'] ?? '')));
$quizTotalRaw = isset($_GET['quiz_total']) ? (int) $_GET['quiz_total'] : -1;
$quizErrorsRaw = isset($_GET['quiz_errors']) ? (int) $_GET['quiz_errors'] : -1;
$quizPercentRaw = isset($_GET['quiz_percent']) ? (int) $_GET['quiz_percent'] : -1;

if ($selectedUnitId !== '' && $quizTotalRaw >= 0) {
    $quizTotal = max(0, $quizTotalRaw);
    $quizErrors = max(0, $quizErrorsRaw);
    if ($quizTotal > 0 && $quizErrors > $quizTotal) {
        $quizErrors = $quizTotal;
    }

    $quizPercent = $quizTotal > 0
        ? max(0, min(100, (int) round((($quizTotal - $quizErrors) / $quizTotal) * 100)))
        : max(0, min(100, $quizPercentRaw));

    save_student_unit_performance($pdo, $studentId, $assignmentId, $selectedUnitId, $quizPercent, $quizErrors, $quizTotal);
}

$allUnits = load_units_for_assignment($pdo, $assignment);
$unitResults = load_student_unit_results($pdo, $studentId, $assignmentId);
$courseName = trim((string) ($assignment['course_name'] ?? 'Curso'));
if ($courseName === '') {
    $courseName = 'Curso';
}
$teacherName = trim((string) ($assignment['teacher_name'] ?? 'Docente'));
$programLabel = ((string) ($assignment['program'] ?? '') === 'english') ? 'Inglés' : 'Técnico';

/* ---- Determine active unit ---- */
if ($selectedUnitId === '' && !empty($allUnits)) {
    $selectedUnitId = (string) ($allUnits[0]['id'] ?? '');
}

$selectedUnitName = 'Unidad';
foreach ($allUnits as $_u) {
    if ((string) ($_u['id'] ?? '') === $selectedUnitId) {
        $selectedUnitName = (string) ($_u['name'] ?? 'Unidad');
        break;
    }
}

/* ---- Activities for selected unit ---- */
$step = max(0, (int) ($_GET['step'] ?? 0));
$activities = $selectedUnitId !== '' ? load_activities_for_unit($pdo, $selectedUnitId) : [];
$total = count($activities);
$isCompleted = $total > 0 && $step >= $total;
$current = (!$isCompleted && $total > 0) ? $activities[$step] : null;
$prevStep = max(0, $step - 1);
$nextStep = $step + 1;
$hasPrev = $step > 0;
$hasNext = $nextStep < $total;
$isLastActivity = !$isCompleted && $total > 0 && $step === ($total - 1);

$activityTypeLabels = [
    'flashcards' => 'Flashcards', 'memory_cards' => 'Memory Cards', 'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice', 'video_comprehension' => 'Video Comprehension',
    'flipbooks' => 'Video Lesson', 'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation', 'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop', 'match' => 'Match',
    'external' => 'External', 'powerpoint' => 'PowerPoint',
    'crossword' => 'Crossword Puzzle',
];

$viewerHref = null;
$currentTypeLabel = 'Actividad';
if ($current) {
    $type = (string) ($current['type'] ?? '');
    $activityPath = get_activity_base_path($type);
    if ($activityPath) {
        $returnUrl = 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId);
        $query = http_build_query([
            'id'         => (string) ($current['id'] ?? ''),
            'unit'       => $selectedUnitId,
            'embedded'   => '1',
            'from'       => 'student_course',
            'assignment' => $assignmentId,
            'return_to'  => $returnUrl,
        ]);
        $viewerHref = $activityPath . '/viewer.php?' . $query;
    }
    $currentType = strtolower(trim($type));
    $currentTypeLabel = $activityTypeLabels[$currentType] ?? ucwords(str_replace('_', ' ', $type));
}

$unitResult = $unitResults[$selectedUnitId] ?? ['completion_percent' => 0, 'quiz_errors' => 0, 'quiz_total' => 0];
$completionPercent = (int) ($unitResult['completion_percent'] ?? 0);
$quizErrors = (int) ($unitResult['quiz_errors'] ?? 0);
$quizTotal = (int) ($unitResult['quiz_total'] ?? 0);
$hasUnitResult = $quizTotal > 0;

$backHref = 'student_dashboard.php';
$completedStep = max(9999, $total);
$completedHref = 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '&step=' . urlencode((string) $completedStep);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($currentTypeLabel . ' — ' . $courseName); ?></title>
<style>
:root{
    --bg:#fff8e6;
    --card:#ffffff;
    --line:#dcc4f0;
    --title:#a855c8;
    --text:#f14902;
    --muted:#b8551f;
    --salmon:#f14902;
    --salmon-dark:#d33d00;
    --salmon-soft:#eddeff;
    --shadow:0 10px 24px rgba(120,40,160,.12);
    --shadow-sm:0 2px 8px rgba(0,0,0,.06);
    --radius:18px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(145deg,#fff8e6 0%,#fdeaff 55%,#f0e0ff 100%);color:var(--text)}

.topbar{
    background:linear-gradient(180deg,#f14902,#d33d00);
    color:#fff;
    padding:16px 24px;
}
.topbar-inner{
    max-width:1280px;
    margin:0 auto;
    display:grid;
    grid-template-columns:180px 1fr;
    align-items:center;
    gap:12px;
}
.top-btn{
    display:inline-flex;align-items:center;justify-content:center;
    padding:10px 14px;border-radius:10px;text-decoration:none;
    font-size:13px;font-weight:700;color:#fff;
    background:rgba(255,255,255,.2);
}
.topbar-title{font-size:26px;font-weight:800;text-align:center}

.page{max-width:1280px;margin:0 auto;padding:18px 20px 28px}
.content{display:flex;flex-direction:column;gap:18px;min-width:0}

.hero-card{
    background:var(--card);border:1px solid var(--line);border-radius:22px;
    box-shadow:var(--shadow);padding:18px 20px;
}
.activity-topline{
    display:inline-flex;align-items:center;gap:8px;padding:5px 10px;
    border-radius:999px;background:var(--salmon-soft);color:var(--salmon);
    font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;
}
.hero-title{margin:10px 0 8px;font-size:20px;font-weight:800;color:var(--muted)}
.hero-badges{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
.hero-badge{
    display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;
    background:var(--salmon-soft);color:var(--salmon);font-size:12px;font-weight:800;
}
.hero-badge.blue{background:#e0f5fd;color:#0d7a9a}

.viewer-shell{
    background:var(--card);border:1px solid var(--line);
    border-radius:22px;box-shadow:var(--shadow);padding:18px;
}
.viewer-top{
    display:flex;align-items:center;justify-content:space-between;
    gap:14px;margin-bottom:14px;flex-wrap:wrap;
}
.section-title{
    display:flex;align-items:center;gap:12px;
    font-size:22px;font-weight:800;color:var(--muted);
}
.section-title::after{
    content:"";flex:1;height:2px;min-width:60px;
    background:linear-gradient(90deg,var(--line) 0%,transparent 100%);
}
.act-badge{
    display:inline-flex;align-items:center;padding:7px 12px;
    border-radius:999px;background:var(--salmon-soft);color:var(--salmon);
    font-size:12px;font-weight:800;text-transform:uppercase;
}
.frame-wrap{
    border-radius:18px;overflow:hidden;background:#fff;
    border:1px solid var(--line);box-shadow:var(--shadow-sm);min-height:78vh;
}
.frame-wrap iframe{display:block;width:100%;height:78vh;border:0;background:#fff}

.controls{
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;padding-top:16px;
}
.step-counter{font-size:13px;font-weight:700;color:var(--muted);text-align:center}
.step-counter strong{color:var(--salmon)}

.ctrl-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    min-width:130px;padding:12px 18px;border-radius:12px;text-decoration:none;
    color:#fff;font-size:14px;font-weight:700;
    background:linear-gradient(180deg,#f14902,#d33d00);
    box-shadow:var(--shadow-sm);transition:filter .15s,transform .15s;
}
.ctrl-btn.blue{background:linear-gradient(180deg,#c97de8,#8b1a9a)}
.ctrl-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.ctrl-btn.disabled{opacity:.38;pointer-events:none}

/* units sidebar strip */
.units-strip{
    display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;
}
.unit-chip{
    display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;
    font-size:13px;font-weight:700;text-decoration:none;
    background:var(--card);border:2px solid var(--line);color:var(--muted);
    transition:background .15s,border-color .15s;
}
.unit-chip:hover{border-color:var(--salmon);color:var(--salmon)}
.unit-chip.active{background:var(--salmon);border-color:var(--salmon);color:#fff}

/* empty / completed */
.empty-shell{
    background:var(--card);border:1px solid var(--line);border-radius:22px;
    box-shadow:var(--shadow);padding:48px 24px;text-align:center;
}
.empty-state{display:flex;flex-direction:column;align-items:center;gap:14px}
.empty-icon{font-size:46px}
.empty-title{font-size:24px;font-weight:800;color:var(--muted)}
.empty-text{max-width:480px;font-size:15px;line-height:1.6;color:var(--muted)}
.empty-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    padding:13px 22px;border-radius:12px;text-decoration:none;color:#fff;
    font-size:15px;font-weight:700;background:linear-gradient(180deg,#f14902,#d33d00);
    box-shadow:var(--shadow-sm);margin-top:4px;
}
.empty-btn.blue{background:linear-gradient(180deg,#c97de8,#8b1a9a)}

@media(max-width:768px){
    .topbar-inner{grid-template-columns:1fr;text-align:center}
    .page{padding:12px}
    .frame-wrap{min-height:56vh}
    .frame-wrap iframe{height:56vh}
    .controls{flex-wrap:wrap}
    .ctrl-btn,.empty-btn{flex:1 1 100%;min-width:0}
    .step-counter{width:100%;order:-1}
}
</style>
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a class="top-btn" href="<?php echo h($backHref); ?>">← Volver</a>
        <h1 class="topbar-title"><?php echo h($courseName); ?></h1>
    </div>
</header>

<div class="page">
<main class="content">

    <!-- Unit selector strip (only if multiple units) -->
    <?php if (count($allUnits) > 1): ?>
    <div class="units-strip">
        <?php foreach ($allUnits as $_unit):
            $_uid = (string) ($_unit['id'] ?? '');
            $_uname = (string) ($_unit['name'] ?? 'Unidad');
            $_href = 'student_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($_uid);
        ?>
            <a class="unit-chip <?php echo $_uid === $selectedUnitId ? 'active' : ''; ?>"
               href="<?php echo h($_href); ?>">
                <?php echo h($_uname); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Hero info card -->
    <section class="hero-card">
        <div class="activity-topline">Actividad del curso</div>
        <h1 class="hero-title"><?php echo h($currentTypeLabel); ?></h1>
        <div class="hero-badges">
            <span class="hero-badge"><?php echo h($courseName); ?></span>
            <?php if ($selectedUnitName !== 'Unidad' && $selectedUnitName !== ''): ?>
                <span class="hero-badge blue"><?php echo h($selectedUnitName); ?></span>
            <?php endif; ?>
            <span class="hero-badge">Docente: <?php echo h($teacherName); ?></span>
            <?php if ($completionPercent > 0): ?>
                <span class="hero-badge">Puntaje: <?php echo $completionPercent; ?>%</span>
            <?php endif; ?>
            <span class="hero-badge"><?php echo h($programLabel); ?></span>
        </div>
    </section>

    <?php if ($isCompleted): ?>
    <!-- COMPLETED -->
    <section class="empty-shell">
        <div class="empty-state">
            <div class="empty-icon">🏁</div>
            <div class="empty-title">¡Unidad completada!</div>
            <div class="empty-text">
                Terminaste todas las actividades de esta unidad.
                <?php if ($hasUnitResult): ?>
                    Tu puntaje es <strong><?php echo $completionPercent; ?>%</strong>
                    (errores: <?php echo $quizErrors; ?>/<?php echo $quizTotal; ?>).
                <?php else: ?>
                    Completa el quiz para registrar tu resultado.
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
                <a class="empty-btn blue" href="<?php echo h($backHref); ?>">← Mis cursos</a>
                <a class="empty-btn"
                   href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=0">
                   Repetir unidad
                </a>
            </div>
        </div>
    </section>

    <?php elseif (!$current || !$viewerHref): ?>
    <!-- NO ACTIVITIES -->
    <section class="empty-shell">
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <div class="empty-title">Sin actividades disponibles</div>
            <div class="empty-text">Esta unidad aún no tiene actividades o el tipo de actividad no cuenta con visor configurado.</div>
            <a class="empty-btn blue" href="<?php echo h($backHref); ?>">← Mis cursos</a>
        </div>
    </section>

    <?php else: ?>
    <!-- ACTIVITY VIEWER -->
    <section class="viewer-shell">
        <div class="viewer-top">
            <h2 class="section-title">Presentación de actividades</h2>
            <span class="act-badge">Actividad <?php echo ($step + 1); ?> / <?php echo $total; ?></span>
        </div>

        <div class="frame-wrap">
            <iframe
                id="activityViewer"
                src="<?php echo h($viewerHref); ?>"
                title="Visor de actividad"
            ></iframe>
        </div>

        <div class="controls">
            <a class="ctrl-btn blue <?php echo $hasPrev ? '' : 'disabled'; ?>"
               href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">
                &larr; Anterior
            </a>
            <div class="step-counter">
                <strong><?php echo ($step + 1); ?></strong> / <?php echo $total; ?>
            </div>
            <a class="ctrl-btn <?php echo ($hasNext || $isLastActivity) ? '' : 'disabled'; ?>"
               href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&step=<?php echo $isLastActivity ? $completedStep : ($hasNext ? $nextStep : $step); ?>">
                <?php echo $isLastActivity ? 'Finalizar unidad' : 'Siguiente &rarr;'; ?>
            </a>
        </div>
    </section>
    <?php endif; ?>

</main>
</div>

<script>
(function () {
    const iframe = document.getElementById('activityViewer');
    if (!iframe) return;

    function hideEmbeddedBackButton() {
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return;

            const selectors = [
                '.back','.btn-volver','.back-button','.btn.back','.back-btn',
                '[class*="back"]','[id*="back"]',
                'a[href*="dashboard"]','a[href*="unit_view"]',
                'a[href*="student_course"]','a[href*="course.php"]'
            ];

            selectors.forEach((selector) => {
                doc.querySelectorAll(selector).forEach((el) => {
                    const text = (el.textContent || '').toLowerCase();
                    const href = (el.getAttribute('href') || '').toLowerCase();
                    if (
                        text.includes('volver') || text.includes('back') ||
                        text.includes('regresar') || text.includes('mis cursos') ||
                        href.includes('dashboard') || href.includes('unit_view') ||
                        href.includes('student_course') || href.includes('course.php')
                    ) {
                        el.style.display = 'none';
                    }
                });
            });

            const style = doc.createElement('style');
            style.innerHTML = 'body{ margin-top:0 !important; padding-top:0 !important; }';
            doc.head.appendChild(style);
        } catch (e) {
            // cross-origin — ignore
        }
    }

    iframe.addEventListener('load', hideEmbeddedBackButton);
})();
</script>
</body>
</html>

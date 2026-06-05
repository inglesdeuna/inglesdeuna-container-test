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

$assignmentFilter = trim((string) ($_GET['assignment'] ?? ''));
$studentId = trim((string) ($_SESSION['student_id'] ?? ''));

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

function ensure_quiz_state_table(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_quiz_state(student_id TEXT NOT NULL,assignment_id TEXT NOT NULL,unit_id TEXT NOT NULL,attempt_number INTEGER NOT NULL DEFAULT 1,quiz_set_json TEXT NOT NULL DEFAULT '[]',answers_json TEXT NOT NULL DEFAULT '{}',is_completed BOOLEAN NOT NULL DEFAULT FALSE,score_percent INTEGER NOT NULL DEFAULT 0,correct_count INTEGER NOT NULL DEFAULT 0,wrong_count INTEGER NOT NULL DEFAULT 0,skip_count INTEGER NOT NULL DEFAULT 0,total_count INTEGER NOT NULL DEFAULT 0,started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),completed_at TIMESTAMPTZ,PRIMARY KEY(student_id,assignment_id,unit_id,attempt_number))");
    } catch (Throwable $e) {
    }
}

function load_assignments(PDO $pdo, string $studentId, string $assignmentFilter = ''): array
{
    if ($studentId === '') {
        return [];
    }

    $hasUnitPosition = table_has_column($pdo, 'units', 'position');
    $hasUnitPhaseId = table_has_column($pdo, 'units', 'phase_id');
    $unitOrderExpr = $hasUnitPosition
        ? 'COALESCE(u.position, 2147483647), u.id::text'
        : 'u.id::text';
    $englishUnitsCondition = $hasUnitPhaseId
        ? "((ab.phase_key <> '' AND u.phase_id::text = ab.phase_key) OR (ab.phase_key = '' AND u.course_id::text = ab.course_id))"
        : "u.course_id::text = ab.course_id";
    $assignmentFilterSql = $assignmentFilter !== '' ? 'AND sa.id = :assignment_id' : '';

    $sql = "
        WITH assignment_base AS (
            SELECT sa.id::text AS assignment_id,
                   sa.student_id::text AS student_id,
                   COALESCE(sa.course_id::text, '') AS course_id,
                   COALESCE(sa.level_id::text, '') AS level_id,
                   LOWER(COALESCE(sa.program, '')) AS program_key,
                   CASE WHEN LOWER(COALESCE(sa.program, '')) = 'english' THEN 'INGLÉS' ELSE 'TÉCNICO' END AS program_name,
                   COALESCE(NULLIF(c.name, ''), 'Course') AS course_name,
                   COALESCE(NULLIF(t.name, ''), '') AS teacher_name,
                   COALESCE(sa.period::text, '') AS period_number,
                   CASE
                       WHEN LOWER(COALESCE(sa.program, '')) = 'english'
                           THEN COALESCE(NULLIF(sa.level_id::text, ''), COALESCE(sa.course_id::text, ''))
                       ELSE COALESCE(sa.level_id::text, '')
                   END AS phase_key,
                   COALESCE(NULLIF(ep.name, ''), 'Phase') AS phase_name,
                   sa.updated_at AS updated_at
            FROM student_assignments sa
            LEFT JOIN courses c ON c.id::text = sa.course_id::text
            LEFT JOIN teachers t ON t.id = sa.teacher_id
            LEFT JOIN english_phases ep ON ep.id::text = (
                CASE
                    WHEN LOWER(COALESCE(sa.program, '')) = 'english'
                        THEN COALESCE(NULLIF(sa.level_id::text, ''), COALESCE(sa.course_id::text, ''))
                    ELSE COALESCE(sa.level_id::text, '')
                END
            )
            WHERE sa.student_id::text = :student_id
              {$assignmentFilterSql}
        ),
        assignment_units AS (
            SELECT ab.assignment_id,
                   ab.program_name,
                   ab.course_name,
                   ab.phase_name,
                   ab.teacher_name,
                   ab.period_number,
                   ab.updated_at,
                   u.id::text AS unit_id,
                   COALESCE(NULLIF(u.name, ''), 'Unit') AS unit_title,
                   ROW_NUMBER() OVER (
                       PARTITION BY ab.assignment_id
                       ORDER BY {$unitOrderExpr}
                   ) AS unit_number
            FROM assignment_base ab
            LEFT JOIN units u
              ON (
                  (ab.program_key = 'english' AND {$englishUnitsCondition})
                  OR (ab.program_key <> 'english' AND u.course_id::text = ab.course_id)
              )
        ),
        quiz_attempts AS (
            SELECT sqs.assignment_id::text AS assignment_id,
                   sqs.unit_id::text AS unit_id,
                   sqs.attempt_number,
                   sqs.score_percent,
                   ROW_NUMBER() OVER (
                       PARTITION BY sqs.assignment_id::text, sqs.unit_id::text
                       ORDER BY sqs.attempt_number ASC
                   ) AS attempt_order,
                   ROW_NUMBER() OVER (
                       PARTITION BY sqs.assignment_id::text, sqs.unit_id::text
                       ORDER BY sqs.score_percent DESC, sqs.attempt_number ASC
                   ) AS best_order
            FROM student_quiz_state sqs
            WHERE sqs.student_id::text = :student_id
              AND sqs.is_completed = TRUE
        )
        SELECT au.assignment_id,
               au.program_name,
               au.course_name,
               au.phase_name,
               au.teacher_name,
               au.period_number,
               au.updated_at,
               au.unit_id,
               au.unit_title,
               au.unit_number,
               MAX(CASE WHEN qa.attempt_order = 1 THEN qa.score_percent END) AS attempt1_score,
               MAX(CASE WHEN qa.attempt_order = 2 THEN qa.score_percent END) AS attempt2_score,
               MAX(CASE WHEN qa.best_order = 1 THEN qa.score_percent END) AS best_score,
               MAX(CASE WHEN qa.best_order = 1 THEN qa.attempt_number END) AS best_attempt_number
        FROM assignment_units au
        LEFT JOIN quiz_attempts qa
          ON qa.assignment_id = au.assignment_id
         AND qa.unit_id = au.unit_id
        GROUP BY au.assignment_id,
                 au.program_name,
                 au.course_name,
                 au.phase_name,
                 au.teacher_name,
                 au.period_number,
                 au.updated_at,
                 au.unit_id,
                 au.unit_title,
                 au.unit_number
        ORDER BY au.updated_at DESC NULLS LAST,
                 au.assignment_id DESC,
                 au.unit_number ASC,
                 au.unit_id ASC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $params = ['student_id' => $studentId];
        if ($assignmentFilter !== '') {
            $params['assignment_id'] = $assignmentFilter;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $assignmentsMap = [];
    foreach ($rows as $row) {
        $assignmentId = trim((string) ($row['assignment_id'] ?? ''));
        if ($assignmentId === '') {
            continue;
        }

        if (!isset($assignmentsMap[$assignmentId])) {
            $assignmentsMap[$assignmentId] = [
                'assignment_id' => $assignmentId,
                'program_name' => trim((string) ($row['program_name'] ?? 'TÉCNICO')),
                'phase_name' => trim((string) ($row['phase_name'] ?? 'Phase')),
                'course_name' => trim((string) ($row['course_name'] ?? 'Course')),
                'teacher_name' => trim((string) ($row['teacher_name'] ?? '')),
                'period_number' => trim((string) ($row['period_number'] ?? '')),
                'units' => [],
            ];
        }

        $unitId = trim((string) ($row['unit_id'] ?? ''));
        if ($unitId === '') {
            continue;
        }

        $attempt1 = is_numeric($row['attempt1_score'] ?? null) ? (int) $row['attempt1_score'] : null;
        $attempt2 = is_numeric($row['attempt2_score'] ?? null) ? (int) $row['attempt2_score'] : null;
        $bestScore = is_numeric($row['best_score'] ?? null) ? (int) $row['best_score'] : null;
        $bestAttemptNumber = is_numeric($row['best_attempt_number'] ?? null) ? (int) $row['best_attempt_number'] : null;

        if ($bestScore === null) {
            $status = 'pending';
        } elseif ($bestScore >= 60) {
            $status = 'passed';
        } elseif ($attempt1 !== null && $attempt2 !== null) {
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        $unitNumber = is_numeric($row['unit_number'] ?? null)
            ? (int) $row['unit_number']
            : (count($assignmentsMap[$assignmentId]['units']) + 1);

        $assignmentsMap[$assignmentId]['units'][] = [
            'unit_number' => $unitNumber,
            'unit_title' => trim((string) ($row['unit_title'] ?? ('Unit ' . $unitNumber))),
            'attempt1_score' => $attempt1,
            'attempt2_score' => $attempt2,
            'best_score' => $bestScore,
            'status' => $status,
            'quiz_attempt_id' => $bestAttemptNumber !== null ? ($assignmentId . ':' . $unitId . ':' . $bestAttemptNumber) : null,
            'unit_id' => $unitId,
        ];
    }

    return array_values($assignmentsMap);
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

ensure_quiz_state_table($pdo);
$assignments = load_assignments($pdo, $studentId, $assignmentFilter);
$showGlobalSummary = $assignmentFilter === '';

$total_units_done = 0;
$all_scores = [];
$global_passed = 0;
foreach ($assignments as $a) {
    foreach ($a['units'] as $u) {
        if ($u['status'] !== 'pending') {
            $total_units_done++;
        }
        if ($u['best_score'] !== null) {
            $all_scores[] = (int) $u['best_score'];
        }
        if ($u['status'] === 'passed') {
            $global_passed++;
        }
    }
}
$global_avg = count($all_scores) ? (int) round(array_sum($all_scores) / count($all_scores)) : null;
$total_courses = count($assignments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Quiz Progress</title>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
:root{
  --orange:#F97316;
  --purple:#7F77DD;
  --green:#1D9E75;
  --red:#E24B4A;
  --muted:#9B8FCC;
  --bg:#F8F7FF;
  --card:#fff;
  --line:#EDE9FA;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#2E2A58;font-family:'Nunito','Segoe UI',sans-serif}
.topbar{position:sticky;top:0;z-index:5;background:#fff;border-bottom:1px solid var(--line)}
.topbar-inner{max-width:1080px;margin:0 auto;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.page-title{margin:0;font:700 30px 'Fredoka',sans-serif;color:var(--purple)}
.back-btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;background:#F3F1FE;color:var(--purple);border:1px solid #DDD8F8;border-radius:12px;padding:9px 14px;font-weight:900}
.page{max-width:1080px;margin:0 auto;padding:20px 18px 40px}
.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
.summary-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px 16px}
.summary-card b{display:block;font:700 34px 'Fredoka',sans-serif;line-height:1}
.summary-card span{display:block;margin-top:6px;color:var(--muted);font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.summary-done b{color:var(--orange)}
.summary-avg b{color:var(--purple)}
.summary-pass b{color:var(--green)}
.summary-course b{color:var(--purple)}
.course{background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;margin-bottom:12px}
.course-toggle{width:100%;border:0;background:transparent;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;text-align:left}
.course-left{display:flex;gap:12px;align-items:center;min-width:0}
.course-icon{width:42px;height:42px;border-radius:12px;background:#FFF2E9;color:var(--orange);display:inline-flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 42px}
.course-title-wrap{min-width:0}
.course-kicker{display:block;font-size:11px;color:var(--muted);font-weight:900;letter-spacing:.06em;text-transform:uppercase}
.course-title{display:block;color:var(--purple);font:700 23px 'Fredoka',sans-serif;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:700px}
.course-meta{display:block;margin-top:2px;color:var(--muted);font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:700px}
.course-chevron{color:var(--muted);font-size:22px;transition:transform .2s ease}
.course.open .course-chevron{transform:rotate(180deg)}
.course-body{display:none;border-top:1px solid var(--line);padding:10px 12px 14px}
.course.open .course-body{display:block}
.units{display:grid;gap:10px}
.unit{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px}
.unit-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.unit-label{display:inline-flex;align-items:center;gap:8px;color:var(--muted);font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase}
.unit-dot{width:9px;height:9px;border-radius:999px;background:var(--purple)}
.unit-title{margin:4px 0 0;font:700 22px 'Fredoka',sans-serif;color:#3E3792;line-height:1.15}
.score-block{text-align:right}
.score-value{font:700 38px 'Fredoka',sans-serif;line-height:1}
.score-pass{color:var(--green)}
.score-fail{color:var(--red)}
.score-pending{color:var(--muted)}
.status-pill{display:inline-flex;margin-top:4px;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;letter-spacing:.05em;text-transform:uppercase}
.status-pass{background:#DCF4EB;color:var(--green)}
.status-fail{background:#FDEBEB;color:var(--red)}
.status-pending{background:#EEEAFE;color:var(--muted)}
.attempts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.attempt-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900;background:#F3F1FE;color:#5A52B5}
.attempt-chip.bad{background:#FDECEC;color:var(--red)}
.actions{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;border-radius:11px;padding:9px 12px;font-size:12px;font-weight:900}
.btn-review{background:#F0ECFF;color:var(--purple);border:1px solid #E3DBFF}
.btn-go{background:var(--orange);color:#fff;border:1px solid #E86A1A}
.empty{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:24px;color:var(--muted);font-weight:800;text-align:center}
@media (max-width:900px){
  .summary{grid-template-columns:repeat(2,minmax(0,1fr))}
  .course-title{max-width:360px}
  .course-meta{max-width:360px}
}
@media (max-width:640px){
  .page-title{font-size:24px}
  .course-title{font-size:20px;max-width:220px}
  .course-meta{max-width:220px}
  .unit-title{font-size:19px}
  .score-value{font-size:30px}
}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <h1 class="page-title">Scores by unit and quiz</h1>
    <a class="back-btn" href="student_dashboard.php"><i class="ti ti-arrow-left"></i>Back</a>
  </div>
</header>

<main class="page">
  <?php if ($showGlobalSummary): ?>
    <section class="summary" aria-label="Global summary">
      <article class="summary-card summary-done"><b><?php echo (int) $total_units_done; ?></b><span>Units done</span></article>
      <article class="summary-card summary-avg"><b><?php echo $global_avg === null ? '—' : (int) $global_avg; ?></b><span>Global avg</span></article>
      <article class="summary-card summary-pass"><b><?php echo (int) $global_passed; ?></b><span>Passed units</span></article>
      <article class="summary-card summary-course"><b><?php echo (int) $total_courses; ?></b><span>Courses</span></article>
    </section>
  <?php endif; ?>

  <?php if (empty($assignments)): ?>
    <div class="empty">No assigned courses found for this student.</div>
  <?php else: ?>
    <?php foreach ($assignments as $idx => $assignment): ?>
      <?php
        $coursePanelId = 'course-panel-' . $idx;
        $isOpen = ($idx === 0);
      ?>
      <section class="course<?php echo $isOpen ? ' open' : ''; ?>">
        <button type="button" class="course-toggle" data-target="<?php echo h($coursePanelId); ?>" aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>">
          <span class="course-left">
            <span class="course-icon"><i class="ti ti-book-2"></i></span>
            <span class="course-title-wrap">
              <span class="course-kicker"><?php echo h((string) $assignment['program_name']); ?></span>
              <span class="course-title"><?php echo h((string) $assignment['phase_name']); ?></span>
              <span class="course-meta"><?php echo h((string) $assignment['course_name']); ?><?php if ((string) $assignment['teacher_name'] !== ''): ?> · <?php echo h((string) $assignment['teacher_name']); ?><?php endif; ?><?php if ((string) $assignment['period_number'] !== ''): ?> · Period <?php echo h((string) $assignment['period_number']); ?><?php endif; ?></span>
            </span>
          </span>
          <i class="ti ti-chevron-down course-chevron" aria-hidden="true"></i>
        </button>

        <div class="course-body" id="<?php echo h($coursePanelId); ?>">
          <?php if (empty($assignment['units'])): ?>
            <div class="empty">No units found for this course.</div>
          <?php else: ?>
            <div class="units">
              <?php foreach ($assignment['units'] as $unit): ?>
                <?php
                  $status = (string) ($unit['status'] ?? 'pending');
                  $bestScore = $unit['best_score'];
                  $statusText = $status === 'passed' ? 'Passed' : ($status === 'failed' ? 'Failed' : 'Pending');
                  $statusClass = $status === 'passed' ? 'status-pass' : ($status === 'failed' ? 'status-fail' : 'status-pending');
                  $scoreClass = $status === 'passed' ? 'score-pass' : ($status === 'failed' ? 'score-fail' : 'score-pending');

                  $returnToQuery = $showGlobalSummary
                    ? '../../academic/student_quiz.php'
                    : ('../../academic/student_quiz.php?assignment=' . urlencode((string) $assignment['assignment_id']));
                  $review_url = '../activities/quiz/viewer.php?' . http_build_query([
                    'mode' => 'review',
                    'assignment' => (string) $assignment['assignment_id'],
                    'unit' => (string) $unit['unit_id'],
                    'attempt' => (string) ($unit['quiz_attempt_id'] ?? ''),
                    'return_to' => $returnToQuery,
                  ]);
                  $go_url = 'student_course.php?assignment=' . urlencode((string) $assignment['assignment_id']) . '&unit=' . urlencode((string) $unit['unit_number']);
                ?>
                <article class="unit">
                  <header class="unit-head">
                    <div>
                      <span class="unit-label"><span class="unit-dot" aria-hidden="true"></span>Unit <?php echo (int) $unit['unit_number']; ?></span>
                      <h3 class="unit-title"><?php echo h((string) $unit['unit_title']); ?></h3>
                    </div>
                    <div class="score-block">
                      <div class="score-value <?php echo h($scoreClass); ?>"><?php echo $bestScore === null ? '—' : (int) $bestScore; ?></div>
                      <span class="status-pill <?php echo h($statusClass); ?>"><?php echo h($statusText); ?></span>
                    </div>
                  </header>

                  <div class="attempts">
                    <span class="attempt-chip<?php echo ($unit['attempt1_score'] !== null && (int) $unit['attempt1_score'] < 60) ? ' bad' : ''; ?>">Attempt 1: <?php echo $unit['attempt1_score'] === null ? '—' : (int) $unit['attempt1_score']; ?></span>
                    <span class="attempt-chip<?php echo ($unit['attempt2_score'] !== null && (int) $unit['attempt2_score'] < 60) ? ' bad' : ''; ?>">Attempt 2: <?php echo $unit['attempt2_score'] === null ? '—' : (int) $unit['attempt2_score']; ?></span>
                    <?php if ($status === 'failed'): ?>
                      <span class="attempt-chip bad"><i class="ti ti-alert-circle"></i>2 attempts used</span>
                    <?php endif; ?>
                  </div>

                  <div class="actions">
                    <?php if (!empty($unit['quiz_attempt_id'])): ?>
                      <a class="btn btn-review" href="<?php echo h($review_url); ?>"><i class="ti ti-eye"></i>Review</a>
                    <?php endif; ?>
                    <a class="btn btn-go" href="<?php echo h($go_url); ?>"><i class="ti ti-arrow-right"></i>Go</a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<script>
document.querySelectorAll('.course-toggle').forEach(function (button) {
  button.addEventListener('click', function () {
    var section = button.closest('.course');
    var isOpen = section.classList.contains('open');
    section.classList.toggle('open', !isOpen);
    button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
  });
});
</script>
</body>
</html>

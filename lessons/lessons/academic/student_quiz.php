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
    header('Location: student_dashboard.php');
    exit;
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

  function ensure_student_performance_tables(PDO $pdo): void
  {
    try {
      $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_unit_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              quiz_errors INTEGER NOT NULL DEFAULT 0,\n              quiz_total INTEGER NOT NULL DEFAULT 0,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id)\n            )\n        ");
      $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_activity_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              activity_id TEXT NOT NULL,\n              activity_type TEXT NOT NULL DEFAULT '',\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              errors_count INTEGER NOT NULL DEFAULT 0,\n              total_count INTEGER NOT NULL DEFAULT 0,\n              attempts_count INTEGER NOT NULL DEFAULT 1,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id, activity_id)\n            )\n        ");
      $pdo->exec("ALTER TABLE student_activity_results ADD COLUMN IF NOT EXISTS attempts_count INTEGER NOT NULL DEFAULT 1");
      $pdo->exec("ALTER TABLE student_unit_results ADD COLUMN IF NOT EXISTS quiz_score_percent INTEGER");
    } catch (Throwable $e) {
    }
  }

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT sa.id, sa.student_id, sa.course_id, sa.level_id, sa.unit_id, sa.program, sa.period,
                   c.name AS course_name,
                   t.name AS teacher_name,
                   ep.name AS phase_name,
                   u.phase_id::text AS phase_id,
                   u.module_id::text AS module_id
            FROM student_assignments sa
            LEFT JOIN courses c ON c.id::text = sa.course_id
            LEFT JOIN teachers t ON t.id = sa.teacher_id
            LEFT JOIN units u ON u.id::text = sa.unit_id
            LEFT JOIN english_phases ep ON ep.id = u.phase_id
            WHERE sa.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_unit_scores(PDO $pdo, string $studentId, string $assignmentId): array
{
    if ($studentId === '' || $assignmentId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT sur.assignment_id,
                   sur.unit_id,
                   sur.completion_percent,
                   sur.quiz_errors,
                   sur.quiz_total,
                   COALESCE(sur.quiz_score_percent, 0) AS quiz_score_percent,
                   COALESCE(NULLIF(TRIM(u.name), ''), 'Unit ' || sur.unit_id) AS unit_name
            FROM student_unit_results sur
            LEFT JOIN units u ON u.id::text = sur.unit_id
            WHERE sur.student_id = :student_id
              AND sur.assignment_id = :assignment_id
            ORDER BY u.name ASC NULLS LAST, sur.unit_id ASC
        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ensure_quiz_state_table(PDO $pdo): void
{
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_quiz_state(student_id TEXT NOT NULL,assignment_id TEXT NOT NULL,unit_id TEXT NOT NULL,attempt_number INTEGER NOT NULL DEFAULT 1,quiz_set_json TEXT NOT NULL DEFAULT '[]',answers_json TEXT NOT NULL DEFAULT '{}',is_completed BOOLEAN NOT NULL DEFAULT FALSE,score_percent INTEGER NOT NULL DEFAULT 0,correct_count INTEGER NOT NULL DEFAULT 0,wrong_count INTEGER NOT NULL DEFAULT 0,skip_count INTEGER NOT NULL DEFAULT 0,total_count INTEGER NOT NULL DEFAULT 0,started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),completed_at TIMESTAMPTZ,PRIMARY KEY(student_id,assignment_id,unit_id,attempt_number))");
  } catch (Throwable $e) {
  }
}

function load_student_quiz_attempts(PDO $pdo, string $studentId, string $assignmentId, string $unitId): array
{
  if ($studentId === '' || $assignmentId === '' || $unitId === '') {
    return [];
  }

  try {
    $stmt = $pdo->prepare("SELECT attempt_number,is_completed,score_percent FROM student_quiz_state WHERE student_id=:s AND assignment_id=:a AND unit_id=:u ORDER BY attempt_number ASC");
    $stmt->execute([
      's' => $studentId,
      'a' => $assignmentId,
      'u' => $unitId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function load_activity_scores(PDO $pdo, string $studentId, string $assignmentId, string $unitId): array
{
    try {
    try {
      $stmt = $pdo->prepare("
        SELECT sar.activity_type, sar.completion_percent, sar.errors_count, sar.total_count,
             COALESCE(sar.attempts_count, 1) AS attempts_count
        FROM student_activity_results sar
        WHERE sar.student_id = :student_id
          AND sar.assignment_id = :assignment_id
          AND sar.unit_id = :unit_id
        ORDER BY sar.activity_type ASC
      ");
      $stmt->execute([
        'student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'unit_id' => $unitId,
      ]);

      return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      $stmt = $pdo->prepare("
        SELECT sar.activity_type, sar.completion_percent, sar.errors_count, sar.total_count
        FROM student_activity_results sar
        WHERE sar.student_id = :student_id
          AND sar.assignment_id = :assignment_id
          AND sar.unit_id = :unit_id
        ORDER BY sar.activity_type ASC
      ");
      $stmt->execute([
        'student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'unit_id' => $unitId,
      ]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as &$row) {
        $row['attempts_count'] = 1;
      }
      unset($row);
      return $rows;
    }
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
  die('Database is not available.');
}

ensure_student_performance_tables($pdo);
ensure_quiz_state_table($pdo);

$assignment = load_assignment($pdo, $assignmentId);
if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
  die('You do not have access to this record.');
}

$rows = load_unit_scores($pdo, $studentId, $assignmentId);
$courseName = trim((string) ($assignment['course_name'] ?? 'Course'));
if ($courseName === '') {
  $courseName = 'Course';
}
$toUpper = function (string $value): string {
  $normalized = strtr($value, [
    'á' => 'Á',
    'é' => 'É',
    'í' => 'Í',
    'ó' => 'Ó',
    'ú' => 'Ú',
    'ü' => 'Ü',
    'ñ' => 'Ñ',
  ]);

  return function_exists('mb_strtoupper') ? mb_strtoupper($normalized, 'UTF-8') : strtoupper($normalized);
};
$periodLabel = trim((string) ($assignment['period'] ?? ''));
$programLabel = ((string) ($assignment['program'] ?? '') === 'english') ? 'INGLÉS' : 'TÉCNICO';
$teacherName = trim((string) ($assignment['teacher_name'] ?? ''));
$phaseName   = trim((string) ($assignment['phase_name'] ?? ''));

$unitCards = [];
$totalFinal = 0;
$passedCount = 0;
$unitIndex = 0;

foreach ($rows as $row) {
  $unitIndex++;
  $rowAssignmentId = trim((string) ($row['assignment_id'] ?? ''));
  if ($rowAssignmentId === '') {
    $rowAssignmentId = $assignmentId;
  }
  $unitId = (string) ($row['unit_id'] ?? '');
  $unitName = trim((string) ($row['unit_name'] ?? ''));
  if ($unitName === '') {
    $unitName = 'Unit ' . $unitId;
  }
  $activities = ($unitId !== '' && $rowAssignmentId !== '') ? load_activity_scores($pdo, $studentId, $rowAssignmentId, $unitId) : [];
  $quizAttempts = ($unitId !== '' && $rowAssignmentId !== '') ? load_student_quiz_attempts($pdo, $studentId, $rowAssignmentId, $unitId) : [];
  $actScore = (int) ($row['completion_percent'] ?? 0);
  $quizScore = (int) ($row['quiz_score_percent'] ?? 0);
  $finalGrade = (int) round(0.6 * $actScore + 0.4 * $quizScore);
  $status = ($actScore <= 0 && $quizScore <= 0) ? 'pending' : ($finalGrade >= 60 ? 'passed' : 'failed');
  if ($status === 'passed') {
    $passedCount++;
  }
  $totalFinal += $finalGrade;

  $completedQuizAttempts = [];
  foreach ($quizAttempts as $attempt) {
    $isCompleted = $attempt['is_completed'] ?? false;
    if ($isCompleted === true || $isCompleted === 't' || $isCompleted === '1' || (int) $isCompleted === 1) {
      $completedQuizAttempts[] = $attempt;
    }
  }
  $errorBadges = [];
  $activityBadges = [];
  foreach ($activities as $activity) {
    $activityType = trim((string) ($activity['activity_type'] ?? ''));
    if ($activityType !== '') {
      $activityBadges[] = ucwords(str_replace('_', ' ', $activityType));
    }
    $errorsCount = (int) ($activity['errors_count'] ?? 0);
    if ($errorsCount > 0) {
      $errorBadges[] = [
        'count' => $errorsCount,
        'label' => ($activityType !== '' ? ucwords(str_replace('_', ' ', $activityType)) : 'Activity'),
      ];
    }
  }
  if (empty($activityBadges) && $status !== 'pending') {
    $activityBadges[] = 'Quiz';
  }

  $attemptOne = isset($completedQuizAttempts[0]) ? (int) ($completedQuizAttempts[0]['score_percent'] ?? 0) : 0;
  $attemptTwo = isset($completedQuizAttempts[1]) ? (int) ($completedQuizAttempts[1]['score_percent'] ?? 0) : null;

  $unitCards[] = [
    'assignment_id' => $rowAssignmentId,
    'unit_id' => $unitId,
    'unit_number' => $unitIndex,
    'unit_name' => $unitName,
    'act_score' => $actScore,
    'quiz_score' => $quizScore,
    'final_grade' => $finalGrade,
    'status' => $status,
    'attempt_one' => $attemptOne,
    'attempt_two' => $attemptTwo,
    'activities' => $activityBadges,
    'errors' => $errorBadges,
  ];
}

$unitsCount = count($unitCards);
$avgScore = $unitsCount > 0 ? (int) round($totalFinal / $unitsCount) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Scores</title>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&amp;family=Nunito:wght@600;700;800;900&amp;display=swap" rel="stylesheet">
<style>
:root{
  --orange:#F97316;
  --purple:#7F77DD;
  --green:#1D9E75;
  --red:#E24B4A;
  --muted:#9B8FCC;
  --bg:#F8F7FF;
  --card:#ffffff;
  --line:#EDE9FA;
  --topline:#F0EEF8;
  --kicker-bg:#FFF0E6;
  --kicker-line:#FCDDBF;
  --kicker-text:#C2580A;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:var(--bg);
  color:#2F2A5E;
  font-family:'Nunito','Segoe UI',sans-serif;
}
.topbar{
  background:#fff;
  border-bottom:1px solid var(--topline);
  padding:18px 24px;
}
.topbar-inner{
  max-width:1120px;
  margin:0 auto;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:14px;
}
.page-title{
  margin:0;
  color:var(--orange);
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:28px;
  font-weight:700;
}
.back-btn{
  background:#fff;
  border:2px solid #D8D8D8;
  color:#111;
  text-decoration:none;
  border-radius:18px;
  padding:10px 18px;
  font-weight:900;
  font-size:16px;
  line-height:1;
}
.page{
  max-width:1120px;
  margin:0 auto;
  padding:28px 24px 44px;
}
.panel{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:20px;
  padding:18px 20px;
}
.summary{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:20px;
  margin-bottom:18px;
}
.kicker{
  display:inline-flex;
  align-items:center;
  border:1px solid var(--kicker-line);
  background:var(--kicker-bg);
  color:var(--kicker-text);
  border-radius:999px;
  padding:6px 20px;
  font-weight:900;
  font-size:16px;
  margin-bottom:10px;
}
.course-title{
  margin:0;
  color:#383086;
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:36px;
  line-height:1;
}
.meta{
  margin-top:8px;
  color:var(--muted);
  font-size:18px;
  font-weight:700;
}
.stats{
  display:flex;
  gap:14px;
  flex-wrap:wrap;
  justify-content:flex-end;
}
.stat-box{
  min-width:170px;
  background:#F1EFFD;
  border:1px solid #E2DCF6;
  border-radius:20px;
  text-align:center;
  padding:16px 18px 12px;
}
.stat-value{
  display:block;
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:42px;
  line-height:.95;
}
.stat-label{
  color:var(--muted);
  font-size:20px;
  font-weight:800;
  line-height:1;
}
.stat-units{color:var(--orange)}
.stat-avg{color:var(--purple)}
.stat-pass{color:var(--green)}
.filter-wrap{
  border:1px solid #DDD7F3;
  border-radius:18px;
  padding:4px;
  display:flex;
  gap:4px;
  margin-bottom:22px;
}
.filter-btn{
  flex:1;
  border:1px solid #AFA8C7;
  background:#fff;
  color:#111;
  border-radius:14px;
  padding:14px 18px;
  font-size:20px;
  font-weight:900;
  cursor:pointer;
}
.filter-btn.active{
  background:#EFECFC;
  color:#383086;
  border-color:#CFC8EA;
}
.section-title{
  margin:0 0 14px;
  color:var(--purple);
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:34px;
}
.cards{
  display:grid;
  gap:16px;
}
.unit-card{
  background:var(--card);
  border:1px solid #DED8F3;
  border-radius:28px;
  padding:18px 20px;
}
.unit-card.pending{
  border-style:dashed;
  opacity:.7;
}
.unit-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
}
.unit-number{
  color:var(--muted);
  font-size:18px;
  font-weight:800;
  margin-bottom:4px;
}
.unit-name{
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  color:#383086;
  font-size:34px;
  line-height:1.08;
}
.result{
  text-align:right;
}
.result-score{
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:50px;
  line-height:.9;
}
.status-pill{
  display:inline-flex;
  margin-top:4px;
  border-radius:999px;
  padding:6px 14px;
  font-size:20px;
  font-weight:900;
}
.status-pass{background:#DDF2EA;color:var(--green)}
.status-fail{background:#FCE9E9;color:var(--red)}
.status-pending{background:#EFEAFE;color:var(--muted)}
.progress{
  margin-top:14px;
}
.bar{
  height:14px;
  border-radius:999px;
  background:#EBE7F7;
  overflow:hidden;
}
.bar > span{
  display:block;
  height:100%;
  border-radius:999px;
}
.bar-passed{background:linear-gradient(90deg,var(--orange),var(--purple))}
.bar-failed{background:var(--red)}
.bar-pending{background:#D9D3EE}
.percent{
  text-align:right;
  color:var(--muted);
  font-size:18px;
  font-weight:900;
}
.attempts{
  margin-top:14px;
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.attempt{
  min-width:150px;
  border-radius:16px;
  background:#F1EFFD;
  border:1px solid #E2DCF6;
  padding:10px 14px;
}
.attempt-label{
  display:block;
  color:var(--muted);
  font-size:18px;
  font-weight:900;
  line-height:1;
}
.attempt-value{
  font-family:'Fredoka','Trebuchet MS',sans-serif;
  font-size:34px;
  line-height:1;
}
.chips{
  margin-top:12px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.chip{
  border-radius:999px;
  padding:6px 16px;
  font-size:18px;
  font-weight:900;
  line-height:1;
}
.chip-activity{background:#EEF2FF;color:#5A57B7}
.chip-error{background:#FDEDEE;color:var(--red)}
.empty{
  color:var(--muted);
  font-size:18px;
  font-weight:700;
}
.cta{
  display:inline-block;
  margin-top:14px;
  background:var(--orange);
  color:#fff;
  text-decoration:none;
  border-radius:14px;
  padding:11px 20px;
  font-size:16px;
  font-weight:900;
}
@media (max-width:920px){
  .topbar{padding:16px}
  .page{padding:20px 16px 28px}
  .page-title{font-size:24px}
  .course-title{font-size:28px}
  .meta{font-size:16px}
  .stat-box{min-width:120px}
  .stat-value{font-size:30px}
  .stat-label{font-size:14px}
  .filter-btn{font-size:16px}
  .section-title{font-size:26px}
  .unit-number{font-size:16px}
  .unit-name{font-size:24px}
  .result-score{font-size:38px}
  .status-pill{font-size:16px}
  .percent{font-size:16px}
  .attempt-label{font-size:15px}
  .attempt-value{font-size:24px}
  .chip{font-size:15px}
  .empty{font-size:16px}
  .cta{font-size:18px}
}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-inner">
    <h1 class="page-title">Scores by unit and quiz</h1>
    <a class="back-btn" href="student_dashboard.php">↩ Back</a>
  </div>
</div>
<div class="page">
  <div class="summary panel">
    <div>
      <div class="kicker"><?php echo h($programLabel); ?></div>
      <h2 class="course-title"><?php echo h($courseName); ?></h2>
      <div class="meta"><?php if ($teacherName !== '') { ?>Teacher: <?php echo h($teacherName); ?><?php } ?><?php if ($teacherName !== '' && $periodLabel !== '') { ?> · <?php } ?><?php if ($periodLabel !== '') { ?>Period <?php echo h($periodLabel); ?><?php } ?><?php if (($teacherName !== '' || $periodLabel !== '') && $phaseName !== '') { ?> · <?php } ?><?php if ($phaseName !== '') { ?><?php echo h($phaseName); ?><?php } ?></div>
    </div>
    <div class="stats">
      <div class="stat-box">
        <span class="stat-value stat-units"><?php echo $unitsCount; ?></span>
        <span class="stat-label">Units</span>
      </div>
      <div class="stat-box">
        <span class="stat-value stat-avg"><?php echo $avgScore; ?></span>
        <span class="stat-label">Avg score</span>
      </div>
      <div class="stat-box">
        <span class="stat-value stat-pass"><?php echo $passedCount; ?></span>
        <span class="stat-label">Passed</span>
      </div>
    </div>
  </div>

  <div class="filter-wrap">
    <button type="button" class="filter-btn active" data-filter="all">All units</button>
    <button type="button" class="filter-btn" data-filter="passed">Passed</button>
    <button type="button" class="filter-btn" data-filter="failed">Failed</button>
  </div>

  <h3 class="section-title">Assigned units</h3>
  <div class="cards">
    <?php if (empty($unitCards)) { ?>
      <div class="empty">There are no assigned units yet.</div>
      <a class="cta" href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>">Go to course</a>
    <?php } else { ?>
      <?php foreach ($unitCards as $card) { ?>
        <?php
          $status = (string) $card['status'];
          $scoreColor = $status === 'passed' ? 'var(--green)' : ($status === 'failed' ? 'var(--red)' : 'var(--muted)');
          $statusText = $status === 'passed' ? 'PASSED' : ($status === 'failed' ? 'FAILED' : 'PENDING');
          $barClass = $status === 'passed' ? 'bar-passed' : ($status === 'failed' ? 'bar-failed' : 'bar-pending');
          $statusClass = $status === 'passed' ? 'status-pass' : ($status === 'failed' ? 'status-fail' : 'status-pending');
        ?>
        <article class="unit-card <?php echo h($status); ?>" data-status="<?php echo h($status); ?>">
          <div class="unit-head">
            <div>
              <div class="unit-number">Unit <?php echo (int) $card['unit_number']; ?></div>
              <div class="unit-name"><?php echo h((string) $card['unit_name']); ?></div>
            </div>
            <div class="result">
              <div class="result-score" style="color:<?php echo h($scoreColor); ?>"><?php echo (int) $card['final_grade']; ?></div>
              <span class="status-pill <?php echo h($statusClass); ?>"><?php echo $statusText; ?></span>
            </div>
          </div>
          <div class="progress">
            <div class="bar"><span class="<?php echo h($barClass); ?>" style="width:<?php echo max(0, min(100, (int) $card['final_grade'])); ?>%"></span></div>
            <div class="percent"><?php echo max(0, min(100, (int) $card['final_grade'])); ?>%</div>
          </div>
          <div class="attempts">
            <div class="attempt">
              <span class="attempt-label">Attempt 1</span>
              <span class="attempt-value" style="color:<?php echo ((int) $card['attempt_one'] >= 60) ? 'var(--green)' : 'var(--red)'; ?>"><?php echo (int) $card['attempt_one']; ?></span>
            </div>
            <div class="attempt">
              <span class="attempt-label">Attempt 2</span>
              <span class="attempt-value" style="color:<?php echo ((int) ($card['attempt_two'] ?? 0) >= 60) ? 'var(--green)' : 'var(--muted)'; ?>"><?php echo ($card['attempt_two'] === null) ? '–' : (int) $card['attempt_two']; ?></span>
            </div>
          </div>
          <div class="chips">
            <?php foreach ((array) $card['activities'] as $activityLabel) { ?>
              <span class="chip chip-activity"><?php echo h((string) $activityLabel); ?></span>
            <?php } ?>
            <?php foreach ((array) $card['errors'] as $errorInfo) { ?>
              <span class="chip chip-error">● <?php echo (int) ($errorInfo['count'] ?? 0); ?> errors · <?php echo h((string) ($errorInfo['label'] ?? 'Activity')); ?></span>
            <?php } ?>
            <?php if ($status === 'pending') { ?>
              <a class="cta" href="student_course.php?assignment=<?php echo urlencode((string) ($card['assignment_id'] ?? $assignmentId)); ?>&unit=<?php echo urlencode((string) ($card['unit_id'] ?? '')); ?>">Go to unit</a>
            <?php } ?>
          </div>
        </article>
      <?php } ?>
    <?php } ?>
  </div>
</div>
<script>
document.querySelectorAll('.filter-btn').forEach(function(button) {
  button.addEventListener('click', function() {
    var filter = this.getAttribute('data-filter');
    document.querySelectorAll('.filter-btn').forEach(function(btn) {
      btn.classList.remove('active');
    });
    this.classList.add('active');
    document.querySelectorAll('.unit-card').forEach(function(card) {
      var status = card.getAttribute('data-status');
      if (filter === 'all' || status === filter) {
        card.style.display = '';
      } else {
        card.style.display = 'none';
      }
    });
  });
});
</script>
</body>
</html>

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
        $stmt->execute(['table_name' => $tableName, 'column_name' => $columnName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_quiz_state_table(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_quiz_state(student_id TEXT NOT NULL,assignment_id TEXT NOT NULL,unit_id TEXT NOT NULL,attempt_number INTEGER NOT NULL DEFAULT 1,quiz_set_json TEXT NOT NULL DEFAULT '[]',answers_json TEXT NOT NULL DEFAULT '{}',is_completed BOOLEAN NOT NULL DEFAULT FALSE,score_percent INTEGER NOT NULL DEFAULT 0,correct_count INTEGER NOT NULL DEFAULT 0,wrong_count INTEGER NOT NULL DEFAULT 0,skip_count INTEGER NOT NULL DEFAULT 0,total_count INTEGER NOT NULL DEFAULT 0,started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),completed_at TIMESTAMPTZ,PRIMARY KEY(student_id,assignment_id,unit_id,attempt_number))");
    } catch (Throwable $e) {}
}

function load_assignments(PDO $pdo, string $studentId, string $assignmentFilter = ''): array
{
    if ($studentId === '') return [];

    $hasUnitPosition  = table_has_column($pdo, 'units', 'position');
    $hasUnitPhaseId   = table_has_column($pdo, 'units', 'phase_id');
    $unitOrderExpr    = $hasUnitPosition ? 'COALESCE(u.position, 2147483647), u.id::text' : 'u.id::text';
    $englishUnitsCondition = $hasUnitPhaseId
        ? "((ab.phase_key <> '' AND u.phase_id::text = ab.phase_key) OR (ab.phase_key = '' AND u.course_id::text = ab.course_id))"
        : "u.course_id::text = ab.course_id";
    $assignmentFilterSql = $assignmentFilter !== '' ? 'AND sa.id = :assignment_id' : '';

    $sql = "
        WITH assignment_base AS (
            SELECT sa.id::text AS assignment_id,
                   sa.student_id::text AS student_id,
                   COALESCE(sa.course_id::text,'') AS course_id,
                   LOWER(COALESCE(sa.program,'')) AS program_key,
                   CASE WHEN LOWER(COALESCE(sa.program,''))='english' THEN 'INGLÉS' ELSE 'TÉCNICO' END AS program_name,
                   COALESCE(NULLIF(c.name,''),'Course') AS course_name,
                   COALESCE(NULLIF(t.name,''),'') AS teacher_name,
                   COALESCE(sa.period::text,'') AS period_number,
                   CASE WHEN LOWER(COALESCE(sa.program,''))='english'
                        THEN COALESCE(NULLIF(sa.level_id::text,''),COALESCE(sa.course_id::text,''))
                        ELSE COALESCE(sa.level_id::text,'') END AS phase_key,
                   COALESCE(NULLIF(ep.name,''),'Phase') AS phase_name,
                   sa.updated_at
            FROM student_assignments sa
            LEFT JOIN courses c ON c.id::text = sa.course_id::text
            LEFT JOIN teachers t ON t.id = sa.teacher_id
            LEFT JOIN english_phases ep ON ep.id::text = (
                CASE WHEN LOWER(COALESCE(sa.program,''))='english'
                     THEN COALESCE(NULLIF(sa.level_id::text,''),COALESCE(sa.course_id::text,''))
                     ELSE COALESCE(sa.level_id::text,'') END)
            WHERE sa.student_id::text = :student_id {$assignmentFilterSql}
        ),
        assignment_units AS (
            SELECT ab.assignment_id, ab.program_name, ab.course_name, ab.phase_name,
                   ab.teacher_name, ab.period_number, ab.updated_at,
                   u.id::text AS unit_id,
                   COALESCE(NULLIF(u.name,''),'Unit') AS unit_title,
                   ROW_NUMBER() OVER (PARTITION BY ab.assignment_id ORDER BY {$unitOrderExpr}) AS unit_number
            FROM assignment_base ab
            LEFT JOIN units u ON (
                (ab.program_key='english' AND {$englishUnitsCondition})
                OR (ab.program_key<>'english' AND u.course_id::text = ab.course_id))
        ),
        quiz_attempts AS (
            SELECT sqs.assignment_id::text, sqs.unit_id::text, sqs.attempt_number, sqs.score_percent,
                   ROW_NUMBER() OVER (PARTITION BY sqs.assignment_id::text, sqs.unit_id::text ORDER BY sqs.attempt_number ASC) AS attempt_order,
                   ROW_NUMBER() OVER (PARTITION BY sqs.assignment_id::text, sqs.unit_id::text ORDER BY sqs.score_percent DESC, sqs.attempt_number ASC) AS best_order
            FROM student_quiz_state sqs
            WHERE sqs.student_id::text = :student_id AND sqs.is_completed = TRUE
        )
        SELECT au.assignment_id, au.program_name, au.course_name, au.phase_name,
               au.teacher_name, au.period_number, au.updated_at,
               au.unit_id, au.unit_title, au.unit_number,
               MAX(CASE WHEN qa.attempt_order=1 THEN qa.score_percent END) AS attempt1_score,
               MAX(CASE WHEN qa.attempt_order=2 THEN qa.score_percent END) AS attempt2_score,
               MAX(CASE WHEN qa.best_order=1 THEN qa.score_percent END) AS best_score,
               MAX(CASE WHEN qa.best_order=1 THEN qa.attempt_number END) AS best_attempt_number
        FROM assignment_units au
        LEFT JOIN quiz_attempts qa ON qa.assignment_id=au.assignment_id AND qa.unit_id=au.unit_id
        GROUP BY au.assignment_id, au.program_name, au.course_name, au.phase_name,
                 au.teacher_name, au.period_number, au.updated_at,
                 au.unit_id, au.unit_title, au.unit_number
        ORDER BY au.updated_at DESC NULLS LAST, au.assignment_id DESC, au.unit_number ASC, au.unit_id ASC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $params = ['student_id' => $studentId];
        if ($assignmentFilter !== '') $params['assignment_id'] = $assignmentFilter;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $assignmentsMap = [];
    foreach ($rows as $row) {
        $assignmentId = trim((string)($row['assignment_id'] ?? ''));
        if ($assignmentId === '') continue;
        if (!isset($assignmentsMap[$assignmentId])) {
            $assignmentsMap[$assignmentId] = [
                'assignment_id' => $assignmentId,
                'program_name'  => trim((string)($row['program_name'] ?? 'TÉCNICO')),
                'phase_name'    => trim((string)($row['phase_name'] ?? 'Phase')),
                'course_name'   => trim((string)($row['course_name'] ?? 'Course')),
                'teacher_name'  => trim((string)($row['teacher_name'] ?? '')),
                'period_number' => trim((string)($row['period_number'] ?? '')),
                'units'         => [],
            ];
        }
        $unitId = trim((string)($row['unit_id'] ?? ''));
        if ($unitId === '') continue;

        $attempt1       = is_numeric($row['attempt1_score'] ?? null) ? (int)$row['attempt1_score'] : null;
        $attempt2       = is_numeric($row['attempt2_score'] ?? null) ? (int)$row['attempt2_score'] : null;
        $bestScore      = is_numeric($row['best_score'] ?? null)     ? (int)$row['best_score']     : null;
        $bestAttemptNum = is_numeric($row['best_attempt_number'] ?? null) ? (int)$row['best_attempt_number'] : null;

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
            ? (int)$row['unit_number']
            : (count($assignmentsMap[$assignmentId]['units']) + 1);

        $assignmentsMap[$assignmentId]['units'][] = [
            'unit_number'     => $unitNumber,
            'unit_title'      => trim((string)($row['unit_title'] ?? ('Unit '.$unitNumber))),
            'attempt1_score'  => $attempt1,
            'attempt2_score'  => $attempt2,
            'best_score'      => $bestScore,
            'status'          => $status,
            'quiz_attempt_id' => $bestAttemptNum !== null ? ($assignmentId.':'.$unitId.':'.$bestAttemptNum) : null,
            'unit_id'         => $unitId,
        ];
    }
    return array_values($assignmentsMap);
}

$pdo = get_pdo_connection();
if (!$pdo) { die('Database is not available.'); }

ensure_quiz_state_table($pdo);
$assignments = load_assignments($pdo, $studentId, $assignmentFilter);
$showGlobalSummary = ($assignmentFilter === '');

$total_units_done = 0;
$all_scores       = [];
$global_passed    = 0;
foreach ($assignments as $a) {
    foreach ($a['units'] as $u) {
        if ($u['status'] !== 'pending') $total_units_done++;
        if ($u['best_score'] !== null)  $all_scores[] = (int)$u['best_score'];
        if ($u['status'] === 'passed')  $global_passed++;
    }
}
$global_avg    = count($all_scores) ? (int)round(array_sum($all_scores)/count($all_scores)) : null;
$total_courses = count($assignments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Scores</title>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:#F8F7FF;font-family:'Nunito',sans-serif;min-height:100vh;}
.topbar{background:#fff;border-bottom:1px solid #F0EEF8;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;}
.topbar-title{font-family:'Fredoka',sans-serif;font-size:20px;font-weight:600;color:#F97316;}
.back-btn{background:#7F77DD;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;}
.main{max-width:680px;margin:0 auto;padding:20px 16px;}
.student-meta{background:#fff;border:1px solid #EDE9FA;border-radius:14px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.stu-name{font-family:'Fredoka',sans-serif;font-size:17px;font-weight:600;color:#3C3489;}
.stu-detail{font-size:12px;color:#9B8FCC;margin-top:1px;}
.stats-row{display:flex;gap:8px;}
.stat-chip{background:#F5F3FF;border:1px solid #EDE9FA;border-radius:10px;padding:7px 12px;text-align:center;}
.stat-num{font-family:'Fredoka',sans-serif;font-size:18px;font-weight:600;line-height:1;}
.stat-lbl{font-size:10px;color:#9B8FCC;font-weight:700;margin-top:1px;}
.course-block{background:#fff;border:1px solid #EDE9FA;border-radius:18px;overflow:hidden;margin-bottom:12px;}
.course-toggle{width:100%;background:transparent;border:none;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;text-align:left;}
.course-toggle:hover{background:#FAFAFA;}
.course-left{display:flex;align-items:center;gap:12px;}
.course-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.ci-english{background:#FFF0E6;}
.ci-tech{background:#E6F1FB;}
.course-name{font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;color:#3C3489;}
.course-sub{font-size:11px;color:#9B8FCC;margin-top:1px;}
.course-right{display:flex;align-items:center;gap:10px;}
.course-badge{font-size:10px;font-weight:900;border-radius:999px;padding:3px 10px;letter-spacing:.5px;}
.cb-english{background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;}
.cb-tech{background:#E6F1FB;border:1px solid #B5D4F4;color:#185FA5;}
.chevron{font-size:18px;color:#9B8FCC;transition:transform .2s;display:inline-block;}
.chevron.open{transform:rotate(180deg);}
.units-panel{border-top:1px solid #F0EEF8;padding:0 14px 14px;}
.units-panel.hidden{display:none;}
.unit-row{padding:11px 6px;border-bottom:1px solid #F5F3FF;display:flex;align-items:center;gap:10px;}
.unit-row:last-child{border-bottom:none;}
.unit-num-dot{width:28px;height:28px;border-radius:50%;background:#F5F3FF;border:1px solid #EDE9FA;display:flex;align-items:center;justify-content:center;font-family:'Fredoka',sans-serif;font-size:13px;font-weight:600;color:#7F77DD;flex-shrink:0;}
.unit-info{flex:1;min-width:0;}
.unit-title{font-size:13px;font-weight:800;color:#3C3489;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.unit-meta-row{display:flex;align-items:center;gap:6px;margin-top:4px;flex-wrap:wrap;}
.att-chip{font-size:10px;font-weight:800;border-radius:6px;padding:2px 7px;}
.att-pass{background:#E1F5EE;color:#0F6E56;}
.att-fail{background:#FCEBEB;color:#A32D2D;}
.att-none{background:#F5F3FF;color:#9B8FCC;}
.prog-mini{display:flex;align-items:center;}
.prog-bg{width:56px;height:5px;background:#F0EEF8;border-radius:999px;overflow:hidden;}
.prog-fill{height:100%;border-radius:999px;}
.unit-score-block{display:flex;flex-direction:column;align-items:flex-end;min-width:44px;}
.unit-score{font-family:'Fredoka',sans-serif;font-size:18px;font-weight:700;line-height:1;}
.us-pass{color:#1D9E75;}
.us-fail{color:#E24B4A;}
.us-none{color:#C9C4E8;}
.us-label{font-size:9px;font-weight:900;margin-top:1px;}
.usl-pass{color:#1D9E75;}
.usl-fail{color:#E24B4A;}
.usl-none{color:#C9C4E8;}
.review-btn{background:transparent;border:1.5px solid #EDE9FA;border-radius:8px;padding:6px 12px;font-family:'Nunito',sans-serif;font-weight:800;font-size:11px;color:#7F77DD;cursor:pointer;white-space:nowrap;flex-shrink:0;text-decoration:none;display:inline-flex;align-items:center;gap:3px;}
.review-btn:hover{background:#F5F3FF;}
.go-btn{background:#F97316;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-family:'Nunito',sans-serif;font-weight:800;font-size:11px;cursor:pointer;white-space:nowrap;flex-shrink:0;text-decoration:none;display:inline-flex;align-items:center;gap:3px;}
.course-summary{background:#F8F7FF;border-top:1px solid #F0EEF8;padding:10px 18px;display:flex;align-items:center;flex-wrap:wrap;gap:12px;}
.cs-stat{display:flex;align-items:center;gap:3px;font-size:11px;color:#9B8FCC;font-weight:700;}
.cs-num{font-family:'Fredoka',sans-serif;font-size:15px;font-weight:600;}
.empty-state{background:#fff;border:1px solid #EDE9FA;border-radius:18px;padding:32px 20px;text-align:center;}
.empty-text{font-family:'Fredoka',sans-serif;font-size:16px;color:#9B8FCC;margin-bottom:16px;}
</style>
</head>
<body>

<div class="topbar">
  <span class="topbar-title">My Scores</span>
  <a href="<?php echo h((string)($_SERVER['HTTP_REFERER'] ?? 'student_dashboard.php')); ?>" class="back-btn">
    <i class="ti ti-arrow-left" aria-hidden="true"></i> Back
  </a>
</div>

<div class="main">

<?php if ($showGlobalSummary): ?>
  <div class="student-meta">
    <div>
      <div class="stu-name"><?php echo h((string)($_SESSION['student_name'] ?? 'Student')); ?></div>
      <div class="stu-detail"><?php echo $total_courses; ?> course<?php echo $total_courses !== 1 ? 's' : ''; ?> assigned</div>
    </div>
    <div class="stats-row">
      <div class="stat-chip">
        <div class="stat-num" style="color:#F97316;"><?php echo $total_units_done; ?></div>
        <div class="stat-lbl">Units done</div>
      </div>
      <?php if ($global_avg !== null): ?>
      <div class="stat-chip">
        <div class="stat-num" style="color:#7F77DD;"><?php echo $global_avg; ?></div>
        <div class="stat-lbl">Avg score</div>
      </div>
      <?php endif; ?>
      <div class="stat-chip">
        <div class="stat-num" style="color:#1D9E75;"><?php echo $global_passed; ?></div>
        <div class="stat-lbl">Passed</div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($assignments)): ?>
  <div class="empty-state">
    <div class="empty-text">No courses assigned yet. Check back soon!</div>
  </div>
<?php else: ?>
<?php foreach ($assignments as $idx => $a):
  $cId      = 'course_'.$idx;
  $isOpen   = ($idx === 0);
  $isEng    = strtoupper((string)$a['program_name']) === 'INGLÉS';
  $c_passed  = count(array_filter($a['units'], fn($u) => $u['status']==='passed'));
  $c_failed  = count(array_filter($a['units'], fn($u) => $u['status']==='failed'));
  $c_pending = count(array_filter($a['units'], fn($u) => $u['status']==='pending'));
  $c_scores  = array_filter(array_column($a['units'],'best_score'), fn($s) => $s !== null);
  $c_avg     = count($c_scores) ? (int)round(array_sum($c_scores)/count($c_scores)) : null;
?>
<div class="course-block">
  <button class="course-toggle" onclick="toggleCourse('<?php echo $cId; ?>',this)" aria-expanded="<?php echo $isOpen?'true':'false'; ?>">
    <div class="course-left">
      <div class="course-icon <?php echo $isEng?'ci-english':'ci-tech'; ?>">
        <i class="ti <?php echo $isEng?'ti-book':'ti-cpu'; ?>" aria-hidden="true" style="font-size:18px;color:<?php echo $isEng?'#F97316':'#378ADD'; ?>;"></i>
      </div>
      <div>
        <div class="course-name"><?php echo h((string)$a['phase_name']); ?></div>
        <div class="course-sub">
          <?php echo h((string)$a['teacher_name']); ?>
          <?php if ($a['period_number'] !== ''): ?>&nbsp;·&nbsp; Period <?php echo h((string)$a['period_number']); ?><?php endif; ?>
          &nbsp;·&nbsp; <?php echo count($a['units']); ?> unit<?php echo count($a['units'])!==1?'s':''; ?>
        </div>
      </div>
    </div>
    <div class="course-right">
      <span class="course-badge <?php echo $isEng?'cb-english':'cb-tech'; ?>"><?php echo h(strtoupper((string)$a['program_name'])); ?></span>
      <i class="ti ti-chevron-down chevron <?php echo $isOpen?'open':''; ?>" id="chev_<?php echo $cId; ?>" aria-hidden="true"></i>
    </div>
  </button>

  <div class="units-panel <?php echo $isOpen?'':'hidden'; ?>" id="<?php echo $cId; ?>">
  <?php foreach ($a['units'] as $unit):
    $status = (string)($unit['status'] ?? 'pending');
    $best   = $unit['best_score'];
    $att1   = $unit['attempt1_score'];
    $att2   = $unit['attempt2_score'];
    $pct    = $best ?? 0;
    $hasAtt = ($att1 !== null || $att2 !== null);
    $sc = ['passed'=>'us-pass','failed'=>'us-fail','pending'=>'us-none'][$status];
    $lc = ['passed'=>'usl-pass','failed'=>'usl-fail','pending'=>'usl-none'][$status];
    $pg = $status==='passed' ? 'linear-gradient(90deg,#F97316,#7F77DD)' : ($status==='failed' ? '#E24B4A' : '#EDE9FA');
    $returnTo   = $showGlobalSummary ? '../../academic/student_quiz.php' : ('../../academic/student_quiz.php?assignment='.urlencode((string)$a['assignment_id']));
    $review_url = '../activities/quiz/viewer.php?'.http_build_query(['mode'=>'review','assignment'=>(string)$a['assignment_id'],'unit'=>(string)$unit['unit_id'],'attempt'=>(string)($unit['quiz_attempt_id']??''),'return_to'=>$returnTo]);
    $go_url     = 'student_course.php?assignment='.urlencode((string)$a['assignment_id']).'&unit='.urlencode((string)$unit['unit_number']);
  ?>
    <div class="unit-row" <?php echo $status==='pending'?'style="opacity:.7;"':''; ?>>
      <div class="unit-num-dot" <?php echo $status==='pending'?'style="opacity:.5;"':''; ?>><?php echo (int)$unit['unit_number']; ?></div>
      <div class="unit-info">
        <div class="unit-title"><?php echo h((string)$unit['unit_title']); ?></div>
        <div class="unit-meta-row">
          <?php if (!$hasAtt): ?>
            <span class="att-chip att-none">No attempts yet</span>
          <?php else: ?>
            <?php if ($att1!==null): ?><span class="att-chip <?php echo $att1>=60?'att-pass':'att-fail'; ?>">Att 1: <?php echo $att1; ?></span><?php endif; ?>
            <?php if ($att2!==null): ?><span class="att-chip <?php echo $att2>=60?'att-pass':'att-fail'; ?>">Att 2: <?php echo $att2; ?></span><?php endif; ?>
            <div class="prog-mini"><div class="prog-bg"><div class="prog-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $pg; ?>;"></div></div></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="unit-score-block">
        <span class="unit-score <?php echo $sc; ?>"><?php echo $best!==null?$best:'—'; ?></span>
        <span class="us-label <?php echo $lc; ?>"><?php echo strtoupper($status); ?></span>
      </div>
      <?php if ($hasAtt && !empty($unit['quiz_attempt_id'])): ?>
        <a href="<?php echo h($review_url); ?>" class="review-btn"><i class="ti ti-eye" aria-hidden="true" style="font-size:12px;"></i>Review</a>
      <?php else: ?>
        <a href="<?php echo h($go_url); ?>" class="go-btn"><i class="ti ti-arrow-right" aria-hidden="true" style="font-size:12px;"></i>Go</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="course-summary" id="cs_<?php echo $cId; ?>" style="<?php echo $isOpen?'':'display:none;'; ?>">
    <?php if ($c_passed>0): ?><div class="cs-stat"><span class="cs-num" style="color:#1D9E75;"><?php echo $c_passed; ?></span>&nbsp;passed</div><?php endif; ?>
    <?php if ($c_failed>0): ?><div class="cs-stat"><span class="cs-num" style="color:#E24B4A;"><?php echo $c_failed; ?></span>&nbsp;failed</div><?php endif; ?>
    <?php if ($c_pending>0): ?><div class="cs-stat"><span class="cs-num" style="color:#9B8FCC;"><?php echo $c_pending; ?></span>&nbsp;pending</div><?php endif; ?>
    <?php if ($c_avg!==null): ?><div class="cs-stat">Avg&nbsp;<span class="cs-num" style="color:#7F77DD;"><?php echo $c_avg; ?></span></div><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

<script>
function toggleCourse(id, btn) {
  var panel   = document.getElementById(id);
  var chev    = document.getElementById('chev_' + id);
  var summary = document.getElementById('cs_' + id);
  var isOpen  = !panel.classList.contains('hidden');
  if (isOpen) {
    panel.classList.add('hidden');
    if (chev)    chev.classList.remove('open');
    if (summary) summary.style.display = 'none';
    btn.setAttribute('aria-expanded','false');
  } else {
    panel.classList.remove('hidden');
    if (chev)    chev.classList.add('open');
    if (summary) summary.style.display = 'flex';
    btn.setAttribute('aria-expanded','true');
  }
}
</script>
</body>
</html>

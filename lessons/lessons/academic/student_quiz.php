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

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    try {
        $stmt = $pdo->prepare("\n            SELECT sa.id, sa.student_id, sa.course_id, sa.program, sa.period, c.name AS course_name\n            FROM student_assignments sa\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            WHERE sa.id = :id\n            LIMIT 1\n        ");
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_unit_scores(PDO $pdo, string $studentId, string $assignmentId): array
{
    try {
    $stmt = $pdo->prepare("\n            SELECT sur.unit_id, sur.completion_percent, sur.quiz_errors, sur.quiz_total, u.name AS unit_name\n            FROM student_unit_results sur\n            LEFT JOIN units u ON u.id::text = sur.unit_id\n            WHERE sur.student_id = :student_id\n              AND sur.assignment_id = :assignment_id\n            ORDER BY u.name ASC NULLS LAST, sur.unit_id ASC\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
  die('Database is not available.');
}

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
$courseName = $toUpper($courseName);
$periodLabel = $toUpper((string) ($assignment['period'] ?? ''));
$programLabel = ((string) ($assignment['program'] ?? '') === 'english') ? 'INGLÉS' : 'TÉCNICO';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Scores</title>
<style>
:root{
  --bg:#fff8f5;
  --card:#fff;
  --line:#ffd9d2;
  --title:#b04632;
  --text:#5e352e;
  --muted:#8a625a;
  --salmon:#fa8072;
  --salmon-dark:#e8654e;
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);padding:22px}
.page{max-width:980px;margin:0 auto}
.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
h1{margin:0;color:var(--title)}
.back{display:inline-block;padding:10px 16px;border-radius:8px;text-decoration:none;color:#fff;background:linear-gradient(180deg,#a855f7,#7c3aed);font-weight:700;box-shadow:0 4px 12px rgba(124,58,237,.25);transition:filter .18s ease,transform .18s ease}
.back:hover{filter:brightness(1.08);transform:translateY(-1px)}
.meta{margin:0 0 14px;color:var(--muted)}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #ffe3de;text-align:left}
th{color:var(--title)}
.empty{color:var(--muted)}
.btn{display:inline-block;margin-top:12px;padding:10px 14px;border-radius:8px;text-decoration:none;color:#fff;background:var(--salmon);font-weight:700}
.btn:hover{background:var(--salmon-dark)}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <h1>Scores by unit and quiz</h1>
    <a class="back" href="student_dashboard.php">← Back</a>
  </div>

  <p class="meta">Course: <strong><?php echo h($courseName); ?></strong> · Program: <strong><?php echo h($programLabel); ?></strong> · Period: <strong><?php echo h($periodLabel); ?></strong></p>

  <div class="card">
    <?php if (empty($rows)) { ?>
      <div class="empty">There are no scores yet. Complete the unit quizzes and check again.</div>
      <a class="btn" href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>">Go to course</a>
    <?php } else { ?>
      <table>
        <thead>
          <tr>
            <th>Unit</th>
            <th>Score</th>
            <th>Quiz errors</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row) { ?>
            <?php $unitLabel = $toUpper((string) ($row['unit_name'] ?: ('Unit ' . (string) ($row['unit_id'] ?? '')))); ?>
            <tr>
              <td><?php echo h($unitLabel); ?></td>
              <td><?php echo (int) ($row['completion_percent'] ?? 0); ?>%</td>
              <td><?php echo (int) ($row['quiz_errors'] ?? 0); ?>/<?php echo (int) ($row['quiz_total'] ?? 0); ?></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    <?php } ?>
  </div>
</div>
</body>
</html>

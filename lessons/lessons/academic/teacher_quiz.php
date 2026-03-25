<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function load_unit_performance_db(?PDO $pdo, string $teacherId, string $assignmentId, string $unitId): array
{
  if (!$pdo || $teacherId === '' || $assignmentId === '' || $unitId === '') {
    return [];
  }

  try {
    $stmt = $pdo->prepare("\n          SELECT completion_percent, quiz_errors, quiz_total\n          FROM teacher_unit_results\n          WHERE teacher_id = :teacher_id\n            AND assignment_id = :assignment_id\n            AND unit_id = :unit_id\n          LIMIT 1\n        ");
    $stmt->execute([
      'teacher_id' => $teacherId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  } catch (Throwable $e) {
    return [];
  }
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$unitId = trim((string) ($_GET['unit'] ?? ''));
$returnTo = trim((string) ($_GET['return_to'] ?? ''));

$backHref = 'teacher_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unitId) . '&step=' . urlencode('9999');
$dashboardHref = 'dashboard.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unitId) . '#unidades-curso';
$quizEditorHref = '../activities/quiz/editor.php?unit=' . urlencode($unitId) . '&assignment=' . urlencode($assignmentId);
$quizReturn = $returnTo !== '' ? $returnTo : $backHref;
$quizViewerHref = '../activities/quiz/viewer.php?unit=' . urlencode($unitId) . '&assignment=' . urlencode($assignmentId) . '&return_to=' . urlencode($quizReturn);

$courseName = trim((string) ($_SESSION['teacher_current_course_name'] ?? 'Curso actual'));
$unitName = trim((string) ($_SESSION['teacher_current_unit_name'] ?? ($unitId !== '' ? ('Unidad ' . $unitId) : 'Unidad actual')));

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));
$completionPercent = 0;
$quizErrors = 0;
$quizTotal = 0;

$performance = $_SESSION['teacher_unit_performance'] ?? [];
$performanceKey = $teacherId . '|' . $assignmentId . '|' . $unitId;
if (is_array($performance) && isset($performance[$performanceKey]) && is_array($performance[$performanceKey])) {
  $completionPercent = (int) ($performance[$performanceKey]['completion_percent'] ?? 0);
  $quizErrors = (int) ($performance[$performanceKey]['quiz_errors'] ?? 0);
  $quizTotal = (int) ($performance[$performanceKey]['quiz_total'] ?? 0);
}

$dbPerformance = load_unit_performance_db(isset($pdo) && $pdo instanceof PDO ? $pdo : null, $teacherId, $assignmentId, $unitId);
if (!empty($dbPerformance)) {
  $completionPercent = (int) ($dbPerformance['completion_percent'] ?? $completionPercent);
  $quizErrors = (int) ($dbPerformance['quiz_errors'] ?? $quizErrors);
  $quizTotal = (int) ($dbPerformance['quiz_total'] ?? $quizTotal);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quiz time</title>
<style>
:root{
  --bg:#eef5ff;
  --card:#ffffff;
  --line:#d8e2f2;
  --text:#1b3050;
  --title:#0f1f42;
  --muted:#5d6f8f;
  --blue:#2563eb;
  --blue-dark:#1d4ed8;
  --blue-soft:#e9f1ff;
  --warning:#f59e0b;
  --warning-dark:#d97706;
  --shadow:0 10px 24px rgba(0,0,0,.08);
  --shadow-sm:0 2px 8px rgba(0,0,0,.06);
}
*{box-sizing:border-box}
body{
  margin:0;
  font-family:Arial,sans-serif;
  background:var(--bg);
  color:var(--text);
}
.topbar{
  background:linear-gradient(180deg,var(--blue),var(--blue-dark));
  color:#fff;
  padding:16px 24px;
}
.topbar-inner{
  max-width:1100px;
  margin:0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.top-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:10px 14px;
  border-radius:10px;
  text-decoration:none;
  font-size:13px;
  font-weight:700;
  color:#fff;
  background:rgba(255,255,255,.2);
  box-shadow:var(--shadow-sm);
}
.top-title{
  margin:0;
  font-size:24px;
  font-weight:800;
}
.page{
  max-width:1100px;
  margin:0 auto;
  padding:20px;
}
.card{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:22px;
  box-shadow:var(--shadow);
  padding:26px;
}
.badges{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin:12px 0 20px;
}
.badge{
  display:inline-flex;
  align-items:center;
  padding:7px 12px;
  border-radius:999px;
  background:var(--blue-soft);
  color:var(--blue-dark);
  font-size:12px;
  font-weight:800;
}
.badge.warn{
  background:#fff3d9;
  color:var(--warning-dark);
}
.title{
  margin:0 0 10px;
  color:var(--title);
  font-size:30px;
  font-weight:800;
}
.text{
  margin:0;
  color:var(--muted);
  font-size:15px;
  line-height:1.6;
  max-width:760px;
}
.actions{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:24px;
}
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:190px;
  padding:12px 18px;
  border-radius:12px;
  text-decoration:none;
  color:#fff;
  font-size:14px;
  font-weight:700;
  box-shadow:var(--shadow-sm);
  background:linear-gradient(180deg,#3d73ee,#2563eb);
}
.btn.secondary{
  background:linear-gradient(180deg,#7b8b9e,#66758b);
}
.btn:hover{filter:brightness(1.07)}

@media (max-width:768px){
  .topbar{padding:14px}
  .topbar-inner{flex-direction:column;align-items:stretch}
  .top-title{text-align:center;font-size:22px}
  .page{padding:12px}
  .card{padding:20px}
  .title{font-size:24px}
  .actions{flex-direction:column}
  .btn{width:100%;min-width:0}
}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="top-btn" href="<?php echo h($backHref); ?>">&larr; Volver a Completed</a>
    <h1 class="top-title">Quiz time</h1>
    <a class="top-btn" href="<?php echo h($dashboardHref); ?>">Panel docente</a>
  </div>
</header>

<main class="page">
  <section class="card">
    <h2 class="title">🧠 Evaluación final de la unidad</h2>
    <div class="badges">
      <span class="badge">Curso: <?php echo h($courseName); ?></span>
      <span class="badge">Unidad: <?php echo h($unitName); ?></span>
      <span class="badge warn">Porcentaje actual: <?php echo (int) $completionPercent; ?>%</span>
      <?php if ($quizTotal > 0) { ?>
        <span class="badge warn">Errores: <?php echo $quizErrors; ?>/<?php echo $quizTotal; ?></span>
      <?php } ?>
    </div>
    <p class="text">Este quiz final registra errores y porcentaje para mostrar el resultado al finalizar la unidad. Puedes crear preguntas y luego resolver el quiz para actualizar el cierre.</p>

    <div class="actions">
      <a class="btn" href="<?php echo h($quizEditorHref); ?>">Abrir editor de quiz</a>
      <a class="btn" href="<?php echo h($quizViewerHref); ?>">Previsualizar quiz</a>
      <a class="btn secondary" href="<?php echo h($dashboardHref); ?>">Volver al panel docente</a>
    </div>
  </section>
</main>
</body>
</html>

<?php
session_start(); 

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';
$accountsFile = $dataDir . '/teacher_accounts.json';
$studentsFile = $dataDir . '/students.json';
$coursesFile = $dataDir . '/courses.json';
$unitsFile = $dataDir . '/units.json';
$studentAssignmentsFile = $dataDir . '/student_assignments_records.json';

foreach ([$teachersFile, $accountsFile, $studentsFile, $coursesFile, $unitsFile, $studentAssignmentsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$teachers = json_decode((string) file_get_contents($teachersFile), true);
$accounts = json_decode((string) file_get_contents($accountsFile), true);
$students = json_decode((string) file_get_contents($studentsFile), true);
$courses = json_decode((string) file_get_contents($coursesFile), true);
$units = json_decode((string) file_get_contents($unitsFile), true);
$studentAssignments = json_decode((string) file_get_contents($studentAssignmentsFile), true);

$teachers = is_array($teachers) ? $teachers : [];
$accounts = is_array($accounts) ? $accounts : [];
$students = is_array($students) ? $students : [];
$courses = is_array($courses) ? $courses : [];
$units = is_array($units) ? $units : [];
$studentAssignments = is_array($studentAssignments) ? $studentAssignments : [];

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function map_names(array $rows): array
{
    $mapped = [];
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id !== '') {
            $mapped[$id] = (string) ($row['name'] ?? $id);
        }
    }
    return $mapped;
}

$studentNameById = map_names($students);
$courseNameById = map_names($courses);
$unitNameById = map_names($units);

$teachersById = [];
foreach ($teachers as $teacher) {
    $teacherId = (string) ($teacher['id'] ?? '');
    if ($teacherId === '') {
        continue;
    }
    $teachersById[$teacherId] = [
        'id' => $teacherId,
        'name' => (string) ($teacher['name'] ?? 'Docente'),
        'groups' => [],
        'students' => [],
    ];
}

foreach ($accounts as $account) {
    $teacherId = (string) ($account['teacher_id'] ?? '');
    if ($teacherId === '') {
        continue;
    }

    if (!isset($teachersById[$teacherId])) {
        $teachersById[$teacherId] = [
            'id' => $teacherId,
            'name' => (string) ($account['teacher_name'] ?? 'Docente'),
            'groups' => [],
            'students' => [],
        ];
    }

    $groupName = trim((string) ($account['target_name'] ?? ''));
    if ($groupName !== '') {
        $teachersById[$teacherId]['groups'][$groupName] = $groupName;
    }
}

foreach ($studentAssignments as $assignment) {
    $teacherId = (string) ($assignment['teacher_id'] ?? '');
    if ($teacherId === '') {
        continue;
    }

    if (!isset($teachersById[$teacherId])) {
        $teachersById[$teacherId] = [
            'id' => $teacherId,
            'name' => 'Docente',
            'groups' => [],
            'students' => [],
        ];
    }

    $courseName = $courseNameById[(string) ($assignment['course_id'] ?? '')] ?? '';
    $unitName = $unitNameById[(string) ($assignment['unit_id'] ?? '')] ?? '';
    $groupName = trim($courseName !== '' && $unitName !== '' ? ($courseName . ' - ' . $unitName) : ($courseName !== '' ? $courseName : $unitName));

    if ($groupName !== '') {
        $teachersById[$teacherId]['groups'][$groupName] = $groupName;
    }

    $studentId = (string) ($assignment['student_id'] ?? '');
    if ($studentId !== '') {
        $teachersById[$teacherId]['students'][$studentId] = $studentNameById[$studentId] ?? ('Estudiante ' . $studentId);
    }
}

$teacherCards = array_values($teachersById);
usort($teacherCards, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Docentes y Grupos</title>
<style>
body{margin:0;padding:30px;font-family:Arial,sans-serif;background:linear-gradient(180deg,#e9eef7,#e4e9f4);color:#203768}
.wrapper{max-width:1050px;margin:0 auto}
.back{display:inline-block;margin-bottom:18px;color:#fff;background:#2f66dd;text-decoration:none;font-weight:700;border-radius:10px;padding:10px 14px;box-shadow:0 8px 18px rgba(47,102,221,.32)}
.title{text-align:center;margin:6px 0 26px;font-size:56px;color:#1f3c75}
.panel{background:#f5f7fc;border:1px solid #d7dfef;border-radius:20px;padding:18px;box-shadow:0 12px 26px rgba(27,49,94,.12)}
.teacher{background:#fff;border:1px solid #d6deee;border-radius:16px;margin-bottom:14px;overflow:hidden}
.teacher:last-child{margin-bottom:0}
.head{display:flex;justify-content:space-between;gap:16px;padding:20px 22px;align-items:flex-start}
.name{margin:0;font-size:50px;font-weight:800;color:#1f3c75}
.meta{margin-top:6px;display:block;font-size:24px;color:#4a5f86}
.badges{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap}
.badge{display:inline-block;color:#fff;font-weight:800;border-radius:10px;padding:10px 18px;box-shadow:0 8px 16px rgba(45,99,201,.28);font-size:30px}
.badge-1{background:linear-gradient(180deg,#4f89ec,#2f63c9)}
.badge-2{background:linear-gradient(180deg,#8a7ef2,#6653cc)}
.badge-3{background:linear-gradient(180deg,#f79448,#ee6e12)}
.badge-4{background:linear-gradient(180deg,#4dbda0,#219b7b)}
.right{display:flex;align-items:center;gap:12px}
.view-btn{border:none;border-radius:10px;padding:10px 22px;font-size:34px;font-weight:800;color:#fff;background:linear-gradient(180deg,#4f89ec,#2f63c9);box-shadow:0 8px 16px rgba(47,102,221,.35);cursor:pointer}
.toggle{border:none;background:none;color:#7a8fb6;font-size:34px;cursor:pointer;line-height:1}
.body{display:none;background:#f3f6fd;border-top:1px solid #d6deee;padding:16px 22px 20px}
.body.open{display:block}
.body h3{margin:0 0 10px;font-size:30px;color:#213b72}
ol{margin:0;padding-left:30px}
li{font-size:28px;color:#304e80;padding:6px 0;border-bottom:1px solid #dce3f1}
.empty{font-size:22px;color:#5a6f98;font-weight:600}
@media (max-width:980px){
  .title{font-size:34px}
  .name{font-size:30px}
  .meta{font-size:18px}
  .badge,.view-btn{font-size:18px}
  .head{flex-direction:column}
  .right{width:100%;justify-content:flex-end}
  .body h3{font-size:22px}
  li{font-size:18px}
}
</style>
</head>
<body>
<div class="wrapper" id="docentes-grupos">
  <a class="back" href="student_assignments.php">← Volver a asignaciones</a>
  <h1 class="title">Docentes y Grupos</h1>

  <div class="panel">
    <?php if (empty($teacherCards)) { ?>
      <div class="teacher">
        <div class="head"><p class="empty">No hay docentes registrados todavía.</p></div>
      </div>
    <?php } else { ?>
      <?php foreach ($teacherCards as $index => $teacherCard) { ?>
        <?php
          $groups = array_values((array) ($teacherCard['groups'] ?? []));
          $studentsList = array_values((array) ($teacherCard['students'] ?? []));
          $countGroups = count($groups);
        ?>
        <article class="teacher">
          <div class="head">
            <div>
              <p class="name">Prof. <?= h((string) ($teacherCard['name'] ?? 'Docente')) ?></p>
              <span class="meta"><?= $countGroups ?> <?= $countGroups === 1 ? 'Grupo asignado' : 'Grupos asignados' ?></span>
              <?php if (!empty($groups)) { ?>
                <div class="badges">
                  <?php foreach ($groups as $groupIndex => $groupName) { ?>
                    <?php $groupClass = 'badge-' . (($groupIndex % 4) + 1); ?>
                    <span class="badge <?= h($groupClass) ?>"><?= h((string) $groupName) ?></span>
                  <?php } ?>
                </div>
              <?php } ?>
            </div>
            <div class="right">
              <button type="button" class="view-btn" data-target="body-<?= $index ?>">Ver →</button>
              <button type="button" class="toggle" data-target="body-<?= $index ?>">⌄</button>
            </div>
          </div>
          <div class="body" id="body-<?= $index ?>">
            <h3>Lista de Estudiantes Asignados:</h3>
            <?php if (empty($studentsList)) { ?>
              <p class="empty">Este docente no tiene estudiantes asignados todavía.</p>
            <?php } else { ?>
              <ol>
              <?php foreach ($studentsList as $studentName) { ?>
                <li><?= h((string) $studentName) ?></li>
              <?php } ?>
              </ol>
            <?php } ?>
          </div>
        </article>
      <?php } ?>
    <?php } ?>
  </div>
</div>
<script>
function togglePanel(targetId) {
  const panel = document.getElementById(targetId);
  if (!panel) return;
  panel.classList.toggle('open');
}

document.querySelectorAll('.toggle, .view-btn').forEach((button) => {
  button.addEventListener('click', () => {
    togglePanel(button.dataset.target || '');
  });
});
</script>
</body>
</html>

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

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function map_names(array $rows): array {
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
    if ($teacherId === '') continue;
    $teachersById[$teacherId] = [
        'id' => $teacherId,
        'name' => (string) ($teacher['name'] ?? 'Docente'),
        'groups' => [],
        'students' => [],
    ];
}

foreach ($accounts as $account) {
    $teacherId = (string) ($account['teacher_id'] ?? '');
    if ($teacherId === '') continue;

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
    if ($teacherId === '') continue;

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
body{margin:0;padding:28px;font-family:Arial,sans-serif;background:#e8ecf4;color:#23376a}
.wrapper{max-width:1100px;margin:0 auto}
.back{display:inline-block;margin-bottom:14px;color:#2f66dd;text-decoration:none;font-weight:700}
.title{font-size:40px;text-align:center;margin:6px 0 30px}
.list{background:#fff;border:1px solid #d8e1ef;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.08)}
.row{border-top:1px solid #d8e1ef}
.row:first-child{border-top:none}
.head{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:18px 22px}
.name{margin:0;font-size:32px;font-weight:800;color:#263b72}
.meta{font-size:22px;color:#4c618f}
.badges{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap}
.badge{background:linear-gradient(180deg,#3f7ee6,#2f63c9);color:#fff;font-weight:700;border-radius:8px;padding:10px 14px;font-size:20px}
.toggle{font-size:28px;background:none;border:none;color:#6f83ad;cursor:pointer}
.body{display:none;background:#f1f4fb;padding:18px 22px;border-top:1px solid #d8e1ef}
.body.open{display:block}
.body h3{margin:0 0 10px;font-size:26px}
ol{margin:0;padding-left:30px}
li{font-size:22px;padding:8px 0;border-bottom:1px solid #d8e1ef}
.empty{font-size:18px;color:#5a6f98;font-weight:600}
@media (max-width:980px){
 .title{font-size:30px}
 .name{font-size:24px}
 .meta,.badge,.body h3,li{font-size:18px}
}
</style>
</head>
<body>
<div class="wrapper" id="docentes-grupos">
  <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
  <h1 class="title">Docentes y Grupos</h1>

  <div class="list">
    <?php if (empty($teacherCards)) { ?>
      <div class="head"><p class="empty">No hay docentes registrados todavía.</p></div>
    <?php } else { ?>
      <?php foreach ($teacherCards as $index => $teacherCard) { ?>
        <?php $groups = array_values((array) ($teacherCard['groups'] ?? [])); $studentsList = array_values((array) ($teacherCard['students'] ?? [])); $countGroups = count($groups); ?>
        <article class="row">
          <div class="head">
            <div>
              <p class="name">Prof. <?= h((string) ($teacherCard['name'] ?? 'Docente')) ?></p>
              <span class="meta"><?= $countGroups ?> <?= $countGroups === 1 ? 'Grupo asignado' : 'Grupos asignados' ?></span>
              <?php if (!empty($groups)) { ?>
                <div class="badges">
                  <?php foreach ($groups as $groupName) { ?>
                    <span class="badge"><?= h((string) $groupName) ?></span>
                  <?php } ?>
                </div>
              <?php } ?>
            </div>
            <button type="button" class="toggle" data-target="body-<?= $index ?>">⌄</button>
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
document.querySelectorAll('.toggle').forEach((button)=>{
  button.addEventListener('click',()=>{
    const panel = document.getElementById(button.dataset.target);
    if (panel) panel.classList.toggle('open');
  });
});
</script>
</body>
</html>

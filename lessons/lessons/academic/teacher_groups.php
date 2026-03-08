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
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Docentes y Grupos</title>
<style>
/* Estilo general */
body {
  margin: 0;
  padding: 20px;
  font-family: 'Segoe UI', Roboto, Arial, sans-serif;
  background: #e9f1fb; /* azul claro igual al resto de la interfaz */
  color: #2c3e50;
}

.wrapper {
  max-width: 1000px;
  margin: 0 auto;
}

/* Botón volver */
.back {
  display: inline-block;
  margin-bottom: 20px;
  color: #fff;
  background: #3b6dd8;
  text-decoration: none;
  font-weight: 600;
  border-radius: 6px;
  padding: 8px 14px;
  font-size: 14px;
  transition: background .2s;
}
.back:hover { background: #2f5bb5; }

/* Título */
.title {
  text-align: center;
  margin: 10px 0 30px;
  font-size: 28px;
  font-weight: 700;
  color: #1f3c75;
}

/* Panel */
.panel {
  background: #fff;
  border: 1px solid #dce3f1;
  border-radius: 10px;
  padding: 20px;
  box-shadow: 0 4px 12px rgba(0,0,0,.08);
}

/* Tarjeta docente */
.teacher {
  border: 1px solid #e0e6f0;
  border-radius: 8px;
  margin-bottom: 16px;
  overflow: hidden;
  background: #fafbff;
}
.teacher:last-child { margin-bottom: 0; }

.head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
  align-items: center;
}

.name {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #2c3e50;
}

.meta {
  margin-top: 4px;
  display: block;
  font-size: 14px;
  color: #6c7a92;
}

/* Badges */
.badges {
  margin-top: 8px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.badge {
  display: inline-block;
  color: #fff;
  font-weight: 600;
  border-radius: 6px;
  padding: 6px 12px;
  font-size: 13px;
}
.badge-1 { background: #3b6dd8; }
.badge-2 { background: #7a5de8; }
.badge-3 { background: #f57c3d; }
.badge-4 { background: #2fa88d; }

/* Botones */
.right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.view-btn, .toggle {
  border: none;
  border-radius: 6px;
  padding: 6px 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.view-btn {
  background: #3b6dd8;
  color: #fff;
  transition: background .2s;
}
.view-btn:hover { background: #2f5bb5; }
.toggle {
  background: none;
  color: #6c7a92;
}

/* Cuerpo desplegable */
.body {
  display: none;
  background: #f9fbff;
  border-top: 1px solid #e0e6f0;
  padding: 14px 16px;
}
.body.open { display: block; }
.body h3 {
  margin: 0 0 10px;
  font-size: 16px;
  color: #2c3e50;
}
ol {
  margin: 0;
  padding-left: 20px;
}
li {
  font-size: 14px;
  color: #34495e;
  padding: 4px 0;
  border-bottom: 1px solid #e6ebf3;
}
.empty {
  font-size: 13px;
  color: #7a8fa8;
  font-weight: 500;
}

/* Responsive */
@media (max-width: 768px) {
  .title { font-size: 22px; }
  .name { font-size: 18px; }
  .meta { font-size: 13px; }
  .badge { font-size: 12px; }
  .view-btn, .toggle { font-size: 12px; }
  li { font-size: 13px; }
}
</style>
</head>
<body>

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

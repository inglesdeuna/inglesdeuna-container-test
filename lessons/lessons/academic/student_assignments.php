<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$baseDir = __DIR__ . '/data';
$studentsFile = $baseDir . '/students.json';
$teachersFile = $baseDir . '/teachers.json';
$coursesFile = $baseDir . '/courses.json';
$unitsFile = $baseDir . '/units.json';
$studentAssignmentsFile = $baseDir . '/student_assignments_records.json';

foreach ([$studentsFile, $teachersFile, $coursesFile, $unitsFile, $studentAssignmentsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$students = json_decode((string) file_get_contents($studentsFile), true);
$teachers = json_decode((string) file_get_contents($teachersFile), true);
$courses = json_decode((string) file_get_contents($coursesFile), true);
$units = json_decode((string) file_get_contents($unitsFile), true);
$studentAssignments = json_decode((string) file_get_contents($studentAssignmentsFile), true);

$students = is_array($students) ? $students : [];
$teachers = is_array($teachers) ? $teachers : [];
$courses = is_array($courses) ? $courses : [];
$units = is_array($units) ? $units : [];
$studentAssignments = is_array($studentAssignments) ? $studentAssignments : [];

$semesterOptions = ['1', '2', '3', '4', '5', '6'];
$programOptions = [
    'english' => 'Inglés',
    'technical' => 'Programa Técnico',
];

function find_name_by_id(array $rows, string $id, string $fallback): string
{
    foreach ($rows as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return (string) ($row['name'] ?? $fallback);
        }
    }

    return $fallback;
}

function save_student_assignments(string $file, array $records): void
{
    file_put_contents(
        $file,
        json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];

    $studentAssignments = array_values(array_filter($studentAssignments, function ($row) use ($deleteId) {
        return (string) ($row['id'] ?? '') !== $deleteId;
    }));

    save_student_assignments($studentAssignmentsFile, $studentAssignments);

    header('Location: student_assignments.php?saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = trim((string) ($_POST['edit_id'] ?? ''));
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $teacherId = trim((string) ($_POST['teacher_id'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? ''));
    $courseId = trim((string) ($_POST['course_id'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $unitId = trim((string) ($_POST['unit_id'] ?? ''));

    if (!isset($programOptions[$program])) {
        $program = 'technical';
    }

    if ($studentId !== '' && $teacherId !== '' && $courseId !== '' && $semester !== '' && $unitId !== '') {
        $record = [
            'id' => $editId !== '' ? $editId : uniqid('stu_assign_'),
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'program' => $program,
            'course_id' => $courseId,
            'semester' => $semester,
            'unit_id' => $unitId,
        ];

        $updated = false;
        foreach ($studentAssignments as $i => $row) {
            if ((string) ($row['id'] ?? '') === $editId && $editId !== '') {
                $studentAssignments[$i] = array_merge($row, $record);
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $studentAssignments[] = $record;
        }

        save_student_assignments($studentAssignmentsFile, $studentAssignments);
    }

    header('Location: student_assignments.php?saved=1');
    exit;
}

$editId = trim((string) ($_GET['edit'] ?? ''));
$editing = null;
if ($editId !== '') {
    foreach ($studentAssignments as $row) {
        if ((string) ($row['id'] ?? '') === $editId) {
            $editing = $row;
            break;
        }
    }
}

$filterProgram = trim((string) ($_GET['f_program'] ?? ''));
$filterSemester = trim((string) ($_GET['f_semester'] ?? ''));

$visibleAssignments = array_values(array_filter($studentAssignments, function ($row) use ($filterProgram, $filterSemester) {
    if ($filterProgram !== '' && (string) ($row['program'] ?? '') !== $filterProgram) {
        return false;
    }

    if ($filterSemester !== '' && (string) ($row['semester'] ?? '') !== $filterSemester) {
        return false;
    }

    return true;
}));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignación de Estudiantes</title>
<style>
body{font-family:"Segoe UI",Arial,sans-serif;background:#edf1f8;margin:0;padding:22px;color:#2f4460}
.wrapper{max-width:1260px;margin:0 auto}
.header-card{background:#f4f5f8;border:1px solid #dde4ef;border-radius:16px;padding:16px 18px;box-shadow:0 5px 14px rgba(51,72,107,.08)}
.header-card h1{margin:0;font-size:40px;color:#2f4460}
.subtitle{font-size:15px;color:#66748a;margin-top:6px}
.top-actions{display:flex;justify-content:space-between;align-items:center;margin:14px 0}
.back{color:#2d71d2;text-decoration:none;font-weight:700}
.notice{background:#ecfdf3;color:#166534;padding:10px 12px;border-radius:10px;font-size:14px;margin-bottom:14px;border:1px solid #b9eacb}
.layout{display:grid;grid-template-columns:1.1fr .9fr;gap:18px}
.panel{background:#f4f5f8;border:1px solid #dde4ef;border-radius:16px;overflow:hidden;box-shadow:0 5px 14px rgba(51,72,107,.08)}
.panel h3{margin:0;padding:14px 16px;border-bottom:1px solid #dde4ef;font-size:30px;color:#2f4460;background:linear-gradient(180deg,#f8f9fc,#f0f3f9)}
.panel-body{padding:16px}
.row{margin-bottom:12px}
label{display:block;font-weight:700;font-size:14px;margin-bottom:6px;color:#2f4460}
select,input[type="text"]{width:100%;padding:10px;border:1px solid #cfd8e8;border-radius:9px;background:#fff;color:#2f4460}
.btn{border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn-primary{background:linear-gradient(90deg,#2a67c4,#2d71d2);color:#fff}
.btn-save{background:linear-gradient(90deg,#2a67c4,#2d71d2);color:#fff;width:240px;margin:10px auto 0;display:block}
.filters{display:flex;gap:8px;margin-bottom:10px}
.filters select{flex:1}
.list{border:1px solid #d8dfec;border-radius:10px;overflow:hidden;background:#fff}
.item{display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #e7ecf5}
.item:last-child{border-bottom:none}
.meta{font-size:15px;color:#2f4460}
.actions a{text-decoration:none;margin-left:8px;font-size:16px}
.actions .edit{color:#4b5563}
.actions .delete{color:#c62828}
@media (max-width:980px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>

<div class="wrapper">
  <div class="header-card">
    <h1>🎓 Asignación de Estudiantes</h1>
    <div class="subtitle">Selecciona estudiante, docente, semestre, programa, curso y unidad.</div>
  </div>

  <div class="top-actions">
    <a class="back" href="../admin/dashboard.php">← Volver al panel</a>
    <a class="back" href="student_assignments.php">Limpiar filtros</a>
  </div>

  <?php if (isset($_GET['saved'])) { ?>
    <div class="notice">✔ Asignación guardada correctamente.</div>
  <?php } ?>

  <div class="layout">
    <section class="panel">
      <h3>Inscribir Estudiante</h3>
      <div class="panel-body">
        <form method="post">
          <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars((string) ($editing['id'] ?? '')); ?>">

          <div class="row">
            <label>Seleccionar Estudiante</label>
            <select name="student_id" required>
              <option value="">Seleccione un Estudiante</option>
              <?php foreach ($students as $student) { ?>
                <?php $sid = (string) ($student['id'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($sid); ?>" <?php echo ((string) ($editing['student_id'] ?? '') === $sid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string) ($student['name'] ?? $sid)); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Docente</label>
            <select name="teacher_id" required>
              <option value="">Seleccione un Docente</option>
              <?php foreach ($teachers as $teacher) { ?>
                <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($tid); ?>" <?php echo ((string) ($editing['teacher_id'] ?? '') === $tid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string) ($teacher['name'] ?? $tid)); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Programa</label>
            <select name="program" required>
              <?php foreach ($programOptions as $key => $label) { ?>
                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ((string) ($editing['program'] ?? 'technical') === $key) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($label); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Curso</label>
            <select name="course_id" required>
              <option value="">Elige un Curso</option>
              <?php foreach ($courses as $course) { ?>
                <?php $cid = (string) ($course['id'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo ((string) ($editing['course_id'] ?? '') === $cid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string) ($course['name'] ?? $cid)); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Semestre</label>
            <select name="semester" required>
              <option value="">Selecciona un Semestre</option>
              <?php foreach ($semesterOptions as $semester) { ?>
                <option value="<?php echo htmlspecialchars($semester); ?>" <?php echo ((string) ($editing['semester'] ?? '') === $semester) ? 'selected' : ''; ?>>
                  Semestre <?php echo htmlspecialchars($semester); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Unidad</label>
            <select name="unit_id" required>
              <option value="">Seleccione una Unidad</option>
              <?php foreach ($units as $unit) { ?>
                <?php $uid = (string) ($unit['id'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($uid); ?>" <?php echo ((string) ($editing['unit_id'] ?? '') === $uid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string) ($unit['name'] ?? $uid)); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <button class="btn btn-save" type="submit">
            <?php echo $editing ? 'Guardar cambios' : 'Asignar Estudiante'; ?>
          </button>
        </form>
      </div>
    </section>

    <section class="panel">
      <h3>Asignaciones Registradas</h3>
      <div class="panel-body">
        <form method="get" class="filters">
          <select name="f_program">
            <option value="">Filtrar por Programa</option>
            <?php foreach ($programOptions as $key => $label) { ?>
              <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $filterProgram === $key ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($label); ?>
              </option>
            <?php } ?>
          </select>
          <select name="f_semester">
            <option value="">Filtrar por Semestre</option>
            <?php foreach ($semesterOptions as $semester) { ?>
              <option value="<?php echo htmlspecialchars($semester); ?>" <?php echo $filterSemester === $semester ? 'selected' : ''; ?>>Semestre <?php echo htmlspecialchars($semester); ?></option>
            <?php } ?>
          </select>
          <button class="btn btn-primary" type="submit">Aplicar</button>
        </form>

        <div class="list">
          <?php if (empty($visibleAssignments)) { ?>
            <div class="item"><div class="meta">No hay asignaciones registradas.</div></div>
          <?php } else { ?>
            <?php foreach ($visibleAssignments as $row) { ?>
              <?php
              $id = (string) ($row['id'] ?? '');
              $studentName = find_name_by_id($students, (string) ($row['student_id'] ?? ''), 'Estudiante');
              $teacherName = find_name_by_id($teachers, (string) ($row['teacher_id'] ?? ''), 'Docente');
              $courseName = find_name_by_id($courses, (string) ($row['course_id'] ?? ''), 'Curso');
              $unitName = find_name_by_id($units, (string) ($row['unit_id'] ?? ''), 'Unidad');
              $programLabel = $programOptions[(string) ($row['program'] ?? 'technical')] ?? 'Programa';
              $semester = (string) ($row['semester'] ?? '');
              ?>
              <div class="item">
                <div class="meta">
                  <strong><?php echo htmlspecialchars($studentName); ?></strong>
                  - Docente: <?php echo htmlspecialchars($teacherName); ?>
                  <br>
                  <small>
                    <?php echo htmlspecialchars($programLabel); ?> ·
                    <?php echo htmlspecialchars($courseName); ?> ·
                    Semestre <?php echo htmlspecialchars($semester); ?> ·
                    <?php echo htmlspecialchars($unitName); ?>
                  </small>
                </div>
                <div class="actions">
                  <a class="edit" href="student_assignments.php?edit=<?php echo urlencode($id); ?>">✏️</a>
                  <a class="delete" href="student_assignments.php?delete=<?php echo urlencode($id); ?>" onclick="return confirm('¿Eliminar esta asignación?');">🗑️</a>
                </div>
              </div>
            <?php } ?>
          <?php } ?>
        </div>
      </div>
    </section>
  </div>
</div>

</body>
</html>

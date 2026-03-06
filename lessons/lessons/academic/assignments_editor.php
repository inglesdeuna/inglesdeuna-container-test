<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$baseDir = __DIR__ . '/data';
$coursesFile = $baseDir . '/courses.json';
$teachersFile = $baseDir . '/teachers.json';
$assignmentsFile = $baseDir . '/assignments.json';

foreach ([$coursesFile, $teachersFile, $assignmentsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$courses = json_decode(file_get_contents($coursesFile), true);
$teachers = json_decode(file_get_contents($teachersFile), true);
$assignments = json_decode(file_get_contents($assignmentsFile), true);

$courses = is_array($courses) ? $courses : [];
$teachers = is_array($teachers) ? $teachers : [];
$assignments = is_array($assignments) ? $assignments : [];

$program = isset($_GET['program']) ? (string) $_GET['program'] : 'technical';
if ($program !== 'technical' && $program !== 'english') {
    $program = 'technical';
}

$semesterOptions = ['1', '2', '3', '4', '5', '6'];

function find_by_id(array $rows, string $id): ?array
{
    foreach ($rows as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return null;
}

function label_course(array $courses, string $id): string
{
    $course = find_by_id($courses, $id);
    return $course['name'] ?? 'Curso';
}

function label_teacher(array $teachers, string $id): string
{
    $teacher = find_by_id($teachers, $id);
    return $teacher['name'] ?? 'Docente';
}

function save_assignments(string $assignmentsFile, array $assignments): void
{
    file_put_contents(
        $assignmentsFile,
        json_encode(array_values($assignments), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];

    $assignments = array_values(array_filter($assignments, function ($a) use ($deleteId) {
        return (string) ($a['id'] ?? '') !== $deleteId;
    }));

    save_assignments($assignmentsFile, $assignments);

    header('Location: assignments_editor.php?program=' . urlencode($program) . '&saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = isset($_POST['edit_id']) ? trim((string) $_POST['edit_id']) : '';
    $teacherId = isset($_POST['teacher_id']) ? trim((string) $_POST['teacher_id']) : '';
    $courseId = isset($_POST['course_id']) ? trim((string) $_POST['course_id']) : '';
    $semester = isset($_POST['semester']) ? trim((string) $_POST['semester']) : '';
    $permission = isset($_POST['permission']) ? trim((string) $_POST['permission']) : 'editor';

    if ($permission !== 'editor' && $permission !== 'viewer') {
        $permission = 'editor';
    }

    if ($teacherId !== '' && $courseId !== '' && $semester !== '') {
        $record = [
            'id' => $editId !== '' ? $editId : uniqid('assign_'),
            'program' => $program,
            'course_id' => $courseId,
            'period' => $semester,
            'teacher_id' => $teacherId,
            'permission' => $permission,
            'students' => [],
        ];

        $updated = false;
        foreach ($assignments as $i => $a) {
            if ((string) ($a['id'] ?? '') === $editId && $editId !== '') {
                $assignments[$i] = array_merge($a, $record);
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $assignments[] = $record;
        }

        save_assignments($assignmentsFile, $assignments);
    }

    header('Location: assignments_editor.php?program=' . urlencode($program) . '&saved=1');
    exit;
}

$editId = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
$editing = null;
if ($editId !== '') {
    foreach ($assignments as $a) {
        if ((string) ($a['id'] ?? '') === $editId) {
            $editing = $a;
            break;
        }
    }
}

$filterCourse = isset($_GET['f_course']) ? trim((string) $_GET['f_course']) : '';
$filterSemester = isset($_GET['f_semester']) ? trim((string) $_GET['f_semester']) : '';

$programAssignments = array_values(array_filter($assignments, function ($a) use ($program) {
    return (string) ($a['program'] ?? 'technical') === $program;
}));

$visibleAssignments = array_values(array_filter($programAssignments, function ($a) use ($filterCourse, $filterSemester) {
    if ($filterCourse !== '' && (string) ($a['course_id'] ?? '') !== $filterCourse) {
        return false;
    }

    if ($filterSemester !== '' && (string) ($a['period'] ?? '') !== $filterSemester) {
        return false;
    }

    return true;
}));

$titleProgram = $program === 'technical' ? 'Programas Técnicos' : 'Cursos de Inglés';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignaciones</title>
<style>
:root {
  --blue-1:#0d4ea7;
  --blue-2:#2d77db;
  --page-bg:#edf1f8;
  --card-bg:#f4f5f8;
  --surface:#ffffff;
  --text-main:#2f4460;
  --text-soft:#66748a;
  --line:#dde4ef;
  --ok-bg:#ecfdf3;
  --ok-text:#166534;
  --ok-line:#b9eacb;
}
* { box-sizing:border-box; }
body {
  margin:0;
  font-family:"Segoe UI",Arial,sans-serif;
  background:var(--page-bg);
  color:var(--text-main);
}
.topbar{
  background:linear-gradient(90deg,var(--blue-1),#1a61bd 52%,var(--blue-2));
  color:#fff;
  padding:12px 24px;
}
.topbar h1{ margin:0; font-size:38px; font-weight:700; }
.topbar p{ margin:4px 0 0; opacity:.95; font-size:15px; }
.page{ max-width:1260px; margin:18px auto 0; padding:0 16px 24px; }
.top-actions{ display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.back{ color:#2d71d2; text-decoration:none; font-weight:700; }
.notice{
  background:var(--ok-bg);
  color:var(--ok-text);
  padding:10px 12px;
  border-radius:10px;
  font-size:14px;
  margin-bottom:14px;
  border:1px solid var(--ok-line);
}
.layout{ display:grid; grid-template-columns:1.1fr .9fr; gap:18px; }
.panel{
  background:var(--card-bg);
  border:1px solid var(--line);
  border-radius:16px;
  box-shadow:0 5px 14px rgba(51,72,107,.08);
  overflow:hidden;
}
.panel h3{
  margin:0;
  padding:14px 16px;
  border-bottom:1px solid var(--line);
  font-size:30px;
  color:var(--text-main);
  background:linear-gradient(180deg,#f8f9fc,#f0f3f9);
}
.panel-body{ padding:16px; }
.row{ margin-bottom:12px; }
label{ display:block; font-weight:700; font-size:14px; margin-bottom:6px; color:var(--text-main); }
select,input[type="text"]{
  width:100%;
  padding:10px;
  border:1px solid #cfd8e8;
  border-radius:9px;
  background:var(--surface);
  color:var(--text-main);
}
.inline{ display:flex; gap:8px; align-items:center; }
.btn{
  border:none;
  border-radius:8px;
  padding:10px 14px;
  font-weight:700;
  cursor:pointer;
}
.btn-primary{ background:linear-gradient(90deg,#2a67c4,#2d71d2); color:#fff; }
.btn-save{ background:linear-gradient(90deg,#2a67c4,#2d71d2); color:#fff; width:220px; margin:8px auto 0; display:block; }
.perm{ display:flex; gap:14px; padding:5px 0; }
.filters{ display:flex; gap:8px; margin-bottom:10px; }
.filters select{ flex:1; }
.list{ border:1px solid #d8dfec; border-radius:10px; overflow:hidden; background:#fff; }
.item{ display:flex; justify-content:space-between; align-items:center; padding:12px; border-bottom:1px solid #e7ecf5; }
.item:last-child{ border-bottom:none; }
.meta{ font-size:15px; color:var(--text-main); }
.role{ font-weight:700; color:#1f6fd6; }
.actions a{ text-decoration:none; margin-left:8px; font-size:16px; }
.actions .edit{ color:#4b5563; }
.actions .delete{ color:#c62828; }
@media (max-width:980px){
  .topbar{ padding:12px 16px; }
  .topbar h1{ font-size:28px; }
  .layout{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<header class="topbar">
  <h1>🎓 Asignación de Cursos a Docentes</h1>
  <p><?php echo htmlspecialchars($titleProgram); ?></p>
</header>

<main class="page">
  <div class="top-actions">
    <a class="back" href="../admin/dashboard.php">← Volver al panel</a>
    <a class="back" href="assignments_editor.php?program=<?php echo urlencode($program); ?>">Limpiar filtros</a>
  </div>

  <?php if (isset($_GET['saved'])) { ?>
    <div class="notice">✔ Asignación guardada correctamente.</div>
  <?php } ?>

  <div class="layout">
    <section class="panel">
      <h3>Inscribir Docente</h3>
      <div class="panel-body">
        <form method="post">
          <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars((string) ($editing['id'] ?? '')); ?>">

          <div class="row">
            <label>Seleccionar Docente</label>
            <div class="inline">
              <select name="teacher_id" required>
                <option value="">Seleccione un Docente</option>
                <?php foreach ($teachers as $t) { ?>
                  <?php $tid = (string) ($t['id'] ?? ''); ?>
                  <option value="<?php echo htmlspecialchars($tid); ?>" <?php echo ((string) ($editing['teacher_id'] ?? '') === $tid) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) ($t['name'] ?? $tid)); ?>
                  </option>
                <?php } ?>
              </select>
              <button type="button" class="btn btn-primary" onclick="alert('Seleccione el docente y complete la asignación.');">+ Inscribir</button>
            </div>
          </div>

          <div class="row">
            <label>Seleccionar Curso</label>
            <select name="course_id" required>
              <option value="">Elige un Curso</option>
              <?php foreach ($courses as $c) { ?>
                <?php $cid = (string) ($c['id'] ?? ''); ?>
                <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo ((string) ($editing['course_id'] ?? '') === $cid) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string) ($c['name'] ?? $cid)); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Semestre</label>
            <select name="semester" required>
              <option value="">Selecciona un Semestre</option>
              <?php foreach ($semesterOptions as $s) { ?>
                <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ((string) ($editing['period'] ?? '') === $s) ? 'selected' : ''; ?>>
                  Semestre <?php echo htmlspecialchars($s); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Permiso</label>
            <div class="perm">
              <label><input type="radio" name="permission" value="editor" <?php echo ((string) ($editing['permission'] ?? 'editor') === 'editor') ? 'checked' : ''; ?>> Editor</label>
              <label><input type="radio" name="permission" value="viewer" <?php echo ((string) ($editing['permission'] ?? '') === 'viewer') ? 'checked' : ''; ?>> Sólo Ver</label>
            </div>
          </div>

          <button class="btn btn-save" type="submit">
            <?php echo $editing ? 'Guardar cambios' : 'Asignar Curso'; ?>
          </button>
        </form>
      </div>
    </section>

    <section class="panel">
      <h3>Cursos Asignados</h3>
      <div class="panel-body">
        <form method="get" class="filters">
          <input type="hidden" name="program" value="<?php echo htmlspecialchars($program); ?>">
          <select name="f_course">
            <option value="">Filtrar por Curso</option>
            <?php foreach ($courses as $c) { ?>
              <?php $cid = (string) ($c['id'] ?? ''); ?>
              <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo $filterCourse === $cid ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string) ($c['name'] ?? $cid)); ?>
              </option>
            <?php } ?>
          </select>
          <select name="f_semester">
            <option value="">Filtrar por Semestre</option>
            <?php foreach ($semesterOptions as $s) { ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $filterSemester === $s ? 'selected' : ''; ?>>Semestre <?php echo htmlspecialchars($s); ?></option>
            <?php } ?>
          </select>
          <button class="btn btn-primary" type="submit">Aplicar</button>
        </form>

        <div class="list">
          <?php if (empty($visibleAssignments)) { ?>
            <div class="item"><div class="meta">No hay asignaciones en este programa.</div></div>
          <?php } else { ?>
            <?php foreach ($visibleAssignments as $a) { ?>
              <?php
              $id = (string) ($a['id'] ?? '');
              $courseName = label_course($courses, (string) ($a['course_id'] ?? ''));
              $teacherName = label_teacher($teachers, (string) ($a['teacher_id'] ?? ''));
              $period = (string) ($a['period'] ?? '');
              $permissionLabel = ((string) ($a['permission'] ?? 'editor') === 'viewer') ? 'Sólo Ver' : 'Editor';
              ?>
              <div class="item">
                <div class="meta">
                  <strong><?php echo htmlspecialchars($courseName); ?></strong>
                  - Semestre <?php echo htmlspecialchars($period); ?>
                  - <span class="role"><?php echo htmlspecialchars($permissionLabel); ?></span>
                  <br>
                  <small>Docente: <?php echo htmlspecialchars($teacherName); ?></small>
                </div>
                <div class="actions">
                  <a class="edit" href="assignments_editor.php?program=<?php echo urlencode($program); ?>&edit=<?php echo urlencode($id); ?>">✏️</a>
                  <a class="delete" href="assignments_editor.php?program=<?php echo urlencode($program); ?>&delete=<?php echo urlencode($id); ?>" onclick="return confirm('¿Eliminar esta asignación?');">🗑️</a>
                </div>
              </div>
            <?php } ?>
          <?php } ?>
        </div>
      </div>
    </section>
  </div>
</main>

</body>
</html>
PHP

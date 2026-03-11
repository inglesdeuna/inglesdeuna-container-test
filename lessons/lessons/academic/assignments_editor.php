<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
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

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

function slug_username(string $name): string
{
    $name = trim(mb_strtolower($name, 'UTF-8'));

    $replace = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n',
    ];
    $name = strtr($name, $replace);
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    $name = preg_replace('/\s+/', '.', $name);
    $name = trim((string) $name, '.');

    return $name !== '' ? $name : 'docente';
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function generate_unique_teacher_username(PDO $pdo, string $teacherName, string $teacherId): string
{
    $base = slug_username($teacherName);

    try {
        $stmt = $pdo->prepare("
            SELECT username
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $existing = $stmt->fetchColumn();

        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }
    } catch (Throwable $e) {
    }

    $candidate = $base;
    $counter = 2;

    while (true) {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM teacher_accounts
                WHERE username = :username
                LIMIT 1
            ");
            $stmt->execute(['username' => $candidate]);
            $taken = (bool) $stmt->fetchColumn();

            if (!$taken) {
                return $candidate;
            }
        } catch (Throwable $e) {
            return $candidate;
        }

        $candidate = $base . $counter;
        $counter++;
    }
}

function generate_temp_password(): string
{
    return '123456';
}

function ensure_teacher_account(
    PDO $pdo,
    string $teacherId,
    string $teacherName,
    string $program,
    string $courseId,
    string $courseName,
    string $permission
): array {
    $username = generate_unique_teacher_username($pdo, $teacherName, $teacherId);
    $tempPassword = generate_temp_password();

    $hasMustChangePassword = table_has_column($pdo, 'teacher_accounts', 'must_change_password');
    $hasPasswordUpdatedAt = table_has_column($pdo, 'teacher_accounts', 'password_updated_at');
    $hasIsActive = table_has_column($pdo, 'teacher_accounts', 'is_active');

    try {
        $stmt = $pdo->prepare("
            SELECT id, username, password
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
              AND scope = :scope
              AND target_id = :target_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute([
            'teacher_id' => $teacherId,
            'scope' => $program,
            'target_id' => $courseId,
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $existing = false;
    }

    if ($existing) {
        $accountId = (string) ($existing['id'] ?? '');

        $setParts = [
            'teacher_name = :teacher_name',
            'username = :username',
            'password = COALESCE(NULLIF(password, \'\'), :password)',
            'permission = :permission',
            'target_name = :target_name',
            'updated_at = NOW()',
        ];

        if ($hasMustChangePassword) {
            $setParts[] = 'must_change_password = COALESCE(must_change_password, TRUE)';
        }

        if ($hasIsActive) {
            $setParts[] = 'is_active = COALESCE(is_active, TRUE)';
        }

        $sql = "
            UPDATE teacher_accounts
            SET " . implode(",\n                ", $setParts) . "
            WHERE id = :id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'teacher_name' => $teacherName,
            'username' => $username,
            'password' => $tempPassword,
            'permission' => $permission,
            'target_name' => $courseName,
            'id' => $accountId,
        ]);

        try {
            $stmt2 = $pdo->prepare("
                SELECT username, password
                FROM teacher_accounts
                WHERE id = :id
                LIMIT 1
            ");
            $stmt2->execute(['id' => $accountId]);
            $fresh = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $fresh = [];
        }

        return [
            'username' => (string) ($fresh['username'] ?? $username),
            'password' => (string) ($fresh['password'] ?? $tempPassword),
            'created' => false,
        ];
    }

    $columns = [
        'id',
        'teacher_id',
        'teacher_name',
        'scope',
        'target_id',
        'target_name',
        'permission',
        'username',
        'password',
        'updated_at',
    ];

    $values = [
        ':id',
        ':teacher_id',
        ':teacher_name',
        ':scope',
        ':target_id',
        ':target_name',
        ':permission',
        ':username',
        ':password',
        'NOW()',
    ];

    if ($hasMustChangePassword) {
        $columns[] = 'must_change_password';
        $values[] = 'TRUE';
    }

    if ($hasPasswordUpdatedAt) {
        $columns[] = 'password_updated_at';
        $values[] = 'NULL';
    }

    if ($hasIsActive) {
        $columns[] = 'is_active';
        $values[] = 'TRUE';
    }

    $sql = "
        INSERT INTO teacher_accounts (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $values) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'id' => uniqid('acc_'),
        'teacher_id' => $teacherId,
        'teacher_name' => $teacherName,
        'scope' => $program,
        'target_id' => $courseId,
        'target_name' => $courseName,
        'permission' => $permission,
        'username' => $username,
        'password' => $tempPassword,
    ]);

    return [
        'username' => $username,
        'password' => $tempPassword,
        'created' => true,
    ];
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

$courses = json_decode((string) file_get_contents($coursesFile), true);
$teachers = json_decode((string) file_get_contents($teachersFile), true);
$assignments = json_decode((string) file_get_contents($assignmentsFile), true);

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
    return (string) ($course['name'] ?? 'Curso');
}

function label_teacher(array $teachers, string $id): string
{
    $teacher = find_by_id($teachers, $id);
    return (string) ($teacher['name'] ?? 'Docente');
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

$createdAccountInfo = null;

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
        $teacher = find_by_id($teachers, $teacherId);
        $course = find_by_id($courses, $courseId);

        $teacherName = (string) ($teacher['name'] ?? 'Docente');
        $courseName = (string) ($course['name'] ?? 'Curso');

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
                $existingStudents = $a['students'] ?? [];
                $record['students'] = is_array($existingStudents) ? $existingStudents : [];
                $assignments[$i] = array_merge($a, $record);
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $assignments[] = $record;
        }

        save_assignments($assignmentsFile, $assignments);

        $pdo = get_pdo_connection();
        if ($pdo) {
            try {
                $accountInfo = ensure_teacher_account(
                    $pdo,
                    $teacherId,
                    $teacherName,
                    $program,
                    $courseId,
                    $courseName,
                    $permission
                );

                $query = [
                    'program' => $program,
                    'saved' => '1',
                    'account_created' => $accountInfo['created'] ? '1' : '0',
                    'teacher_user' => $accountInfo['username'],
                ];

                if ($accountInfo['created']) {
                    $query['temp_password'] = $accountInfo['password'];
                }

                header('Location: assignments_editor.php?' . http_build_query($query));
                exit;
            } catch (Throwable $e) {
            }
        }
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

$teacherAccountMap = [];
$pdo = get_pdo_connection();
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT teacher_id, scope, target_id, username, password
            FROM teacher_accounts
            ORDER BY updated_at DESC NULLS LAST
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $key = (string) ($row['teacher_id'] ?? '') . '|' . (string) ($row['scope'] ?? '') . '|' . (string) ($row['target_id'] ?? '');
            if (!isset($teacherAccountMap[$key])) {
                $teacherAccountMap[$key] = [
                    'username' => (string) ($row['username'] ?? ''),
                    'password' => (string) ($row['password'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        $teacherAccountMap = [];
    }
}
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
  --warn-bg:#fff8e8;
  --warn-text:#9a6700;
  --warn-line:#f2d38a;
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
.top-actions{ display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px; flex-wrap:wrap; }
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
.notice-warn{
  background:var(--warn-bg);
  color:var(--warn-text);
  border-color:var(--warn-line);
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
.item{ display:flex; justify-content:space-between; align-items:flex-start; padding:12px; border-bottom:1px solid #e7ecf5; gap:12px; }
.item:last-child{ border-bottom:none; }
.meta{ font-size:15px; color:var(--text-main); line-height:1.45; }
.role{ font-weight:700; color:#1f6fd6; }
.cred{ margin-top:4px; font-size:13px; color:var(--text-soft); }
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
  <p><?php echo h($titleProgram); ?></p>
</header>

<main class="page">
  <div class="top-actions">
    <a class="back" href="../admin/dashboard.php">← Volver al panel</a>
    <a class="back" href="assignments_editor.php?program=<?php echo urlencode($program); ?>">Limpiar filtros</a>
  </div>

  <?php if (isset($_GET['saved'])) { ?>
    <div class="notice">✔ Asignación guardada correctamente.</div>
  <?php } ?>

  <?php if (isset($_GET['teacher_user']) && $_GET['teacher_user'] !== '') { ?>
    <div class="notice notice-warn">
      Usuario docente: <strong><?php echo h((string) $_GET['teacher_user']); ?></strong>
      <?php if (isset($_GET['temp_password']) && $_GET['temp_password'] !== '') { ?>
        — Contraseña temporal: <strong><?php echo h((string) $_GET['temp_password']); ?></strong>
      <?php } else { ?>
        — Cuenta existente actualizada.
      <?php } ?>
    </div>
  <?php } ?>

  <div class="layout">
    <section class="panel">
      <h3>Inscribir Docente</h3>
      <div class="panel-body">
        <form method="post">
          <input type="hidden" name="edit_id" value="<?php echo h((string) ($editing['id'] ?? '')); ?>">

          <div class="row">
            <label>Seleccionar Docente</label>
            <div class="inline">
              <select name="teacher_id" required>
                <option value="">Seleccione un Docente</option>
                <?php foreach ($teachers as $t) { ?>
                  <?php $tid = (string) ($t['id'] ?? ''); ?>
                  <option value="<?php echo h($tid); ?>" <?php echo ((string) ($editing['teacher_id'] ?? '') === $tid) ? 'selected' : ''; ?>>
                    <?php echo h((string) ($t['name'] ?? $tid)); ?>
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
                <option value="<?php echo h($cid); ?>" <?php echo ((string) ($editing['course_id'] ?? '') === $cid) ? 'selected' : ''; ?>>
                  <?php echo h((string) ($c['name'] ?? $cid)); ?>
                </option>
              <?php } ?>
            </select>
          </div>

          <div class="row">
            <label>Seleccionar Semestre</label>
            <select name="semester" required>
              <option value="">Selecciona un Semestre</option>
              <?php foreach ($semesterOptions as $s) { ?>
                <option value="<?php echo h($s); ?>" <?php echo ((string) ($editing['period'] ?? '') === $s) ? 'selected' : ''; ?>>
                  Semestre <?php echo h($s); ?>
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
          <input type="hidden" name="program" value="<?php echo h($program); ?>">
          <select name="f_course">
            <option value="">Filtrar por Curso</option>
            <?php foreach ($courses as $c) { ?>
              <?php $cid = (string) ($c['id'] ?? ''); ?>
              <option value="<?php echo h($cid); ?>" <?php echo $filterCourse === $cid ? 'selected' : ''; ?>>
                <?php echo h((string) ($c['name'] ?? $cid)); ?>
              </option>
            <?php } ?>
          </select>
          <select name="f_semester">
            <option value="">Filtrar por Semestre</option>
            <?php foreach ($semesterOptions as $s) { ?>
              <option value="<?php echo h($s); ?>" <?php echo $filterSemester === $s ? 'selected' : ''; ?>>Semestre <?php echo h($s); ?></option>
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
              $courseId = (string) ($a['course_id'] ?? '');
              $teacherId = (string) ($a['teacher_id'] ?? '');
              $courseName = label_course($courses, $courseId);
              $teacherName = label_teacher($teachers, $teacherId);
              $period = (string) ($a['period'] ?? '');
              $permissionLabel = ((string) ($a['permission'] ?? 'editor') === 'viewer') ? 'Sólo Ver' : 'Editor';
              $accountKey = $teacherId . '|' . (string) ($a['program'] ?? '') . '|' . $courseId;
              $accountInfo = $teacherAccountMap[$accountKey] ?? ['username' => '', 'password' => ''];
              ?>
              <div class="item">
                <div class="meta">
                  <strong><?php echo h($courseName); ?></strong>
                  - Semestre <?php echo h($period); ?>
                  - <span class="role"><?php echo h($permissionLabel); ?></span>
                  <br>
                  <small>Docente: <?php echo h($teacherName); ?></small>

                  <?php if ($accountInfo['username'] !== '') { ?>
                    <div class="cred">
                      Usuario: <strong><?php echo h((string) $accountInfo['username']); ?></strong>
                      <?php if ((string) $accountInfo['password'] !== '') { ?>
                        — Temporal: <strong><?php echo h((string) $accountInfo['password']); ?></strong>
                      <?php } ?>
                    </div>
                  <?php } ?>
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

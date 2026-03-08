<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/dashboard.php');
    exit;
}

/* ===============================
   ARCHIVOS
=============================== */
$baseDir = __DIR__ . '/data';
$studentsFile = $baseDir . '/students.json';
$teachersFile = $baseDir . '/teachers.json';
$coursesFile = $baseDir . '/courses.json';
$unitsFile = $baseDir . '/units.json';
$studentAssignmentsFile = $baseDir . '/student_assignments_records.json';
$studentAccountsFile = $baseDir . '/student_accounts.json';

/* ===============================
   CONFIG
=============================== */
$programOptions = [
    'english' => 'Cursos de Inglés',
    'technical' => 'Programa Técnico',
];

/* ===============================
   HELPERS
=============================== */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ensure_data_files(string $baseDir, array $files): void
{
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }

    foreach ($files as $file) {
        if (!file_exists($file)) {
            file_put_contents($file, '[]');
        }
    }
}

function load_json_array(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function save_json_file(string $file, array $records): void
{
    file_put_contents(
        $file,
        json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

function find_name_by_id(array $rows, string $id, string $fallback = 'N/D'): string
{
    foreach ($rows as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return (string) ($row['name'] ?? $fallback);
        }
    }

    return $fallback;
}

function detect_program_for_course(array $course): string
{
    $programRaw = mb_strtolower((string) (
        $course['program']
        ?? $course['program_id']
        ?? $course['scope']
        ?? ''
    ));
    $nameRaw = mb_strtolower((string) ($course['name'] ?? ''));

    if (
        str_contains($programRaw, 'english') ||
        str_contains($programRaw, 'ingles') ||
        str_contains($programRaw, 'prog_english') ||
        str_contains($programRaw, 'english_levels') ||
        str_contains($nameRaw, 'phase') ||
        str_contains($nameRaw, 'fase') ||
        str_contains($nameRaw, 'level')
    ) {
        return 'english';
    }

    return 'technical';
}

function detect_program_for_unit(array $unit): string
{
    $programRaw = mb_strtolower((string) (
        $unit['program']
        ?? $unit['program_id']
        ?? $unit['scope']
        ?? ''
    ));
    $nameRaw = mb_strtolower((string) ($unit['name'] ?? ''));

    if (
        str_contains($programRaw, 'english') ||
        str_contains($programRaw, 'ingles') ||
        str_contains($programRaw, 'prog_english') ||
        str_contains($programRaw, 'english_levels') ||
        str_contains($nameRaw, 'phase') ||
        str_contains($nameRaw, 'fase') ||
        !empty($unit['phase_id'])
    ) {
        return 'english';
    }

    return 'technical';
}

function filter_courses_by_program(array $courses, string $program): array
{
    return array_values(array_filter($courses, function ($course) use ($program) {
        return detect_program_for_course((array) $course) === $program;
    }));
}

function filter_units_by_program(array $units, string $program): array
{
    return array_values(array_filter($units, function ($unit) use ($program) {
        return detect_program_for_unit((array) $unit) === $program;
    }));
}

function normalize_technical_courses(array $technicalCourses, array $technicalUnits): array
{
    $normalized = [];

    foreach ($technicalCourses as $course) {
        $id = (string) ($course['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $normalized[] = [
            'id' => $id,
            'name' => (string) ($course['name'] ?? ('SEMESTRE ' . $id)),
        ];
    }

    if (!empty($normalized)) {
        return $normalized;
    }

    $seen = [];
    foreach ($technicalUnits as $unit) {
        $semesterId = (string) ($unit['course_id'] ?? $unit['level_id'] ?? '');
        if ($semesterId === '' || isset($seen[$semesterId])) {
            continue;
        }

        $seen[$semesterId] = true;
        $normalized[] = [
            'id' => $semesterId,
            'name' => 'SEMESTRE ' . $semesterId,
        ];
    }

    return $normalized;
}

function slugify_username(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/', '.', $value);
    $value = trim((string) $value, '.');
    return $value !== '' ? $value : 'student';
}

function generate_student_username(array $student, array $accounts): string
{
    $name = (string) ($student['name'] ?? 'student');
    $studentId = (string) ($student['id'] ?? '');
    $base = slugify_username($name);

    if ($studentId !== '') {
        $base .= '.' . preg_replace('/[^a-zA-Z0-9]/', '', $studentId);
    }

    $username = $base;
    $counter = 1;
    $existingUsernames = [];

    foreach ($accounts as $acc) {
        $existingUsernames[] = (string) ($acc['username'] ?? '');
    }

    while (in_array($username, $existingUsernames, true)) {
        $username = $base . '.' . $counter;
        $counter++;
    }

    return $username;
}

function generate_temp_password(int $length = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($chars) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }

    return $password;
}

function find_student_by_id(array $students, string $studentId): ?array
{
    foreach ($students as $student) {
        if ((string) ($student['id'] ?? '') === $studentId) {
            return (array) $student;
        }
    }

    return null;
}

function find_student_account(array $accounts, string $studentId): ?array
{
    foreach ($accounts as $account) {
        if ((string) ($account['student_id'] ?? '') === $studentId) {
            return (array) $account;
        }
    }

    return null;
}

function ensure_student_account(string $studentId, array $students, array &$accounts, string $accountsFile): ?array
{
    if ($studentId === '') {
        return null;
    }

    foreach ($accounts as $account) {
        if ((string) ($account['student_id'] ?? '') === $studentId) {
            return $account;
        }
    }

    $student = find_student_by_id($students, $studentId);
    if (!$student) {
        return null;
    }

    $username = generate_student_username($student, $accounts);
    $tempPassword = generate_temp_password(10);

    $newAccount = [
        'id' => uniqid('stu_acc_'),
        'student_id' => $studentId,
        'student_name' => (string) ($student['name'] ?? 'Estudiante'),
        'username' => $username,
        'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
        'temp_password' => $tempPassword,
        'must_change_password' => true,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];

    $accounts[] = $newAccount;
    save_json_file($accountsFile, $accounts);

    return $newAccount;
}

/* ===============================
   DB CATÁLOGO TÉCNICO
=============================== */
function load_technical_catalog_from_database(): array
{
    if (!getenv('DATABASE_URL')) {
        return [[], []];
    }

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return [[], []];
    }

    require $dbFile;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return [[], []];
    }

    try {
        $semestersStmt = $pdo->prepare("\n            SELECT id, name\n            FROM courses\n            WHERE program_id = :program\n            ORDER BY name ASC, id ASC\n        ");
        $semestersStmt->execute(['program' => 'prog_technical']);
        $semestersRaw = $semestersStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->prepare("\n            SELECT u.id, u.name, u.course_id\n            FROM units u\n            INNER JOIN courses c ON c.id = u.course_id\n            WHERE c.program_id = :program\n            ORDER BY u.course_id ASC, u.created_at ASC, u.id ASC\n        ");
        $unitsStmt->execute(['program' => 'prog_technical']);
        $unitsRaw = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [[], []];
    }

    $technicalSemesters = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($semestersRaw) ? $semestersRaw : []);

    $technicalUnits = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'course_id' => (string) ($row['course_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($unitsRaw) ? $unitsRaw : []);

    $technicalSemesters = array_values(array_filter($technicalSemesters, function ($r) {
        return (string) ($r['id'] ?? '') !== '';
    }));
    $technicalUnits = array_values(array_filter($technicalUnits, function ($r) {
        return (string) ($r['id'] ?? '') !== '';
    }));

    return [$technicalSemesters, $technicalUnits];
}

/* ===============================
   DB CATÁLOGO INGLÉS
=============================== */
function load_english_catalog_from_database(): array
{
    if (!getenv('DATABASE_URL')) {
        return [[], [], [], false];
    }

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return [[], [], [], false];
    }

    require $dbFile;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return [[], [], [], false];
    }

    try {
        $levelsStmt = $pdo->query("\n            SELECT id, name\n            FROM english_levels\n            ORDER BY id ASC\n        ");
        $levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

        $phasesStmt = $pdo->query("\n            SELECT id, level_id, name\n            FROM english_phases\n            ORDER BY level_id ASC, created_at ASC, id ASC\n        ");
        $phases = $phasesStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->query("\n            SELECT u.id, u.name, u.phase_id, p.level_id\n            FROM units u\n            INNER JOIN english_phases p ON p.id = u.phase_id\n            ORDER BY p.level_id ASC, u.phase_id ASC, u.id ASC\n        ");
        $unitsRaw = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [[], [], [], false];
    }

    $englishLevels = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($levels) ? $levels : []);

    $englishPhases = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'level_id' => (string) ($row['level_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($phases) ? $phases : []);

    $englishUnits = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'phase_id' => (string) ($row['phase_id'] ?? ''),
            'level_id' => (string) ($row['level_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($unitsRaw) ? $unitsRaw : []);

    $englishLevels = array_values(array_filter($englishLevels, function ($r) {
        return (string) ($r['id'] ?? '') !== '';
    }));
    $englishPhases = array_values(array_filter($englishPhases, function ($r) {
        return (string) ($r['id'] ?? '') !== '';
    }));
    $englishUnits = array_values(array_filter($englishUnits, function ($r) {
        return (string) ($r['id'] ?? '') !== '';
    }));

    return [$englishLevels, $englishPhases, $englishUnits, true];
}

ensure_data_files($baseDir, [
    $studentsFile,
    $teachersFile,
    $coursesFile,
    $unitsFile,
    $studentAssignmentsFile,
    $studentAccountsFile,
]);

$students = load_json_array($studentsFile);
$teachers = load_json_array($teachersFile);
$courses = load_json_array($coursesFile);
$units = load_json_array($unitsFile);
$studentAssignments = load_json_array($studentAssignmentsFile);
$studentAccounts = load_json_array($studentAccountsFile);

/* ===============================
   ELIMINAR
=============================== */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];

    $studentAssignments = array_values(array_filter($studentAssignments, function ($row) use ($deleteId) {
        return (string) ($row['id'] ?? '') !== $deleteId;
    }));

    save_json_file($studentAssignmentsFile, $studentAssignments);
    header('Location: student_assignments.php?saved=1');
    exit;
}

/* ===============================
   CATÁLOGOS
=============================== */
[$englishLevelsDb, $englishPhasesDb, $englishUnitsDb, $englishFromDb] = load_english_catalog_from_database();
[$technicalSemestersDb, $technicalUnitsDb] = load_technical_catalog_from_database();

$technicalCoursesRaw = filter_courses_by_program($courses, 'technical');
$technicalUnitsJson = filter_units_by_program($units, 'technical');
$technicalSemestersJson = normalize_technical_courses($technicalCoursesRaw, $technicalUnitsJson);

$technicalSemesters = !empty($technicalSemestersDb) ? $technicalSemestersDb : $technicalSemestersJson;
$technicalUnits = !empty($technicalUnitsDb) ? $technicalUnitsDb : $technicalUnitsJson;

$englishLevels = $englishLevelsDb;
$englishPhases = $englishPhasesDb;
$englishUnits = $englishUnitsDb;

if (empty($englishLevels)) {
    $englishLevels = [
        ['id' => '1', 'name' => 'PHASE 1'],
        ['id' => '2', 'name' => 'PHASE 2'],
    ];
}

if (empty($englishPhases)) {
    $englishPhases = [
        ['id' => '101', 'level_id' => '1', 'name' => 'PRESCHOOL LEVEL'],
        ['id' => '102', 'level_id' => '1', 'name' => 'KINDERGARTEN LEVEL'],
    ];
}

if (empty($englishUnits)) {
    $englishUnits = [
        ['id' => '1001', 'phase_id' => '101', 'level_id' => '1', 'name' => 'UNIT 1 TOUCH YOUR HEAD'],
    ];
}

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = trim((string) ($_POST['edit_id'] ?? ''));
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $teacherId = trim((string) ($_POST['teacher_id'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? 'technical'));
    $courseId = trim((string) ($_POST['course_id'] ?? ''));
    $levelId = trim((string) ($_POST['level_id'] ?? ''));
    $unitId = trim((string) ($_POST['unit_id'] ?? ''));

    if (!isset($programOptions[$program])) {
        $program = 'technical';
    }

    if ($program === 'technical') {
        $levelId = '';
    }

    if ($program === 'english') {
        $isValid = $studentId !== '' && $teacherId !== '' && $courseId !== '' && $levelId !== '' && $unitId !== '';
    } else {
        $isValid = $studentId !== '' && $teacherId !== '' && $courseId !== '' && $unitId !== '';
    }

    if ($isValid) {
        $studentAccount = ensure_student_account($studentId, $students, $studentAccounts, $studentAccountsFile);

        $record = [
            'id' => $editId !== '' ? $editId : uniqid('stu_assign_'),
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'program' => $program,
            'course_id' => $courseId,
            'level_id' => $levelId,
            'period' => $levelId,
            'unit_id' => $unitId,
            'student_username' => (string) ($studentAccount['username'] ?? ''),
            'student_temp_password' => (string) ($studentAccount['temp_password'] ?? ''),
            'updated_at' => date('c'),
        ];

        $updated = false;
        foreach ($studentAssignments as $index => $existing) {
            if ((string) ($existing['id'] ?? '') === $record['id']) {
                $studentAssignments[$index] = array_merge((array) $existing, $record);
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $studentAssignments[] = $record;
        }

        save_json_file($studentAssignmentsFile, $studentAssignments);
        header('Location: student_assignments.php?saved=1');
        exit;
    }
}

/* ===============================
   EDITAR
=============================== */
$editRecord = null;
if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $editId = (string) $_GET['edit'];

    foreach ($studentAssignments as $row) {
        if ((string) ($row['id'] ?? '') === $editId) {
            $editRecord = (array) $row;
            break;
        }
    }
}

$selectedProgram = (string) ($editRecord['program'] ?? 'technical');
if (!isset($programOptions[$selectedProgram])) {
    $selectedProgram = 'technical';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asignaciones de estudiantes</title>
    <style>
        :root{--bg:#eef2f7;--card:#fff;--line:#dce4f0;--text:#1f2937;--title:#1f3c75;--subtitle:#2c3e50;--muted:#5b6577;--head:#f7faff;--blue:#1f66cc;--blue-hover:#2f5bb5;--badge-bg:#eef2ff;--badge-text:#1f4ec9;--danger:#dc2626;--shadow:0 8px 24px rgba(0,0,0,.08)}
        *{box-sizing:border-box} body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);padding:30px}
        .page{max-width:1100px;margin:0 auto}.back{display:inline-block;margin-bottom:16px;text-decoration:none;color:var(--blue);font-weight:700;font-size:14px}
        .page-title{font-size:28px;font-weight:700;color:var(--title);margin:0 0 18px}.notice{background:#ecfdf3;border:1px solid #b9eacb;color:#166534;border-radius:14px;padding:12px 14px;margin-bottom:18px;box-shadow:var(--shadow)}
        .stack{display:flex;flex-direction:column;gap:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
        .card-header{padding:16px 20px;border-bottom:1px solid var(--line);background:#fafcff}.card-header h2{margin:0;font-size:22px;font-weight:600;color:var(--subtitle)}
        .card-body{padding:20px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:flex;flex-direction:column;min-width:0}.field.full{grid-column:1/-1}
        label{font-size:12px;font-weight:700;color:var(--text);margin:0 0 8px;text-transform:uppercase;letter-spacing:.2px}
        select,input,button{width:100%;min-height:42px;border-radius:10px;border:1px solid #c7d3e3;background:#fff;color:var(--text);padding:10px 12px;font-size:14px}
        select:focus,input:focus,button:focus{outline:none;border-color:#7d9dff;box-shadow:0 0 0 3px rgba(70,96,220,.1)}
        .button-primary{border:none;background:var(--blue);color:#fff;font-weight:700;font-size:14px;cursor:pointer}.button-primary:hover{background:var(--blue-hover)}
        .hint{margin-top:7px;font-size:12px;color:var(--muted)}.hint a{color:var(--blue);text-decoration:none;font-weight:700}
        .table-wrap{width:100%;border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden}.table-scroll{width:100%;overflow-x:auto}
        table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0} thead th{background:var(--head);color:var(--text);font-size:12px;font-weight:700;text-transform:uppercase;padding:12px;text-align:left;white-space:nowrap}
        tbody td{padding:12px;border-bottom:1px solid #e8eef6;font-size:14px;color:var(--text);vertical-align:top}tbody tr:last-child td{border-bottom:none}
        .program-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:var(--badge-bg);color:var(--badge-text);font-size:12px;font-weight:700;white-space:nowrap}
        .credential-box{display:flex;flex-direction:column;gap:4px}.credential-chip{display:inline-block;padding:4px 8px;border-radius:999px;background:#f3f6fb;color:#324968;font-size:12px;font-weight:700;width:max-content;max-width:100%;word-break:break-word}
        .actions{white-space:nowrap}.actions a{text-decoration:none;font-weight:700}.actions a:first-child{color:var(--blue)}.actions a:last-child{color:var(--danger)}.muted{color:var(--muted)}
        @media (max-width:768px){body{padding:20px}.page-title{font-size:24px}.card-header h2{font-size:20px}.form-grid{grid-template-columns:1fr}.button-primary{font-size:12px}}
    </style>
</head>
<body>
<main class="page">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
    <h1 class="page-title">Asignaciones de estudiantes</h1>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice">Guardado correctamente.</div>
    <?php endif; ?>

    <div class="stack">
        <section class="card">
            <div class="card-header"><h2>🎓 Crear o editar asignación</h2></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="edit_id" value="<?= h((string) ($editRecord['id'] ?? '')) ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="student_id">Estudiante</label>
                            <select name="student_id" id="student_id" required>
                                <option value="">Seleccionar estudiante...</option>
                                <?php foreach ($students as $student): $sid = (string) ($student['id'] ?? ''); ?>
                                    <option value="<?= h($sid) ?>" <?= $sid === (string) ($editRecord['student_id'] ?? '') ? 'selected' : '' ?>><?= h((string) ($student['name'] ?? 'Sin nombre')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="teacher_id">Docente</label>
                            <select name="teacher_id" id="teacher_id" required>
                                <option value="">Seleccionar docente...</option>
                                <?php foreach ($teachers as $teacher): $tid = (string) ($teacher['id'] ?? ''); ?>
                                    <option value="<?= h($tid) ?>" <?= $tid === (string) ($editRecord['teacher_id'] ?? '') ? 'selected' : '' ?>><?= h((string) ($teacher['name'] ?? 'Sin nombre')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="program">Programa</label>
                            <select name="program" id="program" required>
                                <?php foreach ($programOptions as $key => $label): ?>
                                    <option value="<?= h($key) ?>" <?= $key === $selectedProgram ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field" id="courseField">
                            <label for="course_id" id="courseLabel">Semestre</label>
                            <select name="course_id" id="course_id" required><option value="">Seleccionar...</option></select>
                        </div>

                        <div class="field" id="phaseField">
                            <label for="level_id">Fase</label>
                            <select name="level_id" id="level_id"><option value="">Seleccionar fase...</option></select>
                            <div class="hint" id="englishHint">
                                Configurar: <a href="english_structure_levels.php">Levels</a>
                                <?php if ($englishFromDb && !empty($englishLevels)): ?>
                                    | <a href="english_structure_phases.php?level=<?= h((string) ($englishLevels[0]['id'] ?? '1')) ?>">Phases</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="field" id="unitField">
                            <label for="unit_id">Unidad</label>
                            <select name="unit_id" id="unit_id" required><option value="">Seleccionar unidad...</option></select>
                        </div>

                        <div class="field full">
                            <button class="button-primary" type="submit">Guardar asignación y crear acceso del estudiante</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2>📋 Asignaciones creadas</h2></div>
            <div class="card-body">
                <div class="table-wrap"><div class="table-scroll">
                    <table>
                        <thead><tr><th>Estudiante</th><th>Usuario</th><th>Password temporal</th><th>Docente</th><th>Programa</th><th>Nivel / Semestre</th><th>Fase</th><th>Unidad</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php if (empty($studentAssignments)): ?>
                            <tr><td colspan="9" class="muted">No hay asignaciones registradas.</td></tr>
                        <?php else: ?>
                            <?php foreach ($studentAssignments as $row):
                                $program = (string) ($row['program'] ?? 'technical');
                                $isEnglish = $program === 'english';
                                $courseName = $isEnglish
                                    ? find_name_by_id($englishLevels, (string) ($row['course_id'] ?? ''), 'N/D')
                                    : find_name_by_id($technicalSemesters, (string) ($row['course_id'] ?? ''), 'N/D');
                                $phaseName = $isEnglish
                                    ? find_name_by_id($englishPhases, (string) ($row['level_id'] ?? $row['period'] ?? ''), 'N/D')
                                    : 'No aplica';
                                $unitName = $isEnglish
                                    ? find_name_by_id($englishUnits, (string) ($row['unit_id'] ?? ''), 'N/D')
                                    : find_name_by_id($technicalUnits, (string) ($row['unit_id'] ?? ''), 'N/D');
                                $studentIdRow = (string) ($row['student_id'] ?? '');
                                $account = find_student_account($studentAccounts, $studentIdRow);
                                $username = (string) ($account['username'] ?? $row['student_username'] ?? '');
                                $tempPassword = (string) ($account['temp_password'] ?? $row['student_temp_password'] ?? '');
                            ?>
                                <tr>
                                    <td><?= h(find_name_by_id($students, $studentIdRow, 'N/D')) ?></td>
                                    <td><?= $username !== '' ? '<div class="credential-box"><span class="credential-chip">' . h($username) . '</span></div>' : '<span class="muted">Sin crear</span>' ?></td>
                                    <td><?= $tempPassword !== '' ? '<div class="credential-box"><span class="credential-chip">' . h($tempPassword) . '</span></div>' : '<span class="muted">No disponible</span>' ?></td>
                                    <td><?= h(find_name_by_id($teachers, (string) ($row['teacher_id'] ?? ''), 'N/D')) ?></td>
                                    <td><span class="program-badge"><?= h($programOptions[$program] ?? 'Programa Técnico') ?></span></td>
                                    <td><?= h($courseName) ?></td>
                                    <td><?= h($phaseName) ?></td>
                                    <td><?= h($unitName) ?></td>
                                    <td class="actions"><a href="student_assignments.php?edit=<?= h((string) ($row['id'] ?? '')) ?>">Editar</a> | <a href="student_assignments.php?delete=<?= h((string) ($row['id'] ?? '')) ?>" onclick="return confirm('¿Eliminar asignación?')">Eliminar</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>
        </section>
    </div>
</main>

<script>
const englishLevels = <?= json_encode($englishLevels, JSON_UNESCAPED_UNICODE) ?>;
const englishPhases = <?= json_encode($englishPhases, JSON_UNESCAPED_UNICODE) ?>;
const englishUnits = <?= json_encode($englishUnits, JSON_UNESCAPED_UNICODE) ?>;
const technicalSemesters = <?= json_encode($technicalSemesters, JSON_UNESCAPED_UNICODE) ?>;
const technicalUnits = <?= json_encode($technicalUnits, JSON_UNESCAPED_UNICODE) ?>;

const selectedProgram = <?= json_encode((string) $selectedProgram) ?>;
const selectedCourse = <?= json_encode((string) ($editRecord['course_id'] ?? '')) ?>;
const selectedPhase = <?= json_encode((string) ($editRecord['level_id'] ?? $editRecord['period'] ?? '')) ?>;
const selectedUnit = <?= json_encode((string) ($editRecord['unit_id'] ?? '')) ?>;

const programSelect = document.getElementById('program');
const courseSelect = document.getElementById('course_id');
const phaseSelect = document.getElementById('level_id');
const unitSelect = document.getElementById('unit_id');

const courseLabel = document.getElementById('courseLabel');
const phaseField = document.getElementById('phaseField');
const englishHint = document.getElementById('englishHint');

function fillSelect(select, items, selectedValue, placeholder) {
    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.appendChild(first);

    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = String(item.id ?? '');
        option.textContent = String(item.name ?? 'Sin nombre');
        if (String(option.value) === String(selectedValue || '')) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

function setEnglishMode() {
    courseLabel.textContent = 'Nivel';
    phaseField.style.display = '';
    englishHint.style.display = '';
    phaseSelect.disabled = false;
    phaseSelect.required = true;
}

function setTechnicalMode() {
    courseLabel.textContent = 'Semestre';
    phaseField.style.display = 'none';
    englishHint.style.display = 'none';
    phaseSelect.disabled = true;
    phaseSelect.required = false;
    phaseSelect.innerHTML = '<option value="">No aplica</option>';
}

function refreshForm(initial = false) {
    const program = programSelect.value || 'technical';

    if (program === 'english') {
        setEnglishMode();

        const currentLevel = initial ? selectedCourse : (courseSelect.value || '');
        fillSelect(courseSelect, englishLevels, currentLevel, 'Seleccionar nivel...');

        const levelId = courseSelect.value || currentLevel || '';
        const phasesForLevel = englishPhases.filter(item => String(item.level_id ?? '') === String(levelId));

        const currentPhase = initial ? selectedPhase : (phaseSelect.value || '');
        fillSelect(phaseSelect, phasesForLevel, currentPhase, 'Seleccionar fase...');

        const phaseId = phaseSelect.value || currentPhase || '';
        const unitsForPhase = englishUnits.filter(item => String(item.phase_id ?? '') === String(phaseId));

        const currentUnit = initial ? selectedUnit : (unitSelect.value || '');
        fillSelect(unitSelect, unitsForPhase, currentUnit, 'Seleccionar unidad...');
    } else {
        setTechnicalMode();

        const currentSemester = initial ? selectedCourse : (courseSelect.value || '');
        fillSelect(courseSelect, technicalSemesters, currentSemester, 'Seleccionar semestre...');

        const semesterId = courseSelect.value || currentSemester || '';
        const unitsForSemester = technicalUnits.filter(item => String(item.course_id ?? item.level_id ?? '') === String(semesterId));

        const currentUnit = initial ? selectedUnit : (unitSelect.value || '');
        fillSelect(unitSelect, unitsForSemester, currentUnit, 'Seleccionar unidad...');
    }
}

programSelect.addEventListener('change', () => {
    courseSelect.value = '';
    phaseSelect.value = '';
    unitSelect.value = '';
    refreshForm(false);
});

courseSelect.addEventListener('change', () => {
    phaseSelect.value = '';
    unitSelect.value = '';
    refreshForm(false);
});

phaseSelect.addEventListener('change', () => {
    unitSelect.value = '';
    refreshForm(false);
});

if (selectedProgram) {
    programSelect.value = selectedProgram;
}
refreshForm(true);
</script>
</body>
</html>
                                   

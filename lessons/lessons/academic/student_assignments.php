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
$coursePeriodsFile = $baseDir . '/course_periods.json';
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

    require $dbFile;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    $cachedPdo = $pdo;
    return $cachedPdo;
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


function build_technical_semesters_from_periods(array $coursePeriods, array $courses): array
{
    if (empty($coursePeriods)) {
        return [];
    }

    $courseNames = [];
    foreach ($courses as $course) {
        $courseId = (string) ($course['id'] ?? '');
        if ($courseId === '') {
            continue;
        }

        $courseNames[$courseId] = (string) ($course['name'] ?? $courseId);
    }

    $semesters = [];
    foreach ($coursePeriods as $periodRow) {
        $periodId = (string) ($periodRow['id'] ?? '');
        $courseId = (string) ($periodRow['course_id'] ?? '');
        $period = trim((string) ($periodRow['period'] ?? $periodRow['name'] ?? ''));

        if ($periodId === '' || $courseId === '' || $period === '') {
            continue;
        }

        $courseName = $courseNames[$courseId] ?? $courseId;
        $semesters[] = [
            'id' => $periodId,
            'name' => $courseName . ' – Periodo ' . $period,
            'course_id' => $courseId,
            'period' => $period,
        ];
    }

    return $semesters;
}

function find_technical_semester_by_id(array $semesters, string $semesterId): ?array
{
    foreach ($semesters as $semester) {
        if ((string) ($semester['id'] ?? '') === $semesterId) {
            return (array) $semester;
        }
    }

    return null;
}


function resolve_technical_selection(string $selectedValue, array $semesters): ?array
{
    if ($selectedValue === '') {
        return null;
    }

    foreach ($semesters as $semester) {
        $semesterId = (string) ($semester['id'] ?? '');
        if ($semesterId !== '' && $semesterId === $selectedValue) {
            return (array) $semester;
        }
    }

    $parts = explode('|', $selectedValue, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$courseId, $period] = $parts;

    foreach ($semesters as $semester) {
        if (
            (string) ($semester['course_id'] ?? '') === $courseId
            && (string) ($semester['period'] ?? '') === $period
        ) {
            return (array) $semester;
        }
    }

    return null;
}

function find_technical_semester_for_assignment(array $semesters, string $courseId, string $period): ?array
{
    foreach ($semesters as $semester) {
        if (
            (string) ($semester['course_id'] ?? '') === $courseId
            && (string) ($semester['period'] ?? '') === $period
        ) {
            return (array) $semester;
        }
    }

    return null;
}

function technical_assignment_label(array $row, array $semesters, array $courses): string
{
    $courseId = (string) ($row['course_id'] ?? '');
    $period = (string) ($row['period'] ?? '');

    $semester = find_technical_semester_for_assignment($semesters, $courseId, $period);
    if ($semester) {
        return (string) ($semester['name'] ?? 'N/D');
    }

    $courseName = find_name_by_id($courses, $courseId, $courseId !== '' ? $courseId : 'N/D');
    if ($period !== '') {
        return $courseName . ' – Periodo ' . $period;
    }

    return $courseName;
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

/* ===============================
   DB ESTUDIANTES
=============================== */
function load_students_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
            FROM students
            ORDER BY name ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter(array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($rows) ? $rows : []), function ($row) {
        return (string) ($row['id'] ?? '') !== '';
    }));
}

/* ===============================
   DB DOCENTES
=============================== */
function load_teachers_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
            FROM teachers
            ORDER BY name ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter(array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }, is_array($rows) ? $rows : []), function ($row) {
        return (string) ($row['id'] ?? '') !== '';
    }));
}

/* ===============================
   DB CUENTAS ESTUDIANTES
=============================== */
function load_student_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, student_id, student_name, username, password_hash, temp_password, must_change_password, created_at, updated_at
            FROM student_accounts
            ORDER BY created_at ASC NULLS LAST, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter(array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'student_id' => (string) ($row['student_id'] ?? ''),
            'student_name' => (string) ($row['student_name'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'password_hash' => (string) ($row['password_hash'] ?? ''),
            'temp_password' => (string) ($row['temp_password'] ?? ''),
            'must_change_password' => (bool) ($row['must_change_password'] ?? false),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, is_array($rows) ? $rows : []), function ($row) {
        return (string) ($row['id'] ?? '') !== '';
    }));
}

function save_student_account_to_database(array $account): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO student_accounts (
                id, student_id, student_name, username, password_hash, temp_password, must_change_password, created_at, updated_at
            ) VALUES (
                :id, :student_id, :student_name, :username, :password_hash, :temp_password, :must_change_password, :created_at, :updated_at
            )
            ON CONFLICT (student_id) DO UPDATE SET
                student_name = EXCLUDED.student_name,
                username = EXCLUDED.username,
                password_hash = EXCLUDED.password_hash,
                temp_password = EXCLUDED.temp_password,
                must_change_password = EXCLUDED.must_change_password,
                updated_at = EXCLUDED.updated_at
        ");

        return $stmt->execute([
            'id' => (string) ($account['id'] ?? ''),
            'student_id' => (string) ($account['student_id'] ?? ''),
            'student_name' => (string) ($account['student_name'] ?? ''),
            'username' => (string) ($account['username'] ?? ''),
            'password_hash' => (string) ($account['password_hash'] ?? ''),
            'temp_password' => (string) ($account['temp_password'] ?? ''),
            'must_change_password' => !empty($account['must_change_password']),
            'created_at' => (string) ($account['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string) ($account['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
    } catch (Throwable $e) {
        return false;
    }
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
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $savedInDb = save_student_account_to_database($newAccount);

    if (!$savedInDb) {
        $accounts[] = $newAccount;
        save_json_file($accountsFile, $accounts);
    } else {
        $accounts[] = $newAccount;
    }

    return $newAccount;
}

/* ===============================
   DB ASIGNACIONES
=============================== */
function load_student_assignments_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, student_id, teacher_id, program, course_id, level_id, period, unit_id, student_username, student_temp_password, updated_at
            FROM student_assignments
            ORDER BY updated_at DESC NULLS LAST, id DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter(array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'student_id' => (string) ($row['student_id'] ?? ''),
            'teacher_id' => (string) ($row['teacher_id'] ?? ''),
            'program' => (string) ($row['program'] ?? ''),
            'course_id' => (string) ($row['course_id'] ?? ''),
            'level_id' => (string) ($row['level_id'] ?? ''),
            'period' => (string) ($row['period'] ?? ''),
            'unit_id' => (string) ($row['unit_id'] ?? ''),
            'student_username' => (string) ($row['student_username'] ?? ''),
            'student_temp_password' => (string) ($row['student_temp_password'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, is_array($rows) ? $rows : []), function ($row) {
        return (string) ($row['id'] ?? '') !== '';
    }));
}

function save_student_assignment_to_database(array $record): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO student_assignments (
                id, student_id, teacher_id, program, course_id, level_id, period, unit_id, student_username, student_temp_password, updated_at
            ) VALUES (
                :id, :student_id, :teacher_id, :program, :course_id, :level_id, :period, :unit_id, :student_username, :student_temp_password, :updated_at
            )
            ON CONFLICT (id) DO UPDATE SET
                student_id = EXCLUDED.student_id,
                teacher_id = EXCLUDED.teacher_id,
                program = EXCLUDED.program,
                course_id = EXCLUDED.course_id,
                level_id = EXCLUDED.level_id,
                period = EXCLUDED.period,
                unit_id = EXCLUDED.unit_id,
                student_username = EXCLUDED.student_username,
                student_temp_password = EXCLUDED.student_temp_password,
                updated_at = EXCLUDED.updated_at
        ");

        return $stmt->execute([
            'id' => (string) ($record['id'] ?? ''),
            'student_id' => (string) ($record['student_id'] ?? ''),
            'teacher_id' => (string) ($record['teacher_id'] ?? ''),
            'program' => (string) ($record['program'] ?? ''),
            'course_id' => (string) ($record['course_id'] ?? ''),
            'level_id' => (string) ($record['level_id'] ?? ''),
            'period' => (string) ($record['period'] ?? ''),
            'unit_id' => (string) ($record['unit_id'] ?? ''),
            'student_username' => (string) ($record['student_username'] ?? ''),
            'student_temp_password' => (string) ($record['student_temp_password'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function delete_student_assignment_from_database(string $id): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo || $id === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM student_assignments WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    } catch (Throwable $e) {
        return false;
    }
}

/* ===============================
   DB CATÁLOGO TÉCNICO
=============================== */
function load_technical_catalog_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [[], []];
    }

    try {
        $semestersStmt = $pdo->query("
            SELECT id, name, program_id
            FROM courses
            WHERE
                LOWER(COALESCE(program_id, '')) IN (
                    'prog_technical',
                    'technical',
                    'prog_tecnico',
                    'tecnico',
                    'programa_tecnico'
                )
                OR LOWER(COALESCE(name, '')) LIKE '%semestre%'
            ORDER BY name ASC, id ASC
        ");
        $semestersRaw = $semestersStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->query("
            SELECT u.id, u.name, u.course_id
            FROM units u
            INNER JOIN courses c ON c.id = u.course_id
            WHERE
                LOWER(COALESCE(c.program_id, '')) IN (
                    'prog_technical',
                    'technical',
                    'prog_tecnico',
                    'tecnico',
                    'programa_tecnico'
                )
                OR LOWER(COALESCE(c.name, '')) LIKE '%semestre%'
            ORDER BY u.course_id ASC, u.id ASC
        ");
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
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [[], [], [], false];
    }

    try {
        $levelsStmt = $pdo->query("
            SELECT id, name
            FROM english_levels
            ORDER BY id ASC
        ");
        $levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

        $phasesStmt = $pdo->query("
            SELECT id, level_id, name
            FROM english_phases
            ORDER BY level_id ASC, id ASC
        ");
        $phases = $phasesStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->query("
            SELECT u.id, u.name, u.phase_id, p.level_id
            FROM units u
            INNER JOIN english_phases p ON p.id = u.phase_id
            ORDER BY p.level_id ASC, u.phase_id ASC, u.id ASC
        ");
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
    $coursePeriodsFile,
    $studentAssignmentsFile,
    $studentAccountsFile,
]);

/* ===============================
   CARGA PRINCIPAL
=============================== */
$studentsJson = load_json_array($studentsFile);
$teachersJson = load_json_array($teachersFile);
$studentAssignmentsJson = load_json_array($studentAssignmentsFile);
$studentAccountsJson = load_json_array($studentAccountsFile);

$studentsDb = load_students_from_database();
$teachersDb = load_teachers_from_database();
$studentAssignmentsDb = load_student_assignments_from_database();
$studentAccountsDb = load_student_accounts_from_database();

$students = !empty($studentsDb) ? $studentsDb : $studentsJson;
$teachers = !empty($teachersDb) ? $teachersDb : $teachersJson;
$studentAssignments = !empty($studentAssignmentsDb) ? $studentAssignmentsDb : $studentAssignmentsJson;
$studentAccounts = !empty($studentAccountsDb) ? $studentAccountsDb : $studentAccountsJson;

$courses = load_json_array($coursesFile);
$units = load_json_array($unitsFile);
$coursePeriods = load_json_array($coursePeriodsFile);

/* ===============================
   ELIMINAR
=============================== */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];

    $deletedInDb = delete_student_assignment_from_database($deleteId);

    if (!$deletedInDb) {
        $studentAssignments = array_values(array_filter($studentAssignments, function ($row) use ($deleteId) {
            return (string) ($row['id'] ?? '') !== $deleteId;
        }));
        save_json_file($studentAssignmentsFile, $studentAssignments);
    }

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
$technicalSemestersFromPeriods = build_technical_semesters_from_periods($coursePeriods, $courses);
$technicalSemestersJson = normalize_technical_courses($technicalCoursesRaw, $technicalUnitsJson);

if (!empty($technicalSemestersFromPeriods)) {
    $technicalSemesters = $technicalSemestersFromPeriods;
} elseif (!empty($technicalSemestersDb)) {
    $technicalSemesters = $technicalSemestersDb;
} else {
    $technicalSemesters = $technicalSemestersJson;
}

$technicalUnits = !empty($technicalUnitsDb) ? $technicalUnitsDb : $technicalUnitsJson;

if (empty($technicalSemesters) && !empty($technicalUnits)) {
    $seen = [];
    foreach ($technicalUnits as $unit) {
        $semesterId = (string) ($unit['course_id'] ?? $unit['level_id'] ?? '');
        if ($semesterId === '' || isset($seen[$semesterId])) {
            continue;
        }

        $seen[$semesterId] = true;
        $technicalSemesters[] = [
            'id' => $semesterId,
            'name' => 'SEMESTRE ' . $semesterId,
        ];
    }
}

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

    $technicalSemester = $program === 'technical' ? resolve_technical_selection($courseId, $technicalSemesters) : null;
    $technicalPeriod = (string) ($technicalSemester['period'] ?? '');

    if ($program === 'technical' && $technicalSemester) {
        $courseId = (string) ($technicalSemester['course_id'] ?? $courseId);
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
            'period' => $program === 'technical' ? $technicalPeriod : $levelId,
            'unit_id' => $unitId,
            'student_username' => (string) ($studentAccount['username'] ?? ''),
            'student_temp_password' => (string) ($studentAccount['temp_password'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $savedInDb = save_student_assignment_to_database($record);

        if (!$savedInDb) {
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
        }

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

$selectedCourseValue = (string) ($editRecord['course_id'] ?? '');
if ($selectedProgram === 'technical' && $editRecord) {
    $currentCourseId = (string) ($editRecord['course_id'] ?? '');
    $currentPeriod = (string) ($editRecord['period'] ?? '');
    $semesterForEdit = find_technical_semester_for_assignment($technicalSemesters, $currentCourseId, $currentPeriod);

    if ($semesterForEdit) {
        $selectedCourseValue = (string) ($semesterForEdit['id'] ?? $selectedCourseValue);
    }
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
                                    <option value="<?= h($sid) ?>" <?= $sid === (string) ($editRecord['student_id'] ?? '') ? 'selected' : '' ?>>
                                        <?= h((string) ($student['name'] ?? 'Sin nombre')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="teacher_id">Docente</label>
                            <select name="teacher_id" id="teacher_id" required>
                                <option value="">Seleccionar docente...</option>
                                <?php foreach ($teachers as $teacher): $tid = (string) ($teacher['id'] ?? ''); ?>
                                    <option value="<?= h($tid) ?>" <?= $tid === (string) ($editRecord['teacher_id'] ?? '') ? 'selected' : '' ?>>
                                        <?= h((string) ($teacher['name'] ?? 'Sin nombre')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="program">Programa</label>
                            <select name="program" id="program" required>
                                <?php foreach ($programOptions as $key => $label): ?>
                                    <option value="<?= h($key) ?>" <?= $key === $selectedProgram ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field" id="courseField">
                            <label for="course_id" id="courseLabel">Semestre</label>
                            <select name="course_id" id="course_id" required>
                                <option value="">Seleccionar...</option>
                            </select>
                        </div>

                        <div class="field" id="phaseField">
                            <label for="level_id">Fase</label>
                            <select name="level_id" id="level_id">
                                <option value="">Seleccionar fase...</option>
                            </select>
                            <div class="hint" id="englishHint">
                                Configurar: <a href="english_structure_levels.php">Levels</a>
                                <?php if ($englishFromDb && !empty($englishLevels)): ?>
                                    | <a href="english_structure_phases.php?level=<?= h((string) ($englishLevels[0]['id'] ?? '1')) ?>">Phases</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="field" id="unitField">
                            <label for="unit_id">Unidad</label>
                            <select name="unit_id" id="unit_id" required>
                                <option value="">Seleccionar unidad...</option>
                            </select>
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
                <div class="table-wrap">
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Estudiante</th>
                                    <th>Usuario</th>
                                    <th>Password temporal</th>
                                    <th>Docente</th>
                                    <th>Programa</th>
                                    <th>Nivel / Semestre</th>
                                    <th>Fase</th>
                                    <th>Unidad</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($studentAssignments)): ?>
                                <tr><td colspan="9" class="muted">No hay asignaciones registradas.</td></tr>
                            <?php else: ?>
                                <?php foreach ($studentAssignments as $row):
                                    $program = (string) ($row['program'] ?? 'technical');
                                    $isEnglish = $program === 'english';

                                    $courseName = $isEnglish
                                        ? find_name_by_id($englishLevels, (string) ($row['course_id'] ?? ''), 'N/D')
                                        : technical_assignment_label((array) $row, $technicalSemesters, $technicalCoursesRaw);

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
                                        <td class="actions">
                                            <a href="student_assignments.php?edit=<?= h((string) ($row['id'] ?? '')) ?>">Editar</a>
                                            |
                                            <a href="student_assignments.php?delete=<?= h((string) ($row['id'] ?? '')) ?>" onclick="return confirm('¿Eliminar asignación?')">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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
const selectedCourse = <?= json_encode((string) ($selectedCourseValue ?? ($editRecord['course_id'] ?? ''))) ?>;
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
        const selectedSemester = technicalSemesters.find(item => String(item.id ?? '') === String(semesterId));
        const fallbackParts = String(semesterId).split('|');
        const fallbackCourseId = fallbackParts.length === 2 ? fallbackParts[0] : semesterId;
        const baseCourseId = String(selectedSemester?.course_id ?? fallbackCourseId);
        const unitsForSemester = technicalUnits.filter(item => String(item.course_id ?? item.level_id ?? '') === baseCourseId);

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

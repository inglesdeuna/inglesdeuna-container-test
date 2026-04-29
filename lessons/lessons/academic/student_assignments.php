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

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM information_schema.columns\n            WHERE table_schema = 'public'\n              AND table_name = :table_name\n              AND column_name = :column_name\n            LIMIT 1\n        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
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

function normalize_semester_name(string $value, string $fallbackId = ''): string
{
    $value = trim($value);

    if ($value === '' && $fallbackId !== '') {
        $value = trim($fallbackId);
    }

    if ($value === '') {
        return 'SEMESTRE';
    }

    if (preg_match('/^\d+$/', $value)) {
        return 'SEMESTRE ' . $value;
    }

    $upper = mb_strtoupper($value, 'UTF-8');

    if (str_contains($upper, 'SEMESTRE')) {
        return $upper;
    }

    return $value;
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

        $name = normalize_semester_name((string) ($course['name'] ?? ''), $id);

        $normalized[] = [
            'id' => $id,
            'name' => $name,
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
            'name' => normalize_semester_name('', $semesterId),
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

        $courseNames[$courseId] = normalize_semester_name((string) ($course['name'] ?? ''), $courseId);
    }

    $semesters = [];
    foreach ($coursePeriods as $periodRow) {
        $periodId = (string) ($periodRow['id'] ?? '');
        $courseId = (string) ($periodRow['course_id'] ?? '');
        $period = trim((string) ($periodRow['period'] ?? $periodRow['name'] ?? ''));

        if ($periodId === '' || $courseId === '' || $period === '') {
            continue;
        }

        $courseName = $courseNames[$courseId] ?? normalize_semester_name('', $courseId);
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
            (string) ($semester['course_id'] ?? $semester['id'] ?? '') === $courseId
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

    $courseName = normalize_semester_name(find_name_by_id($courses, $courseId, $courseId !== '' ? $courseId : 'N/D'), $courseId);

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
    $name  = trim((string) ($student['name'] ?? 'student'));
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);

    if (count($parts) >= 2) {
        $first = slugify_username($parts[0]);
        $last  = slugify_username((string) end($parts));
        $base  = $first . '.' . $last;
    } else {
        $base = slugify_username($name);
    }

    if ($base === '' || $base === '.') {
        $base = 'student';
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

function canonical_student_username(array $student, array $accounts, string $studentId): string
{
    $otherAccounts = array_values(array_filter($accounts, function ($acc) use ($studentId) {
        return (string) ($acc['student_id'] ?? '') !== $studentId;
    }));

    return generate_student_username($student, $otherAccounts);
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
        $hasPermission = table_has_column($pdo, 'student_accounts', 'permission');
        $hasStudentPhoto = table_has_column($pdo, 'student_accounts', 'student_photo');

        $select = 'id, student_id, student_name, username, password_hash, temp_password, must_change_password, created_at, updated_at';
        if ($hasPermission) {
            $select .= ', permission';
        }
        if ($hasStudentPhoto) {
            $select .= ', student_photo';
        }

        $stmt = $pdo->query("SELECT {$select} FROM student_accounts ORDER BY created_at ASC NULLS LAST, id ASC");
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
            'permission' => (string) ($row['permission'] ?? 'viewer'),
            'student_photo' => (string) ($row['student_photo'] ?? ''),
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
        $hasPermission = table_has_column($pdo, 'student_accounts', 'permission');
        $hasStudentPhoto = table_has_column($pdo, 'student_accounts', 'student_photo');

        $columns = [
            'id', 'student_id', 'student_name', 'username', 'password_hash', 'temp_password', 'must_change_password', 'created_at', 'updated_at',
        ];
        $values = [
            ':id', ':student_id', ':student_name', ':username', ':password_hash', ':temp_password', ':must_change_password', ':created_at', ':updated_at',
        ];
        $updates = [
            'student_name = EXCLUDED.student_name',
            'username = EXCLUDED.username',
            'password_hash = EXCLUDED.password_hash',
            'temp_password = EXCLUDED.temp_password',
            'must_change_password = EXCLUDED.must_change_password',
            'updated_at = EXCLUDED.updated_at',
        ];

        if ($hasPermission) {
            $columns[] = 'permission';
            $values[] = ':permission';
            $updates[] = 'permission = EXCLUDED.permission';
        }

        if ($hasStudentPhoto) {
            $columns[] = 'student_photo';
            $values[] = ':student_photo';
            $updates[] = 'student_photo = COALESCE(EXCLUDED.student_photo, student_accounts.student_photo)';
        }

        $stmt = $pdo->prepare("\n            INSERT INTO student_accounts (" . implode(', ', $columns) . ")\n            VALUES (" . implode(', ', $values) . ")\n            ON CONFLICT (student_id) DO UPDATE SET\n                " . implode(",\n                ", $updates) . "\n        ");

        $params = [
            'id' => (string) ($account['id'] ?? ''),
            'student_id' => (string) ($account['student_id'] ?? ''),
            'student_name' => (string) ($account['student_name'] ?? ''),
            'username' => (string) ($account['username'] ?? ''),
            'password_hash' => (string) ($account['password_hash'] ?? ''),
            'temp_password' => (string) ($account['temp_password'] ?? ''),
            'must_change_password' => !empty($account['must_change_password']),
            'created_at' => (string) ($account['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string) ($account['updated_at'] ?? date('Y-m-d H:i:s')),
        ];

        if ($hasPermission) {
            $params['permission'] = (string) ($account['permission'] ?? 'viewer');
        }

        if ($hasStudentPhoto) {
            $params['student_photo'] = (string) ($account['student_photo'] ?? '');
        }

        return $stmt->execute($params);
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

    foreach ($accounts as &$account) {
        if ((string) ($account['student_id'] ?? '') === $studentId) {
            $studentData = find_student_by_id($students, $studentId);
            $existingUsername = trim((string) ($account['username'] ?? ''));
            $currentName = trim((string) ($account['student_name'] ?? ''));
            $dirty = false;

            if ($studentData && $currentName === '') {
                $account['student_name'] = (string) ($studentData['name'] ?? 'Estudiante');
                $dirty = true;
            }

            if ($existingUsername === '' && $studentData) {
                $newUsername = canonical_student_username($studentData, $accounts, $studentId);
                if ($newUsername !== '') {
                    $account['username'] = $newUsername;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $account['updated_at'] = date('Y-m-d H:i:s');
                save_student_account_to_database($account);
                save_json_file($accountsFile, $accounts);
            }

            return $account;
        }
    }
    unset($account);

    $student = find_student_by_id($students, $studentId);
    if (!$student) {
        return null;
    }

    $username = canonical_student_username($student, $accounts, $studentId);
    $tempPassword = '1234';

    $newAccount = [
        'id' => uniqid('stu_acc_'),
        'student_id' => $studentId,
        'student_name' => (string) ($student['name'] ?? 'Estudiante'),
        'username' => $username,
        'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
        'temp_password' => $tempPassword,
        'permission' => 'viewer',
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

function sync_assignment_usernames(
    array &$studentAssignments,
    array $students,
    array &$studentAccounts,
    string $accountsFile,
    string $assignmentsFile
): void {
    if (empty($studentAssignments)) {
        return;
    }

    $jsonDirty = false;

    foreach ($studentAssignments as $index => $assignment) {
        $studentId = trim((string) ($assignment['student_id'] ?? ''));
        if ($studentId === '') {
            continue;
        }

        $account = ensure_student_account($studentId, $students, $studentAccounts, $accountsFile);
        if (!$account) {
            continue;
        }

        $expectedUsername = trim((string) ($account['username'] ?? ''));
        if ($expectedUsername === '') {
            continue;
        }

        $currentUsername = trim((string) ($assignment['student_username'] ?? ''));
        if ($currentUsername === $expectedUsername) {
            continue;
        }

        $studentAssignments[$index]['student_username'] = $expectedUsername;

        if (trim((string) ($studentAssignments[$index]['student_temp_password'] ?? '')) === '') {
            $studentAssignments[$index]['student_temp_password'] = (string) ($account['temp_password'] ?? '');
        }

        $saved = save_student_assignment_to_database($studentAssignments[$index]);
        if (!$saved) {
            $jsonDirty = true;
        }
    }

    if ($jsonDirty) {
        save_json_file($assignmentsFile, $studentAssignments);
    }
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

function delete_student_assignment_bundle_from_database(string $studentId, string $program, string $courseId, string $levelId = '', string $teacherId = ''): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '' || $program === '' || $courseId === '') {
        return false;
    }

    try {
        $sql = "DELETE FROM student_assignments WHERE student_id = :student_id AND program = :program AND course_id = :course_id";
        $params = [
            'student_id' => $studentId,
            'program' => $program,
            'course_id' => $courseId,
        ];

        if ($levelId !== '') {
            $sql .= " AND COALESCE(level_id, '') = :level_id";
            $params['level_id'] = $levelId;
        }

        if ($teacherId !== '') {
            $sql .= " AND COALESCE(teacher_id, '') = :teacher_id";
            $params['teacher_id'] = $teacherId;
        }

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function count_remaining_student_assignments_in_database(string $studentId): int
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '') {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM student_assignments WHERE student_id = :student_id");
        $stmt->execute(['student_id' => $studentId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function delete_student_completely_from_database(string $studentId): void
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '') {
        return;
    }

    try {
        $pdo->prepare("DELETE FROM student_activity_results WHERE student_id = :id")->execute(['id' => $studentId]);
        $pdo->prepare("DELETE FROM student_unit_results WHERE student_id = :id")->execute(['id' => $studentId]);
        $pdo->prepare("DELETE FROM teacher_quiz_unlocks WHERE student_id = :id")->execute(['id' => $studentId]);
        $pdo->prepare("DELETE FROM student_accounts WHERE student_id = :id")->execute(['id' => $studentId]);
        $pdo->prepare("DELETE FROM students WHERE id = :id")->execute(['id' => $studentId]);
    } catch (Throwable $e) {
        // si la tabla no existe o el registro ya no está, no es error crítico
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
                LOWER(COALESCE(program_id::text, '')) IN (
                    '1',
                    'prog_technical',
                    'technical',
                    'prog_tecnico',
                    'tecnico',
                    'programa_tecnico'
                )
                OR LOWER(COALESCE(name, '')) LIKE '%semestre%'
            ORDER BY id ASC, name ASC
        ");
        $semestersRaw = $semestersStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->query("
            SELECT u.id, u.name, u.course_id
            FROM units u
            INNER JOIN courses c ON c.id = u.course_id
            WHERE
                LOWER(COALESCE(c.program_id::text, '')) IN (
                    '1',
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
        $id = (string) ($row['id'] ?? '');
        $name = normalize_semester_name((string) ($row['name'] ?? ''), $id);

        return [
            'id' => $id,
            'name' => $name,
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
$students = load_students_from_database();
$teachers = load_teachers_from_database();
$studentAssignments = load_student_assignments_from_database();
$studentAccounts = load_student_accounts_from_database();

sync_assignment_usernames(
    $studentAssignments,
    $students,
    $studentAccounts,
    $studentAccountsFile,
    $studentAssignmentsFile
);

/* Catálogos académicos:
   por ahora se dejan desde JSON como respaldo temporal,
   mientras terminamos la migración completa de cursos/unidades.
*/
$courses = load_json_array($coursesFile);
$units = load_json_array($unitsFile);
$coursePeriods = load_json_array($coursePeriodsFile);

/* ===============================
   ELIMINAR
=============================== */
if (isset($_GET['delete_group']) && (string) $_GET['delete_group'] === '1') {
    $deleteStudentId = trim((string) ($_GET['student_id'] ?? ''));
    $deleteProgram = trim((string) ($_GET['program'] ?? ''));
    $deleteCourseId = trim((string) ($_GET['course_id'] ?? ''));
    $deleteLevelId = trim((string) ($_GET['level_id'] ?? ''));
    $deleteTeacherId = trim((string) ($_GET['teacher_id'] ?? ''));

    $deletedInDb = delete_student_assignment_bundle_from_database($deleteStudentId, $deleteProgram, $deleteCourseId, $deleteLevelId, $deleteTeacherId);

    if (!$deletedInDb) {
        $studentAssignments = array_values(array_filter($studentAssignments, function ($row) use ($deleteStudentId, $deleteProgram, $deleteCourseId, $deleteLevelId, $deleteTeacherId) {
            if ((string) ($row['student_id'] ?? '') !== $deleteStudentId) {
                return true;
            }
            if ((string) ($row['program'] ?? '') !== $deleteProgram) {
                return true;
            }
            if ((string) ($row['course_id'] ?? '') !== $deleteCourseId) {
                return true;
            }
            if ($deleteLevelId !== '' && (string) ($row['level_id'] ?? '') !== $deleteLevelId) {
                return true;
            }
            if ($deleteTeacherId !== '' && (string) ($row['teacher_id'] ?? '') !== $deleteTeacherId) {
                return true;
            }
            return false;
        }));
        save_json_file($studentAssignmentsFile, $studentAssignments);
    }

    // Si el estudiante ya no tiene ninguna asignación, se elimina completamente del sistema
    if ($deleteStudentId !== '' && count_remaining_student_assignments_in_database($deleteStudentId) === 0) {
        delete_student_completely_from_database($deleteStudentId);
    }

    header('Location: student_assignments.php?saved=1');
    exit;
}

if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];

    // Recuperar el student_id antes de borrar para verificar después
    $deleteStudentIdForCheck = '';
    foreach ($studentAssignments as $row) {
        if ((string) ($row['id'] ?? '') === $deleteId) {
            $deleteStudentIdForCheck = (string) ($row['student_id'] ?? '');
            break;
        }
    }

    $deletedInDb = delete_student_assignment_from_database($deleteId);

    if (!$deletedInDb) {
        $studentAssignments = array_values(array_filter($studentAssignments, function ($row) use ($deleteId) {
            return (string) ($row['id'] ?? '') !== $deleteId;
        }));
        save_json_file($studentAssignmentsFile, $studentAssignments);
    }

    // Si el estudiante ya no tiene ninguna asignación, se elimina completamente del sistema
    if ($deleteStudentIdForCheck !== '' && count_remaining_student_assignments_in_database($deleteStudentIdForCheck) === 0) {
        delete_student_completely_from_database($deleteStudentIdForCheck);
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

/*
   PRIORIDAD:
   1. Semestres desde DB
   2. Semestres desde courses.json
   3. Periodos (respaldo)
*/
if (!empty($technicalSemestersDb)) {
    $technicalSemesters = $technicalSemestersDb;
} elseif (!empty($technicalSemestersJson)) {
    $technicalSemesters = $technicalSemestersJson;
} else {
    $technicalSemesters = $technicalSemestersFromPeriods;
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
            'name' => normalize_semester_name('', $semesterId),
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
    $postAction = trim((string) ($_POST['action'] ?? 'save'));

    /* ---- RESET PASSWORD ---- */
    if ($postAction === 'reset_student_password') {
        $resetStudentId = trim((string) ($_POST['reset_student_id'] ?? ''));
        $newPassword    = trim((string) ($_POST['new_password'] ?? ''));
        $resetOk        = false;

        if ($resetStudentId !== '' && $newPassword !== '') {
            $accountRecord = ensure_student_account($resetStudentId, $students, $studentAccounts, $studentAccountsFile);
            $pdo2 = get_pdo_connection();

            if ($accountRecord && $pdo2) {
                $setParts2   = ['updated_at = NOW()'];
                $resetParams = ['student_id' => $resetStudentId];

                if (table_has_column($pdo2, 'student_accounts', 'password_hash')) {
                    $setParts2[] = 'password_hash = :password_hash';
                    $resetParams['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }
                if (table_has_column($pdo2, 'student_accounts', 'temp_password')) {
                    $setParts2[] = 'temp_password = :temp_password';
                    $resetParams['temp_password'] = $newPassword;
                }
                if (table_has_column($pdo2, 'student_accounts', 'must_change_password')) {
                    $setParts2[] = 'must_change_password = :must_change_password';
                    $resetParams['must_change_password'] = true;
                }

                try {
                    $sqlReset = 'UPDATE student_accounts SET '
                        . implode(', ', $setParts2)
                        . ' WHERE student_id = :student_id';
                    $stmtReset = $pdo2->prepare($sqlReset);
                    $stmtReset->execute($resetParams);
                    $resetOk = $stmtReset->rowCount() > 0;
                } catch (Throwable $e) {
                    $resetOk = false;
                }
            }

            if (!$resetOk && $accountRecord) {
                $jsonAccs = load_json_array($studentAccountsFile);
                foreach ($jsonAccs as $i => $acc) {
                    if ((string) ($acc['student_id'] ?? '') === $resetStudentId) {
                        $jsonAccs[$i]['password_hash']        = password_hash($newPassword, PASSWORD_DEFAULT);
                        $jsonAccs[$i]['temp_password']        = $newPassword;
                        $jsonAccs[$i]['must_change_password'] = true;
                        $jsonAccs[$i]['updated_at']           = date('Y-m-d H:i:s');
                        $resetOk = true;
                        break;
                    }
                }
                if ($resetOk) {
                    save_json_file($studentAccountsFile, $jsonAccs);
                }
            }
        }
        header('Location: student_assignments.php?pwd_reset=' . ($resetOk ? '1' : '0'));
        exit;
    }

    /* ---- SAVE ASSIGNMENT ---- */
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
        $courseId = (string) ($technicalSemester['course_id'] ?? $technicalSemester['id'] ?? $courseId);
    }

    if ($program === 'english') {
        $isValid = $studentId !== '' && $teacherId !== '' && $courseId !== '' && $levelId !== '' && $unitId !== '';
    } else {
        $isValid = $studentId !== '' && $teacherId !== '' && $courseId !== '' && $unitId !== '';
    }

    if ($isValid) {
        $studentAccount = ensure_student_account($studentId, $students, $studentAccounts, $studentAccountsFile);

        // Build list of unit IDs to assign
        if ($unitId === 'all') {
            if ($program === 'english') {
                $allUnitsToAssign = array_filter($englishUnits, function ($u) use ($levelId) {
                    return (string) ($u['phase_id'] ?? '') === $levelId;
                });
            } else {
                $allUnitsToAssign = array_filter($technicalUnits, function ($u) use ($courseId) {
                    return (string) ($u['course_id'] ?? $u['level_id'] ?? '') === $courseId;
                });
            }
            $unitIdsToAssign = array_map(fn($u) => (string) ($u['id'] ?? ''), array_values($allUnitsToAssign));
            $unitIdsToAssign = array_filter($unitIdsToAssign, fn($id) => $id !== '');
        } else {
            $unitIdsToAssign = [$unitId];
        }

        foreach ($unitIdsToAssign as $singleUnitId) {
            $recordId = $editId !== '' && count($unitIdsToAssign) === 1 ? $editId : uniqid('stu_assign_');
            $record = [
                'id' => $recordId,
                'student_id' => $studentId,
                'teacher_id' => $teacherId,
                'program' => $program,
                'course_id' => $courseId,
                'level_id' => $levelId,
                'period' => $program === 'technical' ? $technicalPeriod : $levelId,
                'unit_id' => $singleUnitId,
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
            }
        }

        if (!$savedInDb) {
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
        :root{
            --bg:#eef7f0;--card:#fff;--line:#d8e8dc;--text:#1f3b28;--title:#1f3b28;
            --subtitle:#2a5136;--muted:#5d7465;--head:#f3fbf5;
            --green:#2f9e44;--green-hover:#237a35;--green-soft:#e9f8ee;
            --danger:#dc2626;--danger-soft:#fef2f2;
            --warn:#b45309;--warn-soft:#fef9c3;
            --blue-soft:#dbeafe;--blue-text:#1e40af;
            --shadow:0 10px 24px rgba(0,0,0,.08);
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);padding:30px}
        .page{max-width:1100px;margin:0 auto}
        /* Header */
        .page-header{display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap}
        .page-title{font-size:26px;font-weight:700;color:var(--title);margin:0}
        /* Buttons */
        .btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 14px;border-radius:10px;border:none;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;white-space:nowrap}
        .btn-back{background:linear-gradient(180deg,#7b8b7f,#66756a);color:#fff}.btn-back:hover{background:linear-gradient(180deg,#6b7f75,#556a5e);color:#fff}
        .btn-scores{background:linear-gradient(180deg,#3caf58,#2f9e44);color:#fff}.btn-scores:hover{background:linear-gradient(180deg,#319a4a,#257f37);color:#fff}
        .btn-cancel-edit{background:#f3f4f6;color:var(--muted)}.btn-cancel-edit:hover{background:#e5e7eb}
        /* Notices */
        .notice{border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:14px;font-weight:600}
        .notice-ok{background:#ecfdf3;border:1px solid #b9eacb;color:#166534}
        .notice-pwd{background:#fef9c3;border:1px solid #fde68a;color:#92400e}
        .notice-warn{background:#fff4f4;border:1px solid #fecaca;color:#b42318}
        /* Stack & Cards */
        .stack{display:flex;flex-direction:column;gap:18px}
        .card{background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
        .card-header{padding:14px 20px;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
        .card-header h2{margin:0;font-size:20px;font-weight:600;color:var(--subtitle)}
        .card-body{padding:20px}
        /* Form */
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .field{display:flex;flex-direction:column;min-width:0}.field.full{grid-column:1/-1}
        label{font-size:12px;font-weight:700;color:var(--text);margin:0 0 6px;text-transform:uppercase;letter-spacing:.2px}
        select,input{width:100%;min-height:42px;border-radius:10px;border:1px solid #c7d3e3;background:#fff;color:var(--text);padding:10px 12px;font-size:14px}
        select:focus,input:focus{outline:none;border-color:#6ab786;box-shadow:0 0 0 3px rgba(47,158,68,.15)}
        .button-primary{border:none;background:var(--green);color:#fff;font-weight:700;font-size:14px;cursor:pointer;display:block;width:100%;min-height:44px;border-radius:10px}
        .button-primary:hover{background:var(--green-hover)}
        .hint{margin-top:7px;font-size:12px;color:var(--muted)}.hint a{color:var(--green);text-decoration:none;font-weight:700}
        /* Group accordion */
        .groups-wrap{display:flex;flex-direction:column;gap:10px;padding:16px}
        .group-card{border:1px solid var(--line);border-radius:12px;background:var(--card);overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.04)}
        .group-header{padding:13px 18px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;background:var(--head);gap:12px;flex-wrap:wrap}
        .group-header:hover{background:#e8f5ec}
        .group-info{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .group-name{font-size:15px;font-weight:700;color:var(--subtitle)}
        .group-count{font-size:13px;color:var(--muted)}
        .badge-prog{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
        .badge-en{background:var(--blue-soft);color:var(--blue-text)}
        .badge-tech{background:var(--green-soft);color:#237a35}
        .chevron{font-size:11px;color:var(--muted);width:22px;height:22px;display:flex;align-items:center;justify-content:center;transition:transform .2s;border-radius:6px;background:rgba(0,0,0,.04);flex-shrink:0}
        .chevron.open{transform:rotate(-180deg)}
        .group-body{display:none}
        .group-body.open{display:block}
        /* Student rows */
        .student-row{padding:12px 18px;border-bottom:1px solid #f0f4f2;display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap}
        .student-row:last-child{border-bottom:none}
        .student-main{display:flex;gap:10px;align-items:flex-start;flex:1;min-width:200px}
        .student-avatar{width:38px;height:38px;border-radius:10px;background:var(--green-soft);color:var(--subtitle);font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .student-name{font-size:14px;font-weight:700;color:var(--text);margin-bottom:5px}
        .student-meta{display:flex;flex-wrap:wrap;gap:5px}
        .cred-chip{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;background:#edf9f1;color:#2a5136;white-space:nowrap}
        .cred-chip.dim{background:#f4f7f5;color:var(--muted)}
        .unit-select-wrap{margin-top:10px;max-width:360px}
        .unit-select-wrap label{display:block;margin-bottom:5px;font-size:11px;font-weight:700;color:var(--muted);text-transform:none;letter-spacing:0}
        .unit-list-select{width:100%;min-height:38px;border-radius:10px;border:1px solid #d7e4da;background:#f8fbf8;color:var(--text);padding:8px 10px;font-size:13px}
        .student-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding-top:2px}
        /* Small action buttons */
        .btn-sm{display:inline-flex;align-items:center;gap:4px;height:30px;padding:0 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;white-space:nowrap}
        .btn-pwd{background:var(--warn-soft);color:var(--warn)}.btn-pwd:hover{background:#fde68a}
        .btn-edit{background:var(--blue-soft);color:var(--blue-text)}.btn-edit:hover{background:#bfdbfe}
        .btn-del{background:var(--danger-soft);color:var(--danger)}.btn-del:hover{background:#fee2e2}
        .btn-save{background:var(--green);color:#fff}.btn-save:hover{background:var(--green-hover)}
        .btn-cancel-sm{background:#f3f4f6;color:var(--muted)}.btn-cancel-sm:hover{background:#e5e7eb}
        /* Inline password form */
        .pwd-form{width:100%;padding:10px 0 2px;border-top:1px dashed var(--line);margin-top:8px;display:none}
        .pwd-form form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .pwd-form input{max-width:200px;min-height:34px;flex:1}
        /* Misc */
        .empty-state{padding:28px;text-align:center;color:var(--muted);font-size:14px}
        .program-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:var(--green-soft);color:#237a35;font-size:12px;font-weight:700;white-space:nowrap}
        .muted{color:var(--muted)}
        @media(max-width:768px){
            body{padding:16px}.page-title{font-size:22px}.card-header h2{font-size:18px}
            .form-grid{grid-template-columns:1fr}.button-primary{font-size:13px}
            .student-row{flex-direction:column}.student-actions{justify-content:flex-start}
        }
    </style>
</head>
<body>
<main class="page">
    <div class="page-header">
        <button class="btn btn-back" onclick="location.href='../admin/dashboard.php'">← Volver</button>
        <h1 class="page-title">Asignaciones de estudiantes</h1>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-ok">✅ Guardado correctamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['pwd_reset']) && (string) $_GET['pwd_reset'] === '1'): ?>
        <div class="notice notice-pwd">🔑 Contraseña actualizada. El estudiante quedó habilitado y deberá cambiarla al ingresar.</div>
    <?php elseif (isset($_GET['pwd_reset'])): ?>
        <div class="notice notice-warn">⚠️ No fue posible actualizar la cuenta del estudiante. Verifica que tenga una asignación válida.</div>
    <?php endif; ?>

    <div class="stack">
        <section class="card">
            <div class="card-header">
                <h2><?= $editRecord ? '✏️ Editar asignación' : '🎓 Crear asignación' ?></h2>
                <?php if ($editRecord): ?>
                    <button class="btn btn-cancel-edit" onclick="location.href='student_assignments.php'">✕ Cancelar edición</button>
                <?php endif; ?>
            </div>
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
            <div class="card-header"><h2>📋 Grupos por programa / curso</h2></div>
            <?php
            /* Build groups: group by program + course_id */
            $assignmentGroups = [];
            foreach ($studentAssignments as $aRow) {
                $aProg    = (string) ($aRow['program'] ?? 'technical');
                $aCourseId = (string) ($aRow['course_id'] ?? '');
                $aIsEn    = $aProg === 'english';
                $aKey     = $aProg . '::' . $aCourseId;

                if (!isset($assignmentGroups[$aKey])) {
                    $aCourseName = $aIsEn
                        ? find_name_by_id($englishLevels, $aCourseId, 'Nivel desconocido')
                        : technical_assignment_label((array) $aRow, $technicalSemesters, $courses);

                    $assignmentGroups[$aKey] = [
                        'program'     => $aProg,
                        'label'       => $programOptions[$aProg] ?? 'Técnico',
                        'course_name' => $aCourseName,
                        'is_english'  => $aIsEn,
                        'rows'        => [],
                        'student_ids' => [],
                    ];
                }
                $assignmentGroups[$aKey]['rows'][] = $aRow;
                $studentKeyForCount = (string) ($aRow['student_id'] ?? '');
                if ($studentKeyForCount !== '') {
                    $assignmentGroups[$aKey]['student_ids'][$studentKeyForCount] = true;
                }
            }
            ?>

            <?php if (empty($assignmentGroups)): ?>
                <div class="empty-state">Sin asignaciones registradas todavía.</div>
            <?php else: ?>
                <div class="groups-wrap">
                    <?php foreach ($assignmentGroups as $gKey => $gGroup):
                        $safeKey = 'g_' . md5($gKey);
                        $gIsEn   = $gGroup['is_english'];
                    ?>
                    <div class="group-card">
                        <div class="group-header" onclick="toggleGroup('<?= $safeKey ?>')" id="hdr-<?= $safeKey ?>">
                            <div class="group-info">
                                <span class="badge-prog <?= $gIsEn ? 'badge-en' : 'badge-tech' ?>"><?= h($gGroup['label']) ?></span>
                                <span class="group-name"><?= h($gGroup['course_name']) ?></span>
                                <?php $groupStudentCount = count((array) ($gGroup['student_ids'] ?? [])); ?>
                                <span class="group-count"><?= $groupStudentCount ?> estudiante<?= $groupStudentCount !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="chevron" id="chev-<?= $safeKey ?>">&#9660;</div>
                        </div>

                        <div class="group-body" id="body-<?= $safeKey ?>">
                            <?php
                            $studentBundles = [];
                            foreach ($gGroup['rows'] as $bundleRow) {
                                $bundleStudentId = (string) ($bundleRow['student_id'] ?? '');
                                $bundleTeacherId = (string) ($bundleRow['teacher_id'] ?? '');
                                $bundleScopeId = $gIsEn ? (string) ($bundleRow['level_id'] ?? '') : (string) ($bundleRow['course_id'] ?? '');
                                $bundleKey = $bundleStudentId . '::' . $bundleScopeId . '::' . $bundleTeacherId;

                                if (!isset($studentBundles[$bundleKey])) {
                                    $studentBundles[$bundleKey] = [
                                        'first_row' => $bundleRow,
                                        'unit_ids' => [],
                                        'unit_names' => [],
                                        'teacher_names' => [],
                                    ];
                                }

                                $bundleUnitName = $gIsEn
                                    ? find_name_by_id($englishUnits, (string) ($bundleRow['unit_id'] ?? ''), '')
                                    : find_name_by_id($technicalUnits, (string) ($bundleRow['unit_id'] ?? ''), '');
                                $bundleUnitId = (string) ($bundleRow['unit_id'] ?? '');
                                $bundleTeacherName = find_name_by_id($teachers, $bundleTeacherId, '');

                                if ($bundleUnitId !== '' && !in_array($bundleUnitId, $studentBundles[$bundleKey]['unit_ids'], true)) {
                                    $studentBundles[$bundleKey]['unit_ids'][] = $bundleUnitId;
                                }
                                if ($bundleUnitName !== '' && !in_array($bundleUnitName, $studentBundles[$bundleKey]['unit_names'], true)) {
                                    $studentBundles[$bundleKey]['unit_names'][] = $bundleUnitName;
                                }
                                if ($bundleTeacherName !== '' && !in_array($bundleTeacherName, $studentBundles[$bundleKey]['teacher_names'], true)) {
                                    $studentBundles[$bundleKey]['teacher_names'][] = $bundleTeacherName;
                                }
                            }
                            ?>
                            <?php foreach ($studentBundles as $bundle):
                                $gRow = (array) ($bundle['first_row'] ?? []);
                                $gStudentId  = (string) ($gRow['student_id'] ?? '');
                                $gAccount    = find_student_account($studentAccounts, $gStudentId);
                                $gUsername   = (string) ($gAccount['username'] ?? $gRow['student_username'] ?? '');
                                $gTempPass   = (string) ($gAccount['temp_password'] ?? $gRow['student_temp_password'] ?? '');
                                $gAssignId   = (string) ($gRow['id'] ?? '');
                                $gRowId      = 'r' . md5($gAssignId . '::' . $gStudentId . '::' . $safeKey);
                                $gStudentName = find_name_by_id($students, $gStudentId, 'N/D');
                                $gInitial    = mb_strtoupper(mb_substr(trim($gStudentName), 0, 1, 'UTF-8'), 'UTF-8');
                                $gUnitNames  = (array) ($bundle['unit_names'] ?? []);
                                $gUnitIds    = (array) ($bundle['unit_ids'] ?? []);
                                $gTeacherNames = (array) ($bundle['teacher_names'] ?? []);
                                $gTeacherLabel = implode(', ', $gTeacherNames);
                                $availableUnits = array_values(array_filter($gIsEn ? $englishUnits : $technicalUnits, function ($u) use ($gIsEn, $gRow) {
                                    if ($gIsEn) {
                                        return (string) ($u['phase_id'] ?? '') === (string) ($gRow['level_id'] ?? '');
                                    }
                                    return (string) ($u['course_id'] ?? $u['level_id'] ?? '') === (string) ($gRow['course_id'] ?? '');
                                }));
                                $availableUnitIds = array_values(array_unique(array_filter(array_map(function ($u) {
                                    return (string) ($u['id'] ?? '');
                                }, $availableUnits))));
                                $assignedUnitCount = count($gUnitIds);
                                $hasAllUnits = !empty($availableUnitIds) && count(array_intersect($availableUnitIds, $gUnitIds)) >= count($availableUnitIds);
                                $deleteQuestion = $assignedUnitCount > 1
                                    ? '¿Eliminar todas las unidades asignadas a ' . $gStudentName . ' en este curso?' 
                                    : '¿Eliminar a ' . $gStudentName . '?';
                                $deleteUrl = 'student_assignments.php?delete_group=1&student_id=' . urlencode($gStudentId)
                                    . '&program=' . urlencode((string) ($gRow['program'] ?? ''))
                                    . '&course_id=' . urlencode((string) ($gRow['course_id'] ?? ''))
                                    . '&level_id=' . urlencode((string) ($gRow['level_id'] ?? ''))
                                    . '&teacher_id=' . urlencode((string) ($gRow['teacher_id'] ?? ''));
                            ?>
                            <div class="student-row">
                                <div class="student-main">
                                    <div class="student-avatar"><?= $gInitial ?></div>
                                    <div style="flex:1">
                                        <div class="student-name"><?= h($gStudentName) ?></div>
                                        <div class="student-meta">
                                            <?php if ($gUsername !== ''): ?>
                                                <span class="cred-chip">👤 <?= h($gUsername) ?></span>
                                            <?php endif; ?>
                                            <?php if ($gTempPass !== ''): ?>
                                                <span class="cred-chip">🔑 <?= h($gTempPass) ?></span>
                                            <?php endif; ?>
                                            <span class="cred-chip dim">📚 <?= $hasAllUnits ? 'Todas las unidades' : ($assignedUnitCount . ' unidad' . ($assignedUnitCount !== 1 ? 'es' : '')) ?></span>
                                            <?php if ($gTeacherLabel !== ''): ?>
                                                <span class="cred-chip dim">👩‍🏫 <?= h($gTeacherLabel) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($gUnitNames)): ?>
                                            <div class="unit-select-wrap">
                                                <label>Unidades asignadas</label>
                                                <select class="unit-list-select" onchange="this.selectedIndex=0">
                                                    <option value=""><?= $hasAllUnits ? 'Todas las unidades del curso / semestre' : 'Ver unidades asignadas' ?></option>
                                                    <?php foreach ($gUnitNames as $unitName): ?>
                                                        <option value="<?= h($unitName) ?>"><?= h($unitName) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>
                                        <div class="pwd-form" id="<?= $gRowId ?>">
                                            <form method="post">
                                                <input type="hidden" name="action" value="reset_student_password">
                                                <input type="hidden" name="reset_student_id" value="<?= h($gStudentId) ?>">
                                                <input type="password" name="new_password" placeholder="Nueva contraseña" required>
                                                <button type="submit" class="btn-sm btn-save">Guardar</button>
                                                <button type="button" class="btn-sm btn-cancel-sm" onclick="togglePwd('<?= $gRowId ?>')">Cancelar</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="student-actions">
                                    <button type="button" class="btn-sm btn-pwd" onclick="togglePwd('<?= $gRowId ?>')">🔑 Contraseña</button>
                                    <button type="button" class="btn-sm btn-edit" onclick="location.href='student_assignments.php?edit=<?= h($gAssignId) ?>'">✏️ <?= $assignedUnitCount > 1 ? 'Editar grupo' : 'Cambiar grupo' ?></button>
                                    <button type="button" class="btn-sm btn-del" onclick="if(confirm('<?= h(addslashes($deleteQuestion)) ?>')) location.href='<?= h($deleteUrl) ?>'">🗑️ Eliminar</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
/* ---- Accordion groups ---- */
function toggleGroup(key) {
    const body  = document.getElementById('body-' + key);
    const chev  = document.getElementById('chev-' + key);
    const hdr   = document.getElementById('hdr-' + key);
    if (!body) return;
    const isOpen = body.classList.toggle('open');
    if (chev) chev.classList.toggle('open', isOpen);
    if (hdr)  hdr.style.borderBottomColor = isOpen ? 'var(--line)' : 'transparent';
}

/* ---- Inline password form ---- */
function togglePwd(rowId) {
    const form = document.getElementById(rowId);
    if (!form) return;
    const visible = form.style.display !== 'none' && form.style.display !== '';
    form.style.display = visible ? 'none' : 'block';
    if (!visible) {
        const input = form.querySelector('input[name="new_password"]');
        if (input) input.focus();
    }
}

/* ---- Dropdown selects ---- */
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

function fillUnitSelect(items, selectedValue) {
    fillSelect(unitSelect, items, selectedValue, 'Seleccionar unidad...');
    if (items.length > 0) {
        const allOpt = document.createElement('option');
        allOpt.value = 'all';
        allOpt.textContent = '— Todas las unidades —';
        if (String(selectedValue) === 'all') allOpt.selected = true;
        unitSelect.insertBefore(allOpt, unitSelect.options[1]);
    }
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
        fillUnitSelect(unitsForPhase, currentUnit);
    } else {
        setTechnicalMode();

        const currentSemester = initial ? selectedCourse : (courseSelect.value || '');
        fillSelect(courseSelect, technicalSemesters, currentSemester, 'Seleccionar semestre...');

        const semesterId = courseSelect.value || currentSemester || '';
        const selectedSemester = technicalSemesters.find(item => String(item.id ?? '') === String(semesterId));
        const fallbackParts = String(semesterId).split('|');
        const fallbackCourseId = fallbackParts.length === 2 ? fallbackParts[0] : semesterId;
        const baseCourseId = String(selectedSemester?.course_id ?? selectedSemester?.id ?? fallbackCourseId);

        const unitsForSemester = technicalUnits.filter(
            item => String(item.course_id ?? item.level_id ?? '') === baseCourseId
        );

        const currentUnit = initial ? selectedUnit : (unitSelect.value || '');
        fillUnitSelect(unitsForSemester, currentUnit);
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

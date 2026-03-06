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

$technicalPeriods = ['1', '2', '3', '4', '5', '6'];
$programOptions = [
    'english' => 'Inglés',
    'technical' => 'Programa Técnico',
];

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
        str_contains($programRaw, 'english')
        || str_contains($programRaw, 'ingles')
        || str_contains($programRaw, 'prog_english_courses')
        || str_contains($programRaw, 'prog_english')
        || str_contains($programRaw, 'english_levels')
        || str_contains($nameRaw, 'phase')
        || str_contains($nameRaw, 'fase')
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
        str_contains($programRaw, 'english')
        || str_contains($programRaw, 'ingles')
        || str_contains($programRaw, 'prog_english_courses')
        || str_contains($programRaw, 'prog_english')
        || str_contains($programRaw, 'english_levels')
        || str_contains($nameRaw, 'phase')
        || str_contains($nameRaw, 'fase')
        || !empty($unit['phase_id'])
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

function load_english_catalog_from_database(): array
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
        $levelsStmt = $pdo->query('SELECT id, name FROM english_levels ORDER BY id ASC');
        $levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->query('
            SELECT u.id, u.name, u.phase_id, p.level_id
            FROM units u
            INNER JOIN english_phases p ON p.id = u.phase_id
            ORDER BY p.level_id ASC, u.id ASC
        ');
        $unitsRaw = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [[], []];
    }

    $englishLevels = array_map(function ($level) {
        return [
            'id' => (string) ($level['id'] ?? ''),
            'name' => (string) ($level['name'] ?? ''),
            'program' => 'english',
            'scope' => 'english',
            'program_id' => 'prog_english_courses',
        ];
    }, is_array($levels) ? $levels : []);

    $englishUnits = array_map(function ($unit) {
        return [
            'id' => (string) ($unit['id'] ?? ''),
            'name' => (string) ($unit['name'] ?? ''),
            'phase_id' => (string) ($unit['phase_id'] ?? ''),
            'course_id' => (string) ($unit['level_id'] ?? ''),
            'program' => 'english',
            'scope' => 'english',
            'program_id' => 'prog_english_courses',
        ];
    }, is_array($unitsRaw) ? $unitsRaw : []);

    $englishLevels = array_values(array_filter($englishLevels, function ($row) {
        return (string) ($row['id'] ?? '') !== '';
    }));

    $englishUnits = array_values(array_filter($englishUnits, function ($row) {
        return (string) ($row['id'] ?? '') !== '';
    }));

    return [$englishLevels, $englishUnits];
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
    $program = trim((string) ($_POST['program'] ?? 'technical'));
    $period = trim((string) ($_POST['period'] ?? ''));
    $unitId = trim((string) ($_POST['unit_id'] ?? ''));

    if (!isset($programOptions[$program])) {
        $program = 'technical';
    }

    if ($studentId !== '' && $teacherId !== '' && $period !== '' && $unitId !== '') {
        $record = [
            'id' => $editId !== '' ? $editId : uniqid('stu_assign_'),
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'program' => $program,

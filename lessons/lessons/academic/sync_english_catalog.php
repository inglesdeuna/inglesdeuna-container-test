<?php
session_start();

if (PHP_SAPI !== 'cli') {
    if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
        header('Location: ../admin/login.php');
        exit;
    }
}

$baseDir = __DIR__ . '/data';
$coursesFile = $baseDir . '/courses.json';
$unitsFile = $baseDir . '/units.json';
$dbFile = __DIR__ . '/../config/db.php';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0775, true);
}

foreach ([$coursesFile, $unitsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

if (!file_exists($dbFile)) {
    http_response_code(500);
    exit('No se encontró config/db.php');
}

require $dbFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('No se pudo inicializar la conexión a la base de datos.');
}

$courses = json_decode((string) file_get_contents($coursesFile), true);
$units = json_decode((string) file_get_contents($unitsFile), true);

$courses = is_array($courses) ? $courses : [];
$units = is_array($units) ? $units : [];

$levelsStmt = $pdo->query('SELECT id, name FROM english_levels ORDER BY id ASC');
$levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

$englishUnitsStmt = $pdo->query('
    SELECT u.id, u.name, u.phase_id, p.level_id
    FROM units u
    INNER JOIN english_phases p ON p.id = u.phase_id
    ORDER BY p.level_id ASC, u.id ASC
');
$englishUnitsRaw = $englishUnitsStmt->fetchAll(PDO::FETCH_ASSOC);

$technicalCourses = array_values(array_filter($courses, static function ($course) {
    $programRaw = mb_strtolower((string) (
        $course['program']
        ?? $course['program_id']
        ?? $course['scope']
        ?? ''
    ));

    return !(
        str_contains($programRaw, 'english')
        || str_contains($programRaw, 'ingles')
        || str_contains($programRaw, 'prog_english')
        || str_contains($programRaw, 'english_levels')
    );
}));

$technicalUnits = array_values(array_filter($units, static function ($unit) {
    $programRaw = mb_strtolower((string) (
        $unit['program']
        ?? $unit['program_id']
        ?? $unit['scope']
        ?? ''
    ));

    return !(
        str_contains($programRaw, 'english')
        || str_contains($programRaw, 'ingles')
        || str_contains($programRaw, 'prog_english')
        || str_contains($programRaw, 'english_levels')
        || !empty($unit['phase_id'])
    );
}));

$englishCourses = array_map(static function ($level) {
    return [
        'id' => (string) ($level['id'] ?? ''),
        'name' => (string) ($level['name'] ?? ''),
        'program' => 'english',
        'scope' => 'english',
        'program_id' => 'prog_english_courses',
    ];
}, $levels);

$englishUnits = array_map(static function ($unit) {
    return [
        'id' => (string) ($unit['id'] ?? ''),
        'name' => (string) ($unit['name'] ?? ''),
        'phase_id' => (string) ($unit['phase_id'] ?? ''),
        'course_id' => (string) ($unit['level_id'] ?? ''),
        'program' => 'english',
        'scope' => 'english',
        'program_id' => 'prog_english_courses',
    ];
}, $englishUnitsRaw);

$mergedCourses = array_values(array_filter(array_merge($technicalCourses, $englishCourses), static function ($row) {
    return (string) ($row['id'] ?? '') !== '';
}));

$mergedUnits = array_values(array_filter(array_merge($technicalUnits, $englishUnits), static function ($row) {
    return (string) ($row['id'] ?? '') !== '';
}));

file_put_contents($coursesFile, json_encode($mergedCourses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($unitsFile, json_encode($mergedUnits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$message = 'Sincronización completada. Niveles: ' . count($englishCourses) . ', Unidades: ' . count($englishUnits);

if (PHP_SAPI === 'cli') {
    echo $message . PHP_EOL;
    exit(0);
}

header('Content-Type: text/plain; charset=UTF-8');
echo $message;

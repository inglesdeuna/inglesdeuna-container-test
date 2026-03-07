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

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

[$englishCoursesDb, $englishUnitsDb] = load_english_catalog_from_database();

$technicalCourses = filter_courses_by_program($courses, 'technical');
$englishCoursesLocal = filter_courses_by_program($courses, 'english');
$englishCourses = count($englishCoursesDb) > 0 ? $englishCoursesDb : $englishCoursesLocal;

$technicalUnits = filter_units_by_program($units, 'technical');
$englishUnitsLocal = filter_units_by_program($units, 'english');
$englishUnits = count($englishUnitsDb) > 0 ? $englishUnitsDb : $englishUnitsLocal;

$coursesByProgram = [
    'technical' => $technicalCourses,
    'english' => $englishCourses,
];

$unitsByProgram = [
    'technical' => $technicalUnits,
    'english' => $englishUnits,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = trim((string) ($_POST['edit_id'] ?? ''));
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $teacherId = trim((string) ($_POST['teacher_id'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? 'technical'));
    $period = trim((string) ($_POST['period'] ?? ''));
    $unitId = trim((string) ($_POST['unit_id'] ?? ''));
    $courseId = trim((string) ($_POST['course_id'] ?? ''));

    if (!isset($programOptions[$program])) {
        $program = 'technical';
    }

    if ($studentId !== '' && $teacherId !== '' && $period !== '' && $unitId !== '') {
        if ($courseId === '') {
            foreach ($unitsByProgram[$program] as $unit) {
                if ((string) ($unit['id'] ?? '') === $unitId) {
                    $courseId = (string) ($unit['course_id'] ?? $unit['level_id'] ?? '');
                    break;
                }
            }
        }

        $record = [
            'id' => $editId !== '' ? $editId : uniqid('stu_assign_'),
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'program' => $program,
            'period' => $period,
            'course_id' => $courseId,
            'unit_id' => $unitId,
            'updated_at' => date('c'),
        ];

        $updated = false;
        foreach ($studentAssignments as $index => $existing) {
            if ((string) ($existing['id'] ?? '') === $record['id']) {
                $studentAssignments[$index] = $record;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $studentAssignments[] = $record;
        }

        save_student_assignments($studentAssignmentsFile, $studentAssignments);
        header('Location: student_assignments.php?saved=1');
        exit;
    }
}

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
        body { font-family: Arial, sans-serif; margin: 20px; }
        .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 10px; }
        label { display:block; font-size: 13px; margin-bottom: 4px; }
        select, button { width: 100%; padding: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px; font-size: 14px; }
        th { background: #f2f2f2; }
        .ok { color: #0a7d22; margin-bottom: 10px; }
    </style>
</head>
<body>
<h1>Asignaciones de estudiantes</h1>

<?php if (isset($_GET['saved'])): ?>
    <p class="ok">Guardado correctamente.</p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="edit_id" value="<?= h((string) ($editRecord['id'] ?? '')) ?>">

    <div class="row">
        <div>
            <label>Estudiante</label>
            <select name="student_id" required>
                <option value="">Seleccionar...</option>
                <?php foreach ($students as $student): ?>
                    <?php $sid = (string) ($student['id'] ?? ''); ?>
                    <option value="<?= h($sid) ?>" <?= $sid === (string) ($editRecord['student_id'] ?? '') ? 'selected' : '' ?>>
                        <?= h((string) ($student['name'] ?? 'Sin nombre')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Docente</label>
            <select name="teacher_id" required>
                <option value="">Seleccionar...</option>
                <?php foreach ($teachers as $teacher): ?>
                    <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                    <option value="<?= h($tid) ?>" <?= $tid === (string) ($editRecord['teacher_id'] ?? '') ? 'selected' : '' ?>>
                        <?= h((string) ($teacher['name'] ?? 'Sin nombre')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Programa</label>
            <select name="program" id="program" required>
                <?php foreach ($programOptions as $programKey => $programLabel): ?>
                    <option value="<?= h($programKey) ?>" <?= $programKey === $selectedProgram ? 'selected' : '' ?>>
                        <?= h($programLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Periodo</label>
            <select name="period" required>
                <option value="">Seleccionar...</option>
                <?php foreach ($technicalPeriods as $p): ?>
                    <option value="<?= h($p) ?>" <?= $p === (string) ($editRecord['period'] ?? '') ? 'selected' : '' ?>><?= h($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row">
        <div>
            <label>Curso / Nivel</label>
            <select name="course_id" id="course_id">
                <option value="">Seleccionar...</option>
            </select>
        </div>

        <div>
            <label>Unidad</label>
            <select name="unit_id" id="unit_id" required>
                <option value="">Seleccionar...</option>
            </select>
        </div>

        <div style="align-self:end;">
            <button type="submit">Guardar asignación</button>
        </div>
    </div>
</form>

<table>
    <thead>
    <tr>
        <th>Estudiante</th>
        <th>Docente</th>
        <th>Programa</th>
        <th>Periodo</th>
        <th>Curso</th>
        <th>Unidad</th>
        <th>Acciones</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($studentAssignments as $row): ?>
        <?php
        $program = (string) ($row['program'] ?? 'technical');
        $coursesForRow = $coursesByProgram[$program] ?? [];
        $unitsForRow = $unitsByProgram[$program] ?? [];
        ?>
        <tr>
            <td><?= h(find_name_by_id($students, (string) ($row['student_id'] ?? ''), 'N/D')) ?></td>
            <td><?= h(find_name_by_id($teachers, (string) ($row['teacher_id'] ?? ''), 'N/D')) ?></td>
            <td><?= h($programOptions[$program] ?? 'Programa Técnico') ?></td>
            <td><?= h((string) ($row['period'] ?? '')) ?></td>
            <td><?= h(find_name_by_id($coursesForRow, (string) ($row['course_id'] ?? ''), 'N/D')) ?></td>
            <td><?= h(find_name_by_id($unitsForRow, (string) ($row['unit_id'] ?? ''), 'N/D')) ?></td>
            <td>
                <a href="student_assignments.php?edit=<?= h((string) ($row['id'] ?? '')) ?>">Editar</a>
                |
                <a href="student_assignments.php?delete=<?= h((string) ($row['id'] ?? '')) ?>" onclick="return confirm('¿Eliminar asignación?')">Eliminar</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
const coursesByProgram = <?= json_encode($coursesByProgram, JSON_UNESCAPED_UNICODE) ?>;
const unitsByProgram = <?= json_encode($unitsByProgram, JSON_UNESCAPED_UNICODE) ?>;
const selectedCourse = <?= json_encode((string) ($editRecord['course_id'] ?? '')) ?>;
const selectedUnit = <?= json_encode((string) ($editRecord['unit_id'] ?? '')) ?>;

const programSelect = document.getElementById('program');
const courseSelect = document.getElementById('course_id');
const unitSelect = document.getElementById('unit_id');

function fillSelect(select, items, selected, placeholder) {
    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.appendChild(first);

    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = String(item.id ?? '');
        option.textContent = String(item.name ?? 'Sin nombre');
        if (option.value === selected) option.selected = true;
        select.appendChild(option);
    });
}

function refreshCatalog() {
    const program = programSelect.value || 'technical';
    const courses = coursesByProgram[program] || [];
    const units = unitsByProgram[program] || [];

    const selectedCourseId = courseSelect.value || selectedCourse;
    fillSelect(courseSelect, courses, selectedCourseId, 'Seleccionar...');

    const byCourse = units.filter((u) => String(u.course_id ?? u.level_id ?? '') === (courseSelect.value || selectedCourseId));
    const finalUnits = byCourse.length > 0 ? byCourse : units;
    fillSelect(unitSelect, finalUnits, unitSelect.value || selectedUnit, 'Seleccionar...');
}

programSelect.addEventListener('change', () => {
    courseSelect.value = '';
    unitSelect.value = '';
    refreshCatalog();
});
courseSelect.addEventListener('change', refreshCatalog);
refreshCatalog();
</script>
</body>
</html>

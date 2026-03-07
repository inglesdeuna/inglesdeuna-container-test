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
        return [[], [], false];
    }

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return [[], [], false];
    }

    require $dbFile;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return [[], [], false];
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
        return [[], [], false];
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

    return [$englishLevels, $englishUnits, true];
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

[$englishCoursesDb, $englishUnitsDb, $englishFromDb] = load_english_catalog_from_database();

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

$englishLevels = $englishCourses;
if (empty($englishLevels)) {
    $englishLevels = [
        ['id' => 'preschool', 'name' => 'PRESCHOOL LEVEL'],
        ['id' => 'kindergarten', 'name' => 'KINDERGARTEN LEVEL'],
        ['id' => 'first_grade', 'name' => 'FIRST GRADE LEVEL'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = trim((string) ($_POST['edit_id'] ?? ''));
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $teacherId = trim((string) ($_POST['teacher_id'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? 'technical'));
    $levelId = trim((string) ($_POST['level_id'] ?? ''));
    $unitId = trim((string) ($_POST['unit_id'] ?? ''));
    $courseId = trim((string) ($_POST['course_id'] ?? ''));

    if (!isset($programOptions[$program])) {
        $program = 'technical';
    }

    if ($studentId !== '' && $teacherId !== '' && $unitId !== '') {
        if ($courseId === '') {
            foreach ($unitsByProgram[$program] as $unit) {
                if ((string) ($unit['id'] ?? '') === $unitId) {
                    $courseId = (string) ($unit['course_id'] ?? $unit['level_id'] ?? '');
                    break;
                }
            }
        }

        if ($program === 'english' && $levelId === '') {
            $levelId = $courseId;
        }

        $record = [
            'id' => $editId !== '' ? $editId : uniqid('stu_assign_'),
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'program' => $program,
            'level_id' => $levelId,
            'period' => $levelId,
            'course_id' => $courseId,
            'unit_id' => $unitId,
            'updated_at' => date('c'),
        ];

        $updated = false;
        foreach ($studentAssignments as $index => $existing) {
            if ((string) ($existing['id'] ?? '') === $record['id']) {
                $studentAssignments[$index] = array_merge($existing, $record);
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
            padding:14px 24px;
        }
        .topbar h1{ margin:0; font-size:34px; }
        .topbar p{ margin:6px 0 0; opacity:.95; }
        .page{ max-width:1280px; margin:16px auto 0; padding:0 16px 24px; }
        .top-actions{ display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
        .back{ color:#2d71d2; text-decoration:none; font-weight:700; }
        .notice{ background:var(--ok-bg); color:var(--ok-text); border:1px solid var(--ok-line); padding:10px 12px; border-radius:10px; margin-bottom:12px; }
        .layout{ display:grid; grid-template-columns:1.1fr .9fr; gap:16px; }
        .panel{ background:var(--card-bg); border:1px solid var(--line); border-radius:16px; overflow:hidden; box-shadow:0 5px 14px rgba(51,72,107,.08); }
        .panel h3{ margin:0; padding:14px 16px; border-bottom:1px solid var(--line); background:linear-gradient(180deg,#f8f9fc,#f0f3f9); font-size:28px; }
        .panel-body{ padding:16px; }
        .row{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .row + .row{ margin-top:10px; }
        label{ display:block; font-weight:700; font-size:14px; margin-bottom:6px; }
        select, button{
            width:100%;
            padding:10px;
            border:1px solid #cfd8e8;
            border-radius:9px;
            background:#fff;
            color:var(--text-main);
        }
        .button-primary{ border:none; background:linear-gradient(90deg,#2a67c4,#2d71d2); color:#fff; font-weight:700; cursor:pointer; }
        .hint{ margin-top:8px; font-size:12px; color:var(--text-soft); }
        .hint a{ color:#1f6fd6; text-decoration:none; }
        table{ width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
        th, td{ border-bottom:1px solid #e7ecf5; padding:10px; font-size:14px; }
        th{ background:#f4f7fb; text-align:left; }
        tr:last-child td{ border-bottom:none; }
        .actions a{ text-decoration:none; font-weight:600; }
        .actions a:first-child{ color:#1f6fd6; }
        .actions a:last-child{ color:#c62828; }
        @media (max-width:980px){
            .topbar h1{ font-size:28px; }
            .layout{ grid-template-columns:1fr; }
            .row{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <h1>🎓 Asignaciones de estudiantes</h1>
    <p>Asigna estudiante, docente, programa, level y unidad.</p>
</header>

<main class="page">
    <div class="top-actions">
        <a class="back" href="dashboard.php">← Volver al panel académico</a>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice">Guardado correctamente.</div>
    <?php endif; ?>

    <div class="layout">
        <section class="panel">
            <h3>Formulario de asignación</h3>
            <div class="panel-body">
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
                    </div>

                    <div class="row">
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
                            <label>Level (Inglés)</label>
                            <select name="level_id" id="level_id">
                                <option value="">Seleccionar level...</option>
                                <?php foreach ($englishLevels as $level): ?>
                                    <?php $lvlId = (string) ($level['id'] ?? ''); ?>
                                    <option value="<?= h($lvlId) ?>" <?= $lvlId === (string) ($editRecord['level_id'] ?? $editRecord['period'] ?? '') ? 'selected' : '' ?>>
                                        <?= h((string) ($level['name'] ?? 'LEVEL')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">
                                Configurar levels/phases: <a href="english_structure_levels.php">english_structure_levels.php</a>
                                <?php if ($englishFromDb && !empty($englishLevels)): ?>
                                    | <a href="english_structure_phases.php?level=<?= h((string) ($englishLevels[0]['id'] ?? '1')) ?>">Abrir phases del primer level</a>
                                <?php endif; ?>
                            </div>
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
                    </div>

                    <div class="row" style="grid-template-columns:1fr; margin-top:12px;">
                        <button class="button-primary" type="submit">Guardar asignación</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel">
            <h3>Asignaciones creadas</h3>
            <div class="panel-body">
                <table>
                    <thead>
                    <tr>
                        <th>Estudiante</th>
                        <th>Docente</th>
                        <th>Programa</th>
                        <th>Level</th>
                        <th>Curso</th>
                        <th>Unidad</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($studentAssignments)): ?>
                        <tr>
                            <td colspan="7">No hay asignaciones registradas.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($studentAssignments as $row): ?>
                        <?php
                        $program = (string) ($row['program'] ?? 'technical');
                        $coursesForRow = $coursesByProgram[$program] ?? [];
                        $unitsForRow = $unitsByProgram[$program] ?? [];
                        $rowLevelId = (string) ($row['level_id'] ?? $row['period'] ?? '');
                        ?>
                        <tr>
                            <td><?= h(find_name_by_id($students, (string) ($row['student_id'] ?? ''), 'N/D')) ?></td>
                            <td><?= h(find_name_by_id($teachers, (string) ($row['teacher_id'] ?? ''), 'N/D')) ?></td>
                            <td><?= h($programOptions[$program] ?? 'Programa Técnico') ?></td>
                            <td><?= h(find_name_by_id($englishLevels, $rowLevelId, $rowLevelId !== '' ? $rowLevelId : 'N/D')) ?></td>
                            <td><?= h(find_name_by_id($coursesForRow, (string) ($row['course_id'] ?? ''), 'N/D')) ?></td>
                            <td><?= h(find_name_by_id($unitsForRow, (string) ($row['unit_id'] ?? ''), 'N/D')) ?></td>
                            <td class="actions">
                                <a href="student_assignments.php?edit=<?= h((string) ($row['id'] ?? '')) ?>">Editar</a>
                                |
                                <a href="student_assignments.php?delete=<?= h((string) ($row['id'] ?? '')) ?>" onclick="return confirm('¿Eliminar asignación?')">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<script>
const coursesByProgram = <?= json_encode($coursesByProgram, JSON_UNESCAPED_UNICODE) ?>;
const unitsByProgram = <?= json_encode($unitsByProgram, JSON_UNESCAPED_UNICODE) ?>;
const selectedCourse = <?= json_encode((string) ($editRecord['course_id'] ?? '')) ?>;
const selectedUnit = <?= json_encode((string) ($editRecord['unit_id'] ?? '')) ?>;
const selectedLevel = <?= json_encode((string) ($editRecord['level_id'] ?? $editRecord['period'] ?? '')) ?>;

const programSelect = document.getElementById('program');
const courseSelect = document.getElementById('course_id');
const unitSelect = document.getElementById('unit_id');
const levelSelect = document.getElementById('level_id');

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

    const targetCourse = courseSelect.value || selectedCourseId || (program === 'english' ? (levelSelect.value || selectedLevel) : '');
    const byCourse = units.filter((u) => String(u.course_id ?? u.level_id ?? '') === targetCourse);
    const finalUnits = byCourse.length > 0 ? byCourse : units;
    fillSelect(unitSelect, finalUnits, unitSelect.value || selectedUnit, 'Seleccionar...');

    if (program === 'english') {
        levelSelect.disabled = false;
        if (!levelSelect.value && selectedLevel) {
            levelSelect.value = selectedLevel;
        }
    } else {
        levelSelect.value = '';
        levelSelect.disabled = true;
    }
}

programSelect.addEventListener('change', () => {
    courseSelect.value = '';
    unitSelect.value = '';
    refreshCatalog();
});

courseSelect.addEventListener('change', refreshCatalog);
levelSelect.addEventListener('change', () => {
    if (programSelect.value === 'english' && !courseSelect.value) {
        courseSelect.value = levelSelect.value;
    }
    refreshCatalog();
});

if (selectedLevel) {
    levelSelect.value = selectedLevel;
}
refreshCatalog();
</script>
</body>
</html>

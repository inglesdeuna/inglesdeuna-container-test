<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

/* ===============================
   ARCHIVOS LOCALES
=============================== */
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

function save_student_assignments(string $file, array $records): void
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
        str_contains($nameRaw, 'fase')
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

/* ===============================
   CARGAR CATÁLOGO DE INGLÉS DESDE DB
   english_levels   = NIVEL
   english_phases   = FASE
   units            = UNIDAD
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
        $levelsStmt = $pdo->query("
            SELECT id, name
            FROM english_levels
            ORDER BY id ASC
        ");
        $levels = $levelsStmt->fetchAll(PDO::FETCH_ASSOC);

        $phasesStmt = $pdo->query("
            SELECT id, level_id, name
            FROM english_phases
            ORDER BY level_id ASC, created_at ASC, id ASC
        ");
        $phases = $phasesStmt->fetchAll(PDO::FETCH_ASSOC);

        $unitsStmt = $pdo->query("
            SELECT 
                u.id,
                u.name,
                u.phase_id,
                p.level_id
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
            'program' => 'english',
        ];
    }, is_array($levels) ? $levels : []);

    $englishPhases = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'level_id' => (string) ($row['level_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'program' => 'english',
        ];
    }, is_array($phases) ? $phases : []);

    $englishUnits = array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'phase_id' => (string) ($row['phase_id'] ?? ''),
            'level_id' => (string) ($row['level_id'] ?? ''),
            'program' => 'english',
        ];
    }, is_array($unitsRaw) ? $unitsRaw : []);

    $englishLevels = array_values(array_filter($englishLevels, fn($r) => (string) ($r['id'] ?? '') !== ''));
    $englishPhases = array_values(array_filter($englishPhases, fn($r) => (string) ($r['id'] ?? '') !== ''));
    $englishUnits = array_values(array_filter($englishUnits, fn($r) => (string) ($r['id'] ?? '') !== ''));

    return [$englishLevels, $englishPhases, $englishUnits, true];
}

/* ===============================
   ELIMINAR
=============================== */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];

    $studentAssignments = array_values(array_filter($studentAssignments, function ($row) use ($deleteId) {
        return (string) ($row['id'] ?? '') !== $deleteId;
    }));

    save_student_assignments($studentAssignmentsFile, $studentAssignments);
    header('Location: student_assignments.php?saved=1');
    exit;
}

/* ===============================
   CATÁLOGOS
=============================== */
[$englishLevelsDb, $englishPhasesDb, $englishUnitsDb, $englishFromDb] = load_english_catalog_from_database();

/* Técnico desde JSON */
$technicalSemesters = filter_courses_by_program($courses, 'technical');
$technicalUnits = filter_units_by_program($units, 'technical');

/* Inglés desde DB */
$englishLevels = $englishLevelsDb;
$englishPhases = $englishPhasesDb;
$englishUnits = $englishUnitsDb;

/* Fallbacks por si no hay DB */
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
   - english:
       course_id = nivel
       level_id  = fase
       unit_id   = unidad
   - technical:
       course_id = semestre
       level_id  = vacío
       unit_id   = unidad
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

    $isValid = false;

    if ($program === 'english') {
        $isValid = ($studentId !== '' && $teacherId !== '' && $courseId !== '' && $levelId !== '' && $unitId !== '');
    } else {
        $levelId = '';
        $isValid = ($studentId !== '' && $teacherId !== '' && $courseId !== '' && $unitId !== '');
    }

    if ($isValid) {
        $record = [
            'id' => $editId !== '' ? $editId : uniqid('stu_assign_'),
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'program' => $program,
            'course_id' => $courseId,
            'level_id' => $levelId,
            'period' => $levelId,
            'unit_id' => $unitId,
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

        save_student_assignments($studentAssignmentsFile, $studentAssignments);
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

$selectedProgram = (string) ($editRecord['program'] ?? 'english');
if (!isset($programOptions[$selectedProgram])) {
    $selectedProgram = 'english';
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
            --bg:#edf1f7;
            --card:#ffffff;
            --line:#dde5f0;
            --text:#1c2d45;
            --muted:#66758c;
            --blue:#2f66dd;
            --blue2:#5a35e6;
            --danger:#c62828;
            --shadow:0 10px 28px rgba(36,52,79,.08);
        }

        *{ box-sizing:border-box; }

        body{
            margin:0;
            font-family:"Segoe UI", Arial, sans-serif;
            background:var(--bg);
            color:var(--text);
        }

        .page{
            max-width:1100px;
            margin:26px auto;
            padding:0 18px 30px;
        }

        .back{
            display:inline-block;
            margin-bottom:14px;
            color:#2f66dd;
            text-decoration:none;
            font-weight:700;
            font-size:15px;
        }

        .notice{
            margin:0 0 14px;
            background:#ecfdf3;
            color:#166534;
            border:1px solid #b7e6c7;
            border-radius:12px;
            padding:12px 14px;
        }

        .grid{
            display:grid;
            grid-template-columns:1.08fr .92fr;
            gap:18px;
            align-items:start;
        }

        .card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:18px;
            box-shadow:var(--shadow);
            overflow:hidden;
        }

        .card-header{
            padding:18px 20px;
            border-bottom:1px solid var(--line);
            background:#fbfcff;
        }

        .card-header h2{
            margin:0;
            font-size:22px;
            font-weight:800;
            color:#18304e;
        }

        .card-body{
            padding:18px;
        }

        .form-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:12px 14px;
        }

        .field{
            display:flex;
            flex-direction:column;
        }

        .field.full{
            grid-column:1 / -1;
        }

        label{
            margin:0 0 7px;
            font-size:13px;
            font-weight:800;
            color:#314765;
            letter-spacing:.2px;
            text-transform:uppercase;
        }

        select, button{
            width:100%;
            min-height:46px;
            border-radius:10px;
            border:1px solid #ccd7e6;
            background:#fff;
            color:#243955;
            padding:10px 12px;
            font-size:14px;
        }

        select:focus, button:focus{
            outline:none;
            border-color:#7f9cf5;
            box-shadow:0 0 0 3px rgba(99,102,241,.12);
        }

        .button-primary{
            border:none;
            color:#fff;
            font-weight:800;
            cursor:pointer;
            background:linear-gradient(90deg, var(--blue), var(--blue2));
        }

        .hint{
            margin-top:8px;
            font-size:12px;
            color:var(--muted);
        }

        .hint a{
            color:#2f66dd;
            text-decoration:none;
            font-weight:600;
        }

        .table-wrap{
    background:#ffffff;
    border:1px solid var(--line);
    border-radius:16px;
    padding:12px;
}

table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    background:#fff;
}

th, td{
    padding:11px 10px;
    border-bottom:1px solid #e7edf6;
    font-size:14px;
    vertical-align:top;
}

th{
    background:#f6f9fd;
    text-align:left;
    color:#304866;
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.2px;
}

thead th{
    background:#eef4ff;
}

thead th:first-child{
    border-top-left-radius:12px;
    border-bottom-left-radius:12px;
    padding-left:14px;
}

thead th:last-child{
    border-top-right-radius:12px;
    border-bottom-right-radius:12px;
    padding-right:14px;
}

tbody td:first-child{
    padding-left:14px;
}

tbody td:last-child{
    padding-right:14px;
}

tbody tr:last-child td{
    border-bottom:none;
}

        .actions a{
            text-decoration:none;
            font-weight:700;
        }

        .actions a:first-child{
            color:#2f66dd;
        }

        .actions a:last-child{
            color:var(--danger);
        }

        .muted{
            color:var(--muted);
        }

        .program-badge{
            display:inline-block;
            padding:4px 9px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            background:#eef3ff;
            color:#2f66dd;
        }

        @media (max-width: 980px){
            .grid{
                grid-template-columns:1fr;
            }
            .form-grid{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <a class="back" href="dashboard.php">← Volver al dashboard</a>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice">Guardado correctamente.</div>
    <?php endif; ?>

    <div class="grid">
        <section class="card">
            <div class="card-header">
                <h2>🎓 Asignaciones de estudiantes</h2>
            </div>

            <div class="card-body">
                <form method="post" id="assignmentForm">
                    <input type="hidden" name="edit_id" value="<?= h((string) ($editRecord['id'] ?? '')) ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="student_id">Estudiante</label>
                            <select name="student_id" id="student_id" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($students as $student): ?>
                                    <?php $sid = (string) ($student['id'] ?? ''); ?>
                                    <option value="<?= h($sid) ?>" <?= $sid === (string) ($editRecord['student_id'] ?? '') ? 'selected' : '' ?>>
                                        <?= h((string) ($student['name'] ?? 'Sin nombre')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="teacher_id">Docente</label>
                            <select name="teacher_id" id="teacher_id" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                                    <option value="<?= h($tid) ?>" <?= $tid === (string) ($editRecord['teacher_id'] ?? '') ? 'selected' : '' ?>>
                                        <?= h((string) ($teacher['name'] ?? 'Sin nombre')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="program">Programa</label>
                            <select name="program" id="program" required>
                                <?php foreach ($programOptions as $programKey => $programLabel): ?>
                                    <option value="<?= h($programKey) ?>" <?= $programKey === $selectedProgram ? 'selected' : '' ?>>
                                        <?= h($programLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field" id="courseField">
                            <label for="course_id" id="courseLabel">Nivel</label>
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
                                Configurar estructura:
                                <a href="english_structure_levels.php">Levels</a>
                                <?php if ($englishFromDb && !empty($englishLevels)): ?>
                                    | <a href="english_structure_phases.php?level=<?= h((string) ($englishLevels[0]['id'] ?? '1')) ?>">Phases</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="field" id="unitField">
                            <label for="unit_id">Unidad</label>
                            <select name="unit_id" id="unit_id" required>
                                <option value="">Seleccionar...</option>
                            </select>
                        </div>

                        <div class="field full">
                            <button class="button-primary" type="submit">Guardar asignación</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>📋 Asignaciones creadas</h2>
            </div>

            <div class="card-body">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Estudiante</th>
                    <th>Docente</th>
                    <th>Programa</th>
                    <th>Nivel / Semestre</th>
                    <th>Fase</th>
                    <th>Unidad</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                    <tbody>
                    <?php if (empty($studentAssignments)): ?>
                        <tr>
                            <td colspan="7" class="muted">No hay asignaciones registradas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($studentAssignments as $row): ?>
                            <?php
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
                            ?>
                            <tr>
                                <td><?= h(find_name_by_id($students, (string) ($row['student_id'] ?? ''), 'N/D')) ?></td>
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

const courseField = document.getElementById('courseField');
const phaseField = document.getElementById('phaseField');
const unitField = document.getElementById('unitField');
const courseLabel = document.getElementById('courseLabel');
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

function setEnglishLayout() {
    courseLabel.textContent = 'Nivel';
    phaseField.style.display = '';
    englishHint.style.display = '';
    phaseSelect.disabled = false;
    phaseSelect.required = true;
}

function setTechnicalLayout() {
    courseLabel.textContent = 'Semestre';
    phaseField.style.display = 'none';
    englishHint.style.display = 'none';
    phaseSelect.value = '';
    phaseSelect.disabled = true;
    phaseSelect.required = false;
}

function refreshForm(useInitial = false) {
    const program = programSelect.value || 'english';

    if (program === 'english') {
        setEnglishLayout();

        const currentLevel = useInitial ? selectedCourse : (courseSelect.value || '');
        fillSelect(courseSelect, englishLevels, currentLevel, 'Seleccionar nivel...');

        const levelId = courseSelect.value || currentLevel || '';
        const phasesForLevel = englishPhases.filter((phase) => String(phase.level_id ?? '') === String(levelId));
        const currentPhase = useInitial ? selectedPhase : (phaseSelect.value || '');
        fillSelect(phaseSelect, phasesForLevel, currentPhase, 'Seleccionar fase...');

        const phaseId = phaseSelect.value || currentPhase || '';
        const unitsForPhase = englishUnits.filter((unit) => String(unit.phase_id ?? '') === String(phaseId));
        const currentUnit = useInitial ? selectedUnit : (unitSelect.value || '');
        fillSelect(unitSelect, unitsForPhase, currentUnit, 'Seleccionar unidad...');
    } else {
        setTechnicalLayout();

        const currentSemester = useInitial ? selectedCourse : (courseSelect.value || '');
        fillSelect(courseSelect, technicalSemesters, currentSemester, 'Seleccionar semestre...');

        const semesterId = courseSelect.value || currentSemester || '';
        const unitsForSemester = technicalUnits.filter((unit) => {
            return String(unit.course_id ?? unit.level_id ?? '') === String(semesterId);
        });

        const currentUnit = useInitial ? selectedUnit : (unitSelect.value || '');
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

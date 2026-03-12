<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

/* ===============================
   HELPERS
=============================== */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

function generate_id(string $prefix = 'id_'): string
{
    try {
        return $prefix . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return $prefix . str_replace('.', '', uniqid('', true));
    }
}

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute(['table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

function load_profiled_teachers_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $hasIsActive = column_exists($pdo, 'teacher_accounts', 'is_active');

        $sql = "
            SELECT DISTINCT teacher_id AS id, teacher_name AS name
            FROM teacher_accounts
        ";

        if ($hasIsActive) {
            $sql .= " WHERE COALESCE(is_active, true) = true ";
        }

        $sql .= " ORDER BY teacher_name ASC, teacher_id ASC ";

        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_technical_courses_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_english_targets_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT ph.id, CONCAT(l.name, ' - ', ph.name) AS name
            FROM english_phases ph
            INNER JOIN english_levels l ON l.id = ph.level_id
            ORDER BY l.id ASC, ph.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_technical_units_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    $candidates = [
        ['table' => 'course_units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'technical_units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'technical_units', 'course_column' => 'semester_id', 'name_column' => 'name'],
        ['table' => 'units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'units', 'course_column' => 'semester_id', 'name_column' => 'name'],
    ];

    foreach ($candidates as $candidate) {
        $table = $candidate['table'];
        $courseColumn = $candidate['course_column'];
        $nameColumn = $candidate['name_column'];

        if (
            table_exists($pdo, $table) &&
            column_exists($pdo, $table, 'id') &&
            column_exists($pdo, $table, $courseColumn) &&
            column_exists($pdo, $table, $nameColumn)
        ) {
            try {
                $sql = "
                    SELECT
                        id,
                        {$courseColumn} AS course_id,
                        {$nameColumn} AS name
                    FROM {$table}
                    ORDER BY {$courseColumn} ASC, id ASC
                ";
                $stmt = $pdo->query($sql);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        }
    }

    return [];
}

function load_teacher_assignments_from_database(?string $teacherId = null): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $sql = "
            SELECT id, teacher_id, teacher_name, program_type, course_id, course_name, unit_id, unit_name, updated_at
            FROM teacher_assignments
        ";

        $params = [];
        if ($teacherId !== null && $teacherId !== '') {
            $sql .= " WHERE teacher_id = :teacher_id ";
            $params['teacher_id'] = $teacherId;
        }

        $sql .= "
            ORDER BY teacher_name ASC,
                     program_type ASC,
                     course_name ASC,
                     COALESCE(unit_name, '') ASC,
                     updated_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function save_assignment_to_database(array $record, ?string &$errorMessage = null): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        $errorMessage = 'No hay conexión con la base de datos.';
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO teacher_assignments (
                id, teacher_id, teacher_name, program_type, course_id, course_name, unit_id, unit_name, updated_at
            ) VALUES (
                :id, :teacher_id, :teacher_name, :program_type, :course_id, :course_name, :unit_id, :unit_name, :updated_at
            )
            ON CONFLICT DO NOTHING
        ");

        return $stmt->execute([
            'id' => (string) ($record['id'] ?? generate_id('asg_')),
            'teacher_id' => (string) ($record['teacher_id'] ?? ''),
            'teacher_name' => (string) ($record['teacher_name'] ?? ''),
            'program_type' => (string) ($record['program_type'] ?? ''),
            'course_id' => (string) ($record['course_id'] ?? ''),
            'course_name' => (string) ($record['course_name'] ?? ''),
            'unit_id' => $record['unit_id'] !== null && $record['unit_id'] !== '' ? (string) $record['unit_id'] : null,
            'unit_name' => $record['unit_name'] !== null && $record['unit_name'] !== '' ? (string) $record['unit_name'] : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        return false;
    }
}

function delete_assignment_from_database(string $id): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo || $id === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM teacher_assignments WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    } catch (Throwable $e) {
        return false;
    }
}

function delete_teacher_assignments_from_database(string $teacherId): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM teacher_assignments WHERE teacher_id = :teacher_id");
        return $stmt->execute(['teacher_id' => $teacherId]);
    } catch (Throwable $e) {
        return false;
    }
}

function find_teacher_name_by_id(array $teachers, string $teacherId): string
{
    foreach ($teachers as $teacher) {
        if ((string) ($teacher['id'] ?? '') === $teacherId) {
            return (string) ($teacher['name'] ?? 'Docente');
        }
    }
    return '';
}

function database_is_available(): bool
{
    return get_pdo_connection() instanceof PDO;
}

function build_map(array $items): array
{
    $map = [];
    foreach ($items as $item) {
        $map[(string) ($item['id'] ?? '')] = (string) ($item['name'] ?? '');
    }
    return $map;
}

/* ===============================
   CARGA INICIAL
=============================== */
$teachers = load_profiled_teachers_from_database();
$technicalCourses = load_technical_courses_from_database();
$englishTargets = load_english_targets_from_database();
$technicalUnits = load_technical_units_from_database();

$errors = [];

if (!database_is_available()) {
    $errors[] = 'No hay conexión a la base de datos. Revise la variable de entorno DATABASE_URL y el archivo config/db.php.';
}

$form = [
    'teacher_id' => '',
    'program_type' => 'technical',
    'english_course_ids' => [],
    'technical_course_id' => '',
    'technical_unit_ids' => [],
];

/* ===============================
   ELIMINAR ASIGNACION
=============================== */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    delete_assignment_from_database((string) $_GET['delete']);
    header('Location: teacher_assignments.php?saved=1');
    exit;
}

/* ===============================
   ELIMINAR TODO DOCENTE
=============================== */
if (isset($_GET['delete_teacher']) && $_GET['delete_teacher'] !== '') {
    delete_teacher_assignments_from_database((string) $_GET['delete_teacher']);
    header('Location: teacher_assignments.php?saved=1');
    exit;
}

/* ===============================
   MODO EDICION POR DOCENTE
=============================== */
if (isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '') {
    $form['teacher_id'] = (string) $_GET['teacher_id'];
}

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['teacher_id'] = trim((string) ($_POST['teacher_id'] ?? ''));
    $form['program_type'] = trim((string) ($_POST['program_type'] ?? 'technical'));

    $postedEnglish = $_POST['english_course_ids'] ?? [];
    $form['english_course_ids'] = is_array($postedEnglish)
        ? array_values(array_filter(array_map('strval', $postedEnglish), static fn ($v): bool => trim($v) !== ''))
        : [];

    $form['technical_course_id'] = trim((string) ($_POST['technical_course_id'] ?? ''));

    $postedUnits = $_POST['technical_unit_ids'] ?? [];
    $form['technical_unit_ids'] = is_array($postedUnits)
        ? array_values(array_filter(array_map('strval', $postedUnits), static fn ($v): bool => trim($v) !== ''))
        : [];

    if ($form['program_type'] !== 'technical' && $form['program_type'] !== 'english') {
        $form['program_type'] = 'technical';
    }

    if ($form['teacher_id'] === '') {
        $errors[] = 'Debe seleccionar un docente.';
    }

    $teacherName = find_teacher_name_by_id($teachers, $form['teacher_id']);
    if ($teacherName === '') {
        $errors[] = 'El docente seleccionado no tiene perfil creado.';
    }

    $technicalMap = build_map($technicalCourses);
    $englishMap = build_map($englishTargets);

    $unitsByCourse = [];
    $unitNameMap = [];
    foreach ($technicalUnits as $unit) {
        $courseId = (string) ($unit['course_id'] ?? '');
        $unitId = (string) ($unit['id'] ?? '');
        $unitName = (string) ($unit['name'] ?? 'Unidad');
        if ($courseId === '' || $unitId === '') {
            continue;
        }
        $unitsByCourse[$courseId][] = $unit;
        $unitNameMap[$unitId] = $unitName;
    }

    if ($form['program_type'] === 'english' && empty($form['english_course_ids'])) {
        $errors[] = 'Debe seleccionar al menos un curso de inglés.';
    }

    if ($form['program_type'] === 'technical' && $form['technical_course_id'] === '') {
        $errors[] = 'Debe seleccionar un semestre técnico.';
    }

    if ($form['program_type'] === 'technical' && empty($form['technical_unit_ids'])) {
        $errors[] = 'Debe seleccionar al menos una unidad técnica.';
    }

    if (empty($errors)) {
        $saved = true;
        $dbError = null;
        $processedAssignments = 0;

        if ($form['program_type'] === 'english') {
            foreach ($form['english_course_ids'] as $courseId) {
                $courseId = trim((string) $courseId);
                $courseName = $englishMap[$courseId] ?? '';

                if ($courseId === '' || $courseName === '') {
                    continue;
                }

                $record = [
                    'id' => generate_id('asg_'),
                    'teacher_id' => $form['teacher_id'],
                    'teacher_name' => $teacherName,
                    'program_type' => 'english',
                    'course_id' => $courseId,
                    'course_name' => $courseName,
                    'unit_id' => null,
                    'unit_name' => null,
                ];

                if (!save_assignment_to_database($record, $dbError)) {
                    $saved = false;
                    break;
                }

                $processedAssignments++;
            }
        } else {
            $courseId = $form['technical_course_id'];
            $courseName = $technicalMap[$courseId] ?? '';

            foreach ($form['technical_unit_ids'] as $unitId) {
                $unitId = trim((string) $unitId);
                $unitName = $unitNameMap[$unitId] ?? '';

                if ($courseId === '' || $courseName === '' || $unitId === '' || $unitName === '') {
                    continue;
                }

                $record = [
                    'id' => generate_id('asg_'),
                    'teacher_id' => $form['teacher_id'],
                    'teacher_name' => $teacherName,
                    'program_type' => 'technical',
                    'course_id' => $courseId,
                    'course_name' => $courseName,
                    'unit_id' => $unitId,
                    'unit_name' => $unitName,
                ];

                if (!save_assignment_to_database($record, $dbError)) {
                    $saved = false;
                    break;
                }

                $processedAssignments++;
            }
        }

        if ($saved && $processedAssignments > 0) {
            header('Location: teacher_assignments.php?saved=1&teacher_id=' . urlencode($form['teacher_id']));
            exit;
        }

        if ($saved && $processedAssignments === 0) {
            $errors[] = 'No se guardaron asignaciones válidas. Revise que el curso y las unidades existan en la estructura académica.';
        } else {
            $errors[] = 'No se pudieron guardar las asignaciones.';
        }
        if (!empty($dbError)) {
            $errors[] = 'Detalle técnico: ' . $dbError;
        }
    }
}

$allAssignments = load_teacher_assignments_from_database();
$currentTeacherAssignments = $form['teacher_id'] !== ''
    ? load_teacher_assignments_from_database($form['teacher_id'])
    : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Asignación de Docentes</title>
<style>
:root{
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --subtitle:#2c3e50;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --head:#f7faff;
    --success-bg:#ecfdf3;
    --success-border:#b9eacb;
    --success-text:#166534;
    --error-bg:#fff2f2;
    --error-border:#f3b5b5;
    --error-text:#9f1d1d;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --radius:14px;
}
*{box-sizing:border-box;}
body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    padding:32px 20px;
}
.page-shell{width:100%;display:flex;justify-content:center;}
.wrapper{width:100%;max-width:1100px;margin:0 auto;}
.topbar{display:flex;flex-direction:column;align-items:flex-start;gap:12px;margin-bottom:20px;}
.back{
    display:inline-flex;align-items:center;gap:6px;color:var(--blue);text-decoration:none;font-weight:700;font-size:14px;
}
.links{display:flex;gap:10px;flex-wrap:wrap;}
.links a{
    display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:10px 14px;border-radius:10px;
    text-decoration:none;font-weight:700;color:#fff;background:var(--blue);transition:background .2s ease;
}
.links a:hover{background:var(--blue-hover);}
.card{
    background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
    box-shadow:var(--shadow);padding:24px;margin-bottom:18px;
}
.card-header{margin-bottom:18px;}
.card-header h1,.card-header h2{margin:0;color:var(--subtitle);line-height:1.2;}
.card-header h1{font-size:22px;font-weight:700;}
.card-header h2{font-size:20px;font-weight:700;}
.subtitle{margin:10px 0 0;font-size:14px;color:var(--muted);line-height:1.5;}
.notice{
    padding:12px 14px;border-radius:10px;background:var(--success-bg);border:1px solid var(--success-border);
    color:var(--success-text);margin-bottom:16px;font-size:14px;font-weight:600;
}
.error{
    padding:12px 14px;border-radius:10px;background:var(--error-bg);border:1px solid var(--error-border);
    color:var(--error-text);margin-bottom:16px;font-size:14px;
}
.error div + div{margin-top:6px;}
.form-grid{display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:14px;}
.field{display:flex;flex-direction:column;}
.field.full{grid-column:1 / -1;}
label{
    font-size:12px;font-weight:700;color:var(--text);margin:0 0 8px;text-transform:uppercase;letter-spacing:.3px;
}
input,select,button{
    width:100%;min-height:44px;border-radius:10px;border:1px solid #c9d4e3;background:#fff;color:var(--text);font:inherit;padding:10px 12px;
}
select[multiple]{min-height:160px;}
input:focus,select:focus,button:focus{
    outline:none;border-color:#7d9dff;box-shadow:0 0 0 3px rgba(70,96,220,.10);
}
.button-primary{border:none;background:var(--blue);color:#fff;font-weight:700;cursor:pointer;transition:background .2s ease;}
.button-primary:hover{background:var(--blue-hover);}
.helper-text{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.4;}
.table-wrap{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;}
.table-scroll{width:100%;overflow-x:auto;}
table{width:100%;min-width:980px;border-collapse:separate;border-spacing:0;}
thead th{
    background:var(--head);color:var(--text);font-size:12px;font-weight:700;text-transform:uppercase;
    letter-spacing:.25px;text-align:left;padding:12px 14px;border-bottom:1px solid var(--line);
}
tbody td{
    padding:12px 14px;border-bottom:1px solid #e7edf6;font-size:14px;color:#27415f;vertical-align:top;
}
tbody tr:last-child td{border-bottom:none;}
.badge{
    display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;margin:2px 4px 2px 0;
}
.badge-program-technical{background:#eef4ff;color:#1f66cc;}
.badge-program-english{background:#fff3e8;color:#b45309;}
.badge-unit{background:#eef8f2;color:#1d6a40;}
.actions{display:flex;gap:8px;flex-wrap:wrap;}
.action-btn{
    display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:999px;
    font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;transition:all .2s ease;
}
.edit-btn{background:#e8f0ff;color:#1f66cc;}
.edit-btn:hover{background:#dbeafe;}
.delete-btn{background:#fee2e2;color:#b91c1c;}
.delete-btn:hover{background:#fecaca;}
.empty-row{color:var(--muted);}
.list-stack{display:flex;flex-direction:column;gap:10px;}
.assignment-chip{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:#f8fbff;
}
.assignment-chip-left{display:flex;flex-wrap:wrap;align-items:center;gap:8px;}
.assignment-chip-right a{
    text-decoration:none;font-size:12px;font-weight:700;color:#b91c1c;
}
@media (max-width:768px){
    body{padding:20px 14px;}
    .wrapper{max-width:100%;}
    .card{padding:18px;}
    .form-grid{grid-template-columns:1fr;}
    .card-header h1{font-size:20px;}
    .card-header h2{font-size:18px;}
}
</style>
</head>
<body>
<div class="page-shell">
    <div class="wrapper">
        <div class="topbar">
            <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
            <div class="links">
                <a href="teacher_profiles.php">Perfiles docentes</a>
                <a href="teacher_groups.php">Ver Docentes y Grupos</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h1>📚 Asignación de docentes</h1>
                <p class="subtitle">English: asigna cursos completos. Técnico: asigna semestre y unidades específicas.</p>
            </div>

            <?php if (isset($_GET['saved']) && empty($errors)) { ?>
                <div class="notice">Asignaciones guardadas, editadas o eliminadas correctamente.</div>
            <?php } ?>

            <?php if (!empty($errors)) { ?>
                <div class="error">
                    <?php foreach ($errors as $error) { ?>
                        <div>• <?php echo h($error); ?></div>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="post" class="form-grid">
                <div class="field">
                    <label for="teacher_id">Docente</label>
                    <select name="teacher_id" id="teacher_id" required>
                        <option value="">Seleccione docente con perfil</option>
                        <?php foreach ($teachers as $teacher) { ?>
                            <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                            <option value="<?php echo h($tid); ?>" <?php echo $tid === $form['teacher_id'] ? 'selected' : ''; ?>>
                                <?php echo h((string) ($teacher['name'] ?? 'Docente')); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="field">
                    <label for="program_type">Programa</label>
                    <select name="program_type" id="program_type" required>
                        <option value="technical" <?php echo $form['program_type'] === 'technical' ? 'selected' : ''; ?>>Programa técnico</option>
                        <option value="english" <?php echo $form['program_type'] === 'english' ? 'selected' : ''; ?>>Programa English</option>
                    </select>
                </div>

                <div class="field full" id="englishBlock" style="display:none;">
                    <label for="english_course_ids">Cursos / niveles English</label>
                    <select name="english_course_ids[]" id="english_course_ids" multiple>
                        <?php foreach ($englishTargets as $item) { ?>
                            <?php $eid = (string) ($item['id'] ?? ''); ?>
                            <option value="<?php echo h($eid); ?>" <?php echo in_array($eid, $form['english_course_ids'], true) ? 'selected' : ''; ?>>
                                <?php echo h((string) ($item['name'] ?? 'Curso')); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <div class="helper-text">Cada curso English se guarda completo con todas sus unidades.</div>
                </div>

                <div class="field" id="technicalCourseBlock">
                    <label for="technical_course_id">Semestre técnico</label>
                    <select name="technical_course_id" id="technical_course_id">
                        <option value="">Seleccione semestre</option>
                        <?php foreach ($technicalCourses as $item) { ?>
                            <?php $cid = (string) ($item['id'] ?? ''); ?>
                            <option value="<?php echo h($cid); ?>" <?php echo $cid === $form['technical_course_id'] ? 'selected' : ''; ?>>
                                <?php echo h((string) ($item['name'] ?? 'Semestre')); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="field" id="technicalUnitsBlock">
                    <label for="technical_unit_ids">Unidades técnicas</label>
                    <select name="technical_unit_ids[]" id="technical_unit_ids" multiple>
                    </select>
                    <div class="helper-text">Puedes volver a guardar para agregar más unidades o más semestres al mismo docente.</div>
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">Guardar asignación</button>
                </div>
            </form>
        </div>

        <?php if ($form['teacher_id'] !== '') { ?>
        <div class="card">
            <div class="card-header">
                <h2>Asignaciones actuales del docente</h2>
            </div>

            <?php if (empty($currentTeacherAssignments)) { ?>
                <div class="helper-text">Este docente todavía no tiene asignaciones.</div>
            <?php } else { ?>
                <div class="list-stack">
                    <?php foreach ($currentTeacherAssignments as $assignment) { ?>
                        <?php
                            $program = (string) ($assignment['program_type'] ?? '');
                            $courseName = (string) ($assignment['course_name'] ?? '');
                            $unitName = (string) ($assignment['unit_name'] ?? '');
                        ?>
                        <div class="assignment-chip">
                            <div class="assignment-chip-left">
                                <span class="badge <?php echo $program === 'english' ? 'badge-program-english' : 'badge-program-technical'; ?>">
                                    <?php echo h($program === 'english' ? 'English' : 'Técnico'); ?>
                                </span>
                                <span class="badge"><?php echo h($courseName); ?></span>
                                <?php if ($unitName !== '') { ?>
                                    <span class="badge badge-unit"><?php echo h($unitName); ?></span>
                                <?php } else { ?>
                                    <span class="badge badge-unit">Curso completo</span>
                                <?php } ?>
                            </div>
                            <div class="assignment-chip-right">
                                <a href="teacher_assignments.php?teacher_id=<?php echo h($form['teacher_id']); ?>&delete=<?php echo h((string) ($assignment['id'] ?? '')); ?>" onclick="return confirm('¿Eliminar esta asignación?')">Eliminar</a>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <div style="margin-top:14px;">
                    <a class="action-btn delete-btn" href="teacher_assignments.php?delete_teacher=<?php echo h($form['teacher_id']); ?>" onclick="return confirm('¿Eliminar todas las asignaciones de este docente?')">Eliminar todas las asignaciones del docente</a>
                </div>
            <?php } ?>
        </div>
        <?php } ?>

        <div class="card">
            <div class="card-header">
                <h2>Todas las asignaciones</h2>
            </div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Docente</th>
                                <th>Programa</th>
                                <th>Curso / Semestre</th>
                                <th>Unidad</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($allAssignments)) { ?>
                            <tr>
                                <td colspan="5" class="empty-row">No hay asignaciones registradas todavía.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($allAssignments as $assignment) { ?>
                                <?php $program = (string) ($assignment['program_type'] ?? ''); ?>
                                <tr>
                                    <td><?php echo h((string) ($assignment['teacher_name'] ?? 'Docente')); ?></td>
                                    <td>
                                        <span class="badge <?php echo $program === 'english' ? 'badge-program-english' : 'badge-program-technical'; ?>">
                                            <?php echo h($program === 'english' ? 'English' : 'Técnico'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo h((string) ($assignment['course_name'] ?? '')); ?></td>
                                    <td><?php echo h((string) ($assignment['unit_name'] ?? 'Curso completo')); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a class="action-btn edit-btn" href="teacher_assignments.php?teacher_id=<?php echo h((string) ($assignment['teacher_id'] ?? '')); ?>">Editar docente</a>
                                            <a class="action-btn delete-btn" href="teacher_assignments.php?teacher_id=<?php echo h((string) ($assignment['teacher_id'] ?? '')); ?>&delete=<?php echo h((string) ($assignment['id'] ?? '')); ?>" onclick="return confirm('¿Eliminar esta asignación?')">Eliminar</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const technicalUnits = <?php echo json_encode(array_values($technicalUnits), JSON_UNESCAPED_UNICODE); ?>;
const programType = document.getElementById('program_type');
const englishBlock = document.getElementById('englishBlock');
const technicalCourseBlock = document.getElementById('technicalCourseBlock');
const technicalUnitsBlock = document.getElementById('technicalUnitsBlock');
const technicalCourseId = document.getElementById('technical_course_id');
const technicalUnitIds = document.getElementById('technical_unit_ids');
const preselectedTechnicalUnitIds = <?php echo json_encode(array_values($form['technical_unit_ids']), JSON_UNESCAPED_UNICODE); ?>;

function toggleProgramBlocks() {
    const value = programType ? programType.value : 'technical';
    const isEnglish = value === 'english';

    if (englishBlock) englishBlock.style.display = isEnglish ? 'block' : 'none';
    if (technicalCourseBlock) technicalCourseBlock.style.display = isEnglish ? 'none' : 'block';
    if (technicalUnitsBlock) technicalUnitsBlock.style.display = isEnglish ? 'none' : 'block';
}

function renderTechnicalUnits() {
    if (!technicalCourseId || !technicalUnitIds) {
        return;
    }

    const courseId = String(technicalCourseId.value || '');
    technicalUnitIds.innerHTML = '';

    technicalUnits
        .filter(item => String(item.course_id || '') === courseId)
        .forEach(item => {
            const option = document.createElement('option');
            const value = String(item.id || '');
            option.value = value;
            option.textContent = String(item.name || 'Unidad');

            if (Array.isArray(preselectedTechnicalUnitIds) && preselectedTechnicalUnitIds.includes(value)) {
                option.selected = true;
            }

            technicalUnitIds.appendChild(option);
        });
}

if (programType) {
    toggleProgramBlocks();
    programType.addEventListener('change', toggleProgramBlocks);
}

if (technicalCourseId) {
    renderTechnicalUnits();
    technicalCourseId.addEventListener('change', renderTechnicalUnits);
}
</script>
</body>
</html>

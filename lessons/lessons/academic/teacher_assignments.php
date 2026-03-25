<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

/* ===============================
   HELPERS
   =============================== */

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_pdo_connection(): ?PDO {
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

function generate_id(string $prefix = 'id_'): string {
    try {
        return $prefix . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return $prefix . str_replace('.', '', uniqid('', true));
    }
}

function table_exists(PDO $pdo, string $tableName): bool {
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

function column_exists(PDO $pdo, string $tableName, string $columnName): bool {
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

/* ===============================
   CARGAS DESDE DB
   =============================== */

function load_profiled_teachers_from_database(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $hasIsActive = column_exists($pdo, 'teacher_accounts', 'is_active');

        $sql = "SELECT DISTINCT teacher_id AS id, teacher_name AS name FROM teacher_accounts";
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

function load_technical_courses_from_database(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
            FROM courses
            WHERE LOWER(COALESCE(program_id::text, '')) IN (
                '1','prog_technical','technical','prog_tecnico','tecnico','programa_tecnico'
            )
            OR LOWER(COALESCE(name, '')) LIKE '%semestre%'
            ORDER BY id ASC, name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_english_targets_from_database(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT ph.id, ph.name AS phase_name, l.id AS level_id, l.name AS level_name,
                   CONCAT(l.name, ' - ', ph.name) AS name
            FROM english_phases ph
            INNER JOIN english_levels l ON l.id = ph.level_id
            ORDER BY l.id ASC, ph.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_technical_units_from_database(): array {
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
                    SELECT id, {$courseColumn} AS course_id, {$nameColumn} AS name
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

function load_teacher_assignments_from_database(?string $teacherId = null): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $sql = "SELECT id, teacher_id, teacher_name, program_type, course_id, course_name, unit_id, unit_name, updated_at FROM teacher_assignments";
        $params = [];

        if ($teacherId !== null && $teacherId !== '') {
            $sql .= " WHERE teacher_id = :teacher_id ";
            $params['teacher_id'] = $teacherId;
        }

        $sql .= " ORDER BY teacher_name ASC, program_type ASC, course_name ASC, COALESCE(unit_name, '') ASC, updated_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function save_assignment_to_database(array $record, ?string &$errorMessage = null): bool {
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

/* ===============================
   ELIMINAR
   =============================== */

function delete_assignment_from_database(string $id): bool {
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

function delete_teacher_assignments_from_database(string $teacherId): bool {
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

function find_teacher_name_by_id(array $teachers, string $teacherId): string {
    foreach ($teachers as $teacher) {
        if ((string) ($teacher['id'] ?? '') === $teacherId) {
            return (string) ($teacher['name'] ?? 'Docente');
        }
    }
    return '';
}

function database_is_available(): bool {
    return get_pdo_connection() instanceof PDO;
}

function build_map(array $items): array {
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
    $teacherIdReturn = isset($_GET['teacher_id']) ? (string) $_GET['teacher_id'] : '';
    delete_assignment_from_database((string) $_GET['delete']);

    $url = 'teacher_assignments.php?saved=1';
    if ($teacherIdReturn !== '') {
        $url .= '&teacher_id=' . urlencode($teacherIdReturn);
    }

    header('Location: ' . $url);
    exit;
}

/* ===============================
   ELIMINAR TODO DOCENTE
   =============================== */
if (isset($_GET['delete_teacher']) && $_GET['delete_teacher'] !== '') {
    delete_teacher_assignments_from_database((string) $_GET['delete_teacher']);
    header('Location: teacher_assignments.php?saved=1&teacher_id=' . urlencode((string) $_GET['delete_teacher']));
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

    $unitNameMap = [];
    foreach ($technicalUnits as $unit) {
        $unitId = (string) ($unit['id'] ?? '');
        $unitName = (string) ($unit['name'] ?? 'Unidad');
        if ($unitId !== '') {
            $unitNameMap[$unitId] = $unitName;
        }
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

        $returnToProfiles = isset($_GET['from_profile']) && $_GET['from_profile'] === '1';

        if ($saved && $processedAssignments > 0) {
            if ($returnToProfiles) {
                header('Location: teacher_profiles.php?from_assignments=1');
                exit;
            }

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
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --subtitle:#2a5136;
    --muted:#5d7465;
    --blue:#2f9e44;
    --blue-hover:#237a35;
    --orange:#b45309;
    --green:#166534;
    --head:#f3fbf5;
    --success-bg:#ecfdf3;
    --success-border:#b9eacb;
    --success-text:#166534;
    --error-bg:#fff2f2;
    --error-border:#f3b5b5;
    --error-text:#9f1d1d;
    --danger:#dc2626;
    --danger-hover:#b91c1c;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --radius:14px;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    padding:30px;
    font-family:Arial, "Segoe UI", Roboto, sans-serif;
    background:var(--bg);
    color:var(--text);
    font-size:15px;
}

a{
    color:var(--blue);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.page-shell{
    width:100%;
}

.wrapper{
    max-width:1120px;
    margin:0 auto;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:22px;
    flex-wrap:wrap;
}

.back{
    font-weight:700;
    color:var(--blue);
}

.links{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}

.links a{
    font-size:14px;
    font-weight:600;
}

.card{
    background:var(--card);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:22px;
    margin-bottom:20px;
    border:1px solid var(--line);
}

.card-header{
    margin-bottom:18px;
}

.card-header h1{
    margin:0 0 10px;
    font-size:28px;
    line-height:1.2;
    color:#1f3c75;
}

.card-header h2{
    margin:0;
    font-size:22px;
    line-height:1.3;
    color:var(--subtitle);
}

.subtitle{
    margin:6px 0 0;
    color:var(--muted);
    font-size:14px;
    line-height:1.5;
}

.notice{
    margin-bottom:16px;
    padding:12px 14px;
    border-radius:10px;
    background:var(--success-bg);
    border:1px solid var(--success-border);
    color:var(--success-text);
    font-size:14px;
    font-weight:600;
}

.error{
    margin-bottom:16px;
    padding:12px 14px;
    border-radius:10px;
    background:var(--error-bg);
    border:1px solid var(--error-border);
    color:var(--error-text);
    font-size:14px;
    line-height:1.5;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(260px, 1fr));
    gap:16px;
}

.field{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.field.full{
    grid-column:1 / -1;
}

label{
    font-size:14px;
    font-weight:700;
    color:var(--text);
}

input[type="text"],
select{
    width:100%;
    min-height:44px;
    border:1px solid var(--line);
    border-radius:10px;
    padding:10px 12px;
    font-size:14px;
    color:var(--text);
    background:#fff;
    outline:none;
    transition:border-color .2s ease, box-shadow .2s ease;
}

input[type="text"]:focus,
select:focus{
    border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(31,102,204,.12);
}

select[multiple]{
    min-height:150px;
}

.helper-text{
    color:var(--muted);
    font-size:14px;
    line-height:1.5;
}

.button-primary{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:44px;
    padding:10px 16px;
    border:none;
    border-radius:10px;
    background:var(--blue);
    color:#fff;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    transition:background .2s ease, transform .06s ease;
}

.button-primary:hover{
    background:var(--blue-hover);
    text-decoration:none;
}

.button-primary:active{
    transform:translateY(1px);
}

.badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:#eef7f0;
    color:#2f9e44;
    white-space:nowrap;
}

.badge-program-english{
    background:#fff3e8;
    color:var(--orange);
}

.badge-program-technical{
    background:#eef7f0;
    color:#2f9e44;
}

.badge-unit{
    background:#eef8f2;
    color:var(--green);
}

.list-stack{
    display:flex;
    flex-direction:column;
    gap:12px;
}

.assignment-chip{
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    padding:14px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#f7fcf8;
    flex-wrap:wrap;
}

.assignment-chip-left{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.assignment-chip-right a{
    font-size:13px;
    font-weight:700;
    color:var(--danger);
}

.table-wrap{
    width:100%;
}

.table-scroll{
    overflow-x:auto;
    border:1px solid var(--line);
    border-radius:12px;
}

table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
    min-width:760px;
}

thead th{
    background:var(--head);
    color:var(--subtitle);
    font-size:13px;
    font-weight:700;
    text-align:left;
    padding:14px 16px;
    border-bottom:1px solid var(--line);
}

tbody td{
    padding:14px 16px;
    border-bottom:1px solid #d8e8dc;
    font-size:14px;
    vertical-align:top;
}

tbody tr:last-child td{
    border-bottom:none;
}

.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.action-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 12px;
    border-radius:8px;
    font-size:13px;
    font-weight:700;
    text-decoration:none;
    transition:background .2s ease, color .2s ease;
}

.edit-btn{
    background:#eef7f0;
    color:var(--blue);
    border:1px solid #b8dfc4;
}

.edit-btn:hover{
    background:#d4f0dc;
    text-decoration:none;
}

.delete-btn{
    background:#fff1f2;
    color:var(--danger);
    border:1px solid #fecdd3;
}

.delete-btn:hover{
    background:#ffe4e6;
    color:var(--danger-hover);
    text-decoration:none;
}

.empty-row{
    color:var(--muted);
    font-size:14px;
    text-align:center;
    padding:22px !important;
}

.block-section{
    padding:14px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#f3fbf5;
}

.block-title{
    margin:0 0 8px;
    font-size:15px;
    font-weight:700;
    color:var(--subtitle);
}

.check-groups{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.check-group-wrap{
    border:1px solid var(--line);
    border-radius:10px;
    padding:12px 14px;
    background:#fff;
}

.check-group-header{
    display:flex;
    align-items:center;
    gap:8px;
    cursor:pointer;
    margin-bottom:8px;
    font-size:14px;
}

.check-group-header input,
.check-item input{
    width:auto;
    min-height:auto;
    cursor:pointer;
    margin:0;
}

.select-all-label{
    color:var(--blue);
    font-size:12px;
    font-weight:600;
}

.check-group-items{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    padding-left:18px;
}

.check-item{
    display:flex;
    align-items:center;
    gap:6px;
    cursor:pointer;
    font-size:14px;
    padding:6px 10px;
    border-radius:8px;
    border:1px solid var(--line);
    background:#f7fcf8;
    flex-shrink:0;
}

.check-item:hover{
    background:#eef7f0;
    border-color:#b8dfc4;
}

.check-items-wrap{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    min-height:40px;
}

.btn-select-all{
    padding:6px 12px;
    border:1px solid var(--blue);
    border-radius:8px;
    color:var(--blue);
    background:#eef7f0;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    transition:background .15s ease;
}

.btn-select-all:hover{
    background:#d4f0dc;
}

.btn-deselect{
    border-color:var(--muted);
    color:var(--muted);
    background:#f5f6f7;
}

.btn-deselect:hover{
    background:#e9eaeb;
}

@media (max-width: 768px){
    body{
        padding:20px;
    }

    .card{
        padding:18px;
    }

    .card-header h1{
        font-size:24px;
    }

    .card-header h2{
        font-size:20px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .topbar{
        flex-direction:column;
        align-items:flex-start;
    }

    .links{
        width:100%;
        flex-direction:column;
        gap:8px;
    }

    .assignment-chip{
        flex-direction:column;
    }

    .actions{
        flex-direction:column;
        align-items:flex-start;
    }

    .action-btn{
        width:100%;
    }
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
                <p class="subtitle">Paso 2 de 2: asigna cursos completos (English) o semestre + unidades (Técnico).</p>
            </div>

            <?php if (isset($_GET['from_profile']) && $_GET['from_profile'] === '1') { ?>
                <div class="notice">Perfil creado. Ahora guarda al menos una asignación para finalizar y volver a Perfiles.</div>
            <?php } ?>

            <?php if (isset($_GET['saved'])) { ?>
                <div class="notice">Asignaciones actualizadas correctamente.</div>
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
                        <option value="">Seleccione un docente con perfil</option>
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
                        <option value="technical" <?php echo $form['program_type'] === 'technical' ? 'selected' : ''; ?>>Técnico</option>
                        <option value="english" <?php echo $form['program_type'] === 'english' ? 'selected' : ''; ?>>English</option>
                    </select>
                </div>

                <div class="field full block-section" id="englishBlock">
                    <div class="block-title">Cursos de inglés — selecciona por nivel o fase</div>
                    <div class="check-groups" id="englishCheckGroups">
                        <?php
                        $levelGroups = [];
                        foreach ($englishTargets as $target) {
                            $lvId   = (string)($target['level_id']   ?? $target['id']   ?? '');
                            $lvName = (string)($target['level_name'] ?? $target['name'] ?? 'Nivel');
                            if (!isset($levelGroups[$lvId])) {
                                $levelGroups[$lvId] = ['label' => $lvName, 'phases' => []];
                            }
                            $levelGroups[$lvId]['phases'][] = $target;
                        }
                        foreach ($levelGroups as $lvId => $group) { ?>
                            <div class="check-group-wrap">
                                <label class="check-group-header">
                                    <input type="checkbox" class="level-select-all" data-level="<?php echo h($lvId); ?>">
                                    <strong><?php echo h($group['label']); ?></strong>
                                    <span class="select-all-label">(seleccionar todo el nivel)</span>
                                </label>
                                <div class="check-group-items" data-level-group="<?php echo h($lvId); ?>">
                                    <?php foreach ($group['phases'] as $phase) {
                                        $phaseId   = (string)($phase['id'] ?? '');
                                        $phaseName = (string)($phase['phase_name'] ?? $phase['name'] ?? 'Fase');
                                        $checked   = in_array($phaseId, $form['english_course_ids'], true);
                                    ?>
                                        <label class="check-item">
                                            <input type="checkbox" name="english_course_ids[]" value="<?php echo h($phaseId); ?>" <?php echo $checked ? 'checked' : ''; ?> class="phase-check" data-level="<?php echo h($lvId); ?>">
                                            <span><?php echo h($phaseName); ?></span>
                                        </label>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if (empty($englishTargets)) { ?>
                            <div class="helper-text">No hay cursos de inglés configurados aún.</div>
                        <?php } ?>
                    </div>
                </div>

                <div class="field full block-section" id="technicalCourseBlock">
                    <div class="block-title">Semestre técnico</div>
                    <label for="technical_course_id">Seleccione el semestre</label>
                    <select name="technical_course_id" id="technical_course_id">
                        <option value="">Seleccione semestre técnico</option>
                        <?php foreach ($technicalCourses as $course) { ?>
                            <?php $courseId = (string) ($course['id'] ?? ''); ?>
                            <option value="<?php echo h($courseId); ?>" <?php echo $courseId === $form['technical_course_id'] ? 'selected' : ''; ?>>
                                <?php echo h((string) ($course['name'] ?? 'Semestre')); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="field full block-section" id="technicalUnitsBlock">
                    <div class="block-title">Unidades técnicas — asigna unidad por unidad</div>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">
                        <button type="button" class="btn-select-all" onclick="selectAllTechnicalUnits()">Seleccionar todas</button>
                        <button type="button" class="btn-select-all btn-deselect" onclick="deselectAllTechnicalUnits()">Deseleccionar</button>
                    </div>
                    <div class="check-items-wrap" id="technicalUnitsContainer">
                        <div class="helper-text">Primero selecciona el semestre para ver las unidades.</div>
                    </div>
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">Guardar asignaciones</button>
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
const preselectedTechnicalUnitIds = <?php echo json_encode(array_values($form['technical_unit_ids']), JSON_UNESCAPED_UNICODE); ?>;

function toggleProgramBlocks() {
    const value = programType ? programType.value : 'technical';
    const isEnglish = value === 'english';

    if (englishBlock) englishBlock.style.display = isEnglish ? 'block' : 'none';
    if (technicalCourseBlock) technicalCourseBlock.style.display = isEnglish ? 'none' : 'block';
    if (technicalUnitsBlock) technicalUnitsBlock.style.display = isEnglish ? 'none' : 'block';
}

function renderTechnicalUnits() {
    const container = document.getElementById('technicalUnitsContainer');
    if (!technicalCourseId || !container) return;

    const courseId = String(technicalCourseId.value || '');
    container.innerHTML = '';

    const filtered = technicalUnits.filter(function(item) {
        return String(item.course_id || '') === courseId;
    });

    if (filtered.length === 0) {
        container.innerHTML = '<div class="helper-text">Selecciona un semestre para ver sus unidades.</div>';
        return;
    }

    filtered.forEach(function(item) {
        const value = String(item.id || '');
        const label = document.createElement('label');
        label.className = 'check-item';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.name = 'technical_unit_ids[]';
        cb.value = value;
        cb.className = 'tech-unit-check';
        if (Array.isArray(preselectedTechnicalUnitIds) && preselectedTechnicalUnitIds.includes(value)) {
            cb.checked = true;
        }
        const span = document.createElement('span');
        span.textContent = String(item.name || 'Unidad');
        label.appendChild(cb);
        label.appendChild(span);
        container.appendChild(label);
    });
}

function selectAllTechnicalUnits() {
    document.querySelectorAll('.tech-unit-check').forEach(function(cb) { cb.checked = true; });
}

function deselectAllTechnicalUnits() {
    document.querySelectorAll('.tech-unit-check').forEach(function(cb) { cb.checked = false; });
}

if (programType) {
    toggleProgramBlocks();
    programType.addEventListener('change', toggleProgramBlocks);
}

if (technicalCourseId) {
    renderTechnicalUnits();
    technicalCourseId.addEventListener('change', renderTechnicalUnits);
}

// English level "Select All" behavior
document.querySelectorAll('.level-select-all').forEach(function(masterCb) {
    masterCb.addEventListener('change', function() {
        const levelId = this.dataset.level;
        document.querySelectorAll('.phase-check[data-level="' + levelId + '"]').forEach(function(cb) {
            cb.checked = masterCb.checked;
        });
    });
});

// Sync master checkbox when individual phases change
document.querySelectorAll('.phase-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
        const levelId = this.dataset.level;
        const allPhases = document.querySelectorAll('.phase-check[data-level="' + levelId + '"]');
        const master = document.querySelector('.level-select-all[data-level="' + levelId + '"]');
        if (master) {
            const allChecked = Array.from(allPhases).every(function(p) { return p.checked; });
            const anyChecked = Array.from(allPhases).some(function(p) { return p.checked; });
            master.checked = allChecked;
            master.indeterminate = !allChecked && anyChecked;
        }
    });
});
</script>
</body>
</html>

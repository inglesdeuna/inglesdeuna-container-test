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

function generate_account_id(): string {
    try {
        return 'acc_' . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return 'acc_' . str_replace('.', '', uniqid('', true));
    }
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool {
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

function slug_piece(string $value): string {
    $normalized = mb_strtolower(trim($value), 'UTF-8');
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n',
    ];
    $normalized = strtr($normalized, $map);
    $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
    return trim((string) $normalized);
}

function generate_teacher_username(string $teacherName): string {
    $clean = slug_piece($teacherName);
    $parts = array_values(array_filter(explode(' ', $clean), static fn ($part): bool => $part !== ''));
    if (empty($parts)) {
        return 'docente.docente';
    }
    $firstName = $parts[0];
    $lastName = $parts[count($parts) - 1] ?? $firstName;
    return $firstName . '.' . $lastName;
}

/* ===============================
   CARGAS DESDE DB
   =============================== */

function load_teachers_from_database(): array {
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_accounts_from_database(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->query("
            SELECT id, teacher_id, teacher_name, permission, username, password, updated_at
            FROM teacher_accounts
            ORDER BY updated_at DESC NULLS LAST, teacher_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_latest_credentials_from_database(string $teacherId): ?array {
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT username, password
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function database_is_available(): bool {
    return get_pdo_connection() instanceof PDO;
}

function teacher_account_exists(string $teacherId, ?string $excludeId = null): bool {
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return false;
    }
    try {
        if ($excludeId !== null && $excludeId !== '') {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM teacher_accounts
                WHERE teacher_id = :teacher_id
                  AND id <> :exclude_id
                LIMIT 1
            ");
            $stmt->execute([
                'teacher_id' => $teacherId,
                'exclude_id' => $excludeId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM teacher_accounts
                WHERE teacher_id = :teacher_id
                LIMIT 1
            ");
            $stmt->execute([
                'teacher_id' => $teacherId,
            ]);
        }
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ===============================
   HELPERS DE NEGOCIO
   =============================== */

function find_teacher_name_by_id(array $teachers, string $teacherId): string {
    foreach ($teachers as $teacher) {
        if ((string) ($teacher['id'] ?? '') === $teacherId) {
            return (string) ($teacher['name'] ?? 'Docente');
        }
    }
    return '';
}

function find_account_by_id(array $accounts, string $id): ?array {
    foreach ($accounts as $account) {
        if ((string) ($account['id'] ?? '') === $id) {
            return (array) $account;
        }
    }
    return null;
}

function save_teacher_account_to_database(array $record, ?string &$errorMessage = null): bool {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        $errorMessage = 'No hay conexión con la base de datos.';
        return false;
    }

    try {
        $hasMustChangePassword = table_has_column($pdo, 'teacher_accounts', 'must_change_password');

        $columns = [
            'id', 'teacher_id', 'teacher_name', 'permission', 'username', 'password', 'updated_at',
        ];
        $values = [
            ':id', ':teacher_id', ':teacher_name', ':permission', ':username', ':password', ':updated_at',
        ];

        if ($hasMustChangePassword) {
            $columns[] = 'must_change_password';
            $values[] = ':must_change_password';
        }

        $updateSet = [
            'teacher_id = EXCLUDED.teacher_id',
            'teacher_name = EXCLUDED.teacher_name',
            'permission = EXCLUDED.permission',
            'username = EXCLUDED.username',
            'password = EXCLUDED.password',
            'updated_at = EXCLUDED.updated_at',
        ];

        // Only overwrite must_change_password when it is being set to true (new profile).
        // For edits (set to false), preserve the existing DB value so a teacher who already
        // changed their password isn't forced to do it again.
        if ($hasMustChangePassword) {
            if (!empty($record['must_change_password'])) {
                $updateSet[] = 'must_change_password = EXCLUDED.must_change_password';
            }
            // else: keep existing DB value — do not add to UPDATE SET
        }

        $sql = "
            INSERT INTO teacher_accounts (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            ON CONFLICT (id) DO UPDATE SET
            " . implode(",\n            ", $updateSet) . "
        ";

        $stmt = $pdo->prepare($sql);

        $params = [
            'id' => (string) ($record['id'] ?? ''),
            'teacher_id' => (string) ($record['teacher_id'] ?? ''),
            'teacher_name' => (string) ($record['teacher_name'] ?? ''),
            'permission' => (string) ($record['permission'] ?? 'viewer'),
            'username' => (string) ($record['username'] ?? ''),
            'password' => (string) ($record['password'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? date('Y-m-d H:i:s')),
        ];

        if ($hasMustChangePassword) {
            $params['must_change_password'] = !empty($record['must_change_password']) ? 1 : 0;
        }

        return $stmt->execute($params);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        return false;
    }
}

function delete_teacher_account_from_database(string $id): bool {
    $pdo = get_pdo_connection();
    if (!$pdo || $id === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM teacher_accounts WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    } catch (Throwable $e) {
        return false;
    }
}

function load_teacher_by_id(string $teacherId): ?array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, id_number, phone, bank_account
            FROM teachers
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_students_for_teacher_admin(string $teacherId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT
                sa.id            AS assignment_id,
                sa.student_id,
                sa.program,
                sa.course_id,
                sa.level_id,
                sa.period,
                sa.unit_id,
                COALESCE(NULLIF(TRIM(s.name), ''), sa.student_id) AS student_name,
                CASE
                    WHEN sa.program = 'english'
                    THEN COALESCE(NULLIF(TRIM(ep.name), ''), sa.level_id)
                    ELSE COALESCE(NULLIF(TRIM(c.name), ''), sa.course_id)
                END AS course_name,
                COALESCE(NULLIF(TRIM(acc.username), ''), '') AS student_username
            FROM student_assignments sa
            LEFT JOIN students           s   ON s.id::text    = sa.student_id::text
            LEFT JOIN student_accounts   acc ON acc.student_id::text = sa.student_id::text
            LEFT JOIN courses            c   ON c.id::text    = sa.course_id::text AND sa.program <> 'english'
            LEFT JOIN english_phases     ep  ON ep.id::text   = sa.level_id::text  AND sa.program = 'english'
            WHERE sa.teacher_id = :teacher_id
            ORDER BY student_name ASC, sa.id ASC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function remove_student_assignment(string $assignmentId): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo || $assignmentId === '') {
        return false;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM student_assignments WHERE id = :id");
        return $stmt->execute(['id' => $assignmentId]);
    } catch (Throwable $e) {
        return false;
    }
}

/* ===============================
   CARGA INICIAL
   =============================== */
$teachers = load_teachers_from_database();
$accounts = load_teacher_accounts_from_database();
$errors = [];

if (!database_is_available()) {
    $errors[] = 'No hay conexión a la base de datos. Revise la variable de entorno DATABASE_URL y el archivo config/db.php.';
}

$form = [
    'edit_id' => '',
    'teacher_id' => '',
    'permission' => 'viewer',
    'username' => '',
    'password' => '1234',
];

/* ===============================
   DESVINCULAR ESTUDIANTE DE GRUPO
   =============================== */
if (isset($_GET['remove_sa']) && $_GET['remove_sa'] !== '') {
    $removeSaId     = trim((string) $_GET['remove_sa']);
    $returnTeacher  = trim((string) ($_GET['teacher_id'] ?? ''));
    remove_student_assignment($removeSaId);
    header('Location: teacher_profiles.php?view=' . urlencode($returnTeacher) . '&saved=1');
    exit;
}

/* ===============================
   VISTA DE DETALLE DEL DOCENTE
   =============================== */
$viewTeacherId      = trim((string) ($_GET['view'] ?? ''));
$viewTeacher        = $viewTeacherId !== '' ? load_teacher_by_id($viewTeacherId) : null;
$viewTeacherAccount = null;
$viewTeacherStudents = [];

if ($viewTeacher !== null) {
    foreach ($accounts as $acc) {
        if ((string) ($acc['teacher_id'] ?? '') === $viewTeacherId) {
            $viewTeacherAccount = $acc;
            break;
        }
    }
    $viewTeacherStudents = load_students_for_teacher_admin($viewTeacherId);
}

// Mapa teacher_id → account para el directorio
$accountByTeacherId = [];
foreach ($accounts as $acc) {
    $tid = (string) ($acc['teacher_id'] ?? '');
    if ($tid !== '' && !isset($accountByTeacherId[$tid])) {
        $accountByTeacherId[$tid] = $acc;
    }
}

/* ===============================
   ELIMINAR
   =============================== */
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteId = (string) $_GET['delete'];
    delete_teacher_account_from_database($deleteId);
    header('Location: teacher_profiles.php?saved=1');
    exit;
}

/* ===============================
   EDITAR
   =============================== */
if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $editId = (string) $_GET['edit'];
    $editAccount = find_account_by_id($accounts, $editId);
    if ($editAccount) {
        $form['edit_id'] = (string) ($editAccount['id'] ?? '');
        $form['teacher_id'] = (string) ($editAccount['teacher_id'] ?? '');
        $form['permission'] = (string) ($editAccount['permission'] ?? 'viewer');
        $form['username'] = (string) ($editAccount['username'] ?? '');
        $form['password'] = (string) ($editAccount['password'] ?? '1234');
    }
}

/* ===============================
   GUARDAR (POST)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['edit_id'] = trim((string) ($_POST['edit_id'] ?? ''));
    $form['teacher_id'] = trim((string) ($_POST['teacher_id'] ?? ''));
    $form['permission'] = trim((string) ($_POST['permission'] ?? 'viewer'));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['password'] = trim((string) ($_POST['password'] ?? '1234'));

    if ($form['permission'] !== 'viewer' && $form['permission'] !== 'editor') {
        $form['permission'] = 'viewer';
    }

    if ($form['teacher_id'] === '') {
        $errors[] = 'Debe seleccionar un docente.';
    }

    $teacherName = find_teacher_name_by_id($teachers, $form['teacher_id']);
    if ($teacherName === '') {
        $errors[] = 'El docente seleccionado no existe en la lista.';
    }

    $generatedUsername = $teacherName !== '' ? generate_teacher_username($teacherName) : 'docente.docente';
    $existingCredentials = load_teacher_latest_credentials_from_database($form['teacher_id']);

    if (is_array($existingCredentials)) {
        $storedUsername = trim((string) ($existingCredentials['username'] ?? ''));
        $storedPassword = trim((string) ($existingCredentials['password'] ?? ''));

        if ($storedUsername !== '') {
            $generatedUsername = $storedUsername;
        }
        if ($storedPassword !== '' && $form['password'] === '') {
            $form['password'] = $storedPassword;
        }
    }

    $form['username'] = $generatedUsername;

    if ($form['password'] === '') {
        $form['password'] = '1234';
    }

    if (mb_strlen($form['username']) < 3) {
        $errors[] = 'El usuario generado no es válido.';
    }

    if (mb_strlen($form['password']) < 4) {
        $errors[] = 'La contraseña debe tener mínimo 4 caracteres.';
    }

    if ($form['edit_id'] === '' && teacher_account_exists($form['teacher_id'])) {
        $errors[] = 'Este docente ya tiene un perfil creado.';
    }

    if (empty($errors)) {
        $dbError = null;

        $record = [
            'id' => $form['edit_id'] !== '' ? $form['edit_id'] : generate_account_id(),
            'teacher_id' => $form['teacher_id'],
            'teacher_name' => $teacherName,
            'permission' => $form['permission'],
            'username' => $form['username'],
            'password' => $form['password'],
            'must_change_password' => $form['edit_id'] === '', // only force on NEW profiles
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $saved = save_teacher_account_to_database($record, $dbError);

        if ($saved) {
            header('Location: teacher_assignments.php?teacher_id=' . urlencode($form['teacher_id']) . '&from_profile=1');
            exit;
        }

        $errors[] = 'No se pudo guardar el perfil docente en la base de datos.';
        if (!empty($dbError)) {
            $errors[] = 'Detalle técnico: ' . $dbError;
        }
    }

    $accounts = load_teacher_accounts_from_database();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Perfiles Docente</title>
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
    --orange:#ff6600;
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
    min-height:100vh;
    display:flex;
    justify-content:center;
}

a{
    color:var(--blue);
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.page-shell{
    width:min(1120px,100%);
}

.wrapper{
    width:100%;
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
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:10px;
    background:linear-gradient(180deg,#6b8f71,#4a6e52);
    color:#fff;
    text-decoration:none;
    font-weight:700;
    font-size:13px;
    border:none;
}

.back:hover{
    background:linear-gradient(180deg,#5a7d60,#3a5e42);
    text-decoration:none;
}

.links{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}

.link-secondary{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:10px;
    background:#eef7f0;
    color:var(--blue);
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    border:1px solid #b8dfc4;
}

.link-secondary:hover{
    background:#d4f0dc;
    text-decoration:none;
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
    color:var(--text);
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
    grid-template-columns:repeat(2, minmax(240px, 1fr));
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
    box-shadow:0 0 0 3px rgba(47,158,68,.15);
}

input[readonly]{
    background:#f8fafc;
    color:#4b5563;
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
    min-width:720px;
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
    border-bottom:1px solid var(--line);
    font-size:14px;
    vertical-align:top;
}

tbody tr:last-child td{
    border-bottom:none;
}

.permission-badge{
    display:inline-block;
    padding:5px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
    background:#eef7f0;
    color:var(--blue);
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

.empty{
    color:var(--muted);
    font-size:14px;
    text-align:center;
    padding:22px !important;
}

/* Directorio de docentes */
.teacher-grid{display:flex;flex-direction:column;gap:0}
.teacher-row{display:flex;align-items:center;gap:14px;padding:14px 16px;border-bottom:1px solid var(--line);transition:background .15s}
.teacher-row:last-child{border-bottom:none}
.teacher-row:hover{background:#f7fbf8}
.teacher-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#41b95a,#2f9e44);color:#fff;font-weight:700;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.teacher-info{flex:1;min-width:0}
.teacher-name{font-weight:700;font-size:15px;color:var(--text)}
.teacher-meta{font-size:13px;color:var(--muted);margin-top:2px;display:flex;flex-wrap:wrap;gap:8px}
.chip{background:#eef7f0;color:#237a35;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:700}
.chip.grey{background:#f3f4f6;color:#6b7280}
.chip.red{background:#fff1f2;color:#dc2626}
.btn-view{display:inline-flex;align-items:center;padding:7px 14px;border-radius:8px;background:linear-gradient(180deg,#41b95a,#2f9e44);color:#fff;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap}
.btn-view:hover{filter:brightness(1.07);text-decoration:none}

/* Vista detalle docente */
.detail-header{display:flex;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.detail-avatar{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#41b95a,#2f9e44);color:#fff;font-weight:700;font-size:22px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.detail-name{font-size:22px;font-weight:700;margin:0 0 4px}
.detail-meta{font-size:13px;color:var(--muted);display:flex;flex-wrap:wrap;gap:10px}
.btn-change{display:inline-flex;align-items:center;padding:5px 10px;border-radius:7px;background:#eef7f0;color:var(--blue);font-size:12px;font-weight:700;text-decoration:none;border:1px solid #b8dfc4}
.btn-change:hover{background:#d4f0dc;text-decoration:none}
.btn-remove{display:inline-flex;align-items:center;padding:5px 10px;border-radius:7px;background:#fff1f2;color:var(--danger);font-size:12px;font-weight:700;text-decoration:none;border:1px solid #fecdd3}
.btn-remove:hover{background:#ffe4e6;text-decoration:none}
.student-count{font-size:13px;color:var(--muted);margin-bottom:12px}
.back-to-list{display:inline-flex;align-items:center;padding:7px 14px;border-radius:8px;background:#f3f4f6;color:var(--text);font-size:13px;font-weight:700;text-decoration:none;margin-bottom:16px}
.back-to-list:hover{background:#e5e7eb;text-decoration:none}

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
                <a class="link-secondary" href="teacher_groups.php">Ver docentes y grupos</a>
                <a class="link-secondary" href="teacher_assignments.php">Ir a asignaciones</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h1>👩‍🏫 Crear perfil de docente</h1>
                <p class="subtitle">Aquí se crea el usuario y contraseña del docente para su login.</p>
                <p class="subtitle">Paso 1 de 2: crea usuario/contraseña. Al guardar, te enviamos a Asignaciones para definir cursos, semestres y unidades.</p>
            </div>

            <?php if (isset($_GET['saved']) && empty($errors)) { ?>
                <div class="notice">Perfil docente creado, actualizado o eliminado correctamente.</div>
            <?php } ?>

            <?php if (isset($_GET['from_assignments']) && $_GET['from_assignments'] === '1') { ?>
                <div class="notice">Paso 2 completado: este docente ya tiene asignaciones guardadas.</div>
            <?php } ?>

            <?php if (!empty($errors)) { ?>
                <div class="error">
                    <?php foreach ($errors as $error) { ?>
                        <div>• <?php echo h($error); ?></div>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="post" class="form-grid">
                <input type="hidden" name="edit_id" value="<?php echo h($form['edit_id']); ?>">

                <div class="field">
                    <label for="teacher_id">Docente</label>
                    <select name="teacher_id" id="teacher_id" required>
                        <option value="">Seleccione docente inscrito</option>
                        <?php foreach ($teachers as $teacher) { ?>
                            <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                            <option value="<?php echo h($tid); ?>" <?php echo $tid === $form['teacher_id'] ? 'selected' : ''; ?>>
                                <?php echo h((string) ($teacher['name'] ?? 'Docente')); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="field">
                    <label for="permission">Permiso</label>
                    <select name="permission" id="permission" required>
                        <option value="viewer" <?php echo $form['permission'] === 'viewer' ? 'selected' : ''; ?>>Sólo ver</option>
                        <option value="editor" <?php echo $form['permission'] === 'editor' ? 'selected' : ''; ?>>Puede editar</option>
                    </select>
                </div>

                <div class="field">
                    <label for="username">Usuario</label>
                    <input type="text" name="username" id="username" placeholder="nombre.apellido" value="<?php echo h($form['username']); ?>" required readonly>
                </div>

                <div class="field">
                    <label for="password">Contraseña</label>
                    <input type="text" name="password" id="password" placeholder="1234" value="<?php echo h($form['password']); ?>" required readonly>
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">
                        <?php echo $form['edit_id'] !== '' ? 'Actualizar perfil docente' : 'Crear perfil docente'; ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Perfiles creados</h2>
            </div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Docente</th>
                                <th>Usuario</th>
                                <th>Permiso</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($accounts)) { ?>
                            <tr>
                                <td colspan="4" class="empty">No hay perfiles creados todavía.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($accounts as $account) { ?>
                                <?php
                                    $permissionValue = (string) ($account['permission'] ?? 'viewer');
                                    $permissionLabel = $permissionValue === 'editor' ? 'Puede editar' : 'Sólo ver';
                                ?>
                                <tr>
                                    <td><?php echo h((string) ($account['teacher_name'] ?? 'Docente')); ?></td>
                                    <td><?php echo h((string) ($account['username'] ?? '')); ?></td>
                                    <td><span class="permission-badge"><?php echo h($permissionLabel); ?></span></td>
                                    <td>
                                        <div class="actions">
                                            <a class="action-btn edit-btn" href="teacher_profiles.php?edit=<?php echo h((string) ($account['id'] ?? '')); ?>">Editar</a>
                                            <a class="action-btn edit-btn" href="teacher_assignments.php?teacher_id=<?php echo h((string) ($account['teacher_id'] ?? '')); ?>">Asignar cursos</a>
                                            <a class="action-btn delete-btn" href="teacher_profiles.php?delete=<?php echo h((string) ($account['id'] ?? '')); ?>" onclick="return confirm('¿Eliminar perfil docente?')">Eliminar</a>
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

        <?php if ($viewTeacher !== null): ?>
        <!-- ============================================================
             VISTA DE DETALLE DEL DOCENTE
        ============================================================ -->
        <div class="card">
            <a class="back-to-list" href="teacher_profiles.php">← Volver al directorio</a>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice" style="margin-bottom:16px">Cambio guardado correctamente.</div>
            <?php endif; ?>

            <div class="detail-header">
                <div class="detail-avatar"><?php echo h(mb_strtoupper(mb_substr(trim((string)($viewTeacher['name'] ?? 'D')), 0, 1, 'UTF-8'), 'UTF-8')); ?></div>
                <div>
                    <div class="detail-name"><?php echo h((string)($viewTeacher['name'] ?? 'Docente')); ?></div>
                    <div class="detail-meta">
                        <?php if (($viewTeacher['id_number'] ?? '') !== ''): ?>
                            <span>📄 CC <?php echo h((string)$viewTeacher['id_number']); ?></span>
                        <?php endif; ?>
                        <?php if (($viewTeacher['phone'] ?? '') !== ''): ?>
                            <span>📞 <?php echo h((string)$viewTeacher['phone']); ?></span>
                        <?php endif; ?>
                        <?php if ($viewTeacherAccount !== null): ?>
                            <span>👤 <?php echo h((string)($viewTeacherAccount['username'] ?? '')); ?></span>
                            <span class="chip"><?php echo ($viewTeacherAccount['permission'] ?? '') === 'editor' ? 'Puede editar' : 'Solo ver'; ?></span>
                        <?php else: ?>
                            <span class="chip red">Sin perfil de acceso</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
                    <?php if ($viewTeacherAccount !== null): ?>
                        <a class="action-btn edit-btn" href="teacher_profiles.php?edit=<?php echo h((string)($viewTeacherAccount['id'] ?? '')); ?>">✏️ Editar perfil</a>
                    <?php else: ?>
                        <a class="action-btn edit-btn" href="teacher_profiles.php">+ Crear perfil</a>
                    <?php endif; ?>
                    <a class="action-btn edit-btn" href="teacher_assignments.php?teacher_id=<?php echo h($viewTeacherId); ?>">📚 Asignar cursos</a>
                    <a class="action-btn delete-btn" href="delete_teacher.php?id=<?php echo h($viewTeacherId); ?>"
                       onclick="return confirm('¿Eliminar completamente al docente <?php echo h(addslashes((string)($viewTeacher['name'] ?? ''))); ?>? Se borrarán sus asignaciones, cuenta y estudiantes vinculados.')">
                        🗑️ Eliminar docente
                    </a>
                </div>
            </div>

            <h3 style="margin:0 0 8px;font-size:17px;color:var(--subtitle)">Estudiantes asignados</h3>
            <p class="student-count">
                <?php $cnt = count($viewTeacherStudents); echo $cnt === 0 ? 'Sin estudiantes asignados.' : $cnt . ' estudiante' . ($cnt !== 1 ? 's' : '') . ' asignado' . ($cnt !== 1 ? 's' : '') . '.'; ?>
            </p>

            <?php if (!empty($viewTeacherStudents)): ?>
            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Usuario</th>
                                <th>Curso / Grupo</th>
                                <th>Programa</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($viewTeacherStudents as $stu): ?>
                            <?php
                                $saId      = h((string)($stu['assignment_id'] ?? ''));
                                $stuName   = h((string)($stu['student_name'] ?? ''));
                                $stuUser   = (string)($stu['student_username'] ?? '');
                                $courseName= h((string)($stu['course_name'] ?? '—'));
                                $prog      = (string)($stu['program'] ?? 'technical');
                                $progLabel = $prog === 'english' ? 'English' : 'Técnico';
                            ?>
                            <tr>
                                <td><strong><?php echo $stuName; ?></strong></td>
                                <td><?php echo $stuUser !== '' ? h($stuUser) : '<span style="color:#aaa">Sin cuenta</span>'; ?></td>
                                <td><?php echo $courseName; ?></td>
                                <td><span class="chip"><?php echo h($progLabel); ?></span></td>
                                <td style="white-space:nowrap">
                                    <a class="btn-change"
                                       href="student_assignments.php?edit=<?php echo $saId; ?>">
                                        🔄 Cambiar grupo
                                    </a>
                                    &nbsp;
                                    <a class="btn-remove"
                                       href="teacher_profiles.php?remove_sa=<?php echo $saId; ?>&teacher_id=<?php echo h($viewTeacherId); ?>"
                                       onclick="return confirm('¿Retirar a <?php echo h(addslashes((string)($stu['student_name'] ?? ''))); ?> de este grupo? Solo se desvincula de este docente.')">
                                        ✕ Retirar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             DIRECTORIO DE DOCENTES
        ============================================================ -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Directorio de docentes</h2>
                <p class="subtitle">Haz clic en "Ver" para ver el perfil y los estudiantes asignados a cada docente.</p>
            </div>

            <?php if (empty($teachers)): ?>
                <p class="empty">No hay docentes inscritos.</p>
            <?php else: ?>
            <div class="teacher-grid">
                <?php foreach ($teachers as $teacher):
                    $tid     = (string)($teacher['id'] ?? '');
                    $tname   = (string)($teacher['name'] ?? 'Docente');
                    $initial = mb_strtoupper(mb_substr(trim($tname), 0, 1, 'UTF-8'), 'UTF-8');
                    $acc     = $accountByTeacherId[$tid] ?? null;
                    $isActive = $viewTeacherId === $tid;
                ?>
                <div class="teacher-row" style="<?php echo $isActive ? 'background:#f0fdf4;' : ''; ?>">
                    <div class="teacher-avatar"><?php echo h($initial); ?></div>
                    <div class="teacher-info">
                        <div class="teacher-name"><?php echo h($tname); ?></div>
                        <div class="teacher-meta">
                            <?php if ($acc !== null): ?>
                                <span class="chip">👤 <?php echo h((string)($acc['username'] ?? '')); ?></span>
                                <span class="chip"><?php echo ($acc['permission'] ?? '') === 'editor' ? 'Puede editar' : 'Solo ver'; ?></span>
                            <?php else: ?>
                                <span class="chip red">Sin perfil</span>
                            <?php endif; ?>
                            <?php if (($teacher['phone'] ?? '') !== ''): ?>
                                <span class="chip grey">📞 <?php echo h((string)$teacher['phone']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="btn-view" href="teacher_profiles.php?view=<?php echo h($tid); ?>#detalle">Ver</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
const teacherMap = <?php
echo json_encode(
    array_reduce($teachers, static function (array $carry, array $teacher): array {
        $carry[(string) ($teacher['id'] ?? '')] = (string) ($teacher['name'] ?? '');
        return $carry;
    }, []),
    JSON_UNESCAPED_UNICODE
);
?>;

const teacherSelect = document.getElementById('teacher_id');
const username = document.getElementById('username');
const password = document.getElementById('password');
const isEditMode = <?php echo json_encode($form['edit_id'] !== '', JSON_UNESCAPED_UNICODE); ?>;

function normalizeName(value) {
    return String(value || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function generateUsername(name) {
    const normalized = normalizeName(name);
    if (!normalized) {
        return 'docente.docente';
    }
    const pieces = normalized.split(' ').filter(Boolean);
    const firstName = pieces[0] || 'docente';
    const lastName = pieces[pieces.length - 1] || firstName;
    return `${firstName}.${lastName}`;
}

function syncTeacherCredentials() {
    if (!teacherSelect || !username || !password) {
        return;
    }

    if (isEditMode && username.value && password.value) {
        return;
    }

    const teacherId = String(teacherSelect.value || '');
    const teacherName = String(teacherMap[teacherId] || '');

    username.value = generateUsername(teacherName);
    password.value = '1234';
}

if (teacherSelect) {
    syncTeacherCredentials();
    teacherSelect.addEventListener('change', syncTeacherCredentials);
}
</script>
</body>
</html>

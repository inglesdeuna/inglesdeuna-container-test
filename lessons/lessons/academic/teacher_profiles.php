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
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, teacher_id, teacher_name, scope, target_id, target_name, permission, username, password, updated_at
            FROM teacher_accounts
            ORDER BY updated_at DESC NULLS LAST, teacher_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
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

function slug_piece(string $value): string
{
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

function generate_teacher_username(string $teacherName): string
{
    $clean = slug_piece($teacherName);
    $parts = array_values(array_filter(explode(' ', $clean), static fn ($part): bool => $part !== ''));

    if (empty($parts)) {
        return 'docente.docente';
    }

    $firstName = $parts[0];
    $lastName = $parts[count($parts) - 1] ?? $firstName;

    return $firstName . '.' . $lastName;
}

function load_teacher_latest_credentials_from_database(string $teacherId): ?array
{
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

function find_teacher_name_by_id(array $teachers, string $teacherId): string
{
    foreach ($teachers as $teacher) {
        if ((string) ($teacher['id'] ?? '') === $teacherId) {
            return (string) ($teacher['name'] ?? 'Docente');
        }
    }
    return '';
}

function find_account_by_id(array $accounts, string $id): ?array
{
    foreach ($accounts as $account) {
        if ((string) ($account['id'] ?? '') === $id) {
            return (array) $account;
        }
    }
    return null;
}

function save_teacher_account_to_database(array $record): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $hasMustChangePassword = table_has_column($pdo, 'teacher_accounts', 'must_change_password');

        $columns = [
            'id',
            'teacher_id',
            'teacher_name',
            'scope',
            'target_id',
            'target_name',
            'permission',
            'username',
            'password',
            'updated_at',
        ];

        $values = [
            ':id',
            ':teacher_id',
            ':teacher_name',
            ':scope',
            ':target_id',
            ':target_name',
            ':permission',
            ':username',
            ':password',
            ':updated_at',
        ];

        if ($hasMustChangePassword) {
            $columns[] = 'must_change_password';
            $values[] = ':must_change_password';
        }

        $updateSet = [
            'teacher_id = EXCLUDED.teacher_id',
            'teacher_name = EXCLUDED.teacher_name',
            'scope = EXCLUDED.scope',
            'target_id = EXCLUDED.target_id',
            'target_name = EXCLUDED.target_name',
            'permission = EXCLUDED.permission',
            'username = EXCLUDED.username',
            'password = EXCLUDED.password',
            'updated_at = EXCLUDED.updated_at',
        ];

        if ($hasMustChangePassword) {
            $updateSet[] = 'must_change_password = EXCLUDED.must_change_password';
        }

        $sql = "
            INSERT INTO teacher_accounts (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            ON CONFLICT (id) DO UPDATE SET
                " . implode(",\n                ", $updateSet) . "
        ";

        $stmt = $pdo->prepare($sql);

        $params = [
            'id' => (string) ($record['id'] ?? ''),
            'teacher_id' => (string) ($record['teacher_id'] ?? ''),
            'teacher_name' => (string) ($record['teacher_name'] ?? ''),
            'scope' => (string) ($record['scope'] ?? 'technical'),
            'target_id' => (string) ($record['target_id'] ?? ''),
            'target_name' => (string) ($record['target_name'] ?? ''),
            'permission' => (string) ($record['permission'] ?? 'viewer'),
            'username' => (string) ($record['username'] ?? ''),
            'password' => (string) ($record['password'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? date('Y-m-d H:i:s')),
        ];

        if ($hasMustChangePassword) {
            $params['must_change_password'] = !empty($record['must_change_password']);
        }

        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function delete_teacher_account_from_database(string $id): bool
{
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

/* ===============================
   CARGA INICIAL
=============================== */
$teachers = load_teachers_from_database();
$accounts = load_teacher_accounts_from_database();
$technical = load_technical_courses_from_database();
$english = load_english_targets_from_database();

$errors = [];

$form = [
    'edit_id' => '',
    'teacher_id' => '',
    'scope' => 'technical',
    'target_id' => '',
    'target_name' => '',
    'target_ids' => [],
    'permission' => 'viewer',
    'username' => '',
    'password' => '1234',
];

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
        $form['scope'] = (string) ($editAccount['scope'] ?? 'technical');
        $form['target_id'] = (string) ($editAccount['target_id'] ?? '');
        $form['target_name'] = (string) ($editAccount['target_name'] ?? '');
        $form['permission'] = (string) ($editAccount['permission'] ?? 'viewer');
        $form['username'] = (string) ($editAccount['username'] ?? '');
        $form['password'] = (string) ($editAccount['password'] ?? '1234');
    }
}

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['edit_id'] = trim((string) ($_POST['edit_id'] ?? ''));
    $form['teacher_id'] = trim((string) ($_POST['teacher_id'] ?? ''));
    $form['scope'] = trim((string) ($_POST['scope'] ?? 'technical'));
    $form['target_id'] = trim((string) ($_POST['target_id'] ?? ''));
    $form['target_name'] = trim((string) ($_POST['target_name'] ?? ''));

    $postedTargetIds = $_POST['target_ids'] ?? [];
    $form['target_ids'] = is_array($postedTargetIds)
        ? array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $postedTargetIds), static fn ($value): bool => $value !== ''))
        : [];

    $form['permission'] = trim((string) ($_POST['permission'] ?? 'viewer'));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['password'] = trim((string) ($_POST['password'] ?? '1234'));

    if ($form['scope'] !== 'technical' && $form['scope'] !== 'english') {
        $form['scope'] = 'technical';
    }

    if ($form['permission'] !== 'viewer' && $form['permission'] !== 'editor') {
        $form['permission'] = 'viewer';
    }

    if ($form['teacher_id'] === '') {
        $errors[] = 'Debe seleccionar un docente.';
    }

    if ($form['edit_id'] === '' && empty($form['target_ids'])) {
        $errors[] = 'Debe seleccionar al menos un semestre/curso válido.';
    }

    if ($form['edit_id'] !== '' && ($form['target_id'] === '' || $form['target_name'] === '')) {
        $errors[] = 'Debe seleccionar un semestre/curso válido.';
    }

    $teacherName = find_teacher_name_by_id($teachers, $form['teacher_id']);
    if ($teacherName === '') {
        $errors[] = 'El docente seleccionado no existe en la lista de inscritos.';
    }

    $generatedUsername = $teacherName !== '' ? generate_teacher_username($teacherName) : 'docente.docente';
    $existingCredentials = load_teacher_latest_credentials_from_database($form['teacher_id']);

    if (is_array($existingCredentials)) {
        $storedUsername = trim((string) ($existingCredentials['username'] ?? ''));
        $storedPassword = trim((string) ($existingCredentials['password'] ?? ''));

        if ($storedUsername !== '') {
            $generatedUsername = $storedUsername;
        }

        if ($storedPassword !== '') {
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

    if (empty($errors)) {
        $saved = true;

        if ($form['edit_id'] !== '') {
            $record = [
                'id' => $form['edit_id'],
                'teacher_id' => $form['teacher_id'],
                'teacher_name' => $teacherName,
                'scope' => $form['scope'],
                'target_id' => $form['target_id'],
                'target_name' => $form['target_name'],
                'permission' => $form['permission'],
                'username' => $form['username'],
                'password' => $form['password'],
                'must_change_password' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $saved = save_teacher_account_to_database($record);
        } else {
            $source = $form['scope'] === 'english' ? $english : $technical;
            $targetMap = [];

            foreach ($source as $item) {
                $targetMap[(string) ($item['id'] ?? '')] = (string) ($item['name'] ?? 'Curso');
            }

            foreach ($form['target_ids'] as $targetIdSelected) {
                $targetNameSelected = $targetMap[(string) $targetIdSelected] ?? '';
                if ($targetNameSelected === '') {
                    continue;
                }

                $record = [
                    'id' => uniqid('acc_', true),
                    'teacher_id' => $form['teacher_id'],
                    'teacher_name' => $teacherName,
                    'scope' => $form['scope'],
                    'target_id' => (string) $targetIdSelected,
                    'target_name' => $targetNameSelected,
                    'permission' => $form['permission'],
                    'username' => $form['username'],
                    'password' => $form['password'],
                    'must_change_password' => true,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if (!save_teacher_account_to_database($record)) {
                    $saved = false;
                    break;
                }
            }
        }

        if ($saved) {
            header('Location: teacher_profiles.php?saved=1');
            exit;
        }

        $errors[] = 'No se pudo guardar el perfil docente en la base de datos.';
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
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --title:#1f3c75;
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

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    padding:32px 20px;
}

.page-shell{
    width:100%;
    display:flex;
    justify-content:center;
}

.wrapper{
    width:100%;
    max-width:980px;
    margin:0 auto;
}

.topbar{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    gap:12px;
    margin-bottom:20px;
}

.back{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
    font-size:14px;
}

.links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.links a{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:10px 14px;
    border-radius:10px;
    text-decoration:none;
    font-weight:700;
    color:#fff;
    background:var(--blue);
    transition:background .2s ease;
}

.links a:hover{
    background:var(--blue-hover);
}

.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:24px;
    margin-bottom:18px;
}

.card-header{
    margin-bottom:18px;
}

.card-header h1,
.card-header h2{
    margin:0;
    color:var(--subtitle);
    line-height:1.2;
}

.card-header h1{
    font-size:22px;
    font-weight:700;
}

.card-header h2{
    font-size:20px;
    font-weight:700;
}

.subtitle{
    margin:10px 0 0;
    font-size:14px;
    color:var(--muted);
    line-height:1.5;
}

.notice{
    padding:12px 14px;
    border-radius:10px;
    background:var(--success-bg);
    border:1px solid var(--success-border);
    color:var(--success-text);
    margin-bottom:16px;
    font-size:14px;
    font-weight:600;
}

.error{
    padding:12px 14px;
    border-radius:10px;
    background:var(--error-bg);
    border:1px solid var(--error-border);
    color:var(--error-text);
    margin-bottom:16px;
    font-size:14px;
}

.error div + div{
    margin-top:6px;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
}

.field{
    display:flex;
    flex-direction:column;
}

.field.full{
    grid-column:1 / -1;
}

label{
    font-size:12px;
    font-weight:700;
    color:var(--text);
    margin:0 0 8px;
    text-transform:uppercase;
    letter-spacing:.3px;
}

input,
select,
button{
    width:100%;
    min-height:44px;
    border-radius:10px;
    border:1px solid #c9d4e3;
    background:#fff;
    color:var(--text);
    font:inherit;
    padding:10px 12px;
}

input[readonly]{
    background:#f8fafc;
    color:#6b7280;
}

select[multiple]{
    min-height:140px;
}

input:focus,
select:focus,
button:focus{
    outline:none;
    border-color:#7d9dff;
    box-shadow:0 0 0 3px rgba(70,96,220,.10);
}

.button-primary{
    border:none;
    background:var(--blue);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    transition:background .2s ease;
}

.button-primary:hover{
    background:var(--blue-hover);
}

.table-wrap{
    border:1px solid var(--line);
    border-radius:12px;
    overflow:hidden;
    background:#fff;
}

.table-scroll{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:860px;
    border-collapse:separate;
    border-spacing:0;
}

thead th{
    background:var(--head);
    color:var(--text);
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.25px;
    text-align:left;
    padding:12px 14px;
    border-bottom:1px solid var(--line);
}

tbody td{
    padding:12px 14px;
    border-bottom:1px solid #e7edf6;
    font-size:14px;
    color:#27415f;
    vertical-align:top;
}

tbody tr:last-child td{
    border-bottom:none;
}

.scope-badge,
.permission-badge{
    display:inline-block;
    padding:5px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

.scope-badge{
    background:#eef4ff;
    color:#1f66cc;
}

.permission-badge{
    background:#eef8f2;
    color:#1d6a40;
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
    padding:5px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    text-decoration:none;
    white-space:nowrap;
    transition:all .2s ease;
}

.edit-btn{
    background:#e8f0ff;
    color:#1f66cc;
}

.edit-btn:hover{
    background:#dbeafe;
}

.delete-btn{
    background:#fee2e2;
    color:#b91c1c;
}

.delete-btn:hover{
    background:#fecaca;
}

.empty-row{
    color:var(--muted);
}

.helper-text{
    font-size:12px;
    color:var(--muted);
    margin-top:6px;
    line-height:1.4;
}

@media (max-width: 768px){
    body{
        padding:20px 14px;
    }

    .wrapper{
        max-width:100%;
    }

    .card{
        padding:18px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .card-header h1{
        font-size:20px;
    }

    .card-header h2{
        font-size:18px;
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
                <a href="teacher_groups.php">Ver página Docentes y Grupos</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h1>👩‍🏫 Crear perfil de docente</h1>
                <p class="subtitle">Aquí se crea el usuario y contraseña del docente para su login y acceso a lo asignado.</p>
            </div>

            <?php if (isset($_GET['saved'])) { ?>
                <div class="notice">Perfil docente creado, actualizado o eliminado correctamente.</div>
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
                    <label for="scopeSelect">Programa</label>
                    <select name="scope" id="scopeSelect" required>
                        <option value="technical" <?php echo $form['scope'] === 'technical' ? 'selected' : ''; ?>>Programa técnico (semestres)</option>
                        <option value="english" <?php echo $form['scope'] === 'english' ? 'selected' : ''; ?>>Cursos de inglés</option>
                    </select>
                </div>

                <div class="field">
                    <label for="targetId">Semestre / Curso</label>
                    <select name="target_id" id="targetId" <?php echo $form['edit_id'] !== '' ? 'required' : ''; ?>>
                        <option value="">Seleccione semestre/curso</option>
                    </select>
                    <div class="helper-text">En edición se actualiza un solo perfil.</div>
                </div>

                <div class="field">
                    <label for="targetIds">Asignar varios (nuevo perfil)</label>
                    <select name="target_ids[]" id="targetIds" multiple size="5" <?php echo $form['edit_id'] === '' ? 'required' : ''; ?>>
                    </select>
                    <div class="helper-text">Al crear, puedes asignar varios semestres/cursos al mismo docente.</div>
                </div>

                <div class="field">
                    <label for="targetName">Asignado</label>
                    <input
                        type="text"
                        name="target_name"
                        id="targetName"
                        placeholder="Nombre semestre/curso (auto)"
                        value="<?php echo h($form['target_name']); ?>"
                        <?php echo $form['edit_id'] !== '' ? 'required' : ''; ?>
                        readonly
                    >
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
                    <input
                        type="text"
                        name="username"
                        id="username"
                        placeholder="nombre.apellido"
                        value="<?php echo h($form['username']); ?>"
                        required
                        readonly
                    >
                </div>

                <div class="field full">
                    <label for="password">Contraseña</label>
                    <input
                        type="text"
                        name="password"
                        id="password"
                        placeholder="1234"
                        value="<?php echo h($form['password']); ?>"
                        required
                        readonly
                    >
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
                                <th>Ámbito</th>
                                <th>Asignado</th>
                                <th>Permiso</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($accounts)) { ?>
                            <tr>
                                <td colspan="6" class="empty-row">No hay perfiles creados todavía.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($accounts as $account) { ?>
                                <?php
                                    $scopeValue = (string) ($account['scope'] ?? 'technical');
                                    $scopeLabel = $scopeValue === 'english' ? 'Cursos de inglés' : 'Programa técnico';

                                    $permissionValue = (string) ($account['permission'] ?? 'viewer');
                                    $permissionLabel = $permissionValue === 'editor' ? 'Puede editar' : 'Sólo ver';
                                ?>
                                <tr>
                                    <td><?php echo h((string) ($account['teacher_name'] ?? 'Docente')); ?></td>
                                    <td><?php echo h((string) ($account['username'] ?? '')); ?></td>
                                    <td><span class="scope-badge"><?php echo h($scopeLabel); ?></span></td>
                                    <td><?php echo h((string) ($account['target_name'] ?? '')); ?></td>
                                    <td><span class="permission-badge"><?php echo h($permissionLabel); ?></span></td>
                                    <td>
                                        <div class="actions">
                                            <a class="action-btn edit-btn" href="teacher_profiles.php?edit=<?php echo h((string) ($account['id'] ?? '')); ?>">Editar</a>
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
    </div>
</div>

<script>
const technical = <?php echo json_encode(array_values($technical), JSON_UNESCAPED_UNICODE); ?>;
const english = <?php echo json_encode(array_values($english), JSON_UNESCAPED_UNICODE); ?>;
const preselectedScope = <?php echo json_encode($form['scope'], JSON_UNESCAPED_UNICODE); ?>;
const preselectedTargetId = <?php echo json_encode($form['target_id'], JSON_UNESCAPED_UNICODE); ?>;
const preselectedTargetIds = <?php echo json_encode(array_values($form['target_ids']), JSON_UNESCAPED_UNICODE); ?>;
const preselectedTargetName = <?php echo json_encode($form['target_name'], JSON_UNESCAPED_UNICODE); ?>;
const isEditMode = <?php echo json_encode($form['edit_id'] !== '', JSON_UNESCAPED_UNICODE); ?>;
const teacherMap = <?php
echo json_encode(
    array_reduce($teachers, static function (array $carry, array $teacher): array {
        $carry[(string) ($teacher['id'] ?? '')] = (string) ($teacher['name'] ?? '');
        return $carry;
    }, []),
    JSON_UNESCAPED_UNICODE
);
?>;

const scopeSelect = document.getElementById('scopeSelect');
const targetId = document.getElementById('targetId');
const targetIds = document.getElementById('targetIds');
const targetName = document.getElementById('targetName');
const teacherSelect = document.getElementById('teacher_id');
const username = document.getElementById('username');
const password = document.getElementById('password');

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

function renderOptions() {
    if (!scopeSelect || !targetId || !targetName || !targetIds) {
        return;
    }

    const scope = scopeSelect.value;
    const source = scope === 'english' ? english : technical;

    targetId.innerHTML = '<option value="">Seleccione semestre/curso</option>';
    targetIds.innerHTML = '';

    source.forEach(item => {
        const optionValue = String(item.id || '');
        const optionLabel = String(item.name || 'Curso');

        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionLabel;
        option.dataset.name = optionLabel;

        if (String(optionValue) === String(preselectedTargetId || '')) {
            option.selected = true;
        }

        targetId.appendChild(option);

        const multipleOption = document.createElement('option');
        multipleOption.value = optionValue;
        multipleOption.textContent = optionLabel;

        if (Array.isArray(preselectedTargetIds) && preselectedTargetIds.includes(optionValue)) {
            multipleOption.selected = true;
        }

        targetIds.appendChild(multipleOption);
    });

    const selected = targetId.options[targetId.selectedIndex];
    targetName.value = selected && selected.value !== '' ? (selected.dataset.name || '') : '';
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

if (scopeSelect && targetId && targetName && targetIds) {
    scopeSelect.value = preselectedScope || 'technical';
    renderOptions();
    syncTeacherCredentials();

    if (preselectedTargetName && targetName.value === '') {
        targetName.value = preselectedTargetName;
    }

    scopeSelect.addEventListener('change', () => {
        targetId.selectedIndex = 0;
        targetName.value = '';
        renderOptions();
    });

    if (teacherSelect) {
        teacherSelect.addEventListener('change', () => {
            syncTeacherCredentials();
        });
    }

    targetId.addEventListener('change', () => {
        const selected = targetId.options[targetId.selectedIndex];
        targetName.value = selected && selected.value !== '' ? (selected.dataset.name || '') : '';
    });
}
</script>
</body>
</html>

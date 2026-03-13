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
            'id','teacher_id','teacher_name','permission','username','password','updated_at',
        ];
        $values = [
            ':id',':teacher_id',':teacher_name',':permission',':username',':password',':updated_at',
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
        if ($hasMustChangePassword) {
            $updateSet[] = 'must_change_password = EXCLUDED.must_change_password';
        }
        $sql = "
            INSERT INTO teacher_accounts (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            ON CONFLICT (id) DO UPDATE SET
            " . implode(",\n ", $updateSet) . "
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
            'must_change_password' => true,
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
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --subtitle:#2c3e50;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --orange:#ff6600;
    --green:#1d6a40;
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

/* ... estilos previos ... */

.permission-badge{
    display:inline-block;
    padding:5px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.badge-tech{background:#eef4ff;color:#1f66cc;}
.badge-eng{background:#fff3e8;color:var(--orange);}
.badge-unit{background:#eef8f2;color:var(--green);}
.empty{
    color:var(--muted);
    font-size:14px;
}
</style>
</head>
<body>
<div class="page-shell">
    <div class="wrapper">
        <div class="topbar">
            <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
            <div class="links">
                <a href="teacher_groups.php">Ver docentes y grupos</a>
                <a href="teacher_assignments.php">Ir a asignaciones</a>
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
    const teacherName = String(teacherMap[    const teacherName = String(teacherMap[teacherId] || '');
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


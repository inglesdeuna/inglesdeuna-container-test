<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1");
        $stmt->execute(['table_name' => $tableName, 'column_name' => $columnName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function slugify_username(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower($ascii !== false ? $ascii : $value);
    $value = preg_replace('/[^a-z0-9]+/', '.', $value);
    $value = trim((string) $value, '.');
    return $value !== '' ? $value : 'student';
}

function username_from_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) >= 2) {
        return slugify_username($parts[0]) . '.' . slugify_username((string) end($parts));
    }
    return slugify_username($fullName);
}

function load_students_from_database(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT id, name, guardian, contact, eps FROM students ORDER BY name ASC, id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_accounts_from_database(PDO $pdo): array
{
    try {
        $select = 'id, student_id, student_name, username';
        if (table_has_column($pdo, 'student_accounts', 'temp_password')) {
            $select .= ', temp_password';
        }
        $order = table_has_column($pdo, 'student_accounts', 'created_at')
            ? 'created_at ASC NULLS LAST, id ASC'
            : 'id ASC';
        $stmt = $pdo->query("SELECT {$select} FROM student_accounts ORDER BY {$order}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function unique_username(PDO $pdo, string $requested, string $studentId): string
{
    $base = slugify_username($requested);
    $candidate = $base;
    $suffix = 1;

    do {
        $stmt = $pdo->prepare("SELECT 1 FROM student_accounts WHERE LOWER(username) = LOWER(:username) AND student_id <> :student_id LIMIT 1");
        $stmt->execute(['username' => $candidate, 'student_id' => $studentId]);
        $exists = (bool) $stmt->fetchColumn();
        if ($exists) {
            $candidate = $base . '.' . $suffix;
            $suffix++;
        }
    } while ($exists);

    return $candidate;
}

function sync_assignment_username(PDO $pdo, string $studentId, string $username, string $password = ''): void
{
    try {
        $sql = "UPDATE student_assignments SET student_username = :username";
        $params = ['username' => $username, 'student_id' => $studentId];
        if ($password !== '' && table_has_column($pdo, 'student_assignments', 'student_temp_password')) {
            $sql .= ", student_temp_password = CASE WHEN COALESCE(student_temp_password, '') = '' THEN :password ELSE student_temp_password END";
            $params['password'] = $password;
        }
        if (table_has_column($pdo, 'student_assignments', 'updated_at')) {
            $sql .= ', updated_at = NOW()';
        }
        $sql .= ' WHERE student_id = :student_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Account save remains valid even if the optional assignment sync fails.
    }
}

function repair_duplicate_usernames(PDO $pdo): void
{
    $accounts = load_accounts_from_database($pdo);
    $used = [];

    foreach ($accounts as $account) {
        $studentId = trim((string) ($account['student_id'] ?? ''));
        if ($studentId === '') {
            continue;
        }

        $current = slugify_username((string) ($account['username'] ?? ''));
        $key = strtolower($current);
        if (!isset($used[$key])) {
            $used[$key] = $studentId;
            continue;
        }

        if ($used[$key] === $studentId) {
            continue;
        }

        $name = (string) ($account['student_name'] ?? 'student');
        $replacement = unique_username($pdo, username_from_name($name), $studentId);

        try {
            $pdo->beginTransaction();
            $sql = 'UPDATE student_accounts SET username = :username';
            if (table_has_column($pdo, 'student_accounts', 'updated_at')) {
                $sql .= ', updated_at = NOW()';
            }
            $sql .= ' WHERE student_id = :student_id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['username' => $replacement, 'student_id' => $studentId]);
            sync_assignment_username($pdo, $studentId, $replacement);
            $pdo->commit();
            $used[strtolower($replacement)] = $studentId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS student_accounts_username_unique_ci ON student_accounts (LOWER(username))");
    } catch (Throwable $e) {
        // Non-fatal on installations where an old duplicate still needs manual review.
    }
}

function save_student_profile_to_database(PDO $pdo, string $studentId, string $studentName, string $requestedUsername, string $password): array
{
    $username = unique_username($pdo, $requestedUsername, $studentId);

    $columns = ['student_id', 'student_name', 'username'];
    $values = [':student_id', ':student_name', ':username'];
    $updates = ['student_name = EXCLUDED.student_name', 'username = EXCLUDED.username'];
    $params = ['student_id' => $studentId, 'student_name' => $studentName, 'username' => $username];

    if (table_has_column($pdo, 'student_accounts', 'id')) {
        $columns[] = 'id';
        $values[] = ':id';
        $params['id'] = uniqid('stu_acc_');
    }
    if (table_has_column($pdo, 'student_accounts', 'password_hash')) {
        $columns[] = 'password_hash';
        $values[] = ':password_hash';
        $updates[] = 'password_hash = EXCLUDED.password_hash';
        $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    if (table_has_column($pdo, 'student_accounts', 'temp_password')) {
        $columns[] = 'temp_password';
        $values[] = ':temp_password';
        $updates[] = 'temp_password = EXCLUDED.temp_password';
        $params['temp_password'] = $password;
    }
    if (table_has_column($pdo, 'student_accounts', 'must_change_password')) {
        $columns[] = 'must_change_password';
        $values[] = ':must_change_password';
        $updates[] = 'must_change_password = EXCLUDED.must_change_password';
        $params['must_change_password'] = true;
    }
    if (table_has_column($pdo, 'student_accounts', 'permission')) {
        $columns[] = 'permission';
        $values[] = ':permission';
        $updates[] = 'permission = COALESCE(student_accounts.permission, EXCLUDED.permission)';
        $params['permission'] = 'viewer';
    }
    if (table_has_column($pdo, 'student_accounts', 'created_at')) {
        $columns[] = 'created_at';
        $values[] = 'NOW()';
    }
    if (table_has_column($pdo, 'student_accounts', 'updated_at')) {
        $columns[] = 'updated_at';
        $values[] = 'NOW()';
        $updates[] = 'updated_at = NOW()';
    }

    $sql = "INSERT INTO student_accounts (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ") ON CONFLICT (student_id) DO UPDATE SET " . implode(', ', $updates);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        sync_assignment_username($pdo, $studentId, $username, $password);
        $pdo->commit();
        return ['saved' => true, 'username' => $username];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['saved' => false, 'username' => $username];
    }
}

$pdo = get_pdo_connection();
$students = $pdo ? load_students_from_database($pdo) : [];
if ($pdo) {
    repair_duplicate_usernames($pdo);
}
$accounts = $pdo ? load_accounts_from_database($pdo) : [];

$studentsById = [];
foreach ($students as $student) {
    $id = (string) ($student['id'] ?? '');
    if ($id !== '') {
        $studentsById[$id] = $student;
    }
}

$accountsByStudentId = [];
$existingUsernames = [];
foreach ($accounts as $account) {
    $sid = (string) ($account['student_id'] ?? '');
    if ($sid !== '') {
        $accountsByStudentId[$sid] = $account;
        $existingUsernames[] = ['student_id' => $sid, 'username' => (string) ($account['username'] ?? '')];
    }
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $requestedUsername = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($studentId !== '' && $requestedUsername !== '' && $password !== '' && isset($studentsById[$studentId])) {
        $studentName = (string) ($studentsById[$studentId]['name'] ?? 'Estudiante');
        $result = save_student_profile_to_database($pdo, $studentId, $studentName, $requestedUsername, $password);
        if ($result['saved']) {
            header('Location: student_profiles.php?saved=1&username=' . urlencode((string) $result['username']));
            exit;
        }
        $error = 'No fue posible guardar el perfil.';
    } else {
        $error = 'Complete todos los campos y seleccione un estudiante válido.';
    }
}

if (isset($_GET['saved'])) {
    $message = 'Perfil guardado correctamente. Usuario asignado: ' . (string) ($_GET['username'] ?? '');
}

$studentNamesJson = json_encode(array_values(array_map(function ($s) {
    return ['id' => (string) ($s['id'] ?? ''), 'name' => (string) ($s['name'] ?? '')];
}, $students)), JSON_UNESCAPED_UNICODE);
$existingUsernamesJson = json_encode($existingUsernames, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Perfiles Estudiante</title>
<style>
:root{--bg:#fff8f5;--card:#fff;--border:#ffd9d2;--text:#5e352e;--title:#b04632;--btn:#fa8072;--btn-hover:#e8654e;--notice-bg:#eaf9ef;--notice-border:#bfe7cc;--notice-text:#1d6a40;--error-bg:#fff1f1;--error-border:#efb9b9;--error-text:#9f1d1d;--hint:#888}*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:var(--bg);padding:30px;color:var(--text);margin:0}.wrapper{max-width:1100px;margin:0 auto}.back{display:inline-block;margin-bottom:15px;color:var(--title);text-decoration:none;font-weight:700}.card{background:var(--card);border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(135,58,42,.10);margin-bottom:18px;border:1px solid var(--border)}h1,h2{color:var(--title);margin-top:0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.full{grid-column:1/-1}input,select{font:inherit;padding:10px;border:1px solid var(--border);border-radius:8px;width:100%;color:var(--text)}button{font:inherit;padding:10px;border-radius:8px;width:100%;background:var(--btn);color:#fff;border:none;font-weight:700;cursor:pointer}button:hover{background:var(--btn-hover)}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #ffe3de;text-align:left;font-size:14px}.notice,.error{padding:10px 12px;border-radius:8px;margin-bottom:12px;font-weight:600}.notice{background:var(--notice-bg);border:1px solid var(--notice-border);color:var(--notice-text)}.error{background:var(--error-bg);border:1px solid var(--error-border);color:var(--error-text)}.hint{font-size:12px;color:var(--hint);margin:2px 0 0}.badge-ok{display:inline-block;padding:2px 8px;border-radius:999px;background:#eaf9ef;color:#1d6a40;font-size:12px;font-weight:700}.badge-no{display:inline-block;padding:2px 8px;border-radius:999px;background:#fff2f2;color:#9f1d1d;font-size:12px;font-weight:700}@media(max-width:768px){body{padding:16px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrapper">
<a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
<div class="card">
<h1>🎓 Crear / actualizar perfil de estudiante</h1>
<p>El usuario se genera como <strong>nombre.apellido</strong>. Cuando ya existe, el sistema agrega automáticamente <strong>.1, .2...</strong> sin mezclar las cuentas.</p>
<?php if ($message !== '') { ?><div class="notice"><?php echo htmlspecialchars($message); ?></div><?php } ?>
<?php if ($error !== '') { ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php } ?>
<form method="post" class="grid">
<div class="full"><select name="student_id" id="student_select" required onchange="autoFillUsername()"><option value="">Seleccione estudiante inscrito</option><?php foreach ($students as $student) { ?><option value="<?php echo htmlspecialchars((string) ($student['id'] ?? '')); ?>"><?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?></option><?php } ?></select></div>
<div><input type="text" name="username" id="username_field" placeholder="nombre.apellido" required><p class="hint">La disponibilidad se valida nuevamente en el servidor al guardar.</p></div>
<div><input type="text" name="password" id="password_field" value="1234" required><p class="hint">Contraseña temporal por defecto: 1234.</p></div>
<button class="full" type="submit">Guardar perfil</button>
</form>
</div>
<div class="card"><h2>Estudiantes inscritos y estado de acceso</h2><table><thead><tr><th>Estudiante</th><th>Acudiente</th><th>Contacto</th><th>EPS</th><th>Usuario</th><th>Contraseña temporal</th><th>Acceso</th></tr></thead><tbody>
<?php if (empty($students)) { ?><tr><td colspan="7">No hay estudiantes inscritos.</td></tr><?php } else { foreach ($students as $student) { $sid=(string)($student['id']??''); $account=$accountsByStudentId[$sid]??null; ?>
<tr><td><strong><?php echo htmlspecialchars((string)($student['name']??'')); ?></strong></td><td><?php echo htmlspecialchars((string)($student['guardian']??'')); ?></td><td><?php echo htmlspecialchars((string)($student['contact']??'')); ?></td><td><?php echo htmlspecialchars((string)($student['eps']??'')); ?></td><td><?php echo $account?htmlspecialchars((string)($account['username']??'—')):'<span style="color:#aaa">Sin perfil</span>'; ?></td><td><?php echo $account?htmlspecialchars((string)($account['temp_password']??'—')):'—'; ?></td><td><?php echo $account?'<span class="badge-ok">✓ Activo</span>':'<span class="badge-no">Sin acceso</span>'; ?></td></tr>
<?php }} ?></tbody></table></div>
</div>
<script>
const studentNames=<?php echo $studentNamesJson; ?>;
const existingUsernames=<?php echo $existingUsernamesJson; ?>;
function slugify(value){value=value.toLowerCase().trim().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'.');return value.replace(/^\.+|\.+$/g,'')||'student';}
function generateUsername(fullName){const parts=fullName.trim().split(/\s+/);return parts.length>=2?slugify(parts[0])+'.'+slugify(parts[parts.length-1]):slugify(fullName);}
function availableUsername(base,studentId){const used=new Set(existingUsernames.filter(a=>a.student_id!==studentId).map(a=>String(a.username||'').toLowerCase()));let candidate=base;let n=1;while(used.has(candidate.toLowerCase())){candidate=base+'.'+n;n++;}return candidate;}
function autoFillUsername(){const selectedId=document.getElementById('student_select').value;const field=document.getElementById('username_field');if(!selectedId){field.value='';return;}const student=studentNames.find(s=>s.id===selectedId);if(student&&student.name){field.value=availableUsername(generateUsername(student.name),selectedId);}}
</script>
</body>
</html>

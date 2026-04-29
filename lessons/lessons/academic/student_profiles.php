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

function load_students_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("SELECT id, name, guardian, contact, eps FROM students ORDER BY name ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $select = 'id, student_id, student_name, username';
        if (table_has_column($pdo, 'student_accounts', 'temp_password')) {
            $select .= ', temp_password';
        }
        $stmt = $pdo->query("SELECT {$select} FROM student_accounts ORDER BY student_name ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function save_student_profile_to_database(string $studentId, string $studentName, string $username, string $password): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    if (!table_has_column($pdo, 'student_accounts', 'student_id')) {
        return false;
    }

    $columns = ['student_id', 'student_name', 'username'];
    $values = [':student_id', ':student_name', ':username'];
    $updates = [
        'student_name = EXCLUDED.student_name',
        'username = EXCLUDED.username',
    ];

    if (table_has_column($pdo, 'student_accounts', 'id')) {
        $columns[] = 'id';
        $values[] = ':id';
    }

    if (table_has_column($pdo, 'student_accounts', 'password_hash')) {
        $columns[] = 'password_hash';
        $values[] = ':password_hash';
        $updates[] = 'password_hash = EXCLUDED.password_hash';
    }

    if (table_has_column($pdo, 'student_accounts', 'temp_password')) {
        $columns[] = 'temp_password';
        $values[] = ':temp_password';
        $updates[] = 'temp_password = EXCLUDED.temp_password';
    }

    if (table_has_column($pdo, 'student_accounts', 'must_change_password')) {
        $columns[] = 'must_change_password';
        $values[] = ':must_change_password';
        $updates[] = 'must_change_password = EXCLUDED.must_change_password';
    }

    if (table_has_column($pdo, 'student_accounts', 'permission')) {
        $columns[] = 'permission';
        $values[] = ':permission';
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

    $sql = "INSERT INTO student_accounts (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $values) . ")
            ON CONFLICT (student_id) DO UPDATE SET
                " . implode(",\n                ", $updates);

    $params = [
        'student_id' => $studentId,
        'student_name' => $studentName,
        'username' => $username,
        'id' => uniqid('stu_acc_'),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'temp_password' => $password,
        'must_change_password' => true,
        'permission' => 'viewer',
    ];

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

$students = load_students_from_database();
$accounts = load_accounts_from_database();

$studentsById = [];
foreach ($students as $student) {
    $id = (string) ($student['id'] ?? '');
    if ($id !== '') {
        $studentsById[$id] = $student;
    }
}

// Mapa student_id → account para mostrar estado en la tabla de estudiantes
$accountsByStudentId = [];
foreach ($accounts as $account) {
    $sid = (string) ($account['student_id'] ?? '');
    if ($sid !== '') {
        $accountsByStudentId[$sid] = $account;
    }
}

// JSON de nombres para auto-generación de usuario en el frontend
$studentNamesJson = json_encode(array_values(array_map(function ($s) {
    return ['id' => (string) ($s['id'] ?? ''), 'name' => (string) ($s['name'] ?? '')];
}, $students)), JSON_UNESCAPED_UNICODE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $username   = trim((string) ($_POST['username'] ?? ''));
    $password   = trim((string) ($_POST['password'] ?? ''));

    if ($studentId !== '' && $username !== '' && $password !== '') {
        $studentName = (string) ($studentsById[$studentId]['name'] ?? 'Estudiante');
        save_student_profile_to_database($studentId, $studentName, $username, $password);
        header('Location: student_profiles.php?saved=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Perfiles Estudiante</title>
<style>
:root{--bg:#fff8f5;--card:#fff;--border:#ffd9d2;--text:#5e352e;--title:#b04632;--btn:#fa8072;--btn-hover:#e8654e;--notice-bg:#eaf9ef;--notice-border:#bfe7cc;--notice-text:#1d6a40;--hint:#888}
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:var(--bg);padding:30px;color:var(--text);margin:0}
.wrapper{max-width:1100px;margin:0 auto}
.back{display:inline-block;margin-bottom:15px;color:var(--title);text-decoration:none;font-weight:700}
.card{background:var(--card);border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(135,58,42,.10);margin-bottom:18px;border:1px solid var(--border)}
h1{margin-top:0;color:var(--title)}
h2{color:var(--title);margin-top:0}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.full{grid-column:1/-1}
input,select{font:inherit;padding:10px;border:1px solid var(--border);border-radius:8px;width:100%;color:var(--text)}
input:focus,select:focus{outline:none;border-color:var(--btn);box-shadow:0 0 0 3px rgba(250,128,114,.15)}
button{font:inherit;padding:10px;border-radius:8px;width:100%;background:var(--btn);color:#fff;border:none;font-weight:700;cursor:pointer}
button:hover{background:var(--btn-hover)}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #ffe3de;text-align:left;font-size:14px}
.notice{padding:10px 12px;border-radius:8px;background:var(--notice-bg);border:1px solid var(--notice-border);color:var(--notice-text);margin-bottom:12px;font-weight:600}
.hint{font-size:12px;color:var(--hint);margin:2px 0 0}
.badge-ok{display:inline-block;padding:2px 8px;border-radius:999px;background:#eaf9ef;color:#1d6a40;font-size:12px;font-weight:700}
.badge-no{display:inline-block;padding:2px 8px;border-radius:999px;background:#fff2f2;color:#9f1d1d;font-size:12px;font-weight:700}
@media(max-width:768px){body{padding:16px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

    <div class="card">
        <h1>🎓 Crear / actualizar perfil de estudiante</h1>
        <p>Crea o actualiza el usuario y contraseña del estudiante para su acceso. El usuario se sugiere automáticamente en formato <strong>nombre.apellido</strong>.</p>

        <?php if (isset($_GET['saved'])) { ?>
            <div class="notice">✅ Perfil guardado correctamente.</div>
        <?php } ?>

        <form method="post" class="grid">
            <div class="full">
                <select name="student_id" id="student_select" required onchange="autoFillUsername()">
                    <option value="">Seleccione estudiante inscrito</option>
                    <?php foreach ($students as $student) { ?>
                        <option value="<?php echo htmlspecialchars((string) ($student['id'] ?? '')); ?>">
                            <?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div>
                <input type="text" name="username" id="username_field" placeholder="nombre.apellido" required>
                <p class="hint">Se genera automáticamente al seleccionar el estudiante. Puede editarlo.</p>
            </div>

            <div>
                <input type="text" name="password" id="password_field" value="1234" required>
                <p class="hint">Contraseña temporal por defecto: 1234. Cámbiela si es necesario.</p>
            </div>

            <button class="full" type="submit">Guardar perfil</button>
        </form>
    </div>

    <div class="card">
        <h2>Estudiantes inscritos y estado de acceso</h2>
        <table>
            <thead>
                <tr>
                    <th>Estudiante</th>
                    <th>Acudiente</th>
                    <th>Contacto</th>
                    <th>EPS</th>
                    <th>Usuario</th>
                    <th>Contraseña temporal</th>
                    <th>Acceso</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($students)) { ?>
                <tr><td colspan="7">No hay estudiantes inscritos.</td></tr>
            <?php } else { ?>
                <?php foreach ($students as $student) {
                    $sid = (string) ($student['id'] ?? '');
                    $account = $accountsByStudentId[$sid] ?? null;
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string) ($student['name'] ?? '')); ?></strong></td>
                        <td><?php echo htmlspecialchars((string) ($student['guardian'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($student['contact'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($student['eps'] ?? '')); ?></td>
                        <td><?php echo $account ? htmlspecialchars((string) ($account['username'] ?? '—')) : '<span style="color:#aaa">Sin perfil</span>'; ?></td>
                        <td><?php echo $account ? htmlspecialchars((string) ($account['temp_password'] ?? '—')) : '—'; ?></td>
                        <td><?php echo $account ? '<span class="badge-ok">✓ Activo</span>' : '<span class="badge-no">Sin acceso</span>'; ?></td>
                    </tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const studentNames = <?php echo $studentNamesJson; ?>;

function slugify(value) {
    value = value.toLowerCase().trim();
    const map = {á:'a',é:'e',í:'i',ó:'o',ú:'u',ü:'u',ñ:'n',à:'a',è:'e',ì:'i',ò:'o',ù:'u'};
    value = value.replace(/[áéíóúüñàèìòù]/g, c => map[c] || c);
    value = value.replace(/[^a-z0-9]+/g, '.');
    return value.replace(/^\.+|\.+$/g, '');
}

function generateUsername(fullName) {
    const parts = fullName.trim().split(/\s+/);
    if (parts.length >= 2) {
        return slugify(parts[0]) + '.' + slugify(parts[parts.length - 1]);
    }
    return slugify(fullName) || 'student';
}

function autoFillUsername() {
    const select = document.getElementById('student_select');
    const usernameField = document.getElementById('username_field');
    const selectedId = select.value;
    if (!selectedId) {
        usernameField.value = '';
        return;
    }
    const student = studentNames.find(s => s.id === selectedId);
    if (student && student.name) {
        usernameField.value = generateUsername(student.name);
    }
}
</script>
</body>
</html>

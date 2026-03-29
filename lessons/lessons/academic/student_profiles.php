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
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM information_schema.columns\n            WHERE table_schema = 'public'\n              AND table_name = :table_name\n              AND column_name = :column_name\n            LIMIT 1\n        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
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

    $sql = "INSERT INTO student_accounts (" . implode(', ', $columns) . ")\n            VALUES (" . implode(', ', $values) . ")\n            ON CONFLICT (student_id) DO UPDATE SET\n                " . implode(",\n                ", $updates);

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

$dataDir = __DIR__ . '/data';
$studentsFile = $dataDir . '/students.json';
$accountsFile = $dataDir . '/student_accounts.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

foreach ([$studentsFile, $accountsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$students = json_decode((string) file_get_contents($studentsFile), true);
$accounts = json_decode((string) file_get_contents($accountsFile), true);
$students = is_array($students) ? $students : [];
$accounts = is_array($accounts) ? $accounts : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($studentId !== '' && $username !== '' && $password !== '') {
        $studentName = 'Estudiante';
        foreach ($students as $student) {
            if ((string) ($student['id'] ?? '') === $studentId) {
                $studentName = (string) ($student['name'] ?? 'Estudiante');
                break;
            }
        }

        $foundIndex = null;
        foreach ($accounts as $index => $account) {
            if ((string) ($account['student_id'] ?? '') === $studentId) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex === null) {
            foreach ($accounts as $index => $account) {
                if ((string) ($account['username'] ?? '') === $username) {
                    $foundIndex = $index;
                    break;
                }
            }
        }

        $record = [
            'id' => $foundIndex === null ? uniqid('stu_acc_') : (string) ($accounts[$foundIndex]['id'] ?? uniqid('stu_acc_')),
            'student_id' => $studentId,
            'student_name' => $studentName,
            'username' => $username,
            'password' => $password,
        ];

        if ($foundIndex === null) {
            $accounts[] = $record;
        } else {
            $accounts[$foundIndex] = $record;
        }

        file_put_contents($accountsFile, json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
body{font-family:Arial,sans-serif;background:#fff8f5;padding:30px;color:#5e352e}
.wrapper{max-width:1100px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(135,58,42,.10);margin-bottom:18px;border:1px solid #ffd9d2}
h1{margin-top:0;color:#b04632}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.full{grid-column:1/-1}
input,select,button{font:inherit;padding:10px;border:1px solid #f0beb4;border-radius:8px;width:100%}
button{background:#fa8072;color:#fff;border:none;font-weight:700;cursor:pointer}
button:hover{background:#e8654e}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #ffe3de;text-align:left;font-size:14px}
.back{display:inline-block;margin-bottom:15px;color:#b04632;text-decoration:none;font-weight:700}
.notice{padding:10px 12px;border-radius:8px;background:#eaf9ef;border:1px solid #bfe7cc;color:#1d6a40;margin-bottom:12px}
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

    <div class="card">
        <h1>🎓 Crear perfil de estudiante</h1>
        <p>Con este botón/forma se crea el usuario y contraseña del estudiante para su login y acceso a sus cursos.</p>
        <?php if (isset($_GET['saved'])) { ?><div class="notice">Perfil estudiante creado/actualizado.</div><?php } ?>

        <form method="post" class="grid">
            <select name="student_id" required>
                <option value="">Seleccione estudiante inscrito</option>
                <?php foreach ($students as $student) { ?>
                    <option value="<?php echo htmlspecialchars((string) ($student['id'] ?? '')); ?>"><?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?></option>
                <?php } ?>
            </select>

            <input type="text" name="username" placeholder="Crear usuario" required>
            <input class="full" type="text" name="password" placeholder="Crear password" required>
            <button class="full" type="submit">Crear perfil estudiante</button>
        </form>
    </div>

    <div class="card">
        <h2>Perfiles creados</h2>
        <table>
            <thead><tr><th>Estudiante</th><th>Usuario</th><th>ID</th></tr></thead>
            <tbody>
            <?php if (empty($accounts)) { ?>
                <tr><td colspan="3">No hay perfiles creados todavía.</td></tr>
            <?php } else { ?>
                <?php foreach ($accounts as $account) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($account['student_name'] ?? 'Estudiante')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['username'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['student_id'] ?? '')); ?></td>
                    </tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

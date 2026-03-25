<?php
session_start();

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

$studentId = (string) ($_SESSION['student_id'] ?? '');
if ($studentId === '') {
    session_unset();
    session_destroy();
    header('Location: login_student.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function load_student_accounts_from_json(): array
{
    $accountsFile = __DIR__ . '/data/student_accounts.json';
    if (!is_file($accountsFile)) {
        return [];
    }

    $accounts = json_decode((string) file_get_contents($accountsFile), true);
    return is_array($accounts) ? $accounts : [];
}

function save_student_accounts_to_json(array $accounts): void
{
    $accountsFile = __DIR__ . '/data/student_accounts.json';
    file_put_contents(
        $accountsFile,
        json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function verify_student_password(array $account, string $password): bool
{
    $passwordHash = trim((string) ($account['password_hash'] ?? ''));
    $tempPassword = (string) ($account['temp_password'] ?? '');
    $plainPassword = (string) ($account['password'] ?? '');

    if ($passwordHash !== '' && password_verify($password, $passwordHash)) {
        return true;
    }

    if ($tempPassword !== '' && hash_equals($tempPassword, $password)) {
        return true;
    }

    return $plainPassword !== '' && hash_equals($plainPassword, $password);
}

function load_student_account_from_database(PDO $pdo, string $studentId): ?array
{
    try {
        $hasPassword = table_has_column($pdo, 'student_accounts', 'password');
        $hasPasswordHash = table_has_column($pdo, 'student_accounts', 'password_hash');
        $hasTempPassword = table_has_column($pdo, 'student_accounts', 'temp_password');
        $hasMustChangePassword = table_has_column($pdo, 'student_accounts', 'must_change_password');
        $hasPasswordUpdatedAt = table_has_column($pdo, 'student_accounts', 'password_updated_at');

        $selectPassword = $hasPassword ? 'password' : "'' AS password";
        $selectPasswordHash = $hasPasswordHash ? 'password_hash' : "'' AS password_hash";
        $selectTempPassword = $hasTempPassword ? 'temp_password' : "'' AS temp_password";
        $selectMustChangePassword = $hasMustChangePassword ? 'must_change_password' : 'FALSE AS must_change_password';
        $selectPasswordUpdatedAt = $hasPasswordUpdatedAt ? 'password_updated_at' : 'NULL AS password_updated_at';

        $stmt = $pdo->prepare("SELECT id, student_id, {$selectPassword}, {$selectPasswordHash}, {$selectTempPassword}, {$selectMustChangePassword}, {$selectPasswordUpdatedAt} FROM student_accounts WHERE student_id = :student_id ORDER BY updated_at DESC NULLS LAST LIMIT 1");
        $stmt->execute(['student_id' => $studentId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($account) ? $account : null;
    } catch (Throwable $e) {
        return null;
    }
}

function update_student_password_in_database(PDO $pdo, string $studentId, string $newPassword): bool
{
    $setParts = ['updated_at = NOW()'];
    $params = ['student_id' => $studentId];

    if (table_has_column($pdo, 'student_accounts', 'password')) {
        $setParts[] = 'password = :password';
        $params['password'] = $newPassword;
    }

    if (table_has_column($pdo, 'student_accounts', 'password_hash')) {
        $setParts[] = 'password_hash = :password_hash';
        $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (table_has_column($pdo, 'student_accounts', 'temp_password')) {
        $setParts[] = 'temp_password = :temp_password';
        $params['temp_password'] = null;
    }

    if (table_has_column($pdo, 'student_accounts', 'must_change_password')) {
        $setParts[] = 'must_change_password = :must_change_password';
        $params['must_change_password'] = false;
    }

    if (table_has_column($pdo, 'student_accounts', 'password_updated_at')) {
        $setParts[] = 'password_updated_at = NOW()';
    }

    try {
        $sql = 'UPDATE student_accounts SET ' . implode(', ', $setParts) . ' WHERE student_id = :student_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function update_student_password_in_json(string $studentId, string $newPassword): bool
{
    $accounts = load_student_accounts_from_json();
    $updated = false;

    foreach ($accounts as $index => $account) {
        if ((string) ($account['student_id'] ?? '') !== $studentId) {
            continue;
        }

        $accounts[$index]['password'] = $newPassword;
        $accounts[$index]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $accounts[$index]['temp_password'] = '';
        $accounts[$index]['must_change_password'] = false;
        $updated = true;
        break;
    }

    if ($updated) {
        save_student_accounts_to_json($accounts);
    }

    return $updated;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = trim((string) ($_POST['current_password'] ?? ''));
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Completa todos los campos.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'La nueva contraseña y la confirmación no coinciden.';
    } elseif (mb_strlen($newPassword) < 4) {
        $error = 'La nueva contraseña debe tener al menos 4 caracteres.';
    } else {
        $pdo = get_pdo_connection();

        if ($pdo) {
            $account = load_student_account_from_database($pdo, $studentId);
            if (!$account) {
                $error = 'No se encontró la cuenta del estudiante.';
            } elseif (!verify_student_password($account, $currentPassword)) {
                $error = 'La contraseña actual no es correcta.';
            } elseif (update_student_password_in_database($pdo, $studentId, $newPassword)) {
                $_SESSION['student_must_change_password'] = false;
                header('Location: student_dashboard.php?password_changed=1');
                exit;
            } else {
                $error = 'No fue posible actualizar la contraseña.';
            }
        } else {
            $accounts = load_student_accounts_from_json();
            $account = null;
            foreach ($accounts as $row) {
                if ((string) ($row['student_id'] ?? '') === $studentId) {
                    $account = $row;
                    break;
                }
            }

            if (!$account) {
                $error = 'No se encontró la cuenta del estudiante.';
            } elseif (!verify_student_password((array) $account, $currentPassword)) {
                $error = 'La contraseña actual no es correcta.';
            } elseif (update_student_password_in_json($studentId, $newPassword)) {
                $_SESSION['student_must_change_password'] = false;
                header('Location: student_dashboard.php?password_changed=1');
                exit;
            } else {
                $error = 'No fue posible actualizar la contraseña.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar contraseña estudiante</title>
<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(180deg,#fff7f3,#fffdfc);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
}
.card{
    width:100%;
    max-width:420px;
    background:#fff;
    border:1px solid #ffd8d1;
    border-radius:18px;
    padding:26px;
    box-shadow:0 18px 40px rgba(153,60,44,.14);
}
h1{margin:0 0 8px;color:#b04632;font-size:28px;}
p{margin:0 0 16px;color:#7a5d56;}
input,button{width:100%;height:44px;border-radius:10px;border:1px solid #f0beb4;padding:0 12px;font-size:15px;}
input{margin-top:10px;}
button{margin-top:14px;background:#fa8072;color:#fff;border:none;font-weight:700;cursor:pointer;}
button:hover{background:#e8654e;}
.error{margin-top:10px;color:#c81e1e;font-weight:700;}
.success{margin-top:10px;color:#12703d;font-weight:700;}
</style>
</head>
<body>
<div class="card">
    <h1>Cambiar contraseña</h1>
    <p>Por seguridad, debes cambiar tu contraseña temporal antes de entrar al dashboard.</p>

    <form method="post">
        <input type="password" name="current_password" placeholder="Contraseña actual" required>
        <input type="password" name="new_password" placeholder="Nueva contraseña" required>
        <input type="password" name="confirm_password" placeholder="Confirmar nueva contraseña" required>
        <button type="submit">Guardar y entrar</button>
    </form>

    <?php if ($error): ?>
        <div class="error"><?php echo h($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?php echo h($success); ?></div>
    <?php endif; ?>
</div>
</body>
</html>

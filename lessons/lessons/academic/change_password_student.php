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
        $setParts[] = 'temp_password = NULL';   // SQL literal avoids PDO null binding error
    }

    if (table_has_column($pdo, 'student_accounts', 'must_change_password')) {
        $setParts[] = 'must_change_password = FALSE';  // SQL literal
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
            } else {
                $updated = update_student_password_in_database($pdo, $studentId, $newPassword);
                if (!$updated) {
                    // Fallback: try JSON in case rowCount was 0 due to no-op
                    update_student_password_in_json($studentId, $newPassword);
                }
                $_SESSION['student_must_change_password'] = false;
                header('Location: student_dashboard.php?password_changed=1');
                exit;
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
<title>Cambiar contraseña – Estudiante</title>
<style>
:root{
    --bg:#fff8e6;
    --card:#ffffff;
    --line:#dcc4f0;
    --title:#a855c8;
    --text:#f14902;
    --muted:#b8551f;
    --ocre:#f14902;
    --ocre-hover:#d33d00;
    --ocre-soft:#eddeff;
    --danger:#b42318;
    --danger-soft:#fff5f5;
    --shadow:0 18px 42px rgba(120,40,160,.14);
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(145deg,#fff8e6 0%,#fdeaff 55%,#f0e0ff 100%);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    color:var(--text);
}
.card{
    width:100%;
    max-width:430px;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:20px;
    padding:32px 28px;
    box-shadow:var(--shadow);
}
.card-icon{font-size:36px;margin-bottom:10px;}
h1{margin:0 0 6px;color:var(--title);font-size:26px;font-weight:800;}
.subtitle{margin:0 0 22px;color:var(--muted);font-size:14px;line-height:1.5;}
label{display:block;margin:12px 0 5px;font-weight:700;font-size:13px;color:var(--title);}
.password-wrap{position:relative;}
input[type=password],input[type=text]{
    width:100%;height:46px;border-radius:10px;
    border:1.5px solid var(--line);
    padding:0 48px 0 12px;
    font-size:15px;color:var(--text);background:#fff;outline:none;
    transition:border-color .15s,box-shadow .15s;
}
input:focus{border-color:var(--ocre);box-shadow:0 0 0 3px rgba(241,73,2,.15);}
.pwd-toggle{
    position:absolute;top:50%;right:10px;transform:translateY(-50%);
    width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;
    border:none;border-radius:8px;background:transparent;color:var(--muted);cursor:pointer;padding:0;
}
.pwd-toggle:hover{background:var(--ocre-soft);color:var(--ocre-hover);}
.pwd-toggle svg{width:18px;height:18px;}
.submit-btn{
    width:100%;height:48px;margin-top:22px;border:none;border-radius:12px;
    background:linear-gradient(180deg,var(--ocre),var(--ocre-hover));
    color:#fff;font-weight:800;font-size:15px;cursor:pointer;letter-spacing:.3px;
}
.submit-btn:hover{background:linear-gradient(180deg,#e54500,#c93800);}
.error-box{
    margin-top:14px;color:var(--danger);font-weight:700;font-size:13px;
    background:var(--danger-soft);border:1px solid #fecdd3;border-radius:10px;padding:10px 14px;
}
</style>
</head>
<body>
<div class="card">
    <div class="card-icon">🔐</div>
    <h1>Cambiar contraseña</h1>
    <p class="subtitle">Por seguridad debes cambiar tu contraseña temporal antes de continuar.</p>

    <form method="post">
        <label for="current_password">Contraseña actual</label>
        <div class="password-wrap">
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <button type="button" class="pwd-toggle" data-target="current_password" aria-label="Mostrar contraseña">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
        </div>

        <label for="new_password">Nueva contraseña</label>
        <div class="password-wrap">
            <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
            <button type="button" class="pwd-toggle" data-target="new_password" aria-label="Mostrar contraseña">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
        </div>

        <label for="confirm_password">Confirmar nueva contraseña</label>
        <div class="password-wrap">
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
            <button type="button" class="pwd-toggle" data-target="confirm_password" aria-label="Mostrar contraseña">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
        </div>

        <button type="submit" class="submit-btn">Guardar y entrar</button>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error-box"><?php echo h($error); ?></div>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('.pwd-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) return;
        var isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        btn.setAttribute('aria-label', isVisible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        btn.querySelector('svg').innerHTML = isVisible
            ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    });
});
</script>
</body>
</html>

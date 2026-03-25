<?php
session_start();

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/db.php';

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1"
        );
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function admin_users_json_file(): string
{
    return __DIR__ . '/data/users.json';
}

function load_admin_users_json(): array
{
    $file = admin_users_json_file();
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function save_admin_users_json(array $users): void
{
    file_put_contents(
        admin_users_json_file(),
        json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function update_admin_user_in_json(string $id, array $updates): bool
{
    $users = load_admin_users_json();
    $updated = false;

    foreach ($users as $index => $user) {
        if ((string) ($user['id'] ?? '') !== $id) {
            continue;
        }

        $users[$index] = array_merge($user, $updates);
        $updated = true;
        break;
    }

    if ($updated) {
        save_admin_users_json($users);
    }

    return $updated;
}

function update_admin_user_in_json_by_identifiers(string $adminId, string $adminEmail, string $adminUsername, array $updates): bool
{
    $users = load_admin_users_json();
    $updated = false;

    foreach ($users as $index => $user) {
        $userId = (string) ($user['id'] ?? '');
        $userEmail = trim((string) ($user['email'] ?? ''));
        $userUsername = trim((string) ($user['username'] ?? ''));

        $idMatches = ($adminId !== '' && $userId === $adminId);
        $emailMatches = ($adminEmail !== '' && $userEmail !== '' && strcasecmp($userEmail, $adminEmail) === 0);
        $usernameMatches = ($adminUsername !== '' && $userUsername !== '' && strcasecmp($userUsername, $adminUsername) === 0);

        if (!$idMatches && !$emailMatches && !$usernameMatches) {
            continue;
        }

        $users[$index] = array_merge($user, $updates);
        $updated = true;
        break;
    }

    if ($updated) {
        save_admin_users_json($users);
    }

    return $updated;
}

Security::initializeSession();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$_SESSION['admin_must_change_password'] = false;
header('Location: dashboard.php');
exit;

$csrfToken = Security::generateCSRFToken();
$error = '';
$success = '';
$hasMustChangePasswordColumn = table_has_column($pdo, 'admin_users', 'must_change_password');
$hasPasswordUpdatedAtColumn = table_has_column($pdo, 'admin_users', 'password_updated_at');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['_csrf_token'] ?? '');
    $newPassword = Security::sanitize((string) ($_POST['new_password'] ?? ''), 'string');
    $confirmPassword = Security::sanitize((string) ($_POST['confirm_password'] ?? ''), 'string');

    if (!Security::verifyCSRFToken($submittedToken)) {
        $error = 'Error de seguridad: token inválido. Intenta nuevamente.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'La confirmación de la contraseña no coincide.';
    } else {
        $newHash  = Security::hashPassword($newPassword);
        $adminId  = (string) ($_SESSION['admin_id'] ?? '');
        $adminEmail = (string) ($_SESSION['admin_email'] ?? '');
        $adminUsername = trim((string) ($_SESSION['admin_username'] ?? ''));

        $setParts = ['password_hash = :password_hash'];
        if ($hasMustChangePasswordColumn) {
            $setParts[] = 'must_change_password = FALSE';
        }
        if ($hasPasswordUpdatedAtColumn) {
            $setParts[] = 'password_updated_at = CURRENT_TIMESTAMP';
        }
        $setClause = implode(', ', $setParts);

        $dbUpdated = false;

        if ($adminId !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE admin_users SET {$setClause} WHERE id = :id");
                $stmt->execute(['password_hash' => $newHash, 'id' => $adminId]);
                if ($stmt->rowCount() > 0) {
                    $dbUpdated = true;
                }
            } catch (Throwable $e) {
            }
        }

        if ($adminEmail !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE admin_users SET {$setClause} WHERE email = :email");
                $stmt->execute(['password_hash' => $newHash, 'email' => $adminEmail]);
                if ($stmt->rowCount() > 0) {
                    $dbUpdated = true;
                }
            } catch (Throwable $e) {
            }
        }

        if ($adminUsername !== '' && table_has_column($pdo, 'admin_users', 'username')) {
            try {
                $stmt = $pdo->prepare("UPDATE admin_users SET {$setClause} WHERE username = :username");
                $stmt->execute(['password_hash' => $newHash, 'username' => $adminUsername]);
                if ($stmt->rowCount() > 0) {
                    $dbUpdated = true;
                }
            } catch (Throwable $e) {
            }
        }

        if ($dbUpdated) {
            $_SESSION['admin_must_change_password'] = false;
            update_admin_user_in_json_by_identifiers($adminId, $adminEmail, $adminUsername, [
                'password' => $newPassword,
                'password_hash' => $newHash,
                'must_change_password' => false,
            ]);
            Security::logSecurityEvent('admin_password_changed', 'Password updated successfully in DB', $adminId);
            header('Location: dashboard.php?password_updated=1');
            exit;
        }

        $jsonUpdated = update_admin_user_in_json_by_identifiers($adminId, $adminEmail, $adminUsername, [
            'password' => $newPassword,
            'password_hash' => $newHash,
            'must_change_password' => false,
        ]);

        if (!$jsonUpdated && $adminId !== '') {
            $jsonUpdated = update_admin_user_in_json($adminId, [
                'password' => $newPassword,
                'password_hash' => $newHash,
                'must_change_password' => false,
            ]);
        }

        if ($jsonUpdated) {
            $_SESSION['admin_must_change_password'] = false;
            Security::logSecurityEvent('admin_password_changed', 'Password updated via JSON fallback (no matching DB row)', $adminId);
            header('Location: dashboard.php?password_updated=1');
            exit;
        }

        Security::logSecurityEvent('admin_password_change_failed', 'Could not update password (DB and JSON both failed)', $adminId);
        $error = 'No fue posible actualizar la contraseña. Intenta nuevamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar Clave Admin</title>
<style>
:root{
    --bg:#edf7ef;
    --card:#ffffff;
    --line:#d7e9db;
    --title:#1f7a34;
    --text:#20432a;
    --muted:#5e7564;
    --green:#2fa34a;
    --green-dark:#237a35;
    --danger:#dc2626;
    --shadow:0 18px 42px rgba(0,0,0,.12);
}
*{ box-sizing:border-box; }
body{
    margin:0;
    min-height:100vh;
    font-family:Arial, "Segoe UI", sans-serif;
    background:radial-gradient(circle at top left, #f7fff8 0%, #edf7ef 38%, #e6f1e9 100%);
    color:var(--text);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
}
.card{
    width:100%;
    max-width:480px;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:24px;
    box-shadow:var(--shadow);
    padding:32px;
}
.title{
    margin:0 0 10px;
    color:var(--title);
    font-size:32px;
    font-weight:800;
}
.subtitle{
    margin:0 0 22px;
    color:var(--muted);
    font-size:14px;
    line-height:1.5;
}
.form-group{ margin-bottom:14px; }
.password-wrap{ position:relative; }
.form-label{ display:block; margin-bottom:7px; font-size:14px; font-weight:700; }
.form-input{
    width:100%;
    border:1px solid #cfe1d4;
    background:#f8fcf8;
    color:var(--text);
    border-radius:12px;
    padding:13px 14px;
    font-size:15px;
    outline:none;
    transition:border-color .2s ease, box-shadow .2s ease;
}
.form-input:focus{
    border-color:#78c487;
    box-shadow:0 0 0 4px rgba(47,163,74,.12);
}
.password-input{ padding-right:48px; }
.password-toggle{
    position:absolute;
    right:10px;
    top:50%;
    transform:translateY(-50%);
    border:none;
    background:transparent;
    cursor:pointer;
    font-size:18px;
    color:#577060;
}
.submit-btn{
    width:100%;
    border:none;
    border-radius:14px;
    background:linear-gradient(180deg, var(--green) 0%, var(--green-dark) 100%);
    color:#fff;
    font-size:15px;
    font-weight:800;
    padding:14px 18px;
    cursor:pointer;
    box-shadow:0 14px 26px rgba(35,122,53,.24);
}
.submit-btn:hover{ filter:brightness(1.02); }
.message{
    border-radius:14px;
    padding:12px 14px;
    font-size:14px;
    margin:0 0 18px;
}
.message.error{
    background:#fff1f2;
    border:1px solid #fecdd3;
    color:#b91c1c;
}
.message.success{
    background:#ecfdf3;
    border:1px solid #bbf7d0;
    color:#166534;
}
.note{
    margin-top:14px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}
@media (max-width: 520px) {
    .card{ padding:24px; border-radius:20px; }
    .title{ font-size:28px; }
}
</style>
</head>
<body>
<main class="card">
    <h1 class="title">Cambiar contraseña</h1>
    <p class="subtitle">Actualiza tu clave de administrador para continuar al panel. Usa una contraseña segura de al menos 8 caracteres.</p>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label class="form-label" for="new_password">Nueva contraseña</label>
            <div class="password-wrap">
                <input class="form-input password-input" id="new_password" type="password" name="new_password" required>
                <button class="password-toggle" type="button" data-target="new_password" aria-label="Mostrar u ocultar contraseña">👁</button>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="confirm_password">Confirmar contraseña</label>
            <div class="password-wrap">
                <input class="form-input password-input" id="confirm_password" type="password" name="confirm_password" required>
                <button class="password-toggle" type="button" data-target="confirm_password" aria-label="Mostrar u ocultar contraseña">👁</button>
            </div>
        </div>

        <button class="submit-btn" type="submit">Actualizar contraseña</button>
    </form>

    <p class="note">Cuando se guarde correctamente, el sistema te enviará al dashboard de administración.</p>
</main>

<script>
document.querySelectorAll('.password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-target'));
        if (!input) {
            return;
        }

        input.type = input.type === 'password' ? 'text' : 'password';
        button.textContent = input.type === 'password' ? '👁' : '🙈';
    });
});
</script>
</body>
</html>

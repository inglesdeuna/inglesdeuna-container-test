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

Security::initializeSession();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: login.php');
    exit;
}

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
        try {
            $setParts = ['password_hash = :password_hash'];
            if ($hasMustChangePasswordColumn) {
                $setParts[] = 'must_change_password = FALSE';
            }
            if ($hasPasswordUpdatedAtColumn) {
                $setParts[] = 'password_updated_at = CURRENT_TIMESTAMP';
            }

            $sql = 'UPDATE admin_users SET ' . implode(', ', $setParts) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'password_hash' => Security::hashPassword($newPassword),
                'id' => (string) ($_SESSION['admin_id'] ?? ''),
            ]);

            $_SESSION['admin_must_change_password'] = false;
            Security::logSecurityEvent('admin_password_changed', 'Password updated successfully', (string) ($_SESSION['admin_id'] ?? 'unknown'));
            header('Location: dashboard.php?password_updated=1');
            exit;
        } catch (Throwable $e) {
            Security::logSecurityEvent('admin_password_change_failed', 'Database error: ' . $e->getMessage(), (string) ($_SESSION['admin_id'] ?? 'unknown'));
            $error = 'No fue posible actualizar la contraseña. Intenta nuevamente.';
        }
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
}
.submit-btn{
    width:100%;
    border:none;
    border-radius:12px;
    padding:14px 16px;
    margin-top:8px;
    background:linear-gradient(180deg, var(--green), var(--green-dark));
    color:#fff;
    font-size:15px;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(47,163,74,.22);
}
.error{
    margin-top:14px;
    background:#fef2f2;
    border:1px solid #fecaca;
    color:var(--danger);
    border-radius:12px;
    padding:12px 14px;
    text-align:center;
    font-size:14px;
    font-weight:700;
}
.back-link{
    display:inline-block;
    margin-top:16px;
    color:var(--title);
    font-size:13px;
    font-weight:700;
    text-decoration:none;
}
</style>
</head>
<body>
<div class="card">
    <h1 class="title">Actualizar contraseña</h1>
    <p class="subtitle">Por seguridad, debes definir una nueva contraseña antes de continuar al panel administrador.</p>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label class="form-label" for="new_password">Nueva contraseña</label>
            <input class="form-input" id="new_password" type="password" name="new_password" required>
        </div>

        <div class="form-group">
            <label class="form-label" for="confirm_password">Confirmar contraseña</label>
            <input class="form-input" id="confirm_password" type="password" name="confirm_password" required>
        </div>

        <button class="submit-btn" type="submit">Guardar nueva contraseña</button>
    </form>

    <?php if ($error !== '') { ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php } ?>

    <a class="back-link" href="dashboard.php">Ir al panel</a>
</div>
</body>
</html>
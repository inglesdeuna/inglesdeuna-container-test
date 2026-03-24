<?php
session_start();
require_once __DIR__ . "/../config/security.php";
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: /lessons/lessons/admin/login.php");
    exit;
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $newPassword = Security::sanitize($_POST["new_password"] ?? "", "string");
    $confirmPassword = Security::sanitize($_POST["confirm_password"] ?? "", "string");

    if ($newPassword === "" || $confirmPassword === "") {
        $error = "Debes ingresar la nueva contraseña en ambos campos.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Las contraseñas no coinciden.";
    } else {
        try {
            $newHash = Security::hashPassword($newPassword);

            $stmt = $pdo->prepare("
                UPDATE admin_users 
                SET password_hash = :new_hash, 
                    must_change_password = FALSE, 
                    password_updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id
            ");
            $stmt->execute([
                'new_hash' => $newHash,
                'id' => $_SESSION['admin_id']
            ]);

            // Reiniciar flag en sesión
            $_SESSION['admin_must_change_password'] = false;

            Security::logSecurityEvent('admin_password_change', 'Password updated', (string) $_SESSION['admin_id']);
            $success = "Tu contraseña ha sido actualizada correctamente.";
        } catch (Throwable $e) {
            Security::logSecurityEvent('admin_password_change_failed', $e->getMessage(), (string) $_SESSION['admin_id']);
            $error = "Error al actualizar la contraseña. Intenta de nuevo.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cambiar contraseña</title>
</head>
<body>
<h2>Cambiar contraseña</h2>
<?php if ($error): ?>
<div style="color:red;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div style="color:green;"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<form method="post">
    <label>Nueva contraseña</label>
    <input type="password" name="new_password" required>
    <br>
    <label>Confirmar contraseña</label>
    <input type="password" name="confirm_password" required>
    <br>
    <button type="submit">Actualizar</button>
</form>
</body>
</html>

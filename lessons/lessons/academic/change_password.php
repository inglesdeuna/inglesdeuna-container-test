<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'Docente');

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

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = trim((string) ($_POST['current_password'] ?? ''));
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Completa todos los campos.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'La nueva contraseña y la confirmación no coinciden.';
    } elseif (mb_strlen($newPassword) < 6) {
        $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, password
                FROM teacher_accounts
                WHERE teacher_id = :teacher_id
                ORDER BY updated_at DESC NULLS LAST
                LIMIT 1
            ");
            $stmt->execute(['teacher_id' => $teacherId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                $error = 'No se encontró la cuenta del docente.';
            } elseif ((string) ($account['password'] ?? '') !== $currentPassword) {
                $error = 'La contraseña actual no es correcta.';
            } else {
                $setParts = [
                    'password = :new_password',
                    'updated_at = NOW()',
                ];

                if (table_has_column($pdo, 'teacher_accounts', 'must_change_password')) {
                    $setParts[] = 'must_change_password = FALSE';
                }

                if (table_has_column($pdo, 'teacher_accounts', 'password_updated_at')) {
                    $setParts[] = 'password_updated_at = NOW()';
                }

                $sql = "
                    UPDATE teacher_accounts
                    SET " . implode(",\n                        ", $setParts) . "
                    WHERE teacher_id = :teacher_id
                ";

                $update = $pdo->prepare($sql);
                $update->execute([
                    'new_password' => $newPassword,
                    'teacher_id' => $teacherId,
                ]);

                $_SESSION['teacher_must_change_password'] = false;
                header('Location: dashboard.php?password_changed=1');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'No fue posible actualizar la contraseña.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cambiar contraseña</title>
<style>
:root{
    --bg:#f4f7fc;
    --card:#ffffff;
    --line:#d6e0ee;
    --title:#1f4d8f;
    --text:#1f3559;
    --muted:#5d6f8f;
    --blue:#1f66cc;
    --blue-hover:#184fa3;
    --green:#15803d;
    --danger:#c42828;
    --shadow:0 18px 40px rgba(18,52,114,.15);
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(180deg,#eaf1ff,#f8fbff);
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
    border:1px solid #dce6f6;
    border-radius:18px;
    padding:28px;
    box-shadow:var(--shadow);
}
h1{
    margin:0 0 8px;
    color:var(--title);
    font-size:28px;
}
p{
    margin:0 0 18px;
    color:var(--muted);
    font-size:15px;
}
label{
    display:block;
    margin:10px 0 6px;
    font-weight:700;
    font-size:14px;
}
input{
    width:100%;
    height:46px;
    border-radius:10px;
    border:1px solid #c8d8f0;
    padding:0 12px;
    font-size:15px;
    outline:none;
}
input:focus{
    border-color:#8bb0ea;
}
button{
    width:100%;
    height:46px;
    margin-top:16px;
    border:none;
    border-radius:10px;
    background:var(--blue);
    color:#fff;
    font-weight:700;
    font-size:15px;
    cursor:pointer;
}
button:hover{
    background:var(--blue-hover);
}
.error{
    margin-top:12px;
    color:var(--danger);
    font-weight:700;
    font-size:14px;
}
</style>
</head>
<body>
<div class="card">
    <h1>Cambiar contraseña</h1>
    <p>Docente: <strong><?php echo h($teacherName); ?></strong></p>

    <form method="post">
        <label for="current_password">Contraseña actual</label>
        <input id="current_password" type="password" name="current_password" required>

        <label for="new_password">Nueva contraseña</label>
        <input id="new_password" type="password" name="new_password" required>

        <label for="confirm_password">Confirmar nueva contraseña</label>
        <input id="confirm_password" type="password" name="confirm_password" required>

        <button type="submit">Guardar nueva contraseña</button>
    </form>

    <?php if ($error !== '') { ?>
        <div class="error"><?php echo h($error); ?></div>
    <?php } ?>
</div>
</body>
</html>

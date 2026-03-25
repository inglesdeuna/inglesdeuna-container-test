<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'Docente');

function teacher_dashboard_redirect_url(): string
{
    return 'dashboard.php?password_changed=1';
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

function load_teacher_accounts_from_json(): array
{
    $accountsFile = __DIR__ . '/data/teacher_accounts.json';
    if (!is_file($accountsFile)) {
        return [];
    }

    $accounts = json_decode((string) file_get_contents($accountsFile), true);
    return is_array($accounts) ? $accounts : [];
}

function save_teacher_accounts_to_json(array $accounts): void
{
    $accountsFile = __DIR__ . '/data/teacher_accounts.json';
    file_put_contents(
        $accountsFile,
        json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function update_teacher_password_in_json(string $teacherId, string $newPassword): bool
{
    $accounts = load_teacher_accounts_from_json();
    $updated = false;

    foreach ($accounts as $index => $account) {
        if ((string) ($account['teacher_id'] ?? '') !== $teacherId) {
            continue;
        }

        $accounts[$index]['password'] = $newPassword;
        $accounts[$index]['password_hash'] = Security::hashPassword($newPassword);
        $accounts[$index]['temp_password'] = '';
        $accounts[$index]['must_change_password'] = false;
        $updated = true;
        break;
    }

    if ($updated) {
        save_teacher_accounts_to_json($accounts);
    }

    return $updated;
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

            $currentPasswordMatches = false;
            if ($account) {
                $currentPasswordMatches = (string) ($account['password'] ?? '') === $currentPassword;

                if (!$currentPasswordMatches && !empty($account['password_hash'] ?? '')) {
                    $currentPasswordMatches = Security::verifyPassword($currentPassword, (string) $account['password_hash']);
                }

                if (!$currentPasswordMatches && !empty($account['temp_password'] ?? '')) {
                    $currentPasswordMatches = hash_equals((string) $account['temp_password'], $currentPassword);
                }
            }

            if (!$account) {
                $error = 'No se encontró la cuenta del docente.';
            } elseif (!$currentPasswordMatches) {
                $error = 'La contraseña actual no es correcta.';
            } else {
                $setParts = [
                    'password = :new_password',
                    'updated_at = NOW()',
                ];

                if (table_has_column($pdo, 'teacher_accounts', 'password_hash')) {
                    $setParts[] = 'password_hash = :password_hash';
                }

                if (table_has_column($pdo, 'teacher_accounts', 'temp_password')) {
                    $setParts[] = 'temp_password = NULL';
                }

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
                    'password_hash' => Security::hashPassword($newPassword),
                    'teacher_id' => $teacherId,
                ]);

                $_SESSION['teacher_must_change_password'] = false;
                header('Location: ' . teacher_dashboard_redirect_url());
                exit;
            }
        } catch (Throwable $e) {
            if (update_teacher_password_in_json($teacherId, $newPassword)) {
                $_SESSION['teacher_must_change_password'] = false;
                header('Location: ' . teacher_dashboard_redirect_url());
                exit;
            }

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
    --bg:#eef4fc;
    --card:#ffffff;
    --line:#d6e0ee;
    --title:#1f4d8f;
    --text:#1f3559;
    --muted:#5d6f8f;
    --blue:#1f66cc;
    --blue-hover:#184fa3;
    --blue-soft:#e8f2ff;
    --danger:#c42828;
    --danger-soft:#fff1f2;
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
.password-wrap{ position:relative; }
input{
    width:100%;
    height:46px;
    border-radius:10px;
    border:1px solid var(--line);
    padding:0 48px 0 12px;
    font-size:15px;
    color:var(--text);
    background:#fff;
    outline:none;
}
.password-input{ padding-right:48px; }
.password-toggle{
    position:absolute;
    top:50%;
    right:12px;
    transform:translateY(-50%);
    width:30px;
    height:30px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin:0;
    padding:0;
    border:1px solid transparent;
    border-radius:999px;
    background:transparent;
    color:var(--muted);
    cursor:pointer;
    font-size:16px;
    line-height:1;
}
.password-toggle:hover{
    background:var(--blue-soft);
    color:var(--blue-hover);
}

.password-toggle:focus-visible{
    outline:none;
    border-color:#c8d8f0;
    background:var(--blue-soft);
}

input:focus{
    border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(31,102,204,.12);
}
.submit-btn{
    width:100%;
    height:46px;
    margin-top:16px;
    border:none;
    border-radius:10px;
    background:linear-gradient(180deg,var(--blue),var(--blue-hover));
    color:#fff;
    font-weight:700;
    font-size:15px;
    cursor:pointer;
}
.submit-btn:hover{
    background:linear-gradient(180deg,var(--blue-hover),#123f86);
}
.error{
    margin-top:12px;
    color:var(--danger);
    font-weight:700;
    font-size:14px;
    background:var(--danger-soft);
    border:1px solid #fecdd3;
    border-radius:10px;
    padding:10px 12px;
}
</style>
</head>
<body>
<div class="card">
    <h1>Cambiar contraseña</h1>
    <p>Docente: <strong><?php echo h($teacherName); ?></strong></p>

    <form method="post">
        <label for="current_password">Contraseña actual</label>
        <div class="password-wrap">
            <input class="password-input" id="current_password" type="password" name="current_password" required>
            <button class="password-toggle" type="button" data-target="current_password" aria-label="Mostrar u ocultar contraseña">👁</button>
        </div>

        <label for="new_password">Nueva contraseña</label>
        <div class="password-wrap">
            <input class="password-input" id="new_password" type="password" name="new_password" required>
            <button class="password-toggle" type="button" data-target="new_password" aria-label="Mostrar u ocultar contraseña">👁</button>
        </div>

        <label for="confirm_password">Confirmar nueva contraseña</label>
        <div class="password-wrap">
            <input class="password-input" id="confirm_password" type="password" name="confirm_password" required>
            <button class="password-toggle" type="button" data-target="confirm_password" aria-label="Mostrar u ocultar contraseña">👁</button>
        </div>

        <button class="submit-btn" type="submit">Guardar nueva contraseña</button>
    </form>

    <?php if ($error !== '') { ?>
        <div class="error"><?php echo h($error); ?></div>
    <?php } ?>
</div>
<script>
document.querySelectorAll('.password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        var targetId = button.getAttribute('data-target');
        var input = targetId ? document.getElementById(targetId) : null;
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

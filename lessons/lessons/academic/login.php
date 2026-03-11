<?php
session_start();

if (isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true) {
    header('Location: dashboard.php');
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

function teacher_accounts_has_column(PDO $pdo, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'teacher_accounts'
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute(['column_name' => $columnName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function load_teacher_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    $hasMustChangePassword = teacher_accounts_has_column($pdo, 'must_change_password');
    $hasIsActive = teacher_accounts_has_column($pdo, 'is_active');

    $selectMustChangePassword = $hasMustChangePassword
        ? 'must_change_password'
        : 'FALSE AS must_change_password';

    $selectIsActive = $hasIsActive
        ? 'is_active'
        : 'TRUE AS is_active';

    try {
        $sql = "
            SELECT
                id,
                teacher_id,
                teacher_name,
                username,
                password,
                permission,
                scope,
                target_id,
                target_name,
                {$selectMustChangePassword},
                {$selectIsActive},
                updated_at
            FROM teacher_accounts
            ORDER BY updated_at DESC NULLS LAST, teacher_name ASC
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_accounts_from_json(): array
{
    $dataDir = __DIR__ . '/data';
    $accountsFile = $dataDir . '/teacher_accounts.json';

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    if (!file_exists($accountsFile)) {
        file_put_contents($accountsFile, '[]');
    }

    $accounts = json_decode((string) file_get_contents($accountsFile), true);
    return is_array($accounts) ? $accounts : [];
}

$accounts = load_teacher_accounts_from_database();
if (empty($accounts)) {
    $accounts = load_teacher_accounts_from_json();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_unset();
    session_destroy();
    session_start();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    foreach ($accounts as $account) {
        $accountUsername = trim((string) ($account['username'] ?? ''));
        $accountPassword = (string) ($account['password'] ?? '');
        $isActive = (bool) ($account['is_active'] ?? true);
        $mustChangePassword = (bool) ($account['must_change_password'] ?? false);

        if ($accountUsername !== $username) {
            continue;
        }

        if (!$isActive) {
            $error = 'Tu cuenta está inactiva. Comunícate con administración.';
            break;
        }

        if ($accountPassword !== $password) {
            continue;
        }

        $_SESSION['academic_logged'] = true;
        $_SESSION['teacher_id'] = (string) ($account['teacher_id'] ?? '');
        $_SESSION['teacher_name'] = (string) ($account['teacher_name'] ?? 'Docente');
        $_SESSION['teacher_username'] = $username;
        $_SESSION['teacher_account_id'] = (string) ($account['id'] ?? '');
        $_SESSION['teacher_must_change_password'] = $mustChangePassword;

        if ($mustChangePassword) {
            header('Location: change_password.php');
            exit;
        }

        header('Location: dashboard.php');
        exit;
    }

    if ($error === '') {
        $error = 'Usuario o contraseña inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Docente</title>
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
    --danger:#c42828;
    --shadow:0 18px 40px rgba(18,52,114,.15);
}

*{
    box-sizing:border-box;
}

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
    max-width:400px;
    background:var(--card);
    border:1px solid #dce6f6;
    border-radius:18px;
    padding:28px;
    box-shadow:var(--shadow);
}

h1{
    margin:0 0 8px;
    color:var(--title);
    font-size:30px;
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

.small{
    margin-top:16px;
    font-size:13px;
    color:var(--muted);
}

.small a{
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
}
</style>
</head>
<body>
<div class="card">
    <h1>Perfil Docente</h1>
    <p>Ingresa con tu usuario asignado.</p>

    <form method="post">
        <label for="username">Usuario</label>
        <input id="username" type="text" name="username" placeholder="Usuario" required>

        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" placeholder="Contraseña" required>

        <button type="submit">Entrar</button>
    </form>

    <?php if ($error !== '') { ?>
        <div class="error"><?php echo h($error); ?></div>
    <?php } ?>

    <div class="small">¿Eres estudiante? <a href="login_student.php">Ir a login estudiante</a></div>
</div>
</body>
</html>

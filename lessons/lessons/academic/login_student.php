<?php
session_start();

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged'] === true) {
    header('Location: student_dashboard.php');
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

function load_student_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, student_id, student_name, username, password_hash, temp_password, password
            FROM student_accounts
            ORDER BY updated_at DESC NULLS LAST, student_name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_student_accounts_from_json(): array
{
    $dataDir = __DIR__ . '/data';
    $accountsFile = $dataDir . '/student_accounts.json';

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    if (!file_exists($accountsFile)) {
        file_put_contents($accountsFile, '[]');
    }

    $accounts = json_decode((string) file_get_contents($accountsFile), true);
    return is_array($accounts) ? $accounts : [];
}

function verify_student_password(array $account, string $password): bool
{
    $passwordHash = (string) ($account['password_hash'] ?? '');
    $tempPassword = (string) ($account['temp_password'] ?? '');
    $legacyPassword = (string) ($account['password'] ?? '');

    if ($passwordHash !== '' && password_verify($password, $passwordHash)) {
        return true;
    }

    if ($tempPassword !== '' && hash_equals($tempPassword, $password)) {
        return true;
    }

    if ($legacyPassword !== '' && hash_equals($legacyPassword, $password)) {
        return true;
    }

    return false;
}

$accounts = load_student_accounts_from_database();
if (empty($accounts)) {
    $accounts = load_student_accounts_from_json();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_unset();
    session_destroy();
    session_start();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    foreach ($accounts as $account) {
        if ((string) ($account['username'] ?? '') !== $username) {
            continue;
        }

        if (!verify_student_password($account, $password)) {
            continue;
        }

        $_SESSION['student_logged'] = true;
        $_SESSION['student_id'] = (string) ($account['student_id'] ?? '');
        $_SESSION['student_name'] = (string) ($account['student_name'] ?? 'Estudiante');
        $_SESSION['student_username'] = $username;

        header('Location: student_dashboard.php');
        exit;
    }

    $error = 'Usuario o contraseña inválidos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Estudiante</title>
<style>
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(180deg,#eaf1ff,#f8fbff);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
}
.card{
    width:100%;
    max-width:380px;
    background:#fff;
    border:1px solid #dce6f6;
    border-radius:18px;
    padding:26px;
    box-shadow:0 18px 40px rgba(18,52,114,.15);
}
h1{
    margin:0 0 6px;
    color:#1f3c75;
    font-size:28px;
}
p{
    margin:0 0 16px;
    color:#5a6780;
}
input,button{
    width:100%;
    height:44px;
    border-radius:10px;
    border:1px solid #c8d8f0;
    padding:0 12px;
    font-size:15px;
}
input{
    margin-top:10px;
}
button{
    margin-top:14px;
    background:#1f66cc;
    color:#fff;
    border:none;
    font-weight:700;
    cursor:pointer;
}
.error{
    margin-top:10px;
    color:#c81e1e;
    font-weight:700;
}
.small{
    margin-top:14px;
    font-size:13px;
}
.small a{
    color:#1f66cc;
    text-decoration:none;
    font-weight:700;
}
</style>
</head>
<body>
<div class="card">
    <h1>Perfil Estudiante</h1>
    <p>Ingresa con tu usuario asignado.</p>

    <form method="post">
        <input type="text" name="username" placeholder="Usuario" required>
        <input type="password" name="password" placeholder="Contraseña" required>
        <button type="submit">Entrar</button>
    </form>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="small">¿Eres docente? <a href="login.php">Ir a login docente</a></div>
</div>
</body>
</html>

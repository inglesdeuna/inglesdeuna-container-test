<?php
session_start();

if (isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true) {
    header('Location: dashboard.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$accountsFile = $dataDir . '/teacher_accounts.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($accountsFile)) {
    file_put_contents($accountsFile, '[]');
}

$accounts = json_decode((string) file_get_contents($accountsFile), true);
$accounts = is_array($accounts) ? $accounts : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_unset();
    session_destroy();
    session_start();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    foreach ($accounts as $account) {
        if ((string) ($account['username'] ?? '') === $username && (string) ($account['password'] ?? '') === $password) {
            $_SESSION['academic_logged'] = true;
            $_SESSION['teacher_id'] = (string) ($account['teacher_id'] ?? '');
            $_SESSION['teacher_name'] = (string) ($account['teacher_name'] ?? 'Docente');
            $_SESSION['teacher_username'] = $username;
            header('Location: dashboard.php');
            exit;
        }
    }

    $error = 'Usuario o contraseña inválidos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Docente</title>
<style>
body{font-family:Arial,sans-serif;background:#f0fdf4;display:flex;justify-content:center;align-items:center;height:100vh}
.card{background:white;padding:30px;border-radius:16px;width:340px;box-shadow:0 10px 25px rgba(0,0,0,.15)}
h2{text-align:center;color:#16a34a}
input{width:100%;padding:10px;margin-top:12px}
button{width:100%;margin-top:20px;padding:12px;border:none;border-radius:10px;background:#16a34a;color:white;font-weight:bold}
.error{color:red;font-size:14px;margin-top:10px;text-align:center}
</style>
</head>
<body>
<div class="card">
  <h2>👩‍🏫 Acceso Docente</h2>
  <form method="post">
    <input type="text" name="username" placeholder="Usuario" required>
    <input type="password" name="password" placeholder="Contraseña" required>
    <button>Ingresar</button>
  </form>
  <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
</div>
</body>
</html>

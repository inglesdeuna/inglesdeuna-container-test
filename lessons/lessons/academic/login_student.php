<?php
session_start();

$dataDir = __DIR__ . '/data';
$accountsFile = $dataDir . '/student_accounts.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($accountsFile)) {
    file_put_contents($accountsFile, '[]');
}

$accounts = json_decode((string) file_get_contents($accountsFile), true);
$accounts = is_array($accounts) ? $accounts : [];

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged'] === true) {
    header('Location: student_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_unset();
    session_destroy();
    session_start();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    foreach ($accounts as $account) {
        if ((string) ($account['username'] ?? '') === $username && (string) ($account['password'] ?? '') === $password) {
            $_SESSION['student_logged'] = true;
            $_SESSION['student_id'] = (string) ($account['student_id'] ?? '');
            $_SESSION['student_name'] = (string) ($account['student_name'] ?? 'Estudiante');
            $_SESSION['student_username'] = $username;
            header('Location: student_dashboard.php');
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
<title>Login Estudiante</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:30px;border-radius:14px;max-width:400px;margin:auto}
button,input{padding:10px;width:100%;margin-top:10px}
.error{color:#dc2626;margin-bottom:10px}
</style>
</head>
<body>
<div class="box">
  <h2>🎓 Login Estudiante</h2>

  <?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="text" name="username" placeholder="Usuario" required>
    <input type="password" name="password" placeholder="Contraseña" required>
    <button type="submit">Ingresar</button>
  </form>
</div>
</body>
</html>

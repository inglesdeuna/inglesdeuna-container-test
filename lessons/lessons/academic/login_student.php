<?php
session_start();

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged'] === true) {
    if (!empty($_SESSION['student_must_change_password'])) {
        header('Location: change_password_student.php');
        exit;
    }
    header('Location: student_dashboard.php');
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

function load_student_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $hasMustChangePassword = table_has_column($pdo, 'student_accounts', 'must_change_password');
        $selectMustChangePassword = $hasMustChangePassword ? 'must_change_password' : 'FALSE AS must_change_password';

        $select = "id, student_id, student_name, username, password_hash, temp_password, {$selectMustChangePassword}, updated_at";
        if (table_has_column($pdo, 'student_accounts', 'permission')) {
            $select .= ', permission';
        }
        if (table_has_column($pdo, 'student_accounts', 'student_photo')) {
            $select .= ', student_photo';
        }

        $stmt = $pdo->query("SELECT {$select} FROM student_accounts ORDER BY updated_at DESC NULLS LAST, student_name ASC");
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

function update_student_password_in_database(string $username, string $newPassword, bool $mustChangePassword): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    $setParts = [
        'updated_at = NOW()',
    ];
    $params = [
        'username' => $username,
    ];

    if (table_has_column($pdo, 'student_accounts', 'password')) {
        $setParts[] = 'password = :password';
        $params['password'] = $newPassword;
    }

    if (table_has_column($pdo, 'student_accounts', 'password_hash')) {
        $setParts[] = 'password_hash = :password_hash';
        $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (table_has_column($pdo, 'student_accounts', 'temp_password')) {
        $setParts[] = 'temp_password = :temp_password';
        $params['temp_password'] = $mustChangePassword ? $newPassword : null;
    }

    if (table_has_column($pdo, 'student_accounts', 'must_change_password')) {
        $setParts[] = 'must_change_password = :must_change_password';
        $params['must_change_password'] = $mustChangePassword;
    }

    if (table_has_column($pdo, 'student_accounts', 'password_updated_at')) {
        $setParts[] = $mustChangePassword ? 'password_updated_at = NULL' : 'password_updated_at = NOW()';
    }

    try {
        $sql = 'UPDATE student_accounts SET ' . implode(', ', $setParts) . ' WHERE username = :username';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function update_student_password_in_json(string $username, string $newPassword, bool $mustChangePassword): bool
{
    $accounts = load_student_accounts_from_json();
    $updated = false;

    foreach ($accounts as $index => $account) {
        if ((string) ($account['username'] ?? '') !== $username) {
            continue;
        }

        $accounts[$index]['password'] = $newPassword;
        $accounts[$index]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $accounts[$index]['temp_password'] = $mustChangePassword ? $newPassword : '';
        $accounts[$index]['must_change_password'] = $mustChangePassword;
        $updated = true;
        break;
    }

    if ($updated) {
        $accountsFile = __DIR__ . '/data/student_accounts.json';
        file_put_contents(
            $accountsFile,
            json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return $updated;
}

$accounts = load_student_accounts_from_database();
if (empty($accounts)) {
    $accounts = load_student_accounts_from_json();
}

$error = '';
$success = '';
$temporaryPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'recover_password') {
        $recoveryUsername = trim((string) ($_POST['recovery_username'] ?? ''));

        if ($recoveryUsername === '') {
            $error = 'Ingresa tu usuario para recuperar la clave.';
        } else {
            $temporaryPassword = '1234';
            $recovered = false;

            if (update_student_password_in_database($recoveryUsername, $temporaryPassword, true)) {
                $recovered = true;
            } elseif (update_student_password_in_json($recoveryUsername, $temporaryPassword, true)) {
                $recovered = true;
            }

            if ($recovered) {
                $success = 'Tu contraseña temporal fue restablecida. Usa 1234 para ingresar y cámbiala de inmediato.';
            } else {
                $error = 'No encontramos un estudiante con ese usuario.';
            }
        }
    } else {
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
            $_SESSION['student_permission'] = ((string) ($account['permission'] ?? 'viewer')) === 'editor' ? 'editor' : 'viewer';
            $_SESSION['student_photo'] = (string) ($account['student_photo'] ?? '');

            $mustChangePassword = (bool) ($account['must_change_password'] ?? false);
            $tempPassword = (string) ($account['temp_password'] ?? '');
            if (!$mustChangePassword && $tempPassword !== '' && hash_equals($tempPassword, $password)) {
                $mustChangePassword = true;
            }
            $_SESSION['student_must_change_password'] = $mustChangePassword;

            if ($mustChangePassword) {
                header('Location: change_password_student.php');
                exit;
            }

            header('Location: student_dashboard.php');
            exit;
        }

        $error = 'Usuario o contraseña inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Estudiante</title>
<style>
:root{
    --bg:#f8f3e8;
    --card:#ffffff;
    --line:#e8dcc1;
    --title:#8a5a13;
    --text:#5a4522;
    --muted:#7b6642;
    --ocre:#b7791f;
    --ocre-dark:#8a5a13;
    --ocre-soft:#f6ead2;
    --danger:#b42318;
    --ok:#166534;
    --shadow:0 18px 42px rgba(138,90,19,.16);
}

*{box-sizing:border-box;}
html, body{height:100%;}

body{
    margin:0;
    font-family:Arial, "Segoe UI", sans-serif;
    background:radial-gradient(circle at top left, #fffaf0 0%, #f8f3e8 45%, #f2e7d0 100%);
    color:var(--text);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
}

.login-wrap{
    width:100%;
    max-width:980px;
    display:grid;
    grid-template-columns:1.05fr .95fr;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:28px;
    overflow:hidden;
    box-shadow:var(--shadow);
}

.login-side{
    background:linear-gradient(180deg, var(--ocre) 0%, var(--ocre-dark) 100%);
    color:#fff;
    padding:44px 40px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    min-height:600px;
}

.brand-badge{
    width:76px;
    height:76px;
    border-radius:20px;
    background:rgba(255,255,255,.18);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:34px;
    margin-bottom:22px;
}

.login-side h1{
    margin:0 0 14px;
    font-size:40px;
    line-height:1.05;
    font-weight:800;
}

.login-side p{
    margin:0 0 16px;
    font-size:17px;
    line-height:1.5;
    color:rgba(255,255,255,.92);
}

.side-pills{display:flex;gap:10px;flex-wrap:wrap;}
.side-pills span{
    border:1px solid rgba(255,255,255,.34);
    border-radius:999px;
    padding:8px 14px;
    font-size:13px;
    background:rgba(255,255,255,.12);
}

.form-side{
    padding:40px 36px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.card{width:100%;max-width:380px;}
.card h2{margin:0 0 8px;font-size:30px;color:var(--title);}
.card p{margin:0 0 18px;color:var(--muted);}

label{
    display:block;
    margin-bottom:6px;
    font-size:13px;
    color:var(--muted);
    font-weight:700;
}

input{
    width:100%;
    height:46px;
    border-radius:12px;
    border:1px solid var(--line);
    padding:0 12px;
    font-size:15px;
    background:#fff;
}

input:focus{
    outline:none;
    border-color:var(--ocre);
    box-shadow:0 0 0 3px rgba(183,121,31,.16);
}

.field{margin-bottom:12px;}

.password-wrap{
    position:relative;
}

.toggle-password{
    position:absolute;
    right:8px;
    top:7px;
    border:none;
    background:var(--ocre-soft);
    color:var(--ocre-dark);
    height:32px;
    padding:0 10px;
    border-radius:8px;
    cursor:pointer;
    font-size:12px;
    font-weight:700;
}

.submit-btn{
    width:100%;
    height:46px;
    border:none;
    border-radius:12px;
    background:var(--ocre);
    color:#fff;
    font-weight:800;
    cursor:pointer;
    margin-top:6px;
}

.submit-btn:hover{background:var(--ocre-dark);}

.inline-links{
    margin-top:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    font-size:13px;
}

.inline-links a{
    color:var(--title);
    text-decoration:none;
    font-weight:700;
}

.recover-card{
    margin-top:14px;
    border:1px solid var(--line);
    border-radius:12px;
    padding:12px;
    background:#fffdf7;
    display:none;
}

.recover-card.visible{display:block;}

.recover-btn{
    width:100%;
    margin-top:10px;
    height:40px;
    border:none;
    border-radius:10px;
    background:var(--ocre-soft);
    color:var(--ocre-dark);
    font-weight:700;
    cursor:pointer;
}

.error{margin-top:10px;color:var(--danger);font-weight:700;font-size:14px;}
.success{margin-top:10px;color:var(--ok);font-weight:700;font-size:14px;}

@media (max-width: 900px){
    .login-wrap{grid-template-columns:1fr;max-width:520px;}
    .login-side{min-height:auto;padding:28px 26px;}
    .login-side h1{font-size:30px;}
    .form-side{padding:26px 22px 30px;}
}
</style>
</head>
<body>
<div class="login-wrap">
    <aside class="login-side">
        <div class="brand-badge">🎓</div>
        <h1>Portal<br>Estudiante</h1>
        <p>Ingresa con tu usuario y contraseña para acceder a tus cursos, unidades y puntajes.</p>
        <div class="side-pills">
            <span>Acceso seguro</span>
            <span>Modo estudiante</span>
            <span>Cambio de clave</span>
        </div>
    </aside>

    <section class="form-side">
        <div class="card">
            <h2>Iniciar sesión</h2>
            <p>Accede a tu perfil académico.</p>

            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="login">

                <div class="field">
                    <label for="username">Usuario</label>
                    <input id="username" type="text" name="username" placeholder="Usuario" required>
                </div>

                <div class="field">
                    <label for="password">Contraseña</label>
                    <div class="password-wrap">
                        <input id="password" type="password" name="password" placeholder="Contraseña" required>
                        <button type="button" class="toggle-password" id="togglePassword">Mostrar</button>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Entrar</button>
            </form>

            <div class="inline-links">
                <a href="#" id="forgotPasswordLink">¿Olvidaste tu contraseña?</a>
                <a href="login.php">Login docente</a>
            </div>

            <div class="recover-card" id="recoverCard">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="recover_password">
                    <label for="recovery_username">Usuario estudiante</label>
                    <input id="recovery_username" type="text" name="recovery_username" placeholder="Ej: maria.1020" required>
                    <button type="submit" class="recover-btn">Restablecer a 1234</button>
                </form>
            </div>

            <?php if ($error): ?>
                <div class="error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success"><?php echo h($success); ?></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');
const forgotPasswordLink = document.getElementById('forgotPasswordLink');
const recoverCard = document.getElementById('recoverCard');

if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', function () {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        togglePassword.textContent = isPassword ? 'Ocultar' : 'Mostrar';
    });
}

if (forgotPasswordLink && recoverCard) {
    forgotPasswordLink.addEventListener('click', function (event) {
        event.preventDefault();
        recoverCard.classList.toggle('visible');
    });
}
</script>
</body>
</html>

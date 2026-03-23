<?php
session_start();

// Load security utilities
require_once __DIR__ . "/../config/security.php";
require_once __DIR__ . "/../config/input_validator.php";

// Initialize secure session
Security::initializeSession();

// Si ya hay un docente logueado, ir al dashboard
if (isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true) {
    if (!empty($_SESSION['teacher_must_change_password'])) {
        header('Location: change_password.php');
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

// Helper function
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Load teacher accounts from database
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

function load_teacher_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    $hasMustChangePassword = table_has_column($pdo, 'teacher_accounts', 'must_change_password');
    $hasIsActive = table_has_column($pdo, 'teacher_accounts', 'is_active');
    $hasPasswordHash = table_has_column($pdo, 'teacher_accounts', 'password_hash');
    $hasTempPassword = table_has_column($pdo, 'teacher_accounts', 'temp_password');

    $selectMustChangePassword = $hasMustChangePassword ? 'must_change_password' : 'FALSE AS must_change_password';
    $selectIsActive = $hasIsActive ? 'is_active' : 'TRUE AS is_active';
    $selectPasswordHash = $hasPasswordHash ? 'password_hash' : "'' AS password_hash";
    $selectTempPassword = $hasTempPassword ? 'temp_password' : "'' AS temp_password";

    try {
        $sql = "
            SELECT id, teacher_id, teacher_name, username, password, permission, scope, target_id, target_name,
                   {$selectMustChangePassword}, {$selectIsActive}, {$selectPasswordHash}, {$selectTempPassword}, updated_at
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

function save_teacher_accounts_to_json(array $accounts): void
{
    $accountsFile = __DIR__ . '/data/teacher_accounts.json';
    file_put_contents(
        $accountsFile,
        json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function verify_teacher_password(array $account, string $password): bool
{
    $passwordHash = trim((string) ($account['password_hash'] ?? ''));
    $tempPassword = (string) ($account['temp_password'] ?? '');
    $plainPassword = (string) ($account['password'] ?? '');

    if ($passwordHash !== '' && Security::verifyPassword($password, $passwordHash)) {
        return true;
    }

    if ($tempPassword !== '' && hash_equals($tempPassword, $password)) {
        return true;
    }

    return $plainPassword !== '' && hash_equals($plainPassword, $password);
}

function update_teacher_password_in_database(string $username, string $newPassword, bool $mustChangePassword): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    $setParts = [
        'password = :password',
        'updated_at = NOW()',
    ];

    if (table_has_column($pdo, 'teacher_accounts', 'must_change_password')) {
        $setParts[] = 'must_change_password = :must_change_password';
    }

    if (table_has_column($pdo, 'teacher_accounts', 'password_hash')) {
        $setParts[] = 'password_hash = :password_hash';
    }

    if (table_has_column($pdo, 'teacher_accounts', 'temp_password')) {
        $setParts[] = 'temp_password = :temp_password';
    }

    if (table_has_column($pdo, 'teacher_accounts', 'password_updated_at')) {
        $setParts[] = $mustChangePassword ? 'password_updated_at = NULL' : 'password_updated_at = NOW()';
    }

    try {
        $sql = 'UPDATE teacher_accounts SET ' . implode(', ', $setParts) . ' WHERE username = :username';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'password' => $newPassword,
            'must_change_password' => $mustChangePassword,
            'password_hash' => Security::hashPassword($newPassword),
            'temp_password' => $mustChangePassword ? $newPassword : null,
            'username' => $username,
        ]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function update_teacher_password_in_json(string $username, string $newPassword, bool $mustChangePassword): bool
{
    $accounts = load_teacher_accounts_from_json();
    $updated = false;

    foreach ($accounts as $index => $account) {
        if ((string) ($account['username'] ?? '') !== $username) {
            continue;
        }

        $accounts[$index]['password'] = $newPassword;
        $accounts[$index]['password_hash'] = Security::hashPassword($newPassword);
        $accounts[$index]['temp_password'] = $mustChangePassword ? $newPassword : '';
        $accounts[$index]['must_change_password'] = $mustChangePassword;
        $updated = true;
        break;
    }

    if ($updated) {
        save_teacher_accounts_to_json($accounts);
    }

    return $updated;
}

function establish_teacher_session(array $account, string $username, bool $mustChangePassword): void
{
    session_unset();
    session_regenerate_id(true);

    Security::initializeSession();
    $_SESSION['academic_logged'] = true;
    $_SESSION['teacher_id'] = (string) ($account['teacher_id'] ?? '');
    $_SESSION['teacher_name'] = (string) ($account['teacher_name'] ?? 'Docente');
    $_SESSION['teacher_username'] = $username;
    $_SESSION['teacher_account_id'] = (string) ($account['id'] ?? '');
    $_SESSION['teacher_must_change_password'] = $mustChangePassword;
    $_SESSION['_session_start_time'] = time();
}

$accounts = load_teacher_accounts_from_database();
if (empty($accounts)) {
    $accounts = load_teacher_accounts_from_json();
}

$error = '';
$success = '';
$temporaryPassword = '';
$csrf_token = Security::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $submitted_token = $_POST['_csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? 'login');
    
    if (!Security::verifyCSRFToken($submitted_token)) {
        Security::logSecurityEvent('failed_login', 'CSRF token validation failed - academic');
        $error = 'Error de seguridad: token inválido. Intenta de nuevo.';
    } elseif ($action === 'recover_password') {
        $username = Security::sanitize($_POST['recovery_username'] ?? '', 'string');

        if ($username === '') {
            $error = 'Ingresa tu usuario docente para recuperar la clave.';
        } else {
            $recovered = false;
            $temporaryPassword = 'Doc' . strtoupper(bin2hex(random_bytes(3)));

            if (update_teacher_password_in_database($username, $temporaryPassword, true)) {
                $recovered = true;
            } elseif (update_teacher_password_in_json($username, $temporaryPassword, true)) {
                $recovered = true;
            }

            if ($recovered) {
                Security::logSecurityEvent('teacher_password_recovery', 'Temporary password generated', $username);
                $success = 'Se generó una contraseña temporal para el docente.';
            } else {
                Security::logSecurityEvent('teacher_password_recovery_failed', 'Username not found', $username);
                $error = 'No encontramos un docente con ese usuario.';
            }
        }
    } else {
        $username = Security::sanitize($_POST['username'] ?? '', 'string');
        $password = Security::sanitize($_POST['password'] ?? '', 'string');

        if (empty($username) || empty($password)) {
            Security::logSecurityEvent('failed_login', 'Empty credentials - academic');
            $error = 'Usuario y contraseña son requeridos';
        } else {
            $found = false;

            foreach ($accounts as $account) {
                $accountUsername = trim((string) ($account['username'] ?? ''));
                $accountPassword = (string) ($account['password'] ?? '');
                $isActive = (bool) ($account['is_active'] ?? true);
                $mustChangePassword = (bool) ($account['must_change_password'] ?? false);

                if ($accountUsername !== $username) {
                    continue;
                }

                $found = true;

                if (!$isActive) {
                    Security::logSecurityEvent('failed_login', 'Inactive account - ' . $username);
                    $error = 'Tu cuenta está inactiva. Comunícate con administración.';
                    break;
                }

                if (!verify_teacher_password($account, $password)) {
                    Security::logSecurityEvent('failed_login', 'Invalid password - ' . $username);
                    $error = 'Usuario o contraseña inválidos.';
                    break;
                }

                // Successful login
                establish_teacher_session($account, $username, $mustChangePassword);

                Security::logSecurityEvent('teacher_login', 'Successful login', $account['teacher_id'] ?? 'unknown');

                if ($mustChangePassword) {
                    header('Location: change_password.php');
                    exit;
                }

                header('Location: dashboard.php');
                exit;
            }

            if (!$found) {
                Security::logSecurityEvent('failed_login', 'User not found - ' . $username);
                $error = 'Usuario o contraseña inválidos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Login Docente - Academic</title>

<style>
:root{
    --bg:#eef4fc;
    --card:#ffffff;
    --line:#d6e5f7;
    --title:#0d4ea7;
    --text:#1f3a5f;
    --muted:#5d7a9f;
    --blue:#1f66cc;
    --blue-dark:#184fa3;
    --blue-soft:#e8f2ff;
    --danger:#dc2626;
    --shadow:0 18px 42px rgba(13,78,167,.12);
}

*{
    box-sizing:border-box;
}

html, body{
    height:100%;
}

body{
    margin:0;
    font-family:Arial, "Segoe UI", sans-serif;
    background:
        radial-gradient(circle at top left, #f5f9ff 0%, #eef4fc 38%, #e8f1ff 100%);
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
    grid-template-columns:1.1fr .9fr;
    background:var(--card);
    border:1px solid var(--line);
    border-radius:28px;
    overflow:hidden;
    box-shadow:var(--shadow);
}

.login-side{
    background:linear-gradient(180deg, #1f66cc 0%, #184fa3 100%);
    color:#fff;
    padding:44px 40px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    min-height:620px;
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
    box-shadow:0 10px 24px rgba(0,0,0,.12);
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

.side-pills{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:10px;
}

.side-pill{
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    color:#fff;
    font-size:12px;
    font-weight:700;
}

.login-card{
    padding:42px 34px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fff;
}

.login-panel{
    width:100%;
    max-width:360px;
}

.panel-top{
    text-align:center;
    margin-bottom:24px;
}

.panel-icon{
    width:70px;
    height:70px;
    margin:0 auto 14px;
    border-radius:18px;
    background:var(--blue-soft);
    color:var(--blue-dark);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:32px;
}

.panel-top h2{
    margin:0 0 8px;
    color:var(--title);
    font-size:30px;
    font-weight:800;
}

.panel-top p{
    margin:0;
    color:var(--muted);
    font-size:14px;
    line-height:1.45;
}

.form-group{
    margin-bottom:14px;
}

.password-wrap{
    position:relative;
}

.form-label{
    display:block;
    margin-bottom:7px;
    font-size:14px;
    font-weight:700;
    color:var(--text);
}

.form-input{
    width:100%;
    border:1px solid #d2e3f7;
    background:#f8fbff;
    color:var(--text);
    border-radius:12px;
    padding:13px 14px;
    font-size:15px;
    outline:none;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

.form-input:focus{
    border-color:var(--blue);
    background:#fff;
    box-shadow:0 0 0 4px rgba(31,102,204,.12);
}

.password-input{
    padding-right:48px;
}

.password-toggle{
    position:absolute;
    top:50%;
    right:12px;
    transform:translateY(-50%);
    border:none;
    background:transparent;
    color:var(--muted);
    cursor:pointer;
    font-size:18px;
    line-height:1;
    padding:4px;
}

.submit-btn{
    width:100%;
    border:none;
    border-radius:12px;
    padding:14px 16px;
    margin-top:6px;
    background:linear-gradient(180deg, var(--blue), var(--blue-dark));
    color:#fff;
    font-size:15px;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(31,102,204,.22);
    transition:transform .15s ease, opacity .15s ease;
}

.submit-btn:hover{
    transform:translateY(-1px);
}

.submit-btn:active{
    transform:translateY(0);
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

.success{
    margin-top:14px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    color:#1d4ed8;
    border-radius:12px;
    padding:12px 14px;
    text-align:center;
    font-size:14px;
    font-weight:700;
}

.recovery-card{
    margin-top:16px;
    padding:16px;
    border:1px solid var(--line);
    border-radius:14px;
    background:#f8fbff;
}

.recovery-title{
    margin:0 0 8px;
    color:var(--title);
    font-size:15px;
    font-weight:800;
}

.recovery-text{
    margin:0 0 12px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}

.temp-password{
    display:block;
    margin-top:10px;
    padding:12px;
    border-radius:10px;
    background:#ffffff;
    border:1px dashed #93c5fd;
    color:#1e3a8a;
    font-size:20px;
    font-weight:800;
    letter-spacing:.08em;
}

.footer-note{
    margin-top:18px;
    text-align:center;
    color:var(--muted);
    font-size:12px;
}

.link-area{
    margin-top:16px;
    text-align:center;
    padding-top:12px;
    border-top:1px solid var(--line);
    font-size:13px;
}

.link-area a{
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
}

.link-area a:hover{
    text-decoration:underline;
}

@media (max-width: 860px){
    .login-wrap{
        grid-template-columns:1fr;
    }

    .login-side{
        min-height:auto;
        padding:32px 26px;
    }

    .login-side h1{
        font-size:32px;
    }

    .login-card{
        padding:28px 20px 30px;
    }
}

@media (max-width: 480px){
    body{
        padding:14px;
    }

    .login-side{
        padding:26px 20px;
    }

    .login-card{
        padding:22px 16px 24px;
    }

    .panel-top h2{
        font-size:26px;
    }
}
</style>
</head>

<body>

<div class="login-wrap">
    <section class="login-side">
        <div class="brand-badge">👨‍🎓</div>
        <h1>Perfil Docente</h1>
        <p>Ingresa con tu cuenta de docente para acceder al panel de gestión de cursos, estudiantes y actividades.</p>

        <div class="side-pills">
            <span class="side-pill">Mis Cursos</span>
            <span class="side-pill">Estudiantes</span>
            <span class="side-pill">Actividades</span>
            <span class="side-pill">Calificaciones</span>
        </div>
    </section>

    <section class="login-card">
        <div class="login-panel">
            <div class="panel-top">
                <div class="panel-icon">🔐</div>
                <h2>Docente Login</h2>
                <p>Acceso para docentes y tutores</p>
            </div>

            <form method="post" autocomplete="off">
                <!-- CSRF Token Protection -->
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label class="form-label" for="username">Usuario</label>
                    <input
                        class="form-input"
                        id="username"
                        type="text"
                        name="username"
                        placeholder="Ingresa tu usuario"
                        value="<?= h((string) ($_POST['username'] ?? '')) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <div class="password-wrap">
                        <input
                            class="form-input password-input"
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Ingresa tu contraseña"
                            required
                        >
                        <button class="password-toggle" type="button" data-target="password" aria-label="Mostrar u ocultar contraseña">👁</button>
                    </div>
                </div>

                <button class="submit-btn" type="submit">Entrar</button>
            </form>

            <div class="recovery-card">
                <div class="recovery-title">Recuperar contraseña</div>
                <p class="recovery-text">Si no recuerdas la clave, escribe tu usuario docente y el sistema generará una contraseña temporal para volver a entrar.</p>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="recover_password">

                    <div class="form-group">
                        <label class="form-label" for="recovery_username">Usuario docente</label>
                        <input
                            class="form-input"
                            id="recovery_username"
                            type="text"
                            name="recovery_username"
                            placeholder="Ingresa tu usuario"
                            value="<?= h((string) ($_POST['recovery_username'] ?? '')) ?>"
                            required
                        >
                    </div>

                    <button class="submit-btn" type="submit">Generar contraseña temporal</button>
                </form>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success">
                    <?= h($success) ?>
                    <?php if ($temporaryPassword !== ''): ?>
                        <span class="temp-password"><?= h($temporaryPassword) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="link-area">
                ¿Eres estudiante? <a href="login_student.php">Ir a login estudiante</a>
            </div>

            <div class="footer-note">Panel Docente · Let's Institute</div>
        </div>
    </section>
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

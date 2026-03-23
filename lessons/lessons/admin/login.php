<?php
session_start();

/**
 * ADMIN LOGIN
 * Este login es EXCLUSIVO para administradores
 * No comparte sesión con academic ni student
 */

// Load security utilities
require_once __DIR__ . "/../config/security.php";
require_once __DIR__ . "/../config/db.php";

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

function ensure_admin_recovery_columns(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS username TEXT");
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS password_updated_at TIMESTAMP");
    } catch (Throwable $e) {
        // Si la tabla no existe o el motor no permite la alteración, el login sigue funcionando sin recovery avanzado.
    }
}

function generate_temporary_admin_password(): string
{
    return 'Adm' . strtoupper(bin2hex(random_bytes(3)));
}

function admin_users_json_file(): string
{
    return __DIR__ . '/data/users.json';
}

function load_admin_users_json(): array
{
    $file = admin_users_json_file();
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function save_admin_users_json(array $users): void
{
    file_put_contents(
        admin_users_json_file(),
        json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function find_admin_user_in_json(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    foreach (load_admin_users_json() as $user) {
        $email = trim((string) ($user['email'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $legacyUsername = 'admin';

        if (
            $identifier === $email
            || ($username !== '' && $identifier === $username)
            || ($username === '' && $identifier === $legacyUsername)
        ) {
            return is_array($user) ? $user : null;
        }
    }

    return null;
}

function update_admin_user_in_json(string $id, array $updates): bool
{
    $users = load_admin_users_json();
    $updated = false;

    foreach ($users as $index => $user) {
        if ((string) ($user['id'] ?? '') !== $id) {
            continue;
        }

        $users[$index] = array_merge($user, $updates);
        $updated = true;
        break;
    }

    if ($updated) {
        save_admin_users_json($users);
    }

    return $updated;
}

function verify_json_admin_password(array $jsonUser, string $password): bool
{
    $jsonPasswordHash = (string) ($jsonUser['password_hash'] ?? '');
    $jsonPassword = (string) ($jsonUser['password'] ?? '');

    if ($jsonPasswordHash !== '') {
        return Security::verifyPassword($password, $jsonPasswordHash);
    }

    return $jsonPassword !== '' && hash_equals($jsonPassword, $password);
}

function establish_admin_session(array $user, bool $mustChangePassword = false): void
{
    session_unset();
    session_regenerate_id(true);

    Security::initializeSession();
    $_SESSION['admin_logged'] = true;
    $_SESSION['admin_id'] = (string) ($user['id'] ?? 'admin_json');
    $_SESSION['admin_email'] = (string) ($user['email'] ?? 'admin@lets.com');
    $_SESSION['admin_role'] = (string) ($user['role'] ?? 'admin');
    $_SESSION['admin_must_change_password'] = $mustChangePassword;
    $_SESSION['_session_start_time'] = time();
}

// Initialize secure session settings
Security::initializeSession();
ensure_admin_recovery_columns($pdo);

// Si ya hay un admin logueado, ir directo al dashboard
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    if (!empty($_SESSION['admin_must_change_password'])) {
        header("Location: /lessons/lessons/admin/change_password.php");
        exit;
    }

    header("Location: /lessons/lessons/admin/dashboard.php");
    exit;
}

$error = "";
$success = "";
$recoveryPassword = "";
$activeTab = 'login';
$csrf_token = Security::generateCSRFToken();

$hasUsernameColumn = table_has_column($pdo, 'admin_users', 'username');
$hasMustChangePasswordColumn = table_has_column($pdo, 'admin_users', 'must_change_password');
$hasPasswordUpdatedAtColumn = table_has_column($pdo, 'admin_users', 'password_updated_at');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verify CSRF token
    $submitted_token = $_POST['_csrf_token'] ?? '';
    $action = (string) ($_POST['action'] ?? 'login');
    
    if (!Security::verifyCSRFToken($submitted_token)) {
        Security::logSecurityEvent('failed_login', 'CSRF token validation failed');
        $error = "Error de seguridad: token inválido. Intenta de nuevo.";
    } elseif ($action === 'recover_password') {
        $activeTab = 'recover';
        $recoveryEmail = Security::sanitize($_POST['recovery_email'] ?? '', 'email');

        if ($recoveryEmail === '' || !Security::isValidEmail($recoveryEmail)) {
            $error = 'Ingresa un correo administrador válido para recuperar la clave.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, email FROM admin_users WHERE email = ? AND is_active = TRUE LIMIT 1");
                $stmt->execute([$recoveryEmail]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $temporaryPassword = generate_temporary_admin_password();
                    $passwordHash = Security::hashPassword($temporaryPassword);
                    $setParts = ['password_hash = :password_hash'];

                    if ($hasMustChangePasswordColumn) {
                        $setParts[] = 'must_change_password = TRUE';
                    }

                    if ($hasPasswordUpdatedAtColumn) {
                        $setParts[] = 'password_updated_at = CURRENT_TIMESTAMP';
                    }

                    $updateSql = 'UPDATE admin_users SET ' . implode(', ', $setParts) . ' WHERE id = :id';
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([
                        'password_hash' => $passwordHash,
                        'id' => $user['id'],
                    ]);

                    Security::logSecurityEvent('admin_password_recovery', 'Temporary password generated', (string) $user['id']);
                    $success = 'Se generó una clave temporal para el administrador.';
                    $recoveryPassword = $temporaryPassword;
                } else {
                    $jsonUser = find_admin_user_in_json($recoveryEmail);

                    if ($jsonUser) {
                        $temporaryPassword = generate_temporary_admin_password();
                        $jsonUpdates = [
                            'password' => $temporaryPassword,
                            'must_change_password' => true,
                        ];

                        if (!empty($jsonUser['password_hash'])) {
                            $jsonUpdates['password_hash'] = Security::hashPassword($temporaryPassword);
                        }

                        update_admin_user_in_json((string) ($jsonUser['id'] ?? ''), $jsonUpdates);
                        Security::logSecurityEvent('admin_password_recovery', 'Temporary password generated from JSON store', (string) ($jsonUser['id'] ?? 'unknown'));
                        $success = 'Se generó una clave temporal para el administrador.';
                        $recoveryPassword = $temporaryPassword;
                    } else {
                        Security::logSecurityEvent('admin_password_recovery_failed', 'Recovery requested for unknown email', $recoveryEmail);
                        $error = 'No encontramos un administrador activo con ese correo.';
                    }
                }
            } catch (Throwable $e) {
                $jsonUser = find_admin_user_in_json($recoveryEmail);
                if ($jsonUser) {
                    $temporaryPassword = generate_temporary_admin_password();
                    $jsonUpdates = [
                        'password' => $temporaryPassword,
                        'must_change_password' => true,
                    ];

                    if (!empty($jsonUser['password_hash'])) {
                        $jsonUpdates['password_hash'] = Security::hashPassword($temporaryPassword);
                    }

                    update_admin_user_in_json((string) ($jsonUser['id'] ?? ''), $jsonUpdates);
                    Security::logSecurityEvent('admin_password_recovery', 'Temporary password generated from JSON fallback after database error', (string) ($jsonUser['id'] ?? 'unknown'));
                    $success = 'Se generó una clave temporal para el administrador.';
                    $recoveryPassword = $temporaryPassword;
                } else {
                    Security::logSecurityEvent('admin_password_recovery_failed', 'Database error: ' . $e->getMessage(), $recoveryEmail);
                    $error = 'No fue posible generar la clave temporal en este momento.';
                }
            }
        }
    } else {
        $email = Security::sanitize($_POST["email"] ?? "", "email");
        $pass  = Security::sanitize($_POST["password"] ?? "", "string");
        $loginIdentifier = trim((string) ($_POST['email'] ?? ''));

        // Validate input
        if (empty($loginIdentifier) || empty($pass)) {
            Security::logSecurityEvent('failed_login', 'Empty credentials');
            $error = "Usuario/correo y contraseña son requeridos";
        } else {
            try {
                // Query database for admin user
                $identifier = $email !== '' ? $email : Security::sanitize($loginIdentifier, 'string');
                $whereClause = 'email = :identifier';

                if ($hasUsernameColumn) {
                    $whereClause = '(email = :identifier OR username = :identifier)';
                }

                $mustChangePasswordSelect = $hasMustChangePasswordColumn ? ', must_change_password' : ', FALSE AS must_change_password';
                $stmt = $pdo->prepare("
                    SELECT id, email, password_hash, role, is_active{$mustChangePasswordSelect}
                    FROM admin_users 
                    WHERE {$whereClause} AND is_active = TRUE
                    LIMIT 1
                ");
                $stmt->execute(['identifier' => $identifier]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && Security::verifyPassword($pass, $user['password_hash'])) {
                    establish_admin_session($user, !empty($user['must_change_password']));

                    Security::logSecurityEvent('admin_login', 'Successful login', $user['id']);

                    if (!empty($user['must_change_password'])) {
                        header("Location: /lessons/lessons/admin/change_password.php");
                        exit;
                    }

                    header("Location: /lessons/lessons/admin/dashboard.php");
                    exit;
                }

                $jsonUser = find_admin_user_in_json($identifier);
                $jsonPasswordMatches = $jsonUser ? verify_json_admin_password($jsonUser, $pass) : false;

                if ($jsonUser && $jsonPasswordMatches) {
                    establish_admin_session([
                        'id' => (string) ($jsonUser['id'] ?? 'admin_json'),
                        'email' => (string) ($jsonUser['email'] ?? $identifier),
                        'role' => (string) ($jsonUser['role'] ?? 'admin'),
                    ], !empty($jsonUser['must_change_password']));

                    Security::logSecurityEvent('admin_login', 'Successful JSON fallback login', (string) ($_SESSION['admin_id'] ?? 'admin_json'));

                    if (!empty($jsonUser['must_change_password'])) {
                        header("Location: /lessons/lessons/admin/change_password.php");
                        exit;
                    }

                    header("Location: /lessons/lessons/admin/dashboard.php");
                    exit;
                } else {
                    // Failed login - don't reveal if email exists
                    Security::logSecurityEvent('failed_login', 'Invalid credentials', $identifier);
                    $error = "Credenciales incorrectas";
                }
            } catch (Exception $e) {
                $identifier = $email !== '' ? $email : Security::sanitize($loginIdentifier, 'string');
                $jsonUser = find_admin_user_in_json($identifier);

                if ($jsonUser && verify_json_admin_password($jsonUser, $pass)) {
                    establish_admin_session([
                        'id' => (string) ($jsonUser['id'] ?? 'admin_json'),
                        'email' => (string) ($jsonUser['email'] ?? $identifier),
                        'role' => (string) ($jsonUser['role'] ?? 'admin'),
                    ], !empty($jsonUser['must_change_password']));

                    Security::logSecurityEvent('admin_login', 'Successful JSON fallback login after database error', (string) ($_SESSION['admin_id'] ?? 'admin_json'));

                    if (!empty($jsonUser['must_change_password'])) {
                        header("Location: /lessons/lessons/admin/change_password.php");
                        exit;
                    }

                    header("Location: /lessons/lessons/admin/dashboard.php");
                    exit;
                }

                Security::logSecurityEvent('failed_login', 'Database error: ' . $e->getMessage());
                $error = "Error al procesar la solicitud. Intenta de nuevo.";
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
<title>Login Admin</title>

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
    --green-soft:#e8f7ec;
    --danger:#dc2626;
    --shadow:0 18px 42px rgba(0,0,0,.12);
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
        radial-gradient(circle at top left, #f7fff8 0%, #edf7ef 38%, #e6f1e9 100%);
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
    background:linear-gradient(180deg, #35ac51 0%, #237a35 100%);
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
    background:var(--green-soft);
    color:var(--green-dark);
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
    border:1px solid #cfe1d4;
    background:#f8fcf8;
    color:var(--text);
    border-radius:12px;
    padding:13px 14px;
    font-size:15px;
    outline:none;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

.form-input:focus{
    border-color:var(--green);
    background:#fff;
    box-shadow:0 0 0 4px rgba(47,163,74,.12);
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
    background:linear-gradient(180deg, var(--green), var(--green-dark));
    color:#fff;
    font-size:15px;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 20px rgba(47,163,74,.22);
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
    background:#ecfdf3;
    border:1px solid #bbf7d0;
    color:#166534;
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
    background:#f8fcf8;
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

.helper-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-top:10px;
}

.helper-link{
    border:none;
    background:none;
    color:var(--green-dark);
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    padding:0;
}

.temp-password{
    display:block;
    margin-top:10px;
    padding:12px;
    border-radius:10px;
    background:#ffffff;
    border:1px dashed #86efac;
    color:#14532d;
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
        <div class="brand-badge">🛡️</div>
        <h1>Admin Panel Login</h1>
        <p>Ingresa con tu cuenta de administrador para gestionar cursos, unidades y actividades desde el panel principal.</p>

        <div class="side-pills">
            <span class="side-pill">Administración</span>
            <span class="side-pill">Cursos</span>
            <span class="side-pill">Unidades</span>
            <span class="side-pill">Actividades</span>
        </div>
    </section>

    <section class="login-card">
        <div class="login-panel">
            <div class="panel-top">
                <div class="panel-icon">🔐</div>
                <h2>Admin Login</h2>
                <p>Acceso exclusivo para administradores con recuperación de clave incluida</p>
            </div>

            <form method="post" autocomplete="off">
                <!-- CSRF Token Protection -->
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label class="form-label" for="email">Usuario o email</label>
                    <input
                        class="form-input"
                        id="email"
                        type="text"
                        name="email"
                        placeholder="Ingresa admin o tu correo"
                        value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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

                <button class="submit-btn" type="submit">Ingresar</button>
            </form>

            <div class="recovery-card">
                <div class="recovery-title">Recuperar clave</div>
                <p class="recovery-text">Puedes entrar con <strong>admin</strong> o con el correo del administrador. Si no recuerdas la clave, ingresa el correo y el sistema generará una clave temporal.</p>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="recover_password">

                    <div class="form-group">
                        <label class="form-label" for="recovery_email">Correo administrador</label>
                        <input
                            class="form-input"
                            id="recovery_email"
                            type="email"
                            name="recovery_email"
                            placeholder="admin@dominio.com"
                            value="<?= htmlspecialchars((string) ($_POST['recovery_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <button class="submit-btn" type="submit">Generar clave temporal</button>
                </form>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($recoveryPassword !== ''): ?>
                        <span class="temp-password"><?= htmlspecialchars($recoveryPassword, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="footer-note">Panel administrativo · Let’s Institute</div>
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

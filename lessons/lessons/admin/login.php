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

const ADMIN_FIXED_PASSWORD = '1234';

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

function ensure_admin_access_columns(PDO $pdo): void
{
    try {
        // Create table if it has never been created
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_users (
                id        SERIAL PRIMARY KEY,
                email     TEXT NOT NULL,
                username  TEXT,
                password_hash          TEXT NOT NULL DEFAULT '',
                role                   TEXT NOT NULL DEFAULT 'admin',
                is_active              BOOLEAN DEFAULT TRUE,
                must_change_password   BOOLEAN DEFAULT FALSE,
                password_updated_at    TIMESTAMP,
                created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Add columns that may be missing in older installations
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS username TEXT");
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN DEFAULT FALSE");
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS password_updated_at TIMESTAMP");

        // Make sure existing rows that have must_change_password = NULL are treated as FALSE
        $pdo->exec("UPDATE admin_users SET must_change_password = FALSE WHERE must_change_password IS NULL");
    } catch (Throwable $e) {
        // Si la tabla no existe o el motor no permite la alteracion, el login sigue funcionando con el respaldo JSON.
    }
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

function sync_fixed_admin_password(PDO $pdo): void
{
    $fixedHash = Security::hashPassword(ADMIN_FIXED_PASSWORD);

    try {
        $setParts = ['password_hash = :password_hash'];

        if (table_has_column($pdo, 'admin_users', 'must_change_password')) {
            $setParts[] = 'must_change_password = FALSE';
        }

        if (table_has_column($pdo, 'admin_users', 'password_updated_at')) {
            $setParts[] = 'password_updated_at = CURRENT_TIMESTAMP';
        }

        $stmt = $pdo->prepare('UPDATE admin_users SET ' . implode(', ', $setParts) . ' WHERE is_active = TRUE');
        $stmt->execute(['password_hash' => $fixedHash]);
    } catch (Throwable $e) {
        // Si la base de datos no esta disponible, se mantiene el respaldo JSON.
    }

    $jsonUsers = load_admin_users_json();
    if ($jsonUsers === []) {
        return;
    }

    foreach ($jsonUsers as $index => $user) {
        $jsonUsers[$index]['password'] = ADMIN_FIXED_PASSWORD;
        $jsonUsers[$index]['password_hash'] = $fixedHash;
        $jsonUsers[$index]['must_change_password'] = false;
    }

    save_admin_users_json($jsonUsers);
}

function find_admin_user_in_db(PDO $pdo, string $identifier, bool $hasUsernameColumn): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $usernameSelect = $hasUsernameColumn ? ', username' : ", '' AS username";
    $whereClause = 'email = :identifier';

    if ($hasUsernameColumn) {
        $whereClause = '(email = :identifier OR username = :identifier)';
    }

    $stmt = $pdo->prepare("\n        SELECT id, email{$usernameSelect}, role, is_active\n        FROM admin_users\n        WHERE {$whereClause} AND is_active = TRUE\n        LIMIT 1\n    ");
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user !== false) {
        return $user;
    }

    if (strcasecmp($identifier, 'admin') !== 0) {
        return null;
    }

    $stmt = $pdo->query("\n        SELECT id, email{$usernameSelect}, role, is_active\n        FROM admin_users\n        WHERE is_active = TRUE\n        ORDER BY id ASC\n        LIMIT 1\n    ");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user !== false ? $user : null;
}

function establish_admin_session(array $user): void
{
    session_unset();
    session_regenerate_id(true);

    Security::initializeSession();
    $_SESSION['admin_logged'] = true;
    $_SESSION['admin_id'] = (string) ($user['id'] ?? 'admin_json');
    $_SESSION['admin_email'] = (string) ($user['email'] ?? 'admin@lets.com');
    $_SESSION['admin_username'] = (string) ($user['username'] ?? '');
    $_SESSION['admin_role'] = (string) ($user['role'] ?? 'admin');
    $_SESSION['admin_must_change_password'] = false;
    $_SESSION['_session_start_time'] = time();
}

// Initialize secure session settings
Security::initializeSession();
ensure_admin_access_columns($pdo);
sync_fixed_admin_password($pdo);

// Si ya hay un admin logueado, ir directo al dashboard
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    $_SESSION['admin_must_change_password'] = false;
    header("Location: /lessons/lessons/admin/dashboard.php");
    exit;
}

$error = "";
$csrf_token = Security::generateCSRFToken();

$hasUsernameColumn = table_has_column($pdo, 'admin_users', 'username');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verify CSRF token
    $submitted_token = $_POST['_csrf_token'] ?? '';

    if (!Security::verifyCSRFToken($submitted_token)) {
        Security::logSecurityEvent('failed_login', 'CSRF token validation failed');
        $error = "Error de seguridad: token inválido. Intenta de nuevo.";
    } else {
        $email = Security::sanitize($_POST["email"] ?? "", "email");
        $pass  = Security::sanitize($_POST["password"] ?? "", "string");
        $loginIdentifier = trim((string) ($_POST['email'] ?? ''));

        // Validate input
        if (empty($loginIdentifier) || empty($pass)) {
            Security::logSecurityEvent('failed_login', 'Empty credentials');
            $error = "Usuario/correo y contraseña son requeridos";
        } elseif (!hash_equals(ADMIN_FIXED_PASSWORD, $pass)) {
            Security::logSecurityEvent('failed_login', 'Invalid fixed password', $loginIdentifier);
            $error = "Credenciales incorrectas";
        } else {
            try {
                $identifier = $email !== '' ? $email : Security::sanitize($loginIdentifier, 'string');
                $user = find_admin_user_in_db($pdo, $identifier, $hasUsernameColumn);

                if ($user) {
                    establish_admin_session($user);

                    Security::logSecurityEvent('admin_login', 'Successful login', $user['id']);

                    header("Location: /lessons/lessons/admin/dashboard.php");
                    exit;
                }

                $jsonUser = find_admin_user_in_json($identifier);
                if ($jsonUser) {
                    establish_admin_session([
                        'id' => (string) ($jsonUser['id'] ?? 'admin_json'),
                        'email' => (string) ($jsonUser['email'] ?? $identifier),
                        'username' => (string) ($jsonUser['username'] ?? ''),
                        'role' => (string) ($jsonUser['role'] ?? 'admin'),
                    ]);

                    Security::logSecurityEvent('admin_login', 'Successful JSON fallback login', (string) ($_SESSION['admin_id'] ?? 'admin_json'));

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

                if ($jsonUser) {
                    establish_admin_session([
                        'id' => (string) ($jsonUser['id'] ?? 'admin_json'),
                        'email' => (string) ($jsonUser['email'] ?? $identifier),
                        'username' => (string) ($jsonUser['username'] ?? ''),
                        'role' => (string) ($jsonUser['role'] ?? 'admin'),
                    ]);

                    Security::logSecurityEvent('admin_login', 'Successful JSON fallback login after database error', (string) ($_SESSION['admin_id'] ?? 'admin_json'));

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

.footer-note{
    margin-top:18px;
    text-align:center;
    color:var(--muted);
    font-size:12px;
}

.fixed-password-card{
    margin-top:16px;
    padding:16px;
    border:1px solid var(--line);
    border-radius:14px;
    background:#f8fcf8;
}

.fixed-password-title{
    margin:0 0 8px;
    color:var(--title);
    font-size:15px;
    font-weight:800;
}

.fixed-password-text{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}

.fixed-password-value{
    display:inline-block;
    margin-top:10px;
    padding:10px 12px;
    border-radius:10px;
    background:#ffffff;
    border:1px dashed #86efac;
    color:#14532d;
    font-size:20px;
    font-weight:800;
    letter-spacing:.08em;
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
                <p>Acceso exclusivo para administradores con clave fija configurada</p>
            </div>

            <form method="post" autocomplete="off">
                <!-- CSRF Token Protection -->
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                
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
                            placeholder="Ingresa la clave fija"
                            required
                        >
                        <button class="password-toggle" type="button" data-target="password" aria-label="Mostrar u ocultar contraseña">👁</button>
                    </div>
                </div>

                <button class="submit-btn" type="submit">Ingresar</button>
            </form>

            <div class="fixed-password-card">
                <div class="fixed-password-title">Acceso simplificado</div>
                <p class="fixed-password-text">Ingresa con <strong>admin</strong> o con el correo del administrador. La recuperación y el cambio de contraseña quedaron desactivados.</p>
                <span class="fixed-password-value">1234</span>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
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

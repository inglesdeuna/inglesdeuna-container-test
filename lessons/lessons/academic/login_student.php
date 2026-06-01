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
        $hasStudentId = table_has_column($pdo, 'student_accounts', 'student_id');
        $hasStudentName = table_has_column($pdo, 'student_accounts', 'student_name');
        $hasUsername = table_has_column($pdo, 'student_accounts', 'username');
        $hasMustChangePassword = table_has_column($pdo, 'student_accounts', 'must_change_password');
        $hasLegacyPassword = table_has_column($pdo, 'student_accounts', 'password');
        $hasPasswordHash = table_has_column($pdo, 'student_accounts', 'password_hash');
        $hasTempPassword = table_has_column($pdo, 'student_accounts', 'temp_password');
        $hasUpdatedAt = table_has_column($pdo, 'student_accounts', 'updated_at');

        $selectStudentId = $hasStudentId ? 'student_id' : "'' AS student_id";
        $selectStudentName = $hasStudentName ? 'student_name' : "'' AS student_name";
        $selectUsername = $hasUsername ? 'username' : "'' AS username";
        $selectMustChangePassword = $hasMustChangePassword ? 'must_change_password' : 'FALSE AS must_change_password';
        $selectLegacyPassword = $hasLegacyPassword ? 'password' : "'' AS password";
        $selectPasswordHash = $hasPasswordHash ? 'password_hash' : "'' AS password_hash";
        $selectTempPassword = $hasTempPassword ? 'temp_password' : "'' AS temp_password";
        $selectUpdatedAt = $hasUpdatedAt ? 'updated_at' : 'NULL AS updated_at';

        $select = "id, {$selectStudentId}, {$selectStudentName}, {$selectUsername}, {$selectLegacyPassword}, {$selectPasswordHash}, {$selectTempPassword}, {$selectMustChangePassword}, {$selectUpdatedAt}";
        if (table_has_column($pdo, 'student_accounts', 'permission')) {
            $select .= ', permission';
        }
        if (table_has_column($pdo, 'student_accounts', 'student_photo')) {
            $select .= ', student_photo';
        }

        $orderBy = $hasUpdatedAt
            ? 'ORDER BY updated_at DESC NULLS LAST, student_name ASC'
            : 'ORDER BY student_name ASC, id ASC';
        $stmt = $pdo->query("SELECT {$select} FROM student_accounts {$orderBy}");
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

function find_student_account(array $accounts, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    foreach ($accounts as $account) {
        $username = trim((string) ($account['username'] ?? ''));
        $studentId = trim((string) ($account['student_id'] ?? ''));
        $legacyId = trim((string) ($account['id'] ?? ''));

        if (($username !== '' && strcasecmp($username, $identifier) === 0)
            || ($studentId !== '' && strcasecmp($studentId, $identifier) === 0)
            || ($legacyId !== '' && strcasecmp($legacyId, $identifier) === 0)) {
            return $account;
        }
    }

    return null;
}

function update_student_password_in_database(string $identifier, string $newPassword, bool $mustChangePassword): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    $identifier = trim($identifier);

    $setParts = [
        'updated_at = NOW()',
    ];
    $params = [
        'identifier_username' => $identifier,
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
        $sql = 'UPDATE student_accounts SET ' . implode(', ', $setParts) . ' WHERE username = :identifier_username';
        if (table_has_column($pdo, 'student_accounts', 'student_id')) {
            $sql .= ' OR CAST(student_id AS TEXT) = :identifier_student_id';
            $params['identifier_student_id'] = $identifier;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function update_student_password_in_json(string $identifier, string $newPassword, bool $mustChangePassword): bool
{
    $accounts = load_student_accounts_from_json();
    $updated = false;
    $identifier = trim($identifier);

    foreach ($accounts as $index => $account) {
        $username = trim((string) ($account['username'] ?? ''));
        $studentId = trim((string) ($account['student_id'] ?? ''));
        if (!(($username !== '' && strcasecmp($username, $identifier) === 0)
            || ($studentId !== '' && strcasecmp($studentId, $identifier) === 0))) {
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
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'recover_password') {
        $recoveryUsername = trim((string) ($_POST['recovery_username'] ?? ''));

        if ($recoveryUsername === '') {
            $error = 'Ingresa tu usuario o tu ID para recuperar la clave.';
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
                $error = 'No encontramos un estudiante con ese usuario o ID.';
            }
        }
    } else {
        session_unset();
        session_destroy();
        session_start();

        $username = trim((string) ($_POST['username'] ?? ''));
        $usernameValue = $username;
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Ingresa tu usuario/ID y contraseña.';
        } elseif (empty($accounts)) {
            $error = 'No hay cuentas estudiantiles disponibles para validar el acceso. Contacta al docente.';
        } else {
        $account = find_student_account($accounts, $username);

        if ($account && verify_student_password($account, $password)) {
            $_SESSION['student_logged'] = true;
            $_SESSION['student_id'] = (string) ($account['student_id'] ?? '');
            $_SESSION['student_name'] = (string) ($account['student_name'] ?? 'Estudiante');
            $_SESSION['student_username'] = (string) ($account['username'] ?? $username);
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

        $error = 'Usuario/ID o contraseña inválidos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Estudiante</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --orange: #F97316;
    --purple: #7F77DD;
    --kicker-bg: #FFF0E6;
    --card-border: #EDE9FA;
    --page-bg: #ffffff;
    --left-text: #CECBF6;
}

* { box-sizing: border-box; }
html, body { height: 100%; }

body {
    margin: 0;
    padding: 20px;
    background: var(--page-bg);
    font-family: 'Nunito', Arial, sans-serif;
    color: #342f58;
    display: flex;
    align-items: center;
    justify-content: center;
}

.login-card {
    width: 100%;
    max-width: 640px;
    border: 1px solid var(--card-border);
    border-radius: 22px;
    overflow: hidden;
    display: flex;
    background: #fff;
}

.panel-left {
    width: 240px;
    background: var(--purple);
    color: #fff;
    padding: 1.6rem 1.25rem;
    display: flex;
    flex-direction: column;
}

.brand-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 1.25rem;
}

.brand-name {
    margin: 0;
    font-family: 'Fredoka One', cursive;
    font-size: 1.45rem;
    line-height: 1;
    letter-spacing: 0.3px;
}

.brand-subtitle {
    margin: 0.1rem 0 0;
    font-size: 7px;
    font-weight: 700;
    letter-spacing: 1px;
    color: var(--left-text);
}

.portal-title {
    margin: 0 0 0.65rem;
    font-family: 'Fredoka One', cursive;
    font-size: 27px;
    line-height: 1.08;
    color: #fff;
}

.portal-copy {
    margin: 0 0 1rem;
    font-size: 11.5px;
    line-height: 1.45;
    color: var(--left-text);
}

.left-pills {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.left-pills span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 0.35rem 0.9rem;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
}

.left-footer {
    margin-top: auto;
    padding-top: 1rem;
    font-size: 8px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.28);
    font-weight: 800;
}

.panel-right {
    flex: 1;
    background: #fff;
    padding: 2rem 1.875rem;
}

.login-title {
    margin: 0;
    font-family: 'Fredoka One', cursive;
    font-size: 24px;
    color: var(--orange);
}

.login-subtitle {
    margin: 0.25rem 0 1.1rem;
    font-size: 12px;
    color: #9B8FCC;
}

.form-group { margin-bottom: 0.7rem; }

label {
    display: block;
    margin-bottom: 0.35rem;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--purple);
}

.text-input {
    width: 100%;
    height: 40px;
    border-radius: 0;
    border: 1.5px solid var(--card-border);
    background: #F9F8FF;
    padding: 0 0.7rem;
    font: 700 13px 'Nunito', Arial, sans-serif;
    color: #3a3369;
}

.text-input:focus {
    outline: none;
    border-color: var(--purple);
}

.password-wrap {
    position: relative;
}

.password-wrap .text-input {
    padding-right: 2.45rem;
}

.toggle-password {
    position: absolute;
    top: 1px;
    right: 1px;
    width: 38px;
    height: 38px;
    border: none;
    border-radius: 0;
    background: transparent;
    color: var(--purple);
    cursor: pointer;
    font-size: 15px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.toggle-password .hide { display: none; }
.toggle-password.is-visible .show { display: none; }
.toggle-password.is-visible .hide { display: inline; }

.submit-btn {
    width: 100%;
    height: 42px;
    border: none;
    border-radius: 0;
    background: var(--orange);
    color: #fff;
    font: 800 14px 'Nunito', Arial, sans-serif;
    cursor: pointer;
    margin-top: 0.55rem;
}

.secondary-actions {
    margin-top: 0.7rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.secondary-actions a {
    height: 38px;
    border-radius: 0;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    text-align: center;
    font: 800 11.5px 'Nunito', Arial, sans-serif;
    color: #fff;
    padding: 0 0.45rem;
}

.secondary-actions .forgot { background: var(--purple); }
.secondary-actions .teacher { background: var(--orange); }

.error,
.success {
    margin-top: 0.7rem;
    padding: 0.55rem 0.6rem;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
}

.error {
    border: 1px solid #f5b8b8;
    background: #fff2f2;
    color: #a21c1c;
}

.success {
    border: 1px solid #bdeac8;
    background: #f0fff4;
    color: #166534;
}

.divider {
    margin: 0.95rem 0 0.8rem;
    border: 0;
    border-top: 1px solid var(--card-border);
}

.bottom-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    color: #C4BDED;
    font-size: 9.5px;
    text-align: center;
}

@media (max-width: 560px) {
    .login-card {
        flex-direction: column;
    }

    .panel-left {
        width: 100%;
    }

    .left-pills {
        flex-direction: row;
        flex-wrap: wrap;
    }

    .left-pills span {
        flex: 1 1 auto;
    }

    .secondary-actions {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="login-card">
    <aside class="panel-left">
        <div class="brand-row">
            <svg width="36" height="36" viewBox="0 0 36 36" aria-hidden="true">
                <rect width="36" height="36" rx="9" fill="rgba(255,255,255,0.18)"></rect>
                <circle cx="17" cy="15" r="8.5" fill="#ffffff"></circle>
                <polygon points="12,22 7,30 21,26" fill="#ffffff"></polygon>
                <circle cx="17" cy="15" r="4.5" fill="#7F77DD"></circle>
                <circle cx="24" cy="9" r="3.5" fill="#7F77DD"></circle>
                <circle cx="24" cy="9" r="1.75" fill="#ffffff"></circle>
            </svg>
            <div>
                <p class="brand-name">ONES</p>
                <p class="brand-subtitle">ONLINE ENGLISH SOLUTION</p>
            </div>
        </div>

        <h1 class="portal-title">Portal Estudiante</h1>
        <p class="portal-copy">Ingresa con tu usuario y contraseña para revisar tu avance académico y contenidos asignados.</p>

        <div class="left-pills">
            <span>Acceso seguro</span>
            <span>Modo estudiante</span>
            <span>Cambio de clave</span>
        </div>

        <div class="left-footer">ONES · LET'S INSTITUTE</div>
    </aside>

    <section class="panel-right">
        <h2 class="login-title">Iniciar sesión</h2>
        <p class="login-subtitle">Accede a tu perfil académico.</p>

        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label for="username">Usuario o ID</label>
                <input class="text-input" id="username" type="text" name="username" value="<?php echo h($usernameValue); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <div class="password-wrap">
                    <input class="text-input" id="password" type="password" name="password" required>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Mostrar contraseña" title="Mostrar contraseña">
                        <span class="show">👁</span>
                        <span class="hide">🙈</span>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit-btn">Entrar</button>
        </form>

        <div class="secondary-actions">
            <a class="forgot" href="forgot_password.php">¿Olvidaste tu contraseña?</a>
            <a class="teacher" href="login_teacher.php">Login docente</a>
        </div>

        <?php if ($error): ?>
            <div class="error" role="alert" aria-live="assertive"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo h($success); ?></div>
        <?php endif; ?>

        <hr class="divider">

        <div class="bottom-note">
            <svg width="18" height="18" viewBox="0 0 36 36" aria-hidden="true">
                <rect width="36" height="36" rx="9" fill="#FFF0E6"></rect>
                <circle cx="17" cy="15" r="8.5" fill="#7F77DD"></circle>
                <polygon points="12,22 7,30 21,26" fill="#7F77DD"></polygon>
                <circle cx="17" cy="15" r="4.5" fill="#ffffff"></circle>
                <circle cx="24" cy="9" r="3.5" fill="#F97316"></circle>
                <circle cx="24" cy="9" r="1.75" fill="#ffffff"></circle>
            </svg>
            <span>Online English Solution · Let's Institute · 2026</span>
        </div>
    </section>
</div>

<script>
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', function () {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        togglePassword.classList.toggle('is-visible', isPassword);
        togglePassword.setAttribute('aria-label', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
        togglePassword.setAttribute('title', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
}
</script>
</body>
</html>

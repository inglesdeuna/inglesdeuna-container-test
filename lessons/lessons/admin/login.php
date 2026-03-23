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

// Initialize secure session settings
Security::initializeSession();

// Si ya hay un admin logueado, ir directo al dashboard
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    header("Location: /lessons/lessons/admin/dashboard.php");
    exit;
}

$error = "";
$csrf_token = Security::generateCSRFToken();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verify CSRF token
    $submitted_token = $_POST['_csrf_token'] ?? '';
    
    if (!Security::verifyCSRFToken($submitted_token)) {
        Security::logSecurityEvent('failed_login', 'CSRF token validation failed');
        $error = "Error de seguridad: token inválido. Intenta de nuevo.";
    } else {
        $email = Security::sanitize($_POST["email"] ?? "", "email");
        $pass  = Security::sanitize($_POST["password"] ?? "", "string");

        // Validate input
        if (empty($email) || empty($pass)) {
            Security::logSecurityEvent('failed_login', 'Empty credentials');
            $error = "Correo y contraseña son requeridos";
        } else {
            try {
                // Query database for admin user
                $stmt = $pdo->prepare("
                    SELECT id, email, password_hash, role, is_active 
                    FROM admin_users 
                    WHERE email = ? AND is_active = TRUE
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && Security::verifyPassword($pass, $user['password_hash'])) {
                    // Successful login
                    session_unset();
                    session_regenerate_id(true);
                    session_start();
                    
                    Security::initializeSession();
                    $_SESSION["admin_logged"] = true;
                    $_SESSION["admin_id"]     = $user["id"];
                    $_SESSION["admin_email"]  = $user["email"];
                    $_SESSION["admin_role"]   = $user["role"];
                    $_SESSION['_session_start_time'] = time();

                    Security::logSecurityEvent('admin_login', 'Successful login', $user['id']);
                    header("Location: /lessons/lessons/admin/dashboard.php");
                    exit;
                } else {
                    // Failed login - don't reveal if email exists
                    Security::logSecurityEvent('failed_login', 'Invalid credentials', $email);
                    $error = "Credenciales incorrectas";
                }
            } catch (Exception $e) {
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
                <p>Acceso exclusivo para administradores</p>
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
                        placeholder="Ingresa tu usuario"
                        required
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <input
                        class="form-input"
                        id="password"
                        type="password"
                        name="password"
                        placeholder="Ingresa tu contraseña"
                        required
                    >
                </div>

                <button class="submit-btn" type="submit">Ingresar</button>
            </form>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="footer-note">Panel administrativo · Let’s Institute</div>
        </div>
    </section>
</div>

</body>
</html>

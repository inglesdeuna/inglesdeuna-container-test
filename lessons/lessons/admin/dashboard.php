<?php
session_start();

// Load security utilities
require_once __DIR__ . "/../config/security.php";

// Initialize secure session
Security::initializeSession();

// Check authentication
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['admin_must_change_password'])) {
    header('Location: change_password.php');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    Security::logSecurityEvent('admin_logout', 'User logged out', $_SESSION['admin_id'] ?? 'unknown');
    Security::destroySession();
    header('Location: login.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$adminDisplay = trim((string) ($_SESSION['admin_username'] ?? $_SESSION['admin_email'] ?? 'Administrador'));
$adminRole = 'Panel de Control';

$dashboardSections = [
    [
        'id' => 'programas-tecnicos',
        'eyebrow' => 'Gestion academica',
        'title' => 'Programas Tecnicos',
        'description' => 'Administra la estructura de cursos tecnicos, semestres, unidades y actividades desde un solo bloque de trabajo.',
        'links' => [
            ['label' => 'Gestionar estructura', 'href' => '../academic/courses_manager.php?program=prog_technical', 'variant' => 'primary'],
            ['label' => 'Cursos creados', 'href' => '../academic/technical_courses_created.php', 'variant' => 'secondary'],
        ],
    ],
    [
        'id' => 'cursos-ingles',
        'eyebrow' => 'Gestion academica',
        'title' => 'Cursos de Ingles',
        'description' => 'Organiza niveles, fases y catalogo de cursos de ingles con un flujo mas limpio y directo para administracion.',
        'links' => [
            ['label' => 'Gestionar estructura', 'href' => '../academic/english_structure_levels.php', 'variant' => 'primary'],
            ['label' => 'Cursos creados', 'href' => '../academic/english_courses_created.php', 'variant' => 'secondary'],
        ],
    ],
    [
        'id' => 'docentes',
        'eyebrow' => 'Usuarios',
        'title' => 'Docentes',
        'description' => 'Controla inscripciones y asignaciones docentes manteniendo separado el proceso administrativo y el proceso operativo.',
        'links' => [
            ['label' => 'Inscripciones', 'href' => '../academic/teacher_enrollments.php', 'variant' => 'primary'],
            ['label' => 'Asignaciones', 'href' => '../academic/teacher_profiles.php', 'variant' => 'secondary'],
        ],
    ],
    [
        'id' => 'estudiantes',
        'eyebrow' => 'Usuarios',
        'title' => 'Estudiantes',
        'description' => 'Gestiona el flujo de estudiantes en dos etapas, desde la inscripcion hasta la asignacion final a sus cursos.',
        'links' => [
            ['label' => 'Inscripciones', 'href' => '../academic/student_enrollments.php', 'variant' => 'primary'],
            ['label' => 'Asignaciones', 'href' => '../academic/student_assignments.php', 'variant' => 'secondary'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel Administrador</title>
<style>
:root{
  --bg:#eef7f0;
  --card:#ffffff;
  --line:#d8e8dc;
  --text:#1f3b28;
  --title:#1f3b28;
  --muted:#5d7465;
  --green:#2f9e44;
  --green-dark:#237a35;
  --green-soft:#e9f8ee;
  --green-bright:#41b95a;
  --gray:#6f7e73;
  --shadow:0 10px 24px rgba(0,0,0,.08);
  --shadow-sm:0 2px 8px rgba(0,0,0,.06);
  --radius:18px;
}
*{ box-sizing:border-box; }
body{ margin:0; font-family:Arial,sans-serif; background:var(--bg); color:var(--text); }

.topbar{
  background:linear-gradient(180deg,var(--green),var(--green-dark));
  color:#fff;
  padding:16px 24px;
}

.topbar-inner{
  max-width:1400px;
  margin:0 auto;
  display:grid;
  grid-template-columns:1fr auto;
  align-items:center;
  gap:16px;
}

.topbar-title{ margin:0; font-size:28px; font-weight:800; }

.logout-btn{
  display:inline-block;
  text-decoration:none;
  color:#fff;
  font-size:13px;
  font-weight:700;
  border-radius:12px;
  padding:11px 18px;
  background:linear-gradient(180deg,#4b8b5b,#356844);
  box-shadow:var(--shadow-sm);
  transition:filter .2s, transform .15s;
}

.logout-btn:hover{
  filter:brightness(1.05);
  transform:translateY(-1px);
}

.page{
  max-width:1400px;
  margin:0 auto;
  padding:20px 20px 40px;
}

.layout{
  display:grid;
  grid-template-columns:290px 1fr;
  gap:24px;
  align-items:start;
}

.sidebar,
.card,
.hero-card{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
}

.sidebar{
  padding:22px 18px;
  position:sticky;
  top:20px;
}

.brand-card{
  text-align:center;
  padding-bottom:12px;
  border-bottom:1px solid var(--line);
  margin-bottom:14px;
}

.logo-wrap{
  width:92px;
  height:92px;
  margin:0 auto 14px;
  border-radius:18px;
  overflow:hidden;
  background:linear-gradient(180deg,#ffffff,#dff4e5);
  box-shadow:var(--shadow);
}

.logo-wrap img{ width:100%; height:100%; object-fit:cover; display:block; }

.brand-name{ margin:0 0 4px; font-size:22px; font-weight:800; color:var(--title); }
.brand-role{ margin:0; font-size:13px; color:var(--muted); font-weight:700; }

.profile-stats{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
  margin:16px 0 18px;
}

.stat-box{
  padding:12px;
  border:1px solid var(--line);
  border-radius:12px;
  background:#f7fcf8;
  text-align:center;
}

.stat-label{
  display:block;
  font-size:10px;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:.08em;
  color:var(--muted);
  margin-bottom:4px;
}

.stat-value{
  display:block;
  font-size:18px;
  font-weight:800;
  color:var(--green-dark);
}

.sidebar-title{
  margin:18px 0 10px;
  font-size:11px;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:.08em;
  color:var(--muted);
}

.nav-list{ display:flex; flex-direction:column; gap:8px; }

.nav-link{
  display:block;
  width:100%;
  text-decoration:none;
  color:#fff;
  font-size:13px;
  font-weight:700;
  line-height:1.35;
  padding:11px 12px;
  border-radius:12px;
  background:linear-gradient(180deg,#41b95a,#2f9e44);
  box-shadow:var(--shadow-sm);
  transition:filter .2s, transform .15s;
}

.nav-link:hover{
  filter:brightness(1.06);
  transform:translateY(-1px);
}

.nav-link.secondary{
  background:linear-gradient(180deg,#7b8b7f,#66756a);
}

.main-content{ min-width:0; }

.hero-card{
  position:relative;
  overflow:hidden;
  padding:28px;
  margin-bottom:22px;
  background:linear-gradient(135deg,#ffffff 0%, #f7fcf8 100%);
}

.hero-card::before{
  content:"";
  position:absolute;
  inset:auto -90px -90px auto;
  width:240px;
  height:240px;
  border-radius:50%;
  background:radial-gradient(circle, rgba(47,158,68,.16) 0%, rgba(47,158,68,0) 70%);
}

.hero-content{ position:relative; z-index:1; }

.hero-eyebrow{
  display:inline-block;
  margin:0 0 10px;
  padding:6px 12px;
  border-radius:999px;
  background:var(--green-soft);
  color:var(--green-dark);
  font-size:12px;
  font-weight:800;
}

.hero-title{ margin:0 0 12px; font-size:32px; font-weight:800; color:var(--title); }
.hero-text{ margin:0; font-size:15px; line-height:1.6; color:var(--muted); max-width:760px; }

.section-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:18px;
}

.card{ padding:24px; }

.card-eyebrow{
  display:block;
  margin:0 0 10px;
  font-size:11px;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:.08em;
  color:var(--muted);
}

.card-title{ margin:0 0 12px; font-size:26px; font-weight:800; color:var(--green-dark); }
.card-text{ margin:0 0 18px; font-size:15px; line-height:1.6; color:var(--muted); }

.actions{ display:flex; flex-wrap:wrap; gap:12px; }

.btn{
  display:inline-block;
  min-width:220px;
  text-align:center;
  text-decoration:none;
  color:#fff;
  font-size:14px;
  font-weight:700;
  padding:12px 16px;
  border-radius:12px;
  box-shadow:var(--shadow-sm);
  transition:filter .2s, transform .15s;
}

.btn:hover{
  filter:brightness(1.06);
  transform:translateY(-1px);
}

.btn-primary{ background:linear-gradient(180deg,#41b95a,#2f9e44); }
.btn-secondary{ background:linear-gradient(180deg,#7b8b7f,#66756a); }

@media (max-width: 1080px){
  .layout{ grid-template-columns:1fr; }
  .sidebar{ position:static; }
}

@media (max-width: 768px){
  .topbar-inner{ grid-template-columns:1fr; }
  .logout-btn{ justify-self:start; }
  .page{ padding:14px 14px 32px; }
  .section-grid{ grid-template-columns:1fr; }
  .btn{ width:100%; min-width:0; }
  .hero-title{ font-size:26px; }
}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <h1 class="topbar-title">Panel Administrador</h1>
    <a href="logout.php" class="logout-btn">Cerrar sesion</a>
  </div>
</header>

<main class="page">
  <div class="layout">
    <aside class="sidebar">
      <div class="brand-card">
        <div class="logo-wrap" aria-label="Logo Let&apos;s Aprende Ingles">
          <img src="../hangman/assets/LETS%20NUEVO%20-%20copia.jpeg" alt="Logo Let's Aprende Ingles">
        </div>
        <h2 class="brand-name"><?php echo h($adminDisplay); ?></h2>
        <p class="brand-role"><?php echo h($adminRole); ?></p>

        <div class="profile-stats">
          <div class="stat-box">
            <span class="stat-label">Modulos</span>
            <span class="stat-value"><?php echo count($dashboardSections); ?></span>
          </div>
          <div class="stat-box">
            <span class="stat-label">Acceso</span>
            <span class="stat-value">Total</span>
          </div>
        </div>
      </div>

      <div class="sidebar-title">Accesos rapidos</div>
      <div class="nav-list">
        <?php foreach ($dashboardSections as $section): ?>
          <a class="nav-link<?php echo in_array($section['id'], ['docentes', 'estudiantes'], true) ? ' secondary' : ''; ?>" href="#<?php echo h($section['id']); ?>">
            <?php echo h($section['title']); ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="sidebar-title">Sesion</div>
      <div class="nav-list">
        <a class="nav-link secondary" href="logout.php">Cerrar sesion</a>
      </div>
    </aside>

    <section class="main-content">
      <article class="hero-card">
        <div class="hero-content">
          <span class="hero-eyebrow">Centro de administracion</span>
          <h2 class="hero-title">Gestion central del campus</h2>
          <p class="hero-text">Desde este panel puedes administrar programas tecnicos, cursos de ingles, docentes y estudiantes con el mismo estilo de navegacion claro y moderno del dashboard academico.</p>
        </div>
      </article>

      <div class="section-grid">
        <?php foreach ($dashboardSections as $section): ?>
          <article class="card" id="<?php echo h($section['id']); ?>">
            <span class="card-eyebrow"><?php echo h($section['eyebrow']); ?></span>
            <h2 class="card-title"><?php echo h($section['title']); ?></h2>
            <p class="card-text"><?php echo h($section['description']); ?></p>
            <div class="actions">
              <?php foreach ($section['links'] as $link): ?>
                <a class="btn <?php echo $link['variant'] === 'secondary' ? 'btn-secondary' : 'btn-primary'; ?>" href="<?php echo h($link['href']); ?>">
                  <?php echo h($link['label']); ?>
                </a>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</main>

</body>
</html>

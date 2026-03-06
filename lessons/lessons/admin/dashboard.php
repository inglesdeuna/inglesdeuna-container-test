<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$baseDir = dirname(__DIR__) . '/academic/data';
$teachersFile = $baseDir . '/teachers.json';
$studentsFile = $baseDir . '/students.json';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

foreach ([$teachersFile, $studentsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$teachers = json_decode((string) file_get_contents($teachersFile), true);
$students = json_decode((string) file_get_contents($studentsFile), true);

$teachers = is_array($teachers) ? $teachers : [];
$students = is_array($students) ? $students : [];

function save_people(string $file, array $rows): void
{
    file_put_contents($file, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function add_person(array $rows, string $name, array $extra = []): array
{
    $cleanName = trim($name);
    if ($cleanName === '') {
        return $rows;
    }

    foreach ($rows as $existing) {
        if (mb_strtolower((string) ($existing['name'] ?? '')) === mb_strtolower($cleanName)) {
            return $rows;
        }
    }

    $rows[] = array_merge([
        'id' => uniqid('usr_'),
        'name' => $cleanName,
    ], $extra);

    return $rows;
}

function load_structure_options(): array
{
    $technical = [];
    $english = [];

    $canUseDb = getenv('DATABASE_URL');
    if ($canUseDb) {
        try {
            require __DIR__ . '/../config/db.php';

            $stmtTechnical = $pdo->query("
                SELECT c.id, c.name
                FROM courses c
                INNER JOIN programs p ON p.id = c.program_id
                WHERE p.slug = 'prog_technical'
                ORDER BY c.id ASC
            ");
            $technical = $stmtTechnical->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtEnglish = $pdo->query("
                SELECT ph.id, CONCAT(l.name, ' - ', ph.name) AS name
                FROM english_phases ph
                INNER JOIN english_levels l ON l.id = ph.level_id
                ORDER BY l.id ASC, ph.id ASC
            ");
            $english = $stmtEnglish->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $technical = [];
            $english = [];
        }
    }

    if (empty($technical)) {
        $coursesFile = dirname(__DIR__) . '/academic/data/courses.json';
        $raw = file_exists($coursesFile) ? json_decode((string) file_get_contents($coursesFile), true) : [];
        if (is_array($raw)) {
            foreach ($raw as $course) {
                $technical[] = [
                    'id' => (string) ($course['id'] ?? ''),
                    'name' => (string) ($course['name'] ?? 'Semestre'),
                ];
            }
        }
    }

    return [$technical, $english];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'add_teacher') {
        $teachers = add_person(
            $teachers,
            isset($_POST['teacher_name']) ? (string) $_POST['teacher_name'] : '',
            [
                'id_number' => trim((string) ($_POST['teacher_id_number'] ?? '')),
                'phone' => trim((string) ($_POST['teacher_phone'] ?? '')),
                'bank_account' => trim((string) ($_POST['teacher_bank_account'] ?? '')),
            ]
        );
        save_people($teachersFile, $teachers);
    }

    if ($action === 'add_student') {
        $students = add_person(
            $students,
            isset($_POST['student_name']) ? (string) $_POST['student_name'] : '',
            [
                'guardian' => trim((string) ($_POST['student_guardian'] ?? '')),
                'contact' => trim((string) ($_POST['student_contact'] ?? '')),
                'eps' => trim((string) ($_POST['student_eps'] ?? '')),
            ]
        );
        save_people($studentsFile, $students);
    }

    header('Location: dashboard.php?saved=1');
    exit;
}

[$technicalSemesters, $englishCourses] = load_structure_options();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Administrador</title>
<style>
:root {
  --blue-1:#0d4ea7; --blue-2:#2d77db; --page-bg:#edf1f8; --card-bg:#f4f5f8;
  --text-main:#2f4460; --text-soft:#66748a; --line:#dde4ef;
  --btn-blue:#2d71d2; --btn-green:#4fb489; --btn-orange:#f3ba3b;
}
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",Arial,sans-serif; background:var(--page-bg); color:var(--text-main); }

.topbar{
  background:linear-gradient(90deg,var(--blue-1),#1a61bd 52%,var(--blue-2));
  color:#fff; padding:12px 56px; display:flex; align-items:center; justify-content:space-between;
}
.brand{ display:flex; align-items:center; gap:18px; }
.logo-wrap{ width:96px; height:96px; position:relative; flex-shrink:0; }
.logo-front,.logo-back-green,.logo-back-blue,.logo-back-red{ position:absolute; border-radius:10px; }
.logo-front{
  inset:0; background:linear-gradient(#fff 0 72%, #3d92dd 72%);
  border:5px solid #f5be2f; box-shadow:0 5px 14px rgba(14,48,95,.25); z-index:4;
  display:flex; align-items:center; justify-content:center; flex-direction:column; font-weight:800; line-height:1;
}
.logo-back-green{ inset:-7px 7px 7px -7px; background:#89bb35; z-index:1; }
.logo-back-blue{ inset:7px -7px -7px 7px; background:#2d8fdc; z-index:2; }
.logo-back-red{ inset:7px -7px -7px 7px; transform:translate(7px,7px); background:#de3a33; z-index:0; }
.logo-let{ font-size:20px; letter-spacing:.8px; color:#2b63c7; }
.logo-aprende{ font-size:13px; color:#5e6572; margin:2px 0; }
.logo-ingles{ font-size:18px; color:#ffb11c; }

.topbar h1{ margin:0; font-size:44px; font-weight:700; }
.logout-btn{
  text-decoration:none; color:#fff; font-weight:700; font-size:18px;
  padding:12px 22px; border-radius:10px; background:linear-gradient(180deg,#5aabf6,#4594ec);
}

.page{ max-width:1260px; margin:18px auto 0; padding:0 16px 24px; }
.notice{
  margin-bottom:12px; padding:10px 14px; border-radius:10px;
  border:1px solid #b4e2c8; background:#e9f9ef; color:#12653f; font-weight:600;
}

.grid-two{ display:grid; grid-template-columns:repeat(2,minmax(340px,1fr)); gap:18px; margin-bottom:18px; }
.card{
  background:var(--card-bg); border:1px solid var(--line); border-radius:16px; padding:22px;
  box-shadow:0 5px 14px rgba(51,72,107,.08);
}
.card h2,.card h3{
  margin:0 0 10px; color:var(--text-main); font-size:30px; display:flex; align-items:center; gap:10px;
}
.card p{ margin:0 0 14px; color:var(--text-soft); font-size:15px; }
.flow-note{
  background:#fff; border:1px dashed #c8d6ea; border-radius:10px; padding:10px 12px;
  margin-bottom:10px; color:#42526b; font-size:14px;
}

.btn{
  display:block; width:100%; text-align:center; text-decoration:none; color:#fff;
  font-size:16px; font-weight:700; border-radius:8px; padding:12px 14px; margin-bottom:10px;
}
.btn:last-child{ margin-bottom:0; }
.btn-blue{ background:linear-gradient(90deg,#2a67c4,var(--btn-blue)); }
.btn-green{ background:linear-gradient(90deg,#54b98e,var(--btn-green)); }
.btn-orange{ background:linear-gradient(90deg,#f0b739,var(--btn-orange)); }

.form-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
.form-grid .full{ grid-column:1 / -1; }
input,select,button{ font:inherit; }
input,select{
  width:100%; border:1px solid #d0d9e8; border-radius:8px; background:#fff; padding:10px 12px;
}
.save-btn{
  border:0; background:#0c5cc2; color:#fff; font-weight:700; border-radius:8px; padding:10px 16px; cursor:pointer;
}
.list-mini{ margin:10px 0 0; padding-left:18px; color:#52627b; max-height:130px; overflow:auto; }

.assignment-grid{ display:grid; grid-template-columns:1fr; gap:10px; }
.assignment-actions{ margin-top:10px; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }

@media (max-width:980px){
  .topbar{ padding:12px 16px; }
  .topbar h1{ font-size:28px; }
  .logo-wrap{ width:70px; height:70px; }
  .grid-two{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<header class="topbar">
  <div class="brand">
    <div class="logo-wrap" aria-label="Logo Let's Aprende Inglés">
      <div class="logo-back-green"></div>
      <div class="logo-back-blue"></div>
      <div class="logo-back-red"></div>
      <div class="logo-front">
        <div class="logo-let">LET'S</div>
        <div class="logo-aprende">aprende</div>
        <div class="logo-ingles">Inglés</div>
      </div>
    </div>
    <h1>Panel Administrador</h1>
  </div>
  <a href="logout.php" class="logout-btn">Cerrar sesión</a>
</header>

<main class="page">
  <?php if (isset($_GET['saved'])) { ?>
    <div class="notice">✔ Registro guardado.</div>
  <?php } ?>

  <section class="grid-two">
    <article class="card">
      <h2>📘 Programas Técnicos</h2>
      <p>Gestionar semestres, unidades y actividades.</p>
      <a class="btn btn-blue" href="../academic/courses_manager.php?program=prog_technical">Gestionar estructura</a>
      <a class="btn btn-green" href="../academic/technical_courses_created.php">Cursos creados</a>
      <a class="btn btn-orange" href="../academic/technical_assignments.php">Asignaciones (Docentes / Estudiantes)</a>
    </article>

    <article class="card">
      <h2>🌎 Cursos de Inglés</h2>
      <p>Gestionar levels, phases, unidades y actividades.</p>
      <a class="btn btn-blue" href="../academic/english_structure_levels.php">Gestionar estructura</a>
      <a class="btn btn-green" href="../academic/english_courses_created.php">Cursos creados</a>
      <a class="btn btn-orange" href="../academic/english_assignments.php">Asignaciones (Docentes / Estudiantes)</a>
    </article>
  </section>

  <section class="grid-two">
    <article class="card">
      <h3>🧾 Inscripción de Docentes</h3>
      <p class="flow-note">Bloque independiente. Desde aquí se registran docentes y luego se crean sus perfiles de acceso.</p>
      <form method="post" class="form-grid">
        <input class="full" type="hidden" name="action" value="add_teacher">
        <input class="full" type="text" name="teacher_name" placeholder="Nombre" required>
        <input type="text" name="teacher_id_number" placeholder="C.C">
        <input type="text" name="teacher_phone" placeholder="Teléfono">
        <input class="full" type="text" name="teacher_bank_account" placeholder="# Cuenta">
        <button type="submit" class="save-btn full">Guardar docente</button>
      </form>
      <ul class="list-mini">
        <?php foreach ($teachers as $teacher) { ?>
          <li><?php echo htmlspecialchars((string) ($teacher['name'] ?? 'Docente')); ?></li>
        <?php } ?>
      </ul>
      <a class="btn btn-blue" style="margin-top:12px" href="../academic/teacher_profiles.php">Crear perfil docente →</a>
    </article>

    <article class="card">
      <h3>🧾 Inscripción de Estudiantes</h3>
      <p class="flow-note">(Se mantiene igual.) Ingreso de datos base de estudiantes para el flujo de asignaciones.</p>
      <form method="post" class="form-grid">
        <input class="full" type="hidden" name="action" value="add_student">
        <input class="full" type="text" name="student_name" placeholder="Nombre" required>
        <input type="text" name="student_guardian" placeholder="Acudientes">
        <input type="text" name="student_contact" placeholder="Contacto">
        <input class="full" type="text" name="student_eps" placeholder="EPS">
        <button type="submit" class="save-btn full">Guardar estudiante</button>
      </form>
      <ul class="list-mini">
        <?php foreach ($students as $student) { ?>
          <li><?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?></li>
        <?php } ?>
      </ul>
    </article>
  </section>

  <section class="grid-two">
    <article class="card">
      <h3>🧑‍🏫 Asignación de Docentes</h3>
      <p class="flow-note">Semestres y cursos salen de la estructura creada.</p>
      <div class="assignment-grid">
        <select>
          <option>Seleccione un Docente</option>
          <?php foreach ($teachers as $teacher) { ?>
            <option><?php echo htmlspecialchars((string) ($teacher['name'] ?? 'Docente')); ?></option>
          <?php } ?>
        </select>
        <select>
          <option>Semestre técnico creado</option>
          <?php foreach ($technicalSemesters as $semester) { ?>
            <option><?php echo htmlspecialchars((string) ($semester['name'] ?? 'Semestre')); ?></option>
          <?php } ?>
        </select>
        <select>
          <option>Curso inglés creado</option>
          <?php foreach ($englishCourses as $course) { ?>
            <option><?php echo htmlspecialchars((string) ($course['name'] ?? 'Curso')); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="assignment-actions">
        <a class="btn btn-blue" href="../academic/teacher_profiles.php">Crear perfil docente</a>
        <a class="btn btn-green" href="../academic/technical_assignments.php">Asignaciones</a>
      </div>
    </article>

    <article class="card">
      <h3>🎓 Asignación de Estudiantes</h3>
      <div class="assignment-grid">
        <select>
          <option>Seleccione un Estudiante</option>
          <?php foreach ($students as $student) { ?>
            <option><?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?></option>
          <?php } ?>
        </select>
        <select>
          <option>Semestre técnico creado</option>
          <?php foreach ($technicalSemesters as $semester) { ?>
            <option><?php echo htmlspecialchars((string) ($semester['name'] ?? 'Semestre')); ?></option>
          <?php } ?>
        </select>
        <select>
          <option>Curso inglés creado</option>
          <?php foreach ($englishCourses as $course) { ?>
            <option><?php echo htmlspecialchars((string) ($course['name'] ?? 'Curso')); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="assignment-actions">
        <a class="btn btn-blue" href="../academic/technical_assignments.php">Programa Técnico</a>
        <a class="btn btn-green" href="../academic/english_assignments.php">Cursos Inglés</a>
      </div>
    </article>
  </section>
</main>

</body>
</html>

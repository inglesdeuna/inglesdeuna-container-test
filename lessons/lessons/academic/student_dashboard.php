<?php
session_start();

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

$studentId = (string) ($_SESSION['student_id'] ?? '');
$studentName = (string) ($_SESSION['student_name'] ?? 'Estudiante');

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

function load_student_assignments(string $studentId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, teacher_id, course_id, period, unit_id, program, updated_at
            FROM student_assignments
            WHERE student_id = :student_id
            ORDER BY updated_at DESC NULLS LAST, id DESC
        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_courses_by_id(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    $coursesById = [];

    try {
        $rows = $pdo->query("SELECT id, name FROM courses")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $coursesById[(string) ($row['id'] ?? '')] = (string) ($row['name'] ?? 'Curso');
        }
    } catch (Throwable $e) {
        return [];
    }

    return $coursesById;
}

function load_teachers_by_id(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    $teachersById = [];

    try {
        $rows = $pdo->query("SELECT id, name FROM teachers")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $teachersById[(string) ($row['id'] ?? '')] = (string) ($row['name'] ?? 'Docente');
        }
    } catch (Throwable $e) {
        return [];
    }

    return $teachersById;
}

$myAssignments = load_student_assignments($studentId);
$coursesById = load_courses_by_id();
$teachersById = load_teachers_by_id();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel Estudiante</title>
<style>
:root{
    --bg:#eef3ff;
    --card:#ffffff;
    --line:#d9e3f4;
    --title:#1f4d8f;
    --text:#1d355d;
    --muted:#5d6f8f;
    --blue:#2563eb;
    --blue-hover:#1d4ed8;
    --danger:#c42828;
    --shadow:0 8px 20px rgba(18,58,120,.10);
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
    padding:26px;
}

.page{
    max-width:1200px;
    margin:0 auto;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.top h1{
    margin:0;
    color:var(--title);
    font-size:30px;
}

.logout{
    color:var(--danger);
    text-decoration:none;
    font-weight:700;
}

.welcome{
    margin:0 0 26px;
    font-size:16px;
}

.section-title{
    margin:0 0 16px;
    color:var(--title);
    font-size:24px;
    font-weight:700;
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
    gap:16px;
}

.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:12px;
    padding:18px;
    box-shadow:var(--shadow);
}

.card h3{
    margin:0 0 10px;
    font-size:20px;
    color:var(--title);
}

.card p{
    margin:6px 0;
    color:var(--muted);
    font-size:15px;
}

.btn{
    display:inline-block;
    margin-top:14px;
    padding:10px 14px;
    background:var(--blue);
    color:#fff;
    text-decoration:none;
    border-radius:8px;
    font-weight:700;
    transition:background .2s ease;
}

.btn:hover{
    background:var(--blue-hover);
}

.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:12px;
    padding:18px;
    color:var(--muted);
}

.block{
    margin-top:22px;
    background:#fff;
    border:1px dashed #c5d7f2;
    border-radius:12px;
    padding:14px;
    color:var(--muted);
    font-size:15px;
}

@media (max-width: 768px){
    body{
        padding:18px;
    }

    .top h1{
        font-size:24px;
    }
}
</style>
</head>
<body>
<div class="page">
    <div class="top">
        <h1>Perfil del Estudiante</h1>
        <a class="logout" href="logout.php">Cerrar sesión</a>
    </div>

    <p class="welcome">
        Bienvenido, <strong><?php echo h($studentName); ?></strong>.
    </p>

    <h2 class="section-title">Mis cursos</h2>

    <?php if (empty($myAssignments)) { ?>
        <div class="empty">No tienes cursos asignados aún.</div>
    <?php } else { ?>
        <div class="grid">
            <?php foreach ($myAssignments as $assignment) { ?>
                <?php
                $courseId = (string) ($assignment['course_id'] ?? '');
                $teacherId = (string) ($assignment['teacher_id'] ?? '');
                ?>
                <div class="card">
                    <h3><?php echo h($coursesById[$courseId] ?? 'Curso'); ?></h3>
                    <p>Docente: <strong><?php echo h($teachersById[$teacherId] ?? 'N/D'); ?></strong></p>
                    <p>Periodo: <strong><?php echo h((string) ($assignment['period'] ?? '')); ?></strong></p>

                    <a class="btn" href="student_course.php?assignment=<?php echo urlencode((string) ($assignment['id'] ?? '')); ?>">
                        Entrar al curso
                    </a>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="block">
        <strong>Bloques próximos:</strong> aquí se agregará la parte de notas y quices del estudiante.
    </div>
</div>
</body>
</html>

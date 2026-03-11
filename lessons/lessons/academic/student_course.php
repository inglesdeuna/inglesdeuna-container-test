<?php
session_start();

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$studentId = (string) ($_SESSION['student_id'] ?? '');

if ($assignmentId === '') {
    die('Asignación no especificada.');
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

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

try {
    $stmt = $pdo->prepare("
        SELECT id, student_id, teacher_id, course_id, period
        FROM student_assignments
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $assignment = false;
}

if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
    die('No tienes acceso a este curso.');
}

$courseId = (string) ($assignment['course_id'] ?? '');

try {
    $stmtCourse = $pdo->prepare("
        SELECT id, name
        FROM courses
        WHERE id = :id
        LIMIT 1
    ");
    $stmtCourse->execute(['id' => $courseId]);
    $course = $stmtCourse->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $course = false;
}

if (!$course) {
    die('Curso no encontrado.');
}

try {
    $stmtUnits = $pdo->prepare("
        SELECT id, name
        FROM units
        WHERE course_id = :course_id
        ORDER BY position ASC, id ASC
    ");
    $stmtUnits->execute(['course_id' => $courseId]);
    $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $units = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h((string) ($course['name'] ?? 'Curso')); ?></title>
<style>
:root{
    --bg:#eef3ff;
    --card:#ffffff;
    --line:#d8e2f2;
    --title:#1f4d8f;
    --text:#1d355d;
    --muted:#5d6f8f;
    --blue:#2563eb;
    --blue-hover:#1d4ed8;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:var(--bg);
    font-family:Arial,sans-serif;
    padding:24px;
    color:var(--text);
}

.page{
    max-width:1100px;
    margin:0 auto;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.top h1{
    margin:0;
    color:var(--title);
    font-size:30px;
}

.back{
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
}

.meta{
    margin:0 0 24px;
    color:var(--muted);
    font-size:16px;
}

.section-title{
    margin:0 0 14px;
    color:var(--title);
    font-size:24px;
    font-weight:700;
}

.unit{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:12px;
    padding:16px;
    margin-top:12px;
}

.unit h3{
    margin:0 0 10px;
    color:var(--text);
    font-size:20px;
}

.btn{
    display:inline-block;
    margin-top:8px;
    padding:9px 14px;
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
    padding:16px;
    color:var(--muted);
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
        <h1><?php echo h((string) ($course['name'] ?? 'Curso')); ?></h1>
        <a class="back" href="student_dashboard.php">← Volver</a>
    </div>

    <p class="meta">
        Periodo: <strong><?php echo h((string) ($assignment['period'] ?? '')); ?></strong>
    </p>

    <h2 class="section-title">Unidades</h2>

    <?php if (empty($units)) { ?>
        <div class="empty">No hay unidades disponibles.</div>
    <?php } else { ?>
        <?php foreach ($units as $unit) { ?>
            <div class="unit">
                <h3><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></h3>
                <a
                    class="btn"
                    href="/lessons/lessons/activities/hub/index.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>"
                    target="_blank"
                >
                    Ver actividades
                </a>
            </div>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>

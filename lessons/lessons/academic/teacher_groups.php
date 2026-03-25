<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_pdo_connection(): ?PDO {
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    static $cachedPdo = null;
    static $loaded = false;

    if ($loaded) {
        return $cachedPdo;
    }

    $loaded = true;
    $dbFile = __DIR__ . '/../config/db.php';

    if (!file_exists($dbFile)) {
        return null;
    }

    require $dbFile;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    $cachedPdo = $pdo;
    return $cachedPdo;
}

function delete_teacher_assignments_from_database(string $teacherId): bool {
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM teacher_assignments WHERE teacher_id = :teacher_id");
        return $stmt->execute(['teacher_id' => $teacherId]);
    } catch (Throwable $e) {
        return false;
    }
}

function load_teacher_accounts_summary(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->query("
            SELECT teacher_id, id AS account_id, username, permission
            FROM teacher_accounts
            ORDER BY updated_at DESC NULLS LAST
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $tid = (string)($row['teacher_id'] ?? '');
            if ($tid !== '' && !isset($map[$tid])) {
                $map[$tid] = [
                    'account_id' => (string)($row['account_id'] ?? ''),
                    'username'   => (string)($row['username']   ?? ''),
                    'permission' => (string)($row['permission'] ?? 'viewer'),
                ];
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function load_student_counts_per_teacher(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->query("
            SELECT teacher_id, COUNT(*) AS cnt
            FROM student_assignments
            GROUP BY teacher_id
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $tid = (string)($row['teacher_id'] ?? '');
            if ($tid !== '') {
                $map[$tid] = (int)($row['cnt'] ?? 0);
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function load_students_grouped_per_teacher(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT sa.teacher_id, sa.student_id, COALESCE(s.name, sa.student_id) AS student_name
            FROM student_assignments sa
            LEFT JOIN students s ON s.id = sa.student_id
            WHERE sa.teacher_id IS NOT NULL
              AND sa.teacher_id <> ''
              AND sa.student_id IS NOT NULL
              AND sa.student_id <> ''
            ORDER BY sa.teacher_id ASC, student_name ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grouped = [];

        foreach ($rows as $row) {
            $teacherId = (string) ($row['teacher_id'] ?? '');
            $studentId = (string) ($row['student_id'] ?? '');
            if ($teacherId === '' || $studentId === '') {
                continue;
            }

            if (!isset($grouped[$teacherId])) {
                $grouped[$teacherId] = [];
            }

            $alreadyAdded = false;
            foreach ($grouped[$teacherId] as $added) {
                if ((string) ($added['student_id'] ?? '') === $studentId) {
                    $alreadyAdded = true;
                    break;
                }
            }
            if ($alreadyAdded) {
                continue;
            }

            $grouped[$teacherId][] = [
                'student_id' => $studentId,
                'student_name' => (string) ($row['student_name'] ?? $studentId),
            ];
        }

        return $grouped;
    } catch (Throwable $e) {
        return [];
    }
}

function load_grouped_assignments_from_database(): array {
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT teacher_id, teacher_name, program_type, course_name, unit_name, id
            FROM teacher_assignments
            ORDER BY teacher_name ASC, program_type ASC, course_name ASC, COALESCE(unit_name, '') ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grouped = [];

        foreach ($rows as $row) {
            $teacherId = (string) ($row['teacher_id'] ?? '');
            if ($teacherId === '') {
                continue;
            }

            if (!isset($grouped[$teacherId])) {
                $grouped[$teacherId] = [
                    'teacher_id' => $teacherId,
                    'teacher_name' => (string) ($row['teacher_name'] ?? 'Docente'),
                    'items' => [],
                ];
            }

            $grouped[$teacherId]['items'][] = [
                'id' => (string) ($row['id'] ?? ''),
                'program_type' => (string) ($row['program_type'] ?? ''),
                'course_name' => (string) ($row['course_name'] ?? ''),
                'unit_name' => (string) ($row['unit_name'] ?? ''),
            ];
        }

        return array_values($grouped);
    } catch (Throwable $e) {
        return [];
    }
}

if (isset($_GET['remove_teacher']) && $_GET['remove_teacher'] !== '') {
    delete_teacher_assignments_from_database((string) $_GET['remove_teacher']);
    header('Location: teacher_groups.php?saved=1');
    exit;
}

$teachers = load_grouped_assignments_from_database();
$teacherAccounts = load_teacher_accounts_summary();
$studentCounts = load_student_counts_per_teacher();
$studentsByTeacher = load_students_grouped_per_teacher();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Docentes y Grupos</title>
<style>
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --subtitle:#2a5136;
    --muted:#5d7465;
    --green-primary:#2f9e44;
    --green-dark:#237a35;
    --orange:#b45309;
    --green:#166534;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --radius:14px;
}
*{
    box-sizing:border-box;
}
body{
    margin:0;
    font-family:Arial, "Segoe UI", sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    padding:32px 20px;
}
.wrapper{
    width:100%;
    max-width:980px;
    margin:0 auto;
}
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:18px;
    flex-wrap:wrap;
}
.back{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:10px;
    background:linear-gradient(180deg,#6b8f71,#4a6e52);
    color:#fff;
    text-decoration:none;
    font-weight:700;
    font-size:13px;
    border:none;
}
.back:hover{
    background:linear-gradient(180deg,#5a7d60,#3a5e42);
}
.links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.link-secondary{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:8px 14px;
    border-radius:10px;
    background:#eef7f0;
    color:var(--green-primary);
    text-decoration:none;
    font-weight:700;
    font-size:13px;
    border:1px solid #b8dfc4;
}
.link-secondary:hover{
    background:#d4f0dc;
}
.page-title{
    margin:10px 0 18px;
    color:var(--subtitle);
    font-size:28px;
    font-weight:700;
}
.page-subtitle{
    margin:-8px 0 18px;
    color:var(--muted);
    font-size:14px;
    line-height:1.5;
}
.notice{
    padding:12px 14px;
    border-radius:10px;
    background:#ecfdf3;
    border:1px solid #b9eacb;
    color:#166534;
    margin-bottom:16px;
    font-size:14px;
    font-weight:600;
}
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:16px;
    margin-bottom:16px;
}
.teacher-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    flex-wrap:wrap;
}
.teacher-name{
    font-size:18px;
    font-weight:700;
    color:var(--subtitle);
    margin-bottom:6px;
}
.teacher-meta{
    font-size:13px;
    color:var(--muted);
}
.teacher-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 12px;
    border-radius:10px;
    text-decoration:none;
    font-weight:700;
    font-size:12px;
    border:none;
    cursor:pointer;
}
.btn-green{
    background:linear-gradient(180deg,var(--green-primary),var(--green-dark));
    color:#fff;
    border:none;
}
.btn-green:hover{
    background:linear-gradient(180deg,var(--green-dark),#1b6329);
}
.btn-red{
    background:#fee2e2;
    color:#b91c1c;
}
.btn-red:hover{
    background:#fecaca;
}
.btn-danger{
    background:#b91c1c;
    color:#fff;
}
.btn-danger:hover{
    background:#991b1b;
}
.items{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:14px;
}
.badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.badge-tech{
    background:#eef7f0;
    color:var(--green-primary);
}
.badge-eng{
    background:#fff3e8;
    color:var(--orange);
}
.badge-unit{
    background:#eef8f2;
    color:var(--green);
}
.student-list{
    margin-top:12px;
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.student-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:#f3f8ff;
    color:#1f4d8f;
    border:1px solid #d9e7ff;
}
.empty{
    color:var(--muted);
    font-size:14px;
    line-height:1.6;
}
@media (max-width: 768px){
    body{
        padding:20px 16px;
    }
    .page-title{
        font-size:24px;
    }
    .teacher-head{
        flex-direction:column;
    }
    .teacher-actions{
        width:100%;
    }
    .btn,
    .link-secondary,
    .back{
        width:100%;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <div class="topbar">
        <a class="back" href="teacher_assignments.php">← Volver a asignaciones</a>
        <div class="links">
            <a class="link-secondary" href="teacher_profiles.php">Perfiles docentes</a>
            <a class="link-secondary" href="../admin/dashboard.php">Dashboard</a>
        </div>
    </div>

    <div class="page-title">Docentes y Grupos</div>
    <div class="page-subtitle">
        Aquí puedes ver todos los docentes que tienen asignaciones activas, agrupadas por curso, semestre y unidad.
    </div>

    <?php if (isset($_GET['saved'])) { ?>
        <div class="notice">Asignaciones actualizadas correctamente.</div>
    <?php } ?>

    <?php if (empty($teachers)) { ?>
        <div class="card">
            <div class="empty">No hay docentes con asignaciones actualmente.</div>
            <div class="empty">Primero crea perfil y luego asigna cursos.</div>
            <div class="teacher-actions" style="margin-top:12px;">
                <a class="btn btn-green" href="teacher_profiles.php">Crear/editar perfil</a>
                <a class="btn btn-green" href="teacher_assignments.php">Ir a asignaciones</a>
            </div>
        </div>
    <?php } else { ?>
        <?php foreach ($teachers as $teacher) {
            $tid             = (string)($teacher['teacher_id'] ?? '');
            $account         = $teacherAccounts[$tid] ?? [];
            $studentCount    = $studentCounts[$tid] ?? 0;
            $assignmentCount = count((array)($teacher['items'] ?? []));
        ?>
            <div class="card">
                <div class="teacher-head">
                    <div>
                        <div class="teacher-name">
                            👨‍🏫 Prof. <?php echo h((string)($teacher['teacher_name'] ?? 'Docente')); ?>
                        </div>
                        <div class="teacher-meta">
                            <?php if (!empty($account['username'])) { ?>
                                👤 <?php echo h($account['username']); ?>
                                &nbsp;·&nbsp;
                                <span style="text-transform:capitalize"><?php echo h($account['permission'] ?? 'viewer'); ?></span>
                                &nbsp;·&nbsp;
                            <?php } ?>
                            📋 <?php echo $assignmentCount; ?> asignación(es)
                            <?php if ($studentCount > 0) { ?>
                                &nbsp;·&nbsp; 👥 <?php echo $studentCount; ?> estudiante(s)
                            <?php } ?>
                        </div>
                    </div>

                    <div class="teacher-actions">
                        <?php if (!empty($account['account_id'])) { ?>
                            <a class="btn btn-green" href="teacher_profiles.php?edit=<?php echo h($account['account_id']); ?>">
                                Editar Perfil
                            </a>
                        <?php } ?>
                        <a class="btn btn-green" href="teacher_assignments.php?teacher_id=<?php echo h($tid); ?>">
                            Editar Asignaciones
                        </a>
                        <a class="btn btn-red" href="teacher_groups.php?remove_teacher=<?php echo h($tid); ?>" onclick="return confirm('¿Quitar todas las asignaciones de este docente?')">
                            Quitar Asignaciones
                        </a>
                        <?php if ($tid !== '') { ?>
                            <a class="btn btn-danger" href="delete_teacher.php?id=<?php echo h($tid); ?>" onclick="return confirm('¿Eliminar completamente al docente <?php echo addslashes((string)($teacher['teacher_name'] ?? '')); ?>?\nEsto eliminará su cuenta, perfil y todas sus asignaciones. No se puede deshacer.')">
                                🗑 Eliminar Docente
                            </a>
                        <?php } ?>
                    </div>
                </div>

                <div class="items">
                    <?php foreach ((array)($teacher['items'] ?? []) as $item) {
                        $program    = (string)($item['program_type'] ?? '');
                        $courseName = (string)($item['course_name'] ?? '');
                        $unitName   = (string)($item['unit_name']   ?? '');
                    ?>
                        <?php if ($program === 'english') { ?>
                            <span class="badge badge-eng">
                                📚 <?php echo h($courseName); ?> · completo
                            </span>
                        <?php } else { ?>
                            <span class="badge badge-tech">
                                🔧 <?php echo h($courseName); ?>
                            </span>
                            <?php if ($unitName !== '') { ?>
                                <span class="badge badge-unit">
                                    <?php echo h($unitName); ?>
                                </span>
                            <?php } ?>
                        <?php } ?>
                    <?php } ?>
                </div>

                <?php $studentsForTeacher = $studentsByTeacher[$tid] ?? []; ?>
                <?php if (!empty($studentsForTeacher)) { ?>
                    <div class="student-list">
                        <?php foreach ($studentsForTeacher as $studentRow) { ?>
                            <span class="student-badge">👤 <?php echo h((string) ($studentRow['student_name'] ?? 'Estudiante')); ?></span>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>
</div>
</body>
</html>

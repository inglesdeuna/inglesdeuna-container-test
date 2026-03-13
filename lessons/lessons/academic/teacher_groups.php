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
            if ($teacherId === '') continue;
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Docentes y Grupos</title>
<style>
:root{
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --subtitle:#143d7a;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --orange:#b45309;
    --green:#166534;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --radius:14px;
}
*{box-sizing:border-box;}
body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    padding:32px 20px;
}
.wrapper{width:100%;max-width:980px;margin:0 auto;}
.back{
    display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 14px;
    border-radius:10px;background:var(--blue);color:#fff;text-decoration:none;font-weight:700;font-size:13px;
}
.page-title{
    margin:14px 0 18px;
    color:var(--subtitle);
    font-size:24px;
    font-weight:700;
}
.notice{
    padding:12px 14px;border-radius:10px;background:#ecfdf3;border:1px solid #b9eacb;
    color:#166534;margin-bottom:16px;font-size:14px;font-weight:600;
}
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    margin-bottom:14px;
}
.teacher-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    flex-wrap:wrap;
}
.teacher-name{
    font-size:16px;
    font-weight:700;
    color:#1f3c75;
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
    display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:10px;
    text-decoration:none;font-weight:700;font-size:12px;border:none;cursor:pointer;
}
.btn-blue{background:var(--blue);color:#fff;}
.btn-blue:hover{background:var(--blue-hover);}
.btn-red{background:#fee2e2;color:#b91c1c;}
.btn-red:hover{background:#fecaca;}
.items{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
}
.badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.badge-tech{background:#eef4ff;color:#1f66cc;}
.badge-eng{background:#fff3e8;color:var(--orange);}
.badge-unit{background:#eef8f2;color:var(--green);}
.empty{
    color:var(--muted);
    font-size:14px;
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="teacher_assignments.php">← Volver a asignaciones</a>
    <div class="page-title">Docentes y Grupos</div>

    <?php if (isset($_GET['saved'])) { ?>
        <div class="notice">Asignaciones actualizadas correctamente.</div>
    <?php } ?>

    <?php if (empty($teachers)) { ?>
        <div class="card">
            <div class="empty">No hay docentes con asignaciones actualmente.</div>
            <div class="empty">Primero crea perfil y luego asigna cursos.</div>
            <div class="teacher-actions" style="margin-top:10px;">
                <a class="btn btn-blue" href="teacher_profiles.php">Crear/editar perfil</a>
                <a class="btn btn-blue" href="teacher_assignments.php">Ir a asignaciones</a>
            </div>
        </div>
    <?php } else { ?>
        <?php foreach ($teachers as $teacher) { ?>
            <div class="card">
                <div class="teacher-head">
                    <div>
                        <div class="teacher-name">Prof. <?php echo h((string) ($teacher['teacher_name'] ?? 'Docente')); ?></div>
                        <div class="teacher-meta"><?php echo count((array) ($teacher['items'] ?? [])); ?> asignación(es)</div>
                    </div>
                    <div class="teacher-actions">
                        <a class="btn btn-blue" href="teacher_assignments.php?teacher_id=<?php echo h((string) ($teacher['teacher_id'] ?? '')); ?>">Editar</a>
                        <a class="btn btn-red" href="teacher_groups.php?remove_teacher=<?php echo h((string) ($teacher['teacher_id'] ?? '')); ?>" onclick="return confirm('¿Eliminar todas las asignaciones de este docente?')">Eliminar</a>
                    </div>
                </div>
                <div class="items">
                    <?php foreach ((array) ($teacher['items'] ?? []) as $item) { ?>
                        <?php
                            $program = (string) ($item['program_type'] ?? '');
                            $courseName = (string) ($item['course_name'] ?? '');
                            $unitName = (string) ($item['unit_name'] ?? '');
                        ?>
                        <?php if ($program === 'english') { ?>
                            <span class="badge badge-eng"><?php echo h($courseName); ?> · curso completo</span>
                        <?php } else { ?>
                            <span class="

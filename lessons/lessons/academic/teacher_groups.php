<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function get_pdo_connection(): ?PDO
{
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

function load_teachers_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
            FROM teachers
            ORDER BY name ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_accounts_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, teacher_id, teacher_name, scope, target_id, target_name, permission, username, password, updated_at
            FROM teacher_accounts
            ORDER BY updated_at DESC NULLS LAST, teacher_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_students_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
            FROM students
            ORDER BY name ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_courses_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name
            FROM courses
            ORDER BY id ASC, name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_units_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name, course_id, phase_id
            FROM units
            ORDER BY id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_student_assignments_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, student_id, teacher_id, program, course_id, level_id, period, unit_id, student_username, student_temp_password, updated_at
            FROM student_assignments
            ORDER BY updated_at DESC NULLS LAST, id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function map_names(array $rows): array
{
    $mapped = [];
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id !== '') {
            $mapped[$id] = (string) ($row['name'] ?? $id);
        }
    }
    return $mapped;
}

$teachers = load_teachers_from_database();
$accounts = load_teacher_accounts_from_database();
$students = load_students_from_database();
$courses = load_courses_from_database();
$units = load_units_from_database();
$studentAssignments = load_student_assignments_from_database();

$studentNameById = map_names($students);
$courseNameById = map_names($courses);
$unitNameById = map_names($units);

$teachersById = [];
foreach ($teachers as $teacher) {
    $teacherId = (string) ($teacher['id'] ?? '');
    if ($teacherId === '') {
        continue;
    }

    $teachersById[$teacherId] = [
        'id' => $teacherId,
        'name' => (string) ($teacher['name'] ?? 'Docente'),
        'groups' => [],
        'students' => [],
    ];
}

foreach ($accounts as $account) {
    $teacherId = (string) ($account['teacher_id'] ?? '');
    if ($teacherId === '') {
        continue;
    }

    if (!isset($teachersById[$teacherId])) {
        $teachersById[$teacherId] = [
            'id' => $teacherId,
            'name' => (string) ($account['teacher_name'] ?? 'Docente'),
            'groups' => [],
            'students' => [],
        ];
    }

    $groupName = trim((string) ($account['target_name'] ?? ''));
    if ($groupName !== '') {
        $groupKey = $teacherId . '|' . $groupName;
        $teachersById[$teacherId]['groups'][$groupKey] = $groupName;
    }
}

foreach ($studentAssignments as $assignment) {
    $teacherId = (string) ($assignment['teacher_id'] ?? '');
    if ($teacherId === '') {
        continue;
    }

    if (!isset($teachersById[$teacherId])) {
        $teachersById[$teacherId] = [
            'id' => $teacherId,
            'name' => 'Docente',
            'groups' => [],
            'students' => [],
        ];
    }

    $courseName = $courseNameById[(string) ($assignment['course_id'] ?? '')] ?? '';
    $unitName = $unitNameById[(string) ($assignment['unit_id'] ?? '')] ?? '';

    // Mostrar un solo grupo por curso/semestre, no repetir por cada unidad
    $groupName = trim($courseName !== '' ? $courseName : $unitName);

    if ($groupName !== '') {
        $groupKey = $teacherId . '|' . $groupName;
        $teachersById[$teacherId]['groups'][$groupKey] = $groupName;
    }

    $studentId = (string) ($assignment['student_id'] ?? '');
    if ($studentId !== '') {
        $teachersById[$teacherId]['students'][$studentId] = $studentNameById[$studentId] ?? ('Estudiante ' . $studentId);
    }
}

$teacherCards = array_values($teachersById);
usort($teacherCards, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Docentes y Grupos</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: #eef2f7;
            padding: 30px;
            color: #1f2937;
            margin: 0;
        }

        .wrapper {
            max-width: 1100px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .back {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 8px;
            background: #1f66cc;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            transition: background .2s ease;
        }

        .back:hover {
            background: #2f5bb5;
        }

        h1.title {
            font-size: 28px;
            font-weight: 700;
            color: #1f3c75;
            margin: 0 0 20px;
        }

        .panel {
            background: #ffffff;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
            border: 1px solid #dce4f0;
        }

        .teacher {
            background: #ffffff;
            border: 1px solid #dce4f0;
            border-radius: 14px;
            margin-bottom: 18px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,0,0,.04);
        }

        .teacher:last-child {
            margin-bottom: 0;
        }

        .head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding: 18px 20px;
        }

        .teacher-info {
            flex: 1;
            min-width: 0;
        }

        .name {
            margin: 0 0 6px;
            font-size: 22px;
            font-weight: 700;
            color: #2c3e50;
        }

        .meta {
            font-size: 13px;
            color: #5b6577;
            display: block;
            margin-bottom: 12px;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #1f4ec9;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.4;
        }

        .right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .view-btn,
        .toggle {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: background .2s ease, color .2s ease;
        }

        .view-btn {
            background: #1f66cc;
            color: #fff;
        }

        .view-btn:hover {
            background: #2f5bb5;
        }

        .toggle {
            background: #eef2ff;
            color: #1f4ec9;
        }

        .toggle:hover {
            background: #dfe8ff;
        }

        .body-panel {
            display: none;
            padding: 18px 20px 20px;
            border-top: 1px solid #dce4f0;
            background: #f8fbff;
        }

        .body-panel.open {
            display: block;
        }

        .body-panel h3 {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 12px;
        }

        .students-list {
            margin: 0;
            padding-left: 20px;
        }

        .students-list li {
            font-size: 14px;
            color: #1f2937;
            padding: 8px 0;
            border-bottom: 1px solid #e7edf6;
        }

        .students-list li:last-child {
            border-bottom: none;
        }

        .empty {
            font-size: 13px;
            color: #5b6577;
            margin: 0;
        }

        @media (max-width: 768px) {
            body { padding: 20px; }
            h1.title { font-size: 24px; }
            .head { flex-direction: column; align-items: stretch; }
            .right { width: 100%; justify-content: flex-start; }
            .name { font-size: 20px; }
            .view-btn, .toggle { font-size: 12px; padding: 6px 10px; }
            .body-panel h3 { font-size: 16px; }
        }
    </style>
</head>
<body>
<div class="wrapper" id="docentes-grupos">
    <div class="topbar">
        <a class="back" href="student_assignments.php">← Volver a asignaciones</a>
    </div>

    <h1 class="title">Docentes y Grupos</h1>

    <div class="panel">
        <?php if (empty($teacherCards)) { ?>
            <article class="teacher">
                <div class="head">
                    <p class="empty">No hay docentes registrados todavía.</p>
                </div>
            </article>
        <?php } else { ?>
            <?php foreach ($teacherCards as $index => $teacherCard) { ?>
                <?php
                    $groups = array_values((array) ($teacherCard['groups'] ?? []));
                    $studentsList = array_values((array) ($teacherCard['students'] ?? []));
                    $countGroups = count($groups);
                ?>
                <article class="teacher">
                    <div class="head">
                        <div class="teacher-info">
                            <p class="name">Prof. <?= h((string) ($teacherCard['name'] ?? 'Docente')) ?></p>
                            <span class="meta">
                                <?= $countGroups ?> <?= $countGroups === 1 ? 'grupo asignado' : 'grupos asignados' ?>
                            </span>

                            <?php if (!empty($groups)) { ?>
                                <div class="badges">
                                    <?php foreach ($groups as $groupName) { ?>
                                        <span class="badge"><?= h((string) $groupName) ?></span>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>

                        <div class="right">
                            <button type="button" class="view-btn" data-target="body-<?= $index ?>">Ver estudiantes</button>
                            <button type="button" class="toggle" data-target="body-<?= $index ?>">⌄</button>
                        </div>
                    </div>

                    <div class="body-panel" id="body-<?= $index ?>">
                        <h3>Lista de estudiantes asignados</h3>

                        <?php if (empty($studentsList)) { ?>
                            <p class="empty">Este docente no tiene estudiantes asignados todavía.</p>
                        <?php } else { ?>
                            <ol class="students-list">
                                <?php foreach ($studentsList as $studentName) { ?>
                                    <li><?= h((string) $studentName) ?></li>
                                <?php } ?>
                            </ol>
                        <?php } ?>
                    </div>
                </article>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<script>
function togglePanel(targetId) {
    const panel = document.getElementById(targetId);
    if (!panel) return;
    panel.classList.toggle('open');
}

document.querySelectorAll('.toggle, .view-btn').forEach((button) => {
    button.addEventListener('click', () => {
        togglePanel(button.dataset.target || '');
    });
});
</script>
</body>
</html>

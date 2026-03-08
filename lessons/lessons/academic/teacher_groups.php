<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';
$accountsFile = $dataDir . '/teacher_accounts.json';
$studentsFile = $dataDir . '/students.json';
$coursesFile = $dataDir . '/courses.json';
$unitsFile = $dataDir . '/units.json';
$studentAssignmentsFile = $dataDir . '/student_assignments_records.json';

foreach ([$teachersFile, $accountsFile, $studentsFile, $coursesFile, $unitsFile, $studentAssignmentsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$teachers = json_decode((string) file_get_contents($teachersFile), true);
$accounts = json_decode((string) file_get_contents($accountsFile), true);
$students = json_decode((string) file_get_contents($studentsFile), true);
$courses = json_decode((string) file_get_contents($coursesFile), true);
$units = json_decode((string) file_get_contents($unitsFile), true);
$studentAssignments = json_decode((string) file_get_contents($studentAssignmentsFile), true);

$teachers = is_array($teachers) ? $teachers : [];
$accounts = is_array($accounts) ? $accounts : [];
$students = is_array($students) ? $students : [];
$courses = is_array($courses) ? $courses : [];
$units = is_array($units) ? $units : [];
$studentAssignments = is_array($studentAssignments) ? $studentAssignments : [];

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
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
        $teachersById[$teacherId]['groups'][$groupName] = $groupName;
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
    $groupName = trim(
        $courseName !== '' && $unitName !== ''
            ? ($courseName . ' - ' . $unitName)
            : ($courseName !== '' ? $courseName : $unitName)
    );

    if ($groupName !== '') {
        $teachersById[$teacherId]['groups'][$groupName] = $groupName;
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
        * {
            box-sizing: border-box;
        }

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
            transition: background .2s ease, color .2s ease, transform .2s ease;
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
            body {
                padding: 20px;
            }

            h1.title {
                font-size: 24px;
            }

            .head {
                flex-direction: column;
                align-items: stretch;
            }

            .right {
                width: 100%;
                justify-content: flex-start;
            }

            .name {
                font-size: 20px;
            }

            .view-btn,
            .toggle {
                font-size: 12px;
                padding: 6px 10px;
            }

            .body-panel h3 {
                font-size: 16px;
            }
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

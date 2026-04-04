<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));
$studentId = trim((string) ($_GET['student'] ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));

if ($studentId === '' || $assignmentId === '') {
    header('Location: teacher_students_list.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_upper(string $value): string
{
    $normalized = strtr($value, [
        'á' => 'Á',
        'é' => 'É',
        'í' => 'Í',
        'ó' => 'Ó',
        'ú' => 'Ú',
        'ü' => 'Ü',
        'ñ' => 'Ñ',
    ]);
    return function_exists('mb_strtoupper') ? mb_strtoupper($normalized, 'UTF-8') : strtoupper($normalized);
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

    try {
        require $dbFile;
        if (isset($pdo) && $pdo instanceof PDO) {
            $cachedPdo = $pdo;
        }
    } catch (Throwable $e) {
        return null;
    }

    return $cachedPdo;
}

function ensure_student_performance_tables(PDO $pdo): void
{
    try {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_unit_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              quiz_errors INTEGER NOT NULL DEFAULT 0,\n              quiz_total INTEGER NOT NULL DEFAULT 0,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id)\n            )\n        ");
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS student_activity_results (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              activity_id TEXT NOT NULL,\n              activity_type TEXT NOT NULL DEFAULT '',\n              completion_percent INTEGER NOT NULL DEFAULT 0,\n              errors_count INTEGER NOT NULL DEFAULT 0,\n              total_count INTEGER NOT NULL DEFAULT 0,\n              attempts_count INTEGER NOT NULL DEFAULT 1,\n              updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id, activity_id)\n            )\n        ");
        $pdo->exec("ALTER TABLE student_activity_results ADD COLUMN IF NOT EXISTS attempts_count INTEGER NOT NULL DEFAULT 1");
    } catch (Throwable $e) {
    }
}

function ensure_teacher_quiz_unlocks_table(PDO $pdo): void
{
    try {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS teacher_quiz_unlocks (\n              student_id TEXT NOT NULL,\n              assignment_id TEXT NOT NULL,\n              unit_id TEXT NOT NULL,\n              enabled_by_teacher_id TEXT NOT NULL,\n              enabled_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n              PRIMARY KEY (student_id, assignment_id, unit_id)\n            )\n        ");
    } catch (Throwable $e) {
    }
}

function enable_teacher_quiz_unlock(PDO $pdo, string $studentId, string $assignmentId, string $unitId, string $teacherId): bool
{
    if ($studentId === '' || $assignmentId === '' || $unitId === '' || $teacherId === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO teacher_quiz_unlocks (student_id, assignment_id, unit_id, enabled_by_teacher_id, enabled_at)\n            VALUES (:student_id, :assignment_id, :unit_id, :teacher_id, NOW())\n            ON CONFLICT (student_id, assignment_id, unit_id)\n            DO UPDATE SET\n              enabled_by_teacher_id = EXCLUDED.enabled_by_teacher_id,\n              enabled_at = NOW()\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
            'unit_id' => $unitId,
            'teacher_id' => $teacherId,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function load_teacher_quiz_unlock_units(PDO $pdo, string $studentId, string $assignmentId): array
{
    if ($studentId === '' || $assignmentId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT unit_id\n            FROM teacher_quiz_unlocks\n            WHERE student_id = :student_id\n              AND assignment_id = :assignment_id\n        ");
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
        ]);

        $enabled = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $unitId = trim((string) ($row['unit_id'] ?? ''));
            if ($unitId !== '') {
                $enabled[$unitId] = true;
            }
        }

        return $enabled;
    } catch (Throwable $e) {
        return [];
    }
}

function load_student_assignment(PDO $pdo, string $assignmentId, string $teacherId): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT sa.id, sa.student_id, sa.course_id, sa.program, sa.period, 
                   COALESCE(NULLIF(TRIM(c.name), ''), 'Curso') AS course_name,
                   COALESCE(NULLIF(TRIM(s.name), ''), sa.student_id) AS student_name
            FROM student_assignments sa
            LEFT JOIN courses c ON c.id::text = sa.course_id
            LEFT JOIN students s ON s.id = sa.student_id
            WHERE sa.id = :id
              AND sa.teacher_id = :teacher_id
            LIMIT 1
        ");
        
        $stmt->execute(['id' => $assignmentId, 'teacher_id' => $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_student_unit_scores(PDO $pdo, string $studentId, string $assignmentId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT sur.unit_id, sur.completion_percent, sur.quiz_errors, sur.quiz_total, 
                   COALESCE(NULLIF(TRIM(u.name), ''), 'Unidad ' || sur.unit_id) AS unit_name
            FROM student_unit_results sur
            LEFT JOIN units u ON u.id::text = sur.unit_id
            WHERE sur.student_id = :student_id
              AND sur.assignment_id = :assignment_id
            ORDER BY u.name ASC NULLS LAST, sur.unit_id ASC
        ");
        
        $stmt->execute([
            'student_id' => $studentId,
            'assignment_id' => $assignmentId,
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_activity_scores(PDO $pdo, string $studentId, string $assignmentId, string $unitId): array
{
    try {
        try {
            $stmt = $pdo->prepare("
                SELECT sar.activity_id, sar.activity_type, sar.completion_percent, sar.errors_count, sar.total_count,
                       COALESCE(sar.attempts_count, 1) AS attempts_count
                FROM student_activity_results sar
                WHERE sar.student_id = :student_id
                  AND sar.assignment_id = :assignment_id
                  AND sar.unit_id = :unit_id
                ORDER BY sar.activity_type ASC
            ");
            $stmt->execute([
                'student_id' => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id' => $unitId,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("
                SELECT sar.activity_id, sar.activity_type, sar.completion_percent, sar.errors_count, sar.total_count
                FROM student_activity_results sar
                WHERE sar.student_id = :student_id
                  AND sar.assignment_id = :assignment_id
                  AND sar.unit_id = :unit_id
                ORDER BY sar.activity_type ASC
            ");
            $stmt->execute([
                'student_id' => $studentId,
                'assignment_id' => $assignmentId,
                'unit_id' => $unitId,
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['attempts_count'] = 1;
            }
            unset($row);

            return $rows;
        }
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

ensure_student_performance_tables($pdo);
ensure_teacher_quiz_unlocks_table($pdo);

$assignment = load_student_assignment($pdo, $assignmentId, $teacherId);
if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
    die('You do not have access to this record.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'enable_quiz') {
    $unitToEnable = trim((string) ($_POST['unit_id'] ?? ''));
    if ($unitToEnable !== '') {
        enable_teacher_quiz_unlock($pdo, $studentId, $assignmentId, $unitToEnable, $teacherId);
    }

    header('Location: teacher_student_progress.php?' . http_build_query([
        'student' => $studentId,
        'assignment' => $assignmentId,
        'quiz_enabled' => '1',
    ]));
    exit;
}

$rows = load_student_unit_scores($pdo, $studentId, $assignmentId);
$teacherQuizUnlockUnits = load_teacher_quiz_unlock_units($pdo, $studentId, $assignmentId);
$courseName = h(app_upper(trim((string) ($assignment['course_name'] ?? 'Curso'))));
$studentName = h(app_upper(trim((string) ($assignment['student_name'] ?? 'Estudiante'))));
$programLabel = app_upper((string) ($assignment['program'] ?? '') === 'english' ? 'INGLÉS' : 'TÉCNICO');
$period = h(app_upper(trim((string) ($assignment['period'] ?? ''))));
$showQuizEnabledMessage = isset($_GET['quiz_enabled']) && (string) $_GET['quiz_enabled'] === '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Progreso del Estudiante</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

        :root {
            --bg: #eef5ff;
            --card: #ffffff;
            --line: #d6e4ff;
            --title: #16325c;
            --text: #16325c;
            --muted: #5f7294;
            --primary: #3b82f6;
            --primary-dark: #1d4ed8;
            --primary-light: #eaf2ff;
            --radius: 12px;
            --shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06);
            --shadow-sm: 0 2px 8px rgba(0,0,0,.06);
            --shadow-md: 0 4px 6px rgba(0,0,0,.1), 0 2px 4px rgba(0,0,0,.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Nunito', 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        
        .page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 20px 40px;
        }
        
        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--line);
            margin-bottom: 24px;
        }
        
        h1 {
            margin: 0;
            color: var(--title);
            font-size: 32px;
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
            font-weight: 800;
        }

        .back {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: #fff;
            background: linear-gradient(180deg, #60a5fa, #2563eb);
            font-size: 14px;
            font-weight: 700;
            box-shadow: var(--shadow);
            transition: all .2s;
            font-family: 'Nunito', 'Segoe UI', sans-serif;
        }

        .back:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .meta {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
        }

        .meta strong {
            color: var(--text);
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }

        th {
            color: var(--title);
            font-weight: 800;
            background: var(--primary-light);
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .completion-bar {
            background: var(--primary-light);
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
            margin: 4px 0;
        }

        .completion-fill {
            background: var(--primary);
            height: 100%;
            transition: width 0.3s;
        }

        .empty {
            color: var(--muted);
            text-align: center;
            padding: 32px 16px;
        }

        .btn {
            display: inline-block;
            margin-top: 12px;
            padding: 11px 16px;
            border-radius: 10px;
            text-decoration: none;
            color: #fff;
            background: linear-gradient(180deg, #60a5fa, #2563eb);
            font-size: 13px;
            font-weight: 600;
            box-shadow: var(--shadow);
            transition: all .3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .unit-row {
            cursor: pointer;
            background: var(--card);
            transition: background .2s ease;
        }

        .unit-row:hover {
            background: #f0f6ff;
        }

        .unit-row.expanded {
            background: #e8f1ff;
        }

        .toggle-icon {
            display: inline-block;
            margin-right: 8px;
            transition: transform .2s ease;
            font-size: 14px;
        }

        .unit-row.expanded .toggle-icon {
            transform: rotate(180deg);
        }

        .activity-row {
            display: none;
            background: #fafbff;
        }

        .activity-row.show {
            display: table-row;
        }

        .activity-cell {
            padding-left: 40px !important;
            font-size: 13px;
        }

        .activity-type {
            color: var(--primary-dark);
            font-weight: 700;
        }

        .attempt-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: #dbeafe;
            color: #1e40af;
        }

        .teacher-actions {
            text-align: right;
            white-space: nowrap;
        }

        .teacher-action-btn {
            border: none;
            border-radius: 8px;
            padding: 7px 10px;
            background: linear-gradient(180deg, #60a5fa, #2563eb);
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }

        .teacher-action-btn:hover {
            filter: brightness(1.06);
        }

        .teacher-action-disabled {
            color: var(--muted);
            font-weight: 700;
            font-size: 12px;
        }

        .teacher-action-enabled {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .teacher-flash {
            margin: 0 0 16px;
            border: 1px solid #86efac;
            background: #f0fdf4;
            color: #166534;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
        }
        
        @media (max-width: 768px) {
            .page {
                padding: 16px;
            }

            h1 {
                font-size: 26px;
            }

            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top">
            <h1>📊 Progreso del Estudiante</h1>
            <a class="back" href="dashboard.php">← Volver</a>
        </div>
        
        <p class="meta">
            <strong>Estudiante:</strong> <?php echo $studentName; ?> · 
            <strong>Curso:</strong> <?php echo $courseName; ?> · 
            <strong>Programa:</strong> <?php echo $programLabel; ?> · 
            <strong>Período:</strong> <?php echo $period; ?>
        </p>

        <?php if ($showQuizEnabledMessage): ?>
            <div class="teacher-flash">Quiz habilitado por docente para esta asignación.</div>
        <?php endif; ?>
        
        <div class="card">
            <?php if (empty($rows)): ?>
                <div class="empty">No hay progreso registrado. El estudiante no ha completado unidades aún.</div>
                <a class="btn" href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>">Ir al curso</a>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Unidad</th>
                            <th>Progreso</th>
                            <th>Errores Quiz</th>
                            <th>%</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $unitName = h(app_upper((string) ($row['unit_name'] ?? 'Unidad')));
                            $unitId = (string) ($row['unit_id'] ?? '');
                            $completion = (int) ($row['completion_percent'] ?? 0);
                            $errors = (int) ($row['quiz_errors'] ?? 0);
                            $total = (int) ($row['quiz_total'] ?? 0);
                            $percent = $completion;
                            $activities = $unitId !== '' ? load_activity_scores($pdo, $studentId, $assignmentId, $unitId) : [];
                            $unitIsEnabledByTeacher = $unitId !== '' && isset($teacherQuizUnlockUnits[$unitId]);
                            $hasTwoAttempts = false;
                            foreach ($activities as $activityRow) {
                                $typeName = strtolower(trim((string) ($activityRow['activity_type'] ?? '')));
                                if ($typeName === 'quiz') {
                                    continue;
                                }
                                if ((int) ($activityRow['attempts_count'] ?? 1) >= 2) {
                                    $hasTwoAttempts = true;
                                    break;
                                }
                            }
                            $canEnableQuiz = !$unitIsEnabledByTeacher && $hasTwoAttempts;
                            ?>
                            <tr class="unit-row" data-unit-id="<?php echo h($unitId); ?>">
                                <td>
                                    <span class="toggle-icon">▼</span>
                                    <?php echo $unitName; ?>
                                </td>
                                <td>
                                    <div class="completion-bar">
                                        <div class="completion-fill" style="width: <?php echo min($percent, 100); ?>%;"></div>
                                    </div>
                                </td>
                                <td><?php echo $errors; ?> / <?php echo $total; ?></td>
                                <td style="font-weight: 700; color: var(--primary-dark);"><?php echo $percent; ?>%</td>
                                <td class="teacher-actions">
                                    <?php if ($unitIsEnabledByTeacher): ?>
                                        <span class="teacher-action-enabled">Quiz habilitado</span>
                                    <?php elseif ($canEnableQuiz && $unitId !== ''): ?>
                                        <form method="post" style="display:inline;" onsubmit="event.stopPropagation();">
                                            <input type="hidden" name="action" value="enable_quiz">
                                            <input type="hidden" name="unit_id" value="<?php echo h($unitId); ?>">
                                            <button type="submit" class="teacher-action-btn">Habilitar quiz</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="teacher-action-disabled">No disponible</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php foreach ($activities as $activity): ?>
                                <?php $attemptsCount = max(1, min(2, (int) ($activity['attempts_count'] ?? 1))); ?>
                                <tr class="activity-row" data-unit-id="<?php echo h($unitId); ?>">
                                    <td class="activity-cell">
                                        <span class="activity-type"><?php echo h(app_upper((string) ($activity['activity_type'] ?? 'Actividad'))); ?></span>
                                        <span class="attempt-badge">INTENTO <?php echo $attemptsCount; ?>/2</span>
                                    </td>
                                    <td>
                                        <div class="completion-bar">
                                            <div class="completion-fill" style="width: <?php echo min((int) ($activity['completion_percent'] ?? 0), 100); ?>%;"></div>
                                        </div>
                                    </td>
                                    <td><?php echo (int) ($activity['errors_count'] ?? 0); ?> / <?php echo (int) ($activity['total_count'] ?? 0); ?></td>
                                    <td style="font-weight: 700; color: var(--primary-dark);"><?php echo (int) ($activity['completion_percent'] ?? 0); ?>%</td>
                                    <td>
                                        <?php
                                        $actType = strtolower(trim((string) ($activity['activity_type'] ?? '')));
                                        $actId   = trim((string) ($activity['activity_id'] ?? ''));
                                        if ($actType === 'writing_practice' && $actId !== '') {
                                            $gradeUrl = '/lessons/lessons/activities/writing_practice/wp_grade.php?'
                                                . 'activity_id=' . urlencode($actId)
                                                . '&student=' . urlencode($studentId)
                                                . '&assignment=' . urlencode($assignmentId)
                                                . '&unit=' . urlencode($unitId);
                                            ?>
                                            <a href="<?php echo h($gradeUrl); ?>" class="teacher-action-btn" style="text-decoration:none;display:inline-block;">Calificar ✏️</a>
                                            <?php
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.querySelectorAll('.unit-row').forEach(function(row) {
            row.addEventListener('click', function() {
                var unitId = this.getAttribute('data-unit-id');
                var isExpanded = this.classList.contains('expanded');
                
                this.classList.toggle('expanded');
                
                var allActivityRows = document.querySelectorAll('.activity-row[data-unit-id="' + unitId + '"]');
                allActivityRows.forEach(function(actRow) {
                    if (isExpanded) {
                        actRow.classList.remove('show');
                    } else {
                        actRow.classList.add('show');
                    }
                });
            });
        });
    </script>
</body>
</html>

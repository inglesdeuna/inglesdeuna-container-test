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

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

$assignment = load_student_assignment($pdo, $assignmentId, $teacherId);
if (!$assignment || (string) ($assignment['student_id'] ?? '') !== $studentId) {
    die('You do not have access to this record.');
}

$rows = load_student_unit_scores($pdo, $studentId, $assignmentId);
$courseName = h(trim((string) ($assignment['course_name'] ?? 'Curso')));
$studentName = h(trim((string) ($assignment['student_name'] ?? 'Estudiante')));
$programLabel = ((string) ($assignment['program'] ?? '') === 'english') ? 'English' : 'Técnico';
$period = h(trim((string) ($assignment['period'] ?? '')));
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
            color: var(--primary-dark);
            text-decoration: none;
            font-weight: 700;
        }

        .back:hover {
            text-decoration: underline;
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $unitName = h((string) ($row['unit_name'] ?? 'Unidad'));
                            $completion = (int) ($row['completion_percent'] ?? 0);
                            $errors = (int) ($row['quiz_errors'] ?? 0);
                            $total = (int) ($row['quiz_total'] ?? 0);
                            $percent = $completion;
                            ?>
                            <tr>
                                <td><?php echo $unitName; ?></td>
                                <td>
                                    <div class="completion-bar">
                                        <div class="completion-fill" style="width: <?php echo min($percent, 100); ?>%;"></div>
                                    </div>
                                </td>
                                <td><?php echo $errors; ?> / <?php echo $total; ?></td>
                                <td style="font-weight: 700; color: var(--primary-dark);"><?php echo $percent; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

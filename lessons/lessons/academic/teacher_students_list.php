<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));
if ($teacherId === '') {
    header('Location: login.php');
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

function load_teacher_students(PDO $pdo, string $teacherId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
              s.id,
              COALESCE(NULLIF(TRIM(s.name), ''), s.id) AS student_name,
              sa.id AS assignment_id,
              COALESCE(NULLIF(TRIM(c.name), ''), 'Curso') AS course_name,
              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,
              COUNT(DISTINCT sur.unit_id) AS units_completed,
              AVG(CAST(sur.completion_percent AS NUMERIC)) AS avg_completion
            FROM student_assignments sa
            LEFT JOIN students s ON s.id = sa.student_id
            LEFT JOIN courses c ON c.id::text = sa.course_id
            LEFT JOIN student_unit_results sur ON sur.assignment_id = sa.id
            WHERE sa.teacher_id = :teacher_id
            GROUP BY s.id, s.name, sa.id, c.name, sa.program
            ORDER BY s.name ASC
        ");
        
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

$students = load_teacher_students($pdo, $teacherId);

$filterStudent = trim((string) ($_GET['student'] ?? ''));
$filteredStudents = $students;

if ($filterStudent !== '') {
    $filteredStudents = array_filter($students, static function (array $row) use ($filterStudent): bool {
        $studentName = trim((string) ($row['student_name'] ?? ''));
        return $studentName === $filterStudent;
    });
}

$studentOptions = [];
foreach ($students as $row) {
    $studentName = trim((string) ($row['student_name'] ?? ''));
    if ($studentName !== '') {
        $studentOptions[$studentName] = true;
    }
}
$studentOptions = array_keys($studentOptions);
sort($studentOptions);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lista de Estudiantes</title>
    <style>
        :root {
            --bg: #fff8f5;
            --card: #fff;
            --line: #ffd9d2;
            --title: #b04632;
            --text: #5e352e;
            --muted: #8a625a;
            --salmon: #fa8072;
            --salmon-dark: #e8654e;
            --green: #4caf50;
            --green-dark: #45a049;
            --gray: #9e9e9e;
        }
        
        * { box-sizing: border-box; }
        
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 22px;
        }
        
        .page {
            max-width: 980px;
            margin: 0 auto;
        }
        
        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        
        h1 {
            margin: 0;
            color: var(--title);
            font-size: 28px;
        }
        
        .back {
            color: var(--salmon-dark);
            text-decoration: none;
            font-weight: 700;
        }
        
        .back:hover {
            text-decoration: underline;
        }
        
        .filter-section {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            font-weight: 700;
            font-size: 12px;
            color: var(--title);
            text-transform: uppercase;
        }
        
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font-family: Arial, sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
        }
        
        .filter-group select:focus {
            outline: none;
            border-color: var(--salmon);
            box-shadow: 0 0 0 2px rgba(250, 128, 114, 0.1);
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        
        .btn-filter {
            background: var(--salmon);
            color: #fff;
        }
        
        .btn-filter:hover {
            background: var(--salmon-dark);
        }
        
        .btn-clear {
            background: var(--gray);
            color: #fff;
        }
        
        .btn-clear:hover {
            background: #757575;
        }
        
        .students-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px;
        }
        
        .student-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .student-card:hover {
            box-shadow: 0 4px 12px rgba(176, 70, 50, 0.15);
            transform: translateY(-2px);
        }
        
        .student-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--title);
            margin: 0 0 8px 0;
        }
        
        .student-info {
            font-size: 13px;
            color: var(--muted);
            margin: 4px 0;
        }
        
        .student-info strong {
            color: var(--text);
        }
        
        .empty {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            color: var(--muted);
        }
        
        @media (max-width: 768px) {
            .filter-section {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .students-list {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top">
            <h1>📚 Lista de Estudiantes</h1>
            <a class="back" href="dashboard.php">← Volver</a>
        </div>
        
        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; flex: 1;">
                <div class="filter-group">
                    <label for="student">Filtrar por Estudiante</label>
                    <select name="student" id="student">
                        <option value="">-- Todos --</option>
                        <?php foreach ($studentOptions as $option): ?>
                            <option value="<?php echo h($option); ?>" <?php echo $filterStudent === $option ? 'selected' : ''; ?>>
                                <?php echo h($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-buttons" style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-filter">🔍 Filtrar</button>
                    <a href="teacher_students_list.php" class="btn btn-clear">✕ Limpiar</a>
                </div>
            </form>
        </div>
        
        <?php if (empty($filteredStudents)): ?>
            <div class="empty">
                No hay estudiantes que coincidan con los filtros seleccionados.
            </div>
        <?php else: ?>
            <div class="students-list">
                <?php foreach ($filteredStudents as $student): ?>
                    <?php
                    $studentId = (string) ($student['student_id'] ?? '');
                    $assignmentId = (string) ($student['assignment_id'] ?? '');
                    $studentName = h((string) ($student['student_name'] ?? 'Estudiante'));
                    $courseName = h((string) ($student['course_name'] ?? 'Curso'));
                    $program = (string) ($student['program'] ?? 'technical');
                    $programLabel = $program === 'english' ? 'English' : 'Técnico';
                    $unitsCompleted = (int) ($student['units_completed'] ?? 0);
                    $avgCompletion = (float) ($student['avg_completion'] ?? 0);
                    $avgCompletionPercent = number_format($avgCompletion, 0);
                    ?>
                    <a href="teacher_student_progress.php?student=<?php echo urlencode($studentId); ?>&assignment=<?php echo urlencode($assignmentId); ?>" class="student-card">
                        <p class="student-name">👤 <?php echo $studentName; ?></p>
                        <div class="student-info">
                            <strong>Curso:</strong> <?php echo $courseName; ?>
                        </div>
                        <div class="student-info">
                            <strong>Programa:</strong> <?php echo $programLabel; ?>
                        </div>
                        <div class="student-info">
                            <strong>Unidades completadas:</strong> <?php echo $unitsCompleted; ?>
                        </div>
                        <div class="student-info">
                            <strong>Progreso promedio:</strong> <span style="color: var(--salmon);"><?php echo $avgCompletionPercent; ?>%</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <p style="margin-top: 16px; color: var(--muted); font-size: 12px;">
                📊 Registros mostrados: <?php echo count($filteredStudents); ?> de <?php echo count($students); ?>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>

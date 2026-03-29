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

function load_teacher_courses(PDO $pdo, string $teacherId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
              sa.course_id,
              COALESCE(NULLIF(TRIM(c.name), ''), 'Curso') AS course_name,
              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,
              COUNT(DISTINCT sa.id) AS total_assignments,
              COUNT(DISTINCT sa.student_id) AS total_students
            FROM student_assignments sa
            LEFT JOIN courses c ON c.id::text = sa.course_id
            WHERE sa.teacher_id = :teacher_id
            GROUP BY sa.course_id, c.name, sa.program
            ORDER BY c.name ASC, sa.program ASC
        ");
        
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_course_students(PDO $pdo, string $teacherId, string $courseId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
              sa.id AS assignment_id,
              s.id AS student_id,
              COALESCE(NULLIF(TRIM(s.name), ''), s.id) AS student_name,
              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,
              COALESCE((
                SELECT COUNT(DISTINCT sur.unit_id)
                FROM student_unit_results sur
                WHERE sur.assignment_id = sa.id
              ), 0) AS units_completed,
              COALESCE((
                SELECT AVG(CAST(sur.completion_percent AS NUMERIC))
                FROM student_unit_results sur
                WHERE sur.assignment_id = sa.id
              ), 0) AS avg_completion
            FROM student_assignments sa
            LEFT JOIN students s ON s.id = sa.student_id
            WHERE sa.teacher_id = :teacher_id
              AND sa.course_id = :course_id
            ORDER BY s.name ASC
        ");
        
        $stmt->execute(['teacher_id' => $teacherId, 'course_id' => $courseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

// Handle AJAX request for course students
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $courseId = trim((string) ($_GET['course'] ?? ''));
    $requestTeacherId = trim((string) ($_GET['teacher_id'] ?? ''));
    
    if ($courseId === '' || $requestTeacherId !== $teacherId) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $students = load_course_students($pdo, $teacherId, $courseId);
    header('Content-Type: application/json');
    echo json_encode(['students' => $students]);
    exit;
}

$courses = load_teacher_courses($pdo, $teacherId);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lista de Cursos</title>
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
            margin-bottom: 22px;
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
        
        .courses-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        
        .course-item {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .course-header {
            padding: 16px;
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        
        .course-header:hover {
            background: #fffaf8;
        }
        
        .course-header.active {
            background: #fff3f0;
        }
        
        .course-title-section {
            flex: 1;
        }
        
        .course-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--title);
            margin: 0 0 4px 0;
        }
        
        .course-meta {
            font-size: 12px;
            color: var(--muted);
            margin: 4px 0 0 0;
        }
        
        .course-toggle {
            font-size: 18px;
            color: var(--salmon);
            transition: transform 0.2s;
            flex-shrink: 0;
        }
        
        .course-header.active .course-toggle {
            transform: rotate(90deg);
        }
        
        .students-container {
            display: none;
            border-top: 1px solid var(--line);
            padding: 16px;
            background: #fffbfa;
        }
        
        .students-container.active {
            display: block;
        }
        
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        
        .student-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px;
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
            font-size: 14px;
            font-weight: 700;
            color: var(--title);
            margin: 0 0 6px 0;
        }
        
        .student-info {
            font-size: 11px;
            color: var(--muted);
            margin: 3px 0;
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
        
        .no-students {
            padding: 16px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            .students-grid {
                grid-template-columns: 1fr;
            }
            
            .course-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="top">
            <h1>📚 Mis Cursos</h1>
            <a class="back" href="dashboard.php">← Volver</a>
        </div>
        
        <?php if (empty($courses)): ?>
            <div class="empty">
                No tienes cursos asignados.
            </div>
        <?php else: ?>
            <div class="courses-list">
                <?php foreach ($courses as $index => $course): ?>
                    <?php
                    $courseId = h((string) ($course['course_id'] ?? ''));
                    $courseName = h((string) ($course['course_name'] ?? 'Curso'));
                    $program = (string) ($course['program'] ?? 'technical');
                    $programLabel = $program === 'english' ? 'English' : 'Técnico';
                    $totalAssignments = (int) ($course['total_assignments'] ?? 0);
                    $totalStudents = (int) ($course['total_students'] ?? 0);
                    $courseKey = 'course_' . $index;
                    ?>
                    <div class="course-item" data-course-key="<?php echo h($courseKey); ?>">
                        <div class="course-header" onclick="toggleCourse(this)">
                            <div class="course-title-section">
                                <p class="course-name">📖 <?php echo $courseName; ?></p>
                                <p class="course-meta">
                                    <strong><?php echo $programLabel; ?></strong> · 
                                    <?php echo $totalStudents; ?> estudiante<?php echo $totalStudents !== 1 ? 's' : ''; ?>
                                </p>
                            </div>
                            <div class="course-toggle">▶</div>
                        </div>
                        
                        <div class="students-container" data-course-id="<?php echo h($courseId); ?>" data-teacher-id="<?php echo h($teacherId); ?>">
                            <div class="loading" style="text-align: center; color: var(--muted);">Cargando estudiantes...</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleCourse(header) {
            const item = header.closest('.course-item');
            const container = item.querySelector('.students-container');
            const isActive = header.classList.toggle('active');
            container.classList.toggle('active');
            
            if (isActive && !container.dataset.loaded) {
                loadStudents(container);
            }
        }
        
        function loadStudents(container) {
            const courseId = container.dataset.courseId;
            const teacherId = container.dataset.teacherId;
            
            fetch('?ajax=1&course=' + encodeURIComponent(courseId) + '&teacher_id=' + encodeURIComponent(teacherId))
                .then(response => response.json())
                .then(data => {
                    container.innerHTML = '';
                    if (data.students && data.students.length > 0) {
                        const grid = document.createElement('div');
                        grid.className = 'students-grid';
                        
                        data.students.forEach(student => {
                            const card = document.createElement('a');
                            card.href = 'teacher_student_progress.php?student=' + encodeURIComponent(student.student_id) + '&assignment=' + encodeURIComponent(student.assignment_id);
                            card.className = 'student-card';
                            
                            const programLabel = student.program === 'english' ? 'English' : 'Técnico';
                            const avgPercent = Math.round(student.avg_completion || 0);
                            
                            card.innerHTML = `
                                <p class="student-name">👤 ${escapeHtml(student.student_name)}</p>
                                <div class="student-info">
                                    <strong>Programa:</strong> ${programLabel}
                                </div>
                                <div class="student-info">
                                    <strong>Unidades:</strong> ${student.units_completed}
                                </div>
                                <div class="student-info">
                                    <strong>Progreso:</strong> <span style="color: var(--salmon);">${avgPercent}%</span>
                                </div>
                            `;
                            grid.appendChild(card);
                        });
                        
                        container.appendChild(grid);
                    } else {
                        container.innerHTML = '<div class="no-students">No hay estudiantes en este curso.</div>';
                    }
                    container.dataset.loaded = '1';
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<div class="no-students">Error al cargar los estudiantes.</div>';
                });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

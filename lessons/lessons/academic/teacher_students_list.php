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

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM information_schema.tables\n            WHERE table_schema = 'public'\n              AND table_name = :table_name\n            LIMIT 1\n        ");
        $stmt->execute(['table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function normalize_program_label(string $program): string
{
    return strtolower(trim($program)) === 'english' ? 'English' : 'TÉCNICO';
}

function build_course_key(string $courseId, string $courseName, string $program, string $period): string
{
    $normalizedName = strtolower(trim($courseName));
    $normalizedProgram = strtolower(trim($program));
    $normalizedPeriod = strtolower(trim($period));

    return ($courseId !== '' ? ('id:' . $courseId) : ('name:' . $normalizedName))
        . '|program:' . $normalizedProgram
        . '|period:' . $normalizedPeriod;
}

function build_course_label(string $courseName, string $program, string $period): string
{
    $label = $courseName !== '' ? $courseName : 'Curso';
    if ($period !== '') {
        $label .= ' - ' . $period;
    }

    return $label . ' (' . normalize_program_label($program) . ')';
}

function load_teacher_courses(PDO $pdo, string $teacherId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
              CASE WHEN sa.program = 'english'
                   THEN COALESCE(NULLIF(TRIM(sa.level_id::text), ''), sa.course_id::text)
                   ELSE sa.course_id::text
              END AS course_id,
              CASE WHEN sa.program = 'english'
                   THEN COALESCE(NULLIF(TRIM(ep.name), ''), 'Fase ingles')
                   ELSE COALESCE(NULLIF(TRIM(c.name), ''), 'Curso')
              END AS course_name,
              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,
              CASE WHEN sa.program = 'english' THEN ''
                   ELSE COALESCE(NULLIF(TRIM(sa.period), ''), '')
              END AS period
            FROM student_assignments sa
            LEFT JOIN courses c ON (sa.program <> 'english' AND c.id::text = sa.course_id::text)
            LEFT JOIN english_phases ep ON (sa.program = 'english' AND ep.id::text = sa.level_id::text)
            WHERE sa.teacher_id = :teacher_id
            ORDER BY course_name ASC, sa.program ASC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_assigned_courses_from_dashboard(PDO $pdo, string $teacherId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
              CAST(course_id AS TEXT) AS course_id,
              COALESCE(NULLIF(course_name, ''), 'Curso') AS course_name,
              COALESCE(NULLIF(program_type, ''), 'technical') AS program,
              '' AS period
            FROM teacher_assignments
            WHERE CAST(teacher_id AS TEXT) = :teacher_id
            ORDER BY course_name ASC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_students(PDO $pdo, string $teacherId): array
{
        try {
                $stmt = $pdo->prepare("
                        SELECT
                            sa.id AS assignment_id,
                            TRIM(sa.student_id::text) AS student_id,
                            COALESCE(
                                NULLIF(TRIM(acc.username), ''),
                                NULLIF(TRIM(s.name), ''),
                                NULLIF(TRIM(sa.student_username), ''),
                                TRIM(sa.student_id::text)
                            ) AS student_name,
                            CASE WHEN sa.program = 'english'
                                     THEN COALESCE(NULLIF(TRIM(sa.level_id::text), ''), sa.course_id::text)
                                     ELSE sa.course_id::text
                            END AS course_id,
                            CASE WHEN sa.program = 'english'
                                     THEN COALESCE(NULLIF(TRIM(ep.name), ''), 'Fase ingles')
                                     ELSE COALESCE(NULLIF(TRIM(c.name), ''), 'Curso')
                            END AS course_name,
                            COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,
                            CASE WHEN sa.program = 'english' THEN ''
                                     ELSE COALESCE(NULLIF(TRIM(sa.period), ''), '')
                            END AS period,
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
                                                LEFT JOIN students s ON TRIM(s.id::text) = TRIM(sa.student_id::text)
                                                LEFT JOIN LATERAL (
                                                        SELECT sa2.username
                                                        FROM student_accounts sa2
                                                        WHERE TRIM(sa2.student_id::text) = TRIM(sa.student_id::text)
                                                            AND NULLIF(TRIM(sa2.username), '') IS NOT NULL
                                                        ORDER BY sa2.updated_at DESC NULLS LAST, sa2.id DESC
                                                        LIMIT 1
                                                ) acc ON TRUE
                        LEFT JOIN courses c ON (sa.program <> 'english' AND c.id::text = sa.course_id::text)
                        LEFT JOIN english_phases ep ON (sa.program = 'english' AND ep.id::text = sa.level_id::text)
                        WHERE sa.teacher_id = :teacher_id
                        ORDER BY student_name ASC, sa.id ASC
                ");
                $stmt->execute(['teacher_id' => $teacherId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
                return [];
        }
}

function load_latest_student_usernames(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("\n            SELECT student_id::text AS student_id, username, updated_at, id\n            FROM student_accounts\n            WHERE NULLIF(TRIM(username), '') IS NOT NULL\n            ORDER BY student_id::text ASC, updated_at DESC NULLS LAST, id DESC\n        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $byStudent = [];
    foreach ($rows as $row) {
        $studentId = trim((string) ($row['student_id'] ?? ''));
        $username = trim((string) ($row['username'] ?? ''));
        if ($studentId === '' || $username === '' || isset($byStudent[$studentId])) {
            continue;
        }
        $byStudent[$studentId] = $username;
    }

    return $byStudent;
}

function load_latest_student_usernames_from_json(): array
{
    $accountsFile = __DIR__ . '/data/student_accounts.json';
    if (!file_exists($accountsFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($accountsFile), true);
    if (!is_array($decoded)) {
        return [];
    }

    $byStudent = [];
    foreach ($decoded as $row) {
        $studentId = trim((string) ($row['student_id'] ?? ''));
        $username = trim((string) ($row['username'] ?? ''));
        if ($studentId === '' || $username === '') {
            continue;
        }

        // Keep last occurrence as latest for flat files
        $byStudent[$studentId] = $username;
    }

    return $byStudent;
}

function normalize_student_username(string $username): string
{
    $value = strtolower(trim($username));
    if ($value === '') {
        return '';
    }

    // Legacy pattern: ledy.rincon.student69b047...
    $value = preg_replace('/\.student[a-z0-9]+$/i', '', $value) ?? $value;
    return trim($value);
}

function repair_teacher_assignment_student_links(PDO $pdo, string $teacherId): void
{
    try {
        $accountsStmt = $pdo->query("\n            SELECT student_id::text AS student_id, username\n            FROM student_accounts\n            WHERE NULLIF(TRIM(username), '') IS NOT NULL\n        ");
        $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $usernameToStudent = [];
        foreach ($accounts as $acc) {
            $sid = trim((string) ($acc['student_id'] ?? ''));
            $uname = trim((string) ($acc['username'] ?? ''));
            if ($sid === '' || $uname === '') {
                continue;
            }
            $usernameToStudent[strtolower($uname)] = ['student_id' => $sid, 'username' => $uname];
            $normalized = normalize_student_username($uname);
            if ($normalized !== '' && !isset($usernameToStudent[$normalized])) {
                $usernameToStudent[$normalized] = ['student_id' => $sid, 'username' => $uname];
            }
        }

        // Merge JSON accounts as fallback/override when DB is stale
        $jsonAccounts = load_latest_student_usernames_from_json();
        foreach ($jsonAccounts as $sid => $uname) {
            $sid = trim((string) $sid);
            $uname = trim((string) $uname);
            if ($sid === '' || $uname === '') {
                continue;
            }
            $usernameToStudent[strtolower($uname)] = ['student_id' => $sid, 'username' => $uname];
            $normalized = normalize_student_username($uname);
            if ($normalized !== '') {
                $usernameToStudent[$normalized] = ['student_id' => $sid, 'username' => $uname];
            }
        }

        if (empty($usernameToStudent)) {
            return;
        }

        $assignStmt = $pdo->prepare("\n            SELECT id::text AS id, student_id::text AS student_id, COALESCE(student_username, '') AS student_username\n            FROM student_assignments\n            WHERE teacher_id = :teacher_id\n        ");
        $assignStmt->execute(['teacher_id' => $teacherId]);
        $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($assignments)) {
            return;
        }

        $updateStmt = $pdo->prepare("\n            UPDATE student_assignments\n            SET student_id = :student_id,\n                student_username = :student_username\n            WHERE id::text = :assignment_id\n        ");

        foreach ($assignments as $row) {
            $assignmentId = trim((string) ($row['id'] ?? ''));
            $currentStudentId = trim((string) ($row['student_id'] ?? ''));
            $currentUsername = trim((string) ($row['student_username'] ?? ''));
            if ($assignmentId === '' || $currentUsername === '') {
                continue;
            }

            $lookupKeys = [strtolower($currentUsername), normalize_student_username($currentUsername)];
            $target = null;
            foreach ($lookupKeys as $key) {
                if ($key !== '' && isset($usernameToStudent[$key])) {
                    $target = $usernameToStudent[$key];
                    break;
                }
            }

            if (!$target) {
                continue;
            }

            $targetStudentId = trim((string) ($target['student_id'] ?? ''));
            $targetUsername = trim((string) ($target['username'] ?? ''));
            if ($targetStudentId === '' || $targetUsername === '') {
                continue;
            }

            if ($targetStudentId !== $currentStudentId || strtolower($targetUsername) !== strtolower($currentUsername)) {
                $updateStmt->execute([
                    'student_id' => $targetStudentId,
                    'student_username' => $targetUsername,
                    'assignment_id' => $assignmentId,
                ]);
            }
        }
    } catch (Throwable $e) {
        // Keep page functional even if repair cannot run
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Database is not available.');
}

repair_teacher_assignment_student_links($pdo, $teacherId);

$allStudents = load_teacher_students($pdo, $teacherId);
$studentUsernames = load_latest_student_usernames($pdo);
$jsonStudentUsernames = load_latest_student_usernames_from_json();
if (!empty($jsonStudentUsernames)) {
    $studentUsernames = array_merge($studentUsernames, $jsonStudentUsernames);
}
if (!empty($studentUsernames)) {
    foreach ($allStudents as $idx => $row) {
        $sid = trim((string) ($row['student_id'] ?? ''));
        if ($sid !== '' && isset($studentUsernames[$sid])) {
            $allStudents[$idx]['student_name'] = $studentUsernames[$sid];
        }
    }
}
$dashboardCourses = load_assigned_courses_from_dashboard($pdo, $teacherId);
$studentCourses = load_teacher_courses($pdo, $teacherId);

$filterCourse = trim((string) ($_GET['course'] ?? ''));
$filteredStudents = $allStudents;

$courseOptions = [];
$courseHasStudents = [];
foreach ($allStudents as $row) {
    $courseId = trim((string) ($row['course_id'] ?? ''));
    $courseName = trim((string) ($row['course_name'] ?? ''));
    $program = trim((string) ($row['program'] ?? 'technical'));
    $period = trim((string) ($row['period'] ?? ''));
    if ($courseName === '') {
        $courseName = 'Curso';
    }

    $courseKey = build_course_key($courseId, $courseName, $program, $period);
    $label = build_course_label($courseName, $program, $period);

    if (!isset($courseOptions[$courseKey])) {
        $courseOptions[$courseKey] = $label;
    }

    $courseHasStudents[$courseKey] = true;
}

foreach ([$studentCourses, $dashboardCourses] as $courseSource) {
    foreach ($courseSource as $course) {
        $courseId = trim((string) ($course['course_id'] ?? ''));
        $courseName = trim((string) ($course['course_name'] ?? ''));
        $program = trim((string) ($course['program'] ?? 'technical'));
        $period = trim((string) ($course['period'] ?? ''));
        if ($courseName === '') {
            $courseName = 'Curso';
        }

        $courseKey = build_course_key($courseId, $courseName, $program, $period);
        if (!isset($courseOptions[$courseKey])) {
            $courseOptions[$courseKey] = build_course_label($courseName, $program, $period);
            $courseHasStudents[$courseKey] = false;
        }
    }
}

asort($courseOptions);

$selectedCourseLabel = '';
if ($filterCourse !== '') {
    $selectedCourseLabel = (string) ($courseOptions[$filterCourse] ?? '');
}

if ($filterCourse !== '') {
    $filteredStudents = array_filter($allStudents, static function (array $row) use ($filterCourse): bool {
        $courseId = trim((string) ($row['course_id'] ?? ''));
        $courseName = trim((string) ($row['course_name'] ?? ''));
        $program = trim((string) ($row['program'] ?? 'technical'));
        $period = trim((string) ($row['period'] ?? ''));
        if ($courseName === '') {
            $courseName = 'Curso';
        }

        $courseKey = build_course_key($courseId, $courseName, $program, $period);

        return $courseKey === $filterCourse;
    });
    $filteredStudents = array_values($filteredStudents);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lista de Estudiantes</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

        :root {
            --bg: #eef5ff;
            --card: #ffffff;
            --line: #d6e4ff;
            --text: #16325c;
            --title: #16325c;
            --muted: #5f7294;
            --radius: 12px;
            --primary: #3b82f6;
            --primary-dark: #1d4ed8;
            --primary-light: #eaf2ff;
            --success: #60a5fa;
            --success-dark: #2563eb;
            --warning: #f59e0b;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06);
            --shadow-md: 0 4px 6px rgba(0,0,0,.1), 0 2px 4px rgba(0,0,0,.06);
            --shadow-lg: 0 10px 15px rgba(0,0,0,.1), 0 4px 6px rgba(0,0,0,.05);
            --shadow-sm: 0 2px 8px rgba(0,0,0,.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Nunito', 'Segoe UI', sans-serif;
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
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
            font-weight: 700;
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

        .filter-section {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: var(--shadow-sm);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            font-weight: 800;
            font-size: 12px;
            color: var(--title);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-group select {
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            font-family: 'Nunito', 'Segoe UI', sans-serif;
            font-size: 14px;
            background: var(--bg);
            color: var(--text);
        }

        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
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
            transition: all 0.2s;
            font-family: 'Nunito', 'Segoe UI', sans-serif;
        }

        .btn-filter {
            background: linear-gradient(180deg, #60a5fa, #2563eb);
            color: #fff;
            box-shadow: var(--shadow);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-clear {
            background: var(--muted);
            color: #fff;
            box-shadow: var(--shadow);
        }

        .btn-clear:hover {
            background: #4a5e7a;
            transform: translateY(-2px);
        }

        .students-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px;
        }

        .student-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            cursor: pointer;
            box-shadow: var(--shadow);
        }

        .student-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        .student-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--title);
            margin: 0 0 8px 0;
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
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
            border-radius: var(--radius);
            padding: 32px;
            text-align: center;
            color: var(--muted);
            box-shadow: var(--shadow-sm);
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
                    <label for="course">Filtrar por Curso</label>
                    <select name="course" id="course" onchange="this.form.submit()">
                        <option value="">-- Todos los cursos --</option>
                        <?php foreach ($courseOptions as $courseId => $courseName): ?>
                            <?php $hasStudents = (bool) ($courseHasStudents[$courseId] ?? false); ?>
                            <option value="<?php echo h($courseId); ?>" <?php echo $filterCourse === $courseId ? 'selected' : ''; ?>>
                                <?php echo h($courseName); ?><?php echo $hasStudents ? '' : ' (sin estudiantes aún)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-buttons" style="display: flex; gap: 10px;">
                    <a href="teacher_students_list.php" class="btn btn-clear">✕ Limpiar</a>
                </div>
            </form>
        </div>
        
        <?php if (empty($filteredStudents)): ?>
            <div class="empty">
                <?php if ($filterCourse !== '' && (($courseHasStudents[$filterCourse] ?? false) === false && $selectedCourseLabel !== '')) { ?>
                    El curso <strong><?php echo h($selectedCourseLabel); ?></strong> está asignado al docente, pero aún no tiene estudiantes vinculados.
                <?php } else { ?>
                    No hay estudiantes en el curso seleccionado.
                <?php } ?>
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
                    $programLabel = normalize_program_label($program);
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
                            <strong>Progreso promedio:</strong> <span style="color: var(--primary-dark); font-weight: 700;"><?php echo $avgCompletionPercent; ?>%</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <p style="margin-top: 16px; color: var(--muted); font-size: 12px;">
                📊 Registros mostrados: <?php echo count($filteredStudents); ?> de <?php echo count($allStudents); ?>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>

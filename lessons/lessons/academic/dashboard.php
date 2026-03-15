<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'Docente');
$teacherPhoto = trim((string) ($_SESSION['teacher_photo'] ?? ''));

if ($teacherPhoto === '') {
    $teacherPhoto = 'assets/img/default-teacher.png';
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
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute(['table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function load_teacher_assignments(string $teacherId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                teacher_id,
                teacher_name,
                program_type,
                course_id,
                course_name,
                unit_id,
                unit_name,
                updated_at
            FROM teacher_assignments
            WHERE teacher_id = :teacher_id
            ORDER BY
                CASE WHEN program_type = 'english' THEN 1 ELSE 2 END,
                course_name ASC,
                COALESCE(unit_name, '') ASC,
                updated_at DESC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_teacher_permission_from_accounts(string $teacherId): string
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '') {
        return 'viewer';
    }

    try {
        if (!table_exists($pdo, 'teacher_accounts')) {
            return 'viewer';
        }

        $stmt = $pdo->prepare("
            SELECT permission
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $permission = (string) $stmt->fetchColumn();

        return $permission === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        return 'viewer';
    }
}

function load_english_units_by_phase_ids(array $phaseIds): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || empty($phaseIds)) {
        return [];
    }

    if (!table_exists($pdo, 'units') || !column_exists($pdo, 'units', 'phase_id')) {
        return [];
    }

    $phaseIds = array_values(array_unique(array_filter(array_map('strval', $phaseIds), static fn ($v): bool => $v !== '')));
    if (empty($phaseIds)) {
        return [];
    }

    try {
        $placeholders = [];
        $params = [];

        foreach ($phaseIds as $index => $phaseId) {
            $key = ':phase_' . $index;
            $placeholders[] = $key;
            $params['phase_' . $index] = $phaseId;
        }

        $sql = "
            SELECT id, name, phase_id
            FROM units
            WHERE phase_id IN (" . implode(', ', $placeholders) . ")
            ORDER BY phase_id ASC, id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        foreach ($rows as $row) {
            $phaseId = (string) ($row['phase_id'] ?? '');
            if ($phaseId === '') {
                continue;
            }
            $grouped[$phaseId][] = $row;
        }

        return $grouped;
    } catch (Throwable $e) {
        return [];
    }
}

function load_technical_units_for_assignments(array $technicalAssignments): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || empty($technicalAssignments)) {
        return [];
    }

    $unitIds = [];
    $courseIds = [];

    foreach ($technicalAssignments as $assignment) {
        $unitId = trim((string) ($assignment['unit_id'] ?? ''));
        $courseId = trim((string) ($assignment['course_id'] ?? ''));

        if ($unitId !== '') {
            $unitIds[] = $unitId;
        }

        if ($courseId !== '') {
            $courseIds[] = $courseId;
        }
    }

    $unitIds = array_values(array_unique($unitIds));
    $courseIds = array_values(array_unique($courseIds));
    $result = [];

    if (!empty($unitIds)) {
        foreach ($technicalAssignments as $assignment) {
            $assignmentId = (string) ($assignment['id'] ?? '');
            $unitId = trim((string) ($assignment['unit_id'] ?? ''));
            $unitName = trim((string) ($assignment['unit_name'] ?? ''));

            if ($assignmentId !== '' && $unitId !== '') {
                $result[$assignmentId][] = [
                    'id' => $unitId,
                    'name' => $unitName !== '' ? $unitName : 'Unidad',
                ];
            }
        }

        return $result;
    }

    $candidates = [
        ['table' => 'course_units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'technical_units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'technical_units', 'course_column' => 'semester_id', 'name_column' => 'name'],
        ['table' => 'units', 'course_column' => 'course_id', 'name_column' => 'name'],
        ['table' => 'units', 'course_column' => 'semester_id', 'name_column' => 'name'],
    ];

    foreach ($candidates as $candidate) {
        $table = $candidate['table'];
        $courseColumn = $candidate['course_column'];
        $nameColumn = $candidate['name_column'];

        if (
            !table_exists($pdo, $table) ||
            !column_exists($pdo, $table, 'id') ||
            !column_exists($pdo, $table, $courseColumn) ||
            !column_exists($pdo, $table, $nameColumn)
        ) {
            continue;
        }

        if (empty($courseIds)) {
            continue;
        }

        try {
            $placeholders = [];
            $params = [];

            foreach ($courseIds as $index => $courseId) {
                $key = ':course_' . $index;
                $placeholders[] = $key;
                $params['course_' . $index] = $courseId;
            }

            $sql = "
                SELECT id, {$courseColumn} AS course_id, {$nameColumn} AS name
                FROM {$table}
                WHERE {$courseColumn} IN (" . implode(', ', $placeholders) . ")
                ORDER BY {$courseColumn} ASC, id ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $groupedByCourse = [];
            foreach ($rows as $row) {
                $courseId = (string) ($row['course_id'] ?? '');
                if ($courseId === '') {
                    continue;
                }

                $groupedByCourse[$courseId][] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'name' => (string) ($row['name'] ?? 'Unidad'),
                ];
            }

            foreach ($technicalAssignments as $assignment) {
                $assignmentId = (string) ($assignment['id'] ?? '');
                $courseId = trim((string) ($assignment['course_id'] ?? ''));

                if ($assignmentId !== '' && $courseId !== '' && isset($groupedByCourse[$courseId])) {
                    $result[$assignmentId] = $groupedByCourse[$courseId];
                }
            }

            if (!empty($result)) {
                return $result;
            }
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}

function build_assignment_title(array $assignment): string
{
    $courseName = trim((string) ($assignment['course_name'] ?? 'Curso'));
    $unitName = trim((string) ($assignment['unit_name'] ?? ''));
    $programType = trim((string) ($assignment['program_type'] ?? ''));

    if ($programType === 'technical' && $unitName !== '') {
        return $courseName . ' · ' . $unitName;
    }

    return $courseName;
}

$assignments = load_teacher_assignments($teacherId);
$firstAssignment = $assignments[0] ?? null;
$teacherPermission = load_teacher_permission_from_accounts($teacherId);

$englishPhaseIds = [];
$technicalAssignments = [];

foreach ($assignments as $assignment) {
    $programType = (string) ($assignment['program_type'] ?? '');

    if ($programType === 'english') {
        $phaseId = trim((string) ($assignment['course_id'] ?? ''));
        if ($phaseId !== '') {
            $englishPhaseIds[] = $phaseId;
        }
    } elseif ($programType === 'technical') {
        $technicalAssignments[] = $assignment;
    }
}

$englishUnitsByPhase = load_english_units_by_phase_ids($englishPhaseIds);
$technicalUnitsByAssignment = load_technical_units_for_assignments($technicalAssignments);

$todayUnits = [];
$todayTitle = 'Curso';
$todayProgramLabel = 'Docente';

if ($firstAssignment) {
    $todayTitle = build_assignment_title($firstAssignment);
    $todayProgramLabel = ((string) ($firstAssignment['program_type'] ?? '') === 'english') ? 'English' : 'Técnico';

    if ((string) ($firstAssignment['program_type'] ?? '') === 'english') {
        $phaseId = trim((string) ($firstAssignment['course_id'] ?? ''));
        $todayUnits = $englishUnitsByPhase[$phaseId] ?? [];
    } else {
        $assignmentId = (string) ($firstAssignment['id'] ?? '');
        $todayUnits = $technicalUnitsByAssignment[$assignmentId] ?? [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Perfil del Docente</title>
<style>
:root{
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --title:#1f3c75;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --green:#16a34a;
    --green-hover:#15803d;
    --orange:#f59e0b;
    --orange-hover:#d97706;
    --danger:#dc2626;
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
}

.page{
    max-width:1280px;
    margin:0 auto;
    padding:30px;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    padding-bottom:14px;
    border-bottom:2px solid var(--line);
    margin-bottom:22px;
}

.header h1{
    margin:0;
    font-size:28px;
    font-weight:700;
    color:var(--title);
}

.logout{
    color:var(--danger);
    text-decoration:none;
    font-weight:700;
    font-size:14px;
}

.layout{
    display:grid;
    grid-template-columns:320px 1fr;
    gap:24px;
}

.panel,
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.panel{
    padding:18px;
}

.profile-box{
    text-align:center;
}

.avatar{
    width:160px;
    height:160px;
    margin:0 auto 18px;
    border-radius:50%;
    overflow:hidden;
    background:#dbe7f6;
    border:4px solid #edf3fb;
    box-shadow:0 6px 18px rgba(31, 60, 117, 0.12);
}

.avatar-image{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.teacher-name{
    font-size:20px;
    font-weight:700;
    color:var(--title);
    margin-bottom:6px;
}

.teacher-role{
    font-size:15px;
    color:var(--muted);
    margin-bottom:18px;
}

.side-button{
    display:block;
    width:100%;
    margin-top:10px;
    padding:12px 14px;
    border-radius:10px;
    text-decoration:none;
    font-size:14px;
    font-weight:700;
    color:#fff;
    background:linear-gradient(180deg, #2f74ce, #1f4d95);
    text-align:center;
}

.main-section-title{
    display:flex;
    align-items:center;
    gap:14px;
    font-size:22px;
    font-weight:700;
    color:var(--title);
    margin:0 0 14px;
}

.main-section-title::after{
    content:"";
    flex:1;
    height:2px;
    background:var(--line);
}

.card{
    padding:20px;
    margin-bottom:18px;
}

.activity-title{
    margin:0 0 12px;
    font-size:18px;
    font-weight:700;
    color:var(--title);
}

.activity-text{
    margin:0 0 18px;
    font-size:15px;
    color:var(--text);
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
}

.btn{
    display:inline-block;
    padding:12px 18px;
    border-radius:10px;
    text-decoration:none;
    color:#fff;
    font-size:14px;
    font-weight:700;
    transition:background .2s ease;
}

.btn-green{
    background:linear-gradient(180deg, #4cbf62, #249145);
}

.btn-green:hover{
    background:var(--green-hover);
}

.btn-orange{
    background:linear-gradient(180deg, #f7a531, #e57e08);
}

.btn-orange:hover{
    background:var(--orange-hover);
}

.course-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:16px;
}

.course-card{
    border-radius:14px;
    padding:22px 18px;
    color:#fff;
    text-decoration:none;
    min-height:140px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:8px;
    box-shadow:var(--shadow);
}

.course-blue{
    background:linear-gradient(180deg, #2f74ce, #1f4d95);
}

.course-yellow{
    background:linear-gradient(180deg, #f5be35, #db9600);
}

.course-green{
    background:linear-gradient(180deg, #71c557, #2b9d48);
}

.course-name{
    font-size:22px;
    font-weight:700;
    line-height:1.2;
}

.course-sub{
    font-size:14px;
    opacity:.95;
}

.course-meta{
    font-size:12px;
    font-weight:700;
    opacity:.95;
    text-transform:uppercase;
    letter-spacing:.03em;
}

.unit-list{
    margin-top:16px;
}

.unit{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    margin-bottom:10px;
    border:1px solid var(--line);
    border-radius:10px;
    background:#fff;
}

.unit-name{
    font-size:15px;
    font-weight:700;
    color:#243b63;
}

.unit-actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.unit-btn{
    display:inline-block;
    padding:8px 12px;
    border-radius:8px;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    color:#fff;
    background:var(--blue);
}

.unit-btn:hover{
    background:var(--blue-hover);
}

.unit-btn.edit{
    background:var(--green);
}

.unit-btn.edit:hover{
    background:var(--green-hover);
}

.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:14px;
    padding:18px;
    color:var(--muted);
    font-size:14px;
}

.badge-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:14px;
}

.badge{
    display:inline-block;
    padding:4px 10px;
    border-radius:999px;
    background:#eef2ff;
    color:#1f4ec9;
    font-size:12px;
    font-weight:700;
}

@media (max-width: 1024px){
    .layout{
        grid-template-columns:1fr;
    }

    .course-grid{
        grid-template-columns:1fr;
    }
}

@media (max-width: 768px){
    .page{
        padding:20px;
    }

    .header{
        flex-direction:column;
        align-items:flex-start;
    }

    .unit{
        flex-direction:column;
        align-items:flex-start;
    }

    .actions{
        flex-direction:column;
    }

    .btn{
        width:100%;
        text-align:center;
    }
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <h1>Perfil del Docente</h1>
        <a class="logout" href="logout.php">Cerrar sesión</a>
    </div>

    <div class="layout">
        <aside class="panel">
            <div class="profile-box">
                <div class="avatar">
                    <img
                        src="<?php echo h($teacherPhoto); ?>"
                        alt="Foto de <?php echo h($teacherName); ?>"
                        class="avatar-image"
                    >
                </div>

                <div class="teacher-name"><?php echo h($teacherName); ?></div>
                <div class="teacher-role">Docente</div>

                <a class="side-button" href="teacher_groups.php">Lista de Estudiantes</a>
                <a class="side-button" href="teacher_groups.php">Progreso del Estudiante</a>
            </div>
        </aside>

        <main>
            <h2 class="main-section-title">Actividad para Hoy</h2>

            <?php if ($firstAssignment) { ?>
                <div class="card">
                    <h3 class="activity-title">
                        Tema: "<?php echo h($todayTitle); ?>"
                    </h3>

                    <p class="activity-text">
                        Ingresa al curso para proyectar las actividades en modo presentación y avanzar con Next.
                    </p>

                    <div class="actions">
                        <a class="btn btn-green" href="teacher_course.php?assignment=<?php echo urlencode((string) ($firstAssignment['id'] ?? '')); ?>">
                            Iniciar Presentación
                        </a>

                        <?php if ($teacherPermission === 'editor') { ?>
                            <a class="btn btn-orange" href="teacher_course.php?assignment=<?php echo urlencode((string) ($firstAssignment['id'] ?? '')); ?>&mode=edit">
                                Ver actividades
                            </a>
                        <?php } ?>
                    </div>

                    <div class="unit-list">
                        <div class="badge-row">
                            <span class="badge"><?php echo h($todayProgramLabel); ?></span>
                            <span class="badge"><?php echo h($teacherPermission === 'editor' ? 'Puede editar' : 'Solo ver'); ?></span>
                        </div>

                        <?php if (empty($todayUnits)) { ?>
                            <div class="empty">No hay unidades encontradas para esta asignación.</div>
                        <?php } else { ?>
                            <?php foreach ($todayUnits as $unit) { ?>
                                <div class="unit">
                                    <div class="unit-name">
                                        <?php echo h((string) ($unit['name'] ?? 'Unidad')); ?>
                                    </div>

                                    <div class="unit-actions">
                                        <a class="unit-btn" href="teacher_unit.php?assignment=<?php echo urlencode((string) ($firstAssignment['id'] ?? '')); ?>&unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=view">
                                            Ver
                                        </a>

                                        <?php if ($teacherPermission === 'editor') { ?>
                                            <a class="unit-btn edit" href="teacher_unit.php?assignment=<?php echo urlencode((string) ($firstAssignment['id'] ?? '')); ?>&unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=edit">
                                                Editar
                                            </a>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>
            <?php } else { ?>
                <div class="empty">No tienes cursos asignados todavía.</div>
            <?php } ?>

            <h2 class="main-section-title">Mis Cursos</h2>

            <?php if (empty($assignments)) { ?>
                <div class="empty">No tienes cursos asignados todavía.</div>
            <?php } else { ?>
                <div class="course-grid">
                    <?php foreach ($assignments as $index => $assignment) { ?>
                        <?php
                        $colorClass = 'course-blue';
                        if ($index % 3 === 1) {
                            $colorClass = 'course-yellow';
                        } elseif ($index % 3 === 2) {
                            $colorClass = 'course-green';
                        }

                        $programType = (string) ($assignment['program_type'] ?? '');
                        $cardTitle = build_assignment_title($assignment);
                        $cardSub = $programType === 'english' ? 'Curso de inglés' : 'Curso técnico';
                        ?>
                        <a class="course-card <?php echo h($colorClass); ?>" href="teacher_course.php?assignment=<?php echo urlencode((string) ($assignment['id'] ?? '')); ?>">
                            <div class="course-meta"><?php echo h($programType === 'english' ? 'English' : 'Técnico'); ?></div>
                            <div class="course-name"><?php echo h($cardTitle); ?></div>
                            <div class="course-sub"><?php echo h($cardSub); ?></div>
                        </a>
                    <?php } ?>
                </div>
            <?php } ?>
        </main>
    </div>
</div>
</body>
</html>

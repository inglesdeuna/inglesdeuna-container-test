<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$teacherName = (string) ($_SESSION['teacher_name'] ?? 'Docente');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_teacher_accounts(string $teacherId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, teacher_id, teacher_name, scope, target_id, target_name, permission
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST, target_name ASC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_units_grouped(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [
            'by_course' => [],
            'by_phase' => [],
        ];
    }

    $unitsByCourse = [];
    $unitsByPhase = [];

    try {
        $stmt = $pdo->query("
            SELECT id, name, course_id, phase_id
            FROM units
            ORDER BY id ASC
        ");
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($units as $unit) {
            if (!empty($unit['course_id'])) {
                $unitsByCourse[(string) $unit['course_id']][] = $unit;
            }

            if (!empty($unit['phase_id'])) {
                $unitsByPhase[(string) $unit['phase_id']][] = $unit;
            }
        }
    } catch (Throwable $e) {
        $unitsByCourse = [];
        $unitsByPhase = [];
    }

    return [
        'by_course' => $unitsByCourse,
        'by_phase' => $unitsByPhase,
    ];
}

$accounts = load_teacher_accounts($teacherId);
$firstAccount = $accounts[0] ?? null;

$groupedUnits = load_units_grouped();
$unitsByCourse = $groupedUnits['by_course'];
$unitsByPhase = $groupedUnits['by_phase'];

$todayUnits = [];
$todayPermission = 'viewer';

if ($firstAccount) {
    $scope = (string) ($firstAccount['scope'] ?? 'technical');
    $targetId = (string) ($firstAccount['target_id'] ?? '');
    $todayPermission = (string) ($firstAccount['permission'] ?? 'viewer');

    $todayUnits = $scope === 'english'
        ? ($unitsByPhase[$targetId] ?? [])
        : ($unitsByCourse[$targetId] ?? []);
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
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial, sans-serif;
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
    border-radius:14px;
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
    background:#dbe7f6;
    border:4px solid #edf3fb;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:64px;
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
    min-height:120px;
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
                <div class="avatar">👨‍🏫</div>
                <div class="teacher-name"><?php echo h($teacherName); ?></div>
                <div class="teacher-role">Docente</div>

                <a class="side-button" href="teacher_groups.php">Lista de Estudiantes</a>
                <a class="side-button" href="teacher_groups.php">Progreso del Estudiante</a>
            </div>
        </aside>

        <main>
            <h2 class="main-section-title">Actividad para Hoy</h2>

            <?php if ($firstAccount) { ?>
                <div class="card">
                    <h3 class="activity-title">
                        Tema: "<?php echo h((string) ($firstAccount['target_name'] ?? 'Curso')); ?>"
                    </h3>

                    <p class="activity-text">
                        Ingresa al curso para proyectar las actividades en modo presentación y avanzar con Next.
                    </p>

                    <div class="actions">
                        <a class="btn btn-green" href="teacher_course.php?account=<?php echo urlencode((string) ($firstAccount['id'] ?? '')); ?>">
                            Iniciar Presentación
                        </a>

                        <?php if ($todayPermission === 'editor') { ?>
                            <a class="btn btn-orange" href="teacher_course.php?account=<?php echo urlencode((string) ($firstAccount['id'] ?? '')); ?>&mode=edit">
                                Editar Actividad
                            </a>
                        <?php } ?>
                    </div>

                    <div class="unit-list">
                        <div class="badge-row">
                            <span class="badge">
                                <?php echo h(((string) ($firstAccount['scope'] ?? '') === 'english') ? 'Estudiantes' : 'Docentes'); ?>
                            </span>
                            <span class="badge">
                                <?php echo h($todayPermission === 'editor' ? 'Puede editar' : 'Solo ver'); ?>
                            </span>
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
                                        <a class="unit-btn" href="teacher_unit.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=view">
                                            Ver
                                        </a>

                                        <?php if ($todayPermission === 'editor') { ?>
                                            <a class="unit-btn edit" href="teacher_unit.php?unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=edit">
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

            <?php if (empty($accounts)) { ?>
                <div class="empty">No tienes cursos asignados todavía.</div>
            <?php } else { ?>
                <div class="course-grid">
                    <?php foreach ($accounts as $index => $account) { ?>
                        <?php
                        $colorClass = 'course-blue';
                        if ($index % 3 === 1) {
                            $colorClass = 'course-yellow';
                        } elseif ($index % 3 === 2) {
                            $colorClass = 'course-green';
                        }
                        ?>
                        <a class="course-card <?php echo $colorClass; ?>" href="teacher_course.php?account=<?php echo urlencode((string) ($account['id'] ?? '')); ?>">
                            <div class="course-name"><?php echo h((string) ($account['target_name'] ?? 'Curso')); ?></div>
                            <div class="course-sub">Entrar al curso</div>
                        </a>
                    <?php } ?>
                </div>
            <?php } ?>
        </main>
    </div>
</div>
</body>
</html>

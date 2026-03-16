<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));
$teacherName = trim((string) ($_SESSION['teacher_name'] ?? 'Docente'));
$flashMessage = '';
$flashError = '';

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

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
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

function ensure_data_directory(): string
{
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    return $dataDir;
}

function teacher_photos_store_file(): string
{
    return ensure_data_directory() . '/teacher_photos.json';
}

function teacher_photos_directory(): string
{
    $photosDir = ensure_data_directory() . '/teacher_photos';
    if (!is_dir($photosDir)) {
        mkdir($photosDir, 0777, true);
    }

    return $photosDir;
}

function load_teacher_photos_store(): array
{
    $storeFile = teacher_photos_store_file();
    if (!file_exists($storeFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($storeFile), true);
    return is_array($decoded) ? $decoded : [];
}

function save_teacher_photos_store(array $photos): void
{
    file_put_contents(
        teacher_photos_store_file(),
        json_encode($photos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function load_teacher_photo_from_database(string $teacherId): string
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '' || !table_has_column($pdo, 'teacher_accounts', 'teacher_photo')) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("
            SELECT teacher_photo
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        return trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function save_teacher_photo_to_database(string $teacherId, string $photoPath): void
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '' || !table_has_column($pdo, 'teacher_accounts', 'teacher_photo')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE teacher_accounts
            SET teacher_photo = :teacher_photo
            WHERE teacher_id = :teacher_id
        ");
        $stmt->execute([
            'teacher_photo' => $photoPath,
            'teacher_id' => $teacherId,
        ]);
    } catch (Throwable $e) {
        // Mantener respaldo local JSON.
    }
}

function load_teacher_photo(string $teacherId): string
{
    if ($teacherId === '') {
        return '';
    }

    $store = load_teacher_photos_store();
    $fromStore = trim((string) ($store[$teacherId] ?? ''));
    if ($fromStore !== '') {
        return $fromStore;
    }

    return load_teacher_photo_from_database($teacherId);
}

function save_teacher_photo(string $teacherId, string $photoPath): void
{
    if ($teacherId === '') {
        return;
    }

    $store = load_teacher_photos_store();
    $store[$teacherId] = $photoPath;
    save_teacher_photos_store($store);
    save_teacher_photo_to_database($teacherId, $photoPath);
}

function is_local_teacher_photo_path(string $photoPath): bool
{
    return str_starts_with($photoPath, 'data/teacher_photos/');
}

function maybe_delete_local_teacher_photo(string $photoPath): void
{
    if ($photoPath === '' || !is_local_teacher_photo_path($photoPath)) {
        return;
    }

    $fullPath = __DIR__ . '/' . $photoPath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function load_teacher_assignments(string $teacherId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '' || !table_exists($pdo, 'teacher_assignments')) {
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

function load_teacher_permission(string $teacherId): string
{
    $pdo = get_pdo_connection();
    if (!$pdo || $teacherId === '' || !table_exists($pdo, 'teacher_accounts')) {
        return 'viewer';
    }

    try {
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

function load_units_for_assignment(array $assignment): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || !table_exists($pdo, 'units')) {
        return [];
    }

    $programType = trim((string) ($assignment['program_type'] ?? ''));
    $courseId = trim((string) ($assignment['course_id'] ?? ''));

    if ($courseId === '') {
        return [];
    }

    try {
        if ($programType === 'english' && table_has_column($pdo, 'units', 'phase_id')) {
            $stmt = $pdo->prepare("
                SELECT id, name
                FROM units
                WHERE phase_id = :course_id
                ORDER BY id ASC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT id, name
                FROM units
                WHERE course_id = :course_id
                ORDER BY id ASC
            ");
        }

        $stmt->execute(['course_id' => $courseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'upload_teacher_photo') {
    if ($teacherId === '') {
        $flashError = 'No se encontró la sesión del docente.';
    } elseif (!isset($_FILES['teacher_photo']) || !is_array($_FILES['teacher_photo'])) {
        $flashError = 'Debes seleccionar una imagen.';
    } else {
        $file = $_FILES['teacher_photo'];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            $flashError = 'No se pudo subir la imagen. Intenta nuevamente.';
        } else {
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            $maxBytes = 5 * 1024 * 1024;

            if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0 || $size > $maxBytes) {
                $flashError = 'La imagen debe pesar máximo 5MB.';
            } else {
                $mime = (string) mime_content_type($tmpName);
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                ];

                if (!isset($allowedMimes[$mime])) {
                    $flashError = 'Formato no permitido. Usa JPG, PNG, WEBP o GIF.';
                } else {
                    $extension = $allowedMimes[$mime];
                    $safeTeacherId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $teacherId) ?: 'teacher';
                    $newFilename = $safeTeacherId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $newFileAbsolute = teacher_photos_directory() . '/' . $newFilename;
                    $newFileRelative = 'data/teacher_photos/' . $newFilename;

                    if (!move_uploaded_file($tmpName, $newFileAbsolute)) {
                        $flashError = 'No fue posible guardar la imagen subida.';
                    } else {
                        $oldPhoto = trim((string) ($_SESSION['teacher_photo'] ?? load_teacher_photo($teacherId)));
                        save_teacher_photo($teacherId, $newFileRelative);
                        $_SESSION['teacher_photo'] = $newFileRelative;
                        maybe_delete_local_teacher_photo($oldPhoto);
                        $flashMessage = 'Foto actualizada correctamente.';
                    }
                }
            }
        }
    }
}

$teacherPhoto = trim((string) ($_SESSION['teacher_photo'] ?? ''));
if ($teacherPhoto === '') {
    $teacherPhoto = load_teacher_photo($teacherId);
    if ($teacherPhoto !== '') {
        $_SESSION['teacher_photo'] = $teacherPhoto;
    }
}

$assignments = load_teacher_assignments($teacherId);
$teacherPermission = load_teacher_permission($teacherId);
$selectedAssignmentId = trim((string) ($_GET['assignment'] ?? ''));
$selectedAssignment = null;

if ($selectedAssignmentId !== '') {
    foreach ($assignments as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $selectedAssignmentId) {
            $selectedAssignment = $candidate;
            break;
        }
    }
}

if (!$selectedAssignment) {
    $selectedAssignment = $assignments[0] ?? null;
    $selectedAssignmentId = (string) ($selectedAssignment['id'] ?? '');
}

$todayUnits = [];
$todayTitle = 'Curso';
$todayProgramLabel = 'Docente';

if ($selectedAssignment) {
    $todayTitle = build_assignment_title($selectedAssignment);
    $todayProgramLabel = ((string) ($selectedAssignment['program_type'] ?? '') === 'english') ? 'English' : 'Técnico';
    $todayUnits = load_units_for_assignment($selectedAssignment);
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

*{ box-sizing:border-box; }

body{ margin:0; font-family:Arial, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
.page{ max-width:1280px; margin:0 auto; padding:30px; }

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    padding-bottom:14px;
    border-bottom:2px solid var(--line);
    margin-bottom:22px;
}

.header h1{ margin:0; font-size:28px; font-weight:700; color:var(--title); }
.logout{ color:var(--danger); text-decoration:none; font-weight:700; font-size:14px; }
.layout{ display:grid; grid-template-columns:320px 1fr; gap:24px; }

.panel,
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.panel{ padding:18px; }
.profile-box{ text-align:center; }

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

.avatar-image{ width:100%; height:100%; object-fit:cover; display:block; }
.teacher-name{ font-size:20px; font-weight:700; color:var(--title); margin-bottom:6px; }
.teacher-role{ font-size:15px; color:var(--muted); margin-bottom:18px; }

.side-button{ display:block; width:100%; margin-top:10px; padding:12px 14px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:700; color:#fff; background:linear-gradient(180deg, #2f74ce, #1f4d95); text-align:center; }
.upload-form{ margin-top:14px; margin-bottom:12px; text-align:left; }
.upload-label{ display:block; font-size:12px; color:var(--muted); font-weight:700; margin-bottom:6px; }
.upload-input{ width:100%; margin-bottom:8px; }
.upload-btn{ width:100%; border:none; border-radius:10px; padding:10px; color:#fff; cursor:pointer; font-size:13px; font-weight:700; background:linear-gradient(180deg, #4cbf62, #249145); }
.flash{ border-radius:10px; padding:10px 12px; margin-bottom:14px; font-size:13px; }
.flash.ok{ background:#ecfdf3; border:1px solid #86efac; color:#166534; }
.flash.error{ background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }

.main-section-title{ display:flex; align-items:center; gap:14px; font-size:22px; font-weight:700; color:var(--title); margin:0 0 14px; }
.main-section-title::after{ content:""; flex:1; height:2px; background:var(--line); }
.card{ padding:20px; margin-bottom:18px; }
.activity-title{ margin:0 0 12px; font-size:18px; font-weight:700; color:var(--title); }
.activity-text{ margin:0 0 18px; font-size:15px; color:var(--text); }
.actions{ display:flex; flex-wrap:wrap; gap:12px; }
.btn{ display:inline-block; padding:12px 18px; border-radius:10px; text-decoration:none; color:#fff; font-size:14px; font-weight:700; }
.btn-green{ background:linear-gradient(180deg, #4cbf62, #249145); }
.btn-orange{ background:linear-gradient(180deg, #f7a531, #e57e08); }
.course-grid{ display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:16px; }

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

.course-blue{ background:linear-gradient(180deg, #2f74ce, #1f4d95); }
.course-yellow{ background:linear-gradient(180deg, #f5be35, #db9600); }
.course-green{ background:linear-gradient(180deg, #71c557, #2b9d48); }
.course-name{ font-size:22px; font-weight:700; line-height:1.2; }
.course-sub{ font-size:14px; opacity:.95; }
.course-meta{ font-size:12px; font-weight:700; opacity:.95; text-transform:uppercase; letter-spacing:.03em; }

.unit-list{ margin-top:16px; }
.unit{ display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 14px; margin-bottom:10px; border:1px solid var(--line); border-radius:10px; background:#fff; }
.unit-name{ font-size:15px; font-weight:700; color:#243b63; }
.unit-actions{ display:flex; flex-wrap:wrap; gap:8px; }
.unit-btn{ display:inline-block; padding:8px 12px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:700; color:#fff; background:var(--blue); }
.empty{ background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px; color:var(--muted); font-size:14px; }
.badge-row{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.badge{ display:inline-block; padding:4px 10px; border-radius:999px; background:#eef2ff; color:#1f4ec9; font-size:12px; font-weight:700; }

@media (max-width: 1024px){ .layout{ grid-template-columns:1fr; } .course-grid{ grid-template-columns:1fr; } }
@media (max-width: 768px){ .page{ padding:20px; } .header{ flex-direction:column; align-items:flex-start; } .unit{ flex-direction:column; align-items:flex-start; } .actions{ flex-direction:column; } .btn{ width:100%; text-align:center; } }
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
                    <?php if ($teacherPhoto !== '') { ?>
                        <img class="avatar-image" src="<?php echo h($teacherPhoto); ?>" alt="Foto de <?php echo h($teacherName); ?>">
                    <?php } else { ?>
                        <span>👨‍🏫</span>
                    <?php } ?>
                </div>

                <?php if ($flashMessage !== '') { ?>
                    <div class="flash ok"><?php echo h($flashMessage); ?></div>
                <?php } ?>
                <?php if ($flashError !== '') { ?>
                    <div class="flash error"><?php echo h($flashError); ?></div>
                <?php } ?>

                <div class="teacher-name"><?php echo h($teacherName); ?></div>
                <div class="teacher-role">Docente</div>

                <form class="upload-form" method="post" enctype="multipart/form-data">
                    <label class="upload-label" for="teacher_photo">Subir / cambiar foto</label>
                    <input class="upload-input" type="file" id="teacher_photo" name="teacher_photo" accept="image/*" required>
                    <input type="hidden" name="action" value="upload_teacher_photo">
                    <button type="submit" class="upload-btn">Guardar foto</button>
                </form>

                <a class="side-button" href="teacher_groups.php">Lista de Estudiantes</a>
                <a class="side-button" href="teacher_groups.php">Progreso del Estudiante</a>
            </div>
        </aside>

        <main>
            <h2 class="main-section-title">Actividad para Hoy</h2>

            <?php if ($selectedAssignment) { ?>
                <div class="card">
                    <h3 class="activity-title">Tema: "<?php echo h($todayTitle); ?>"</h3>

                    <p class="activity-text">
                        Ingresa al curso para proyectar las actividades en modo presentación y avanzar con Next.
                    </p>

                    <div class="actions">
                        <a class="btn btn-green" href="teacher_course.php?assignment=<?php echo urlencode((string) ($selectedAssignment['id'] ?? '')); ?>">
                            Iniciar Presentación
                        </a>

                        <a class="btn btn-orange" href="#unidades-curso">
                          Ver unidades
                           </a>

                <?php if ($teacherPermission === 'editor') { ?>
                         <a class="btn btn-red" href="teacher_course.php?assignment=<?php echo urlencode((string) ($selectedAssignment['id'] ?? '')); ?>&mode=edit">
                         Editar
                            </a>
                        <?php } ?>
                    </div>

                    <div class="unit-list" id="unidades-curso">
                        <div class="badge-row">
                            <span class="badge"><?php echo h($todayProgramLabel); ?></span>
                            <span class="badge"><?php echo h($teacherPermission === 'editor' ? 'Puede editar' : 'Solo ver'); ?></span>
                        </div>

                        <?php if (empty($todayUnits)) { ?>
                            <div class="empty">No hay unidades encontradas para esta asignación.</div>
                        <?php } else { ?>
                            <?php foreach ($todayUnits as $unit) { ?>
                                <div class="unit">
                                    <div class="unit-name"><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></div>

                                    <div class="unit-actions">
                                        <a class="unit-btn" href="teacher_unit.php?assignment=<?php echo urlencode((string) ($selectedAssignment['id'] ?? '')); ?>&unit=<?php echo urlencode((string) ($unit['id'] ?? '')); ?>&mode=view">
                                            Ver
                                        </a>
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
                        <a class="course-card <?php echo h($colorClass); ?>" href="dashboard.php?assignment=<?php echo urlencode((string) ($assignment['id'] ?? '')); ?>#unidades-curso">
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

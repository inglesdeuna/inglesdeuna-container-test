<?php
session_start();

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password'])) {
    header('Location: change_password_student.php');
    exit;
}

$studentId = trim((string) ($_SESSION['student_id'] ?? ''));
$studentName = trim((string) ($_SESSION['student_name'] ?? 'Estudiante'));
$flashMessage = '';
$flashError = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function student_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'ES';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) === 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'ES';
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

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1");
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

function student_photos_store_file(): string
{
    return ensure_data_directory() . '/student_photos.json';
}

function student_photos_directory(): string
{
    $photosDir = ensure_data_directory() . '/student_photos';
    if (!is_dir($photosDir)) {
        mkdir($photosDir, 0777, true);
    }

    return $photosDir;
}

function load_student_photos_store(): array
{
    $storeFile = student_photos_store_file();
    if (!file_exists($storeFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($storeFile), true);
    return is_array($decoded) ? $decoded : [];
}

function save_student_photos_store(array $photos): void
{
    file_put_contents(student_photos_store_file(), json_encode($photos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function load_student_photo_from_database(string $studentId): string
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '' || !table_has_column($pdo, 'student_accounts', 'student_photo')) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("SELECT student_photo FROM student_accounts WHERE student_id = :student_id ORDER BY updated_at DESC NULLS LAST LIMIT 1");
        $stmt->execute(['student_id' => $studentId]);
        return trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function save_student_photo_to_database(string $studentId, string $photoPath): void
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '' || !table_has_column($pdo, 'student_accounts', 'student_photo')) {
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE student_accounts SET student_photo = :student_photo WHERE student_id = :student_id");
        $stmt->execute([
            'student_photo' => $photoPath,
            'student_id' => $studentId,
        ]);
    } catch (Throwable $e) {
    }
}

function load_student_photo(string $studentId): string
{
    if ($studentId === '') {
        return '';
    }

    $store = load_student_photos_store();
    $fromStore = trim((string) ($store[$studentId] ?? ''));
    if ($fromStore !== '') {
        return $fromStore;
    }

    return load_student_photo_from_database($studentId);
}

function save_student_photo(string $studentId, string $photoPath): void
{
    if ($studentId === '') {
        return;
    }

    $store = load_student_photos_store();
    $store[$studentId] = $photoPath;
    save_student_photos_store($store);
    save_student_photo_to_database($studentId, $photoPath);
}

function maybe_delete_local_student_photo(string $photoPath): void
{
    if (!str_starts_with($photoPath, 'data/student_photos/')) {
        return;
    }

    $fullPath = __DIR__ . '/' . $photoPath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function resolve_student_photo_src(string $studentPhoto): string
{
    $studentPhoto = trim($studentPhoto);
    if ($studentPhoto === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $studentPhoto)) {
        return $studentPhoto;
    }

    $fullPath = __DIR__ . '/' . ltrim($studentPhoto, '/');
    if (is_file($fullPath)) {
        return h($studentPhoto);
    }

    return '';
}

function load_student_permission(string $studentId): string
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '' || !table_has_column($pdo, 'student_accounts', 'permission')) {
        return 'viewer';
    }

    try {
        $stmt = $pdo->prepare("SELECT permission FROM student_accounts WHERE student_id = :student_id ORDER BY updated_at DESC NULLS LAST LIMIT 1");
        $stmt->execute(['student_id' => $studentId]);
        $permission = (string) $stmt->fetchColumn();
        return $permission === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        return 'viewer';
    }
}

function load_student_assignments(string $studentId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT sa.id, sa.teacher_id, sa.course_id, sa.period, sa.unit_id, sa.level_id, sa.program, sa.updated_at,\n                   t.name AS teacher_name,\n                   c.name AS course_name,\n                   u.name AS unit_name\n            FROM student_assignments sa\n            LEFT JOIN teachers t ON t.id = sa.teacher_id\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            LEFT JOIN units u ON u.id::text = sa.unit_id\n            WHERE sa.student_id = :student_id\n            ORDER BY sa.updated_at DESC NULLS LAST, sa.id DESC\n        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_assignment_score_summary(string $studentId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT assignment_id,\n                   AVG(completion_percent)::numeric(5,2) AS avg_percent,\n                   SUM(quiz_errors) AS total_errors,\n                   SUM(quiz_total) AS total_questions\n            FROM student_unit_results\n            WHERE student_id = :student_id\n            GROUP BY assignment_id\n        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $summary = [];
        foreach ($rows as $row) {
            $assignmentId = (string) ($row['assignment_id'] ?? '');
            if ($assignmentId === '') {
                continue;
            }

            $summary[$assignmentId] = [
                'avg_percent' => (int) round((float) ($row['avg_percent'] ?? 0)),
                'total_errors' => (int) ($row['total_errors'] ?? 0),
                'total_questions' => (int) ($row['total_questions'] ?? 0),
            ];
        }

        return $summary;
    } catch (Throwable $e) {
        return [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'upload_student_photo') {
    if ($studentId === '') {
        $flashError = 'No se encontró la sesión del estudiante.';
    } elseif (!isset($_FILES['student_photo']) || !is_array($_FILES['student_photo'])) {
        $flashError = 'Debes seleccionar una imagen.';
    } else {
        $file = $_FILES['student_photo'];
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
                    $safeStudentId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId) ?: 'student';
                    $newFilename = $safeStudentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $newFileAbsolute = student_photos_directory() . '/' . $newFilename;
                    $newFileRelative = 'data/student_photos/' . $newFilename;

                    if (!move_uploaded_file($tmpName, $newFileAbsolute)) {
                        $flashError = 'No fue posible guardar la imagen subida.';
                    } else {
                        $oldPhoto = trim((string) ($_SESSION['student_photo'] ?? load_student_photo($studentId)));
                        save_student_photo($studentId, $newFileRelative);
                        $_SESSION['student_photo'] = $newFileRelative;
                        maybe_delete_local_student_photo($oldPhoto);
                        $flashMessage = 'Foto actualizada correctamente.';
                    }
                }
            }
        }
    }
}

$studentPhoto = trim((string) ($_SESSION['student_photo'] ?? ''));
if ($studentPhoto === '') {
    $studentPhoto = load_student_photo($studentId);
    if ($studentPhoto !== '') {
        $_SESSION['student_photo'] = $studentPhoto;
    }
}

$studentPhotoSrc = resolve_student_photo_src($studentPhoto);
$studentPermission = load_student_permission($studentId);
$_SESSION['student_permission'] = $studentPermission;
$studentInitials = student_initials($studentName);
$myAssignments = load_student_assignments($studentId);
$scoreSummaryByAssignment = load_assignment_score_summary($studentId);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel Estudiante</title>
<style>
:root{
    --bg:#fff8e6;
    --card:#ffffff;
    --line:#dcc4f0;
    --title:#a855c8;
    --text:#f14902;
    --muted:#b8551f;
    --salmon:#f14902;
    --salmon-dark:#d33d00;
    --danger:#c42828;
    --soft:#eddeff;
    --shadow:0 10px 24px rgba(120,40,160,.13);
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:linear-gradient(145deg,#fff8e6 0%,#fdeaff 55%,#f0e0ff 100%);
    color:var(--text);
    padding:26px;
}
.page{max-width:1200px;margin:0 auto}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.top h1{margin:0;color:var(--title);font-size:30px}
.logout{color:#fff;text-decoration:none;font-weight:700;background:var(--title);padding:10px 14px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}
.logout:hover{background:#2ba7c5}
.profile-card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:14px;
    box-shadow:var(--shadow);
    padding:16px;
    margin-bottom:18px;
    display:flex;
    gap:18px;
    align-items:center;
    flex-wrap:wrap;
}
.avatar{
    width:112px;
    height:112px;
    border-radius:50%;
    overflow:hidden;
    background:linear-gradient(180deg,#c97de8,#8b1a9a);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:34px;
    font-weight:800;
    flex-shrink:0;
}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}
.profile-body{flex:1;min-width:220px}
.welcome{margin:0 0 8px;font-size:16px}
.badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:var(--soft);
    color:var(--title);
    border:1px solid #f7c95f;
}
.profile-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.profile-form input[type="file"]{max-width:240px}
.btn{
    display:inline-block;
    margin-top:14px;
    padding:10px 14px;
    background:var(--salmon);
    color:#fff;
    text-decoration:none;
    border-radius:8px;
    font-weight:700;
    border:none;
    cursor:pointer;
}
.btn:hover{background:var(--salmon-dark)}
.btn.secondary{background:#a08a85}
.btn.secondary{background:var(--title)}
.btn.secondary:hover{background:#2ba7c5}
.section-title{margin:0 0 16px;color:var(--title);font-size:24px;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;box-shadow:var(--shadow)}
.card h3{margin:0 0 10px;font-size:20px;color:var(--title)}
.card p{margin:6px 0;color:var(--muted);font-size:15px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;color:var(--muted)}
.notice{padding:10px 12px;border-radius:8px;margin-bottom:12px;font-weight:700}
.notice-ok{background:#ebfff0;border:1px solid #c2efcf;color:#1b7a39}
.notice-error{background:#fff0f0;border:1px solid #f3c4c4;color:#b42323}

@media (max-width: 768px){
    body{padding:18px}
    .top h1{font-size:24px}
}
</style>
</head>
<body>
<div class="page">
    <div class="top">
        <h1>Perfil del Estudiante</h1>
        <a class="logout" href="logout.php">Cerrar sesión</a>
    </div>

    <?php if ($flashMessage !== '') { ?><div class="notice notice-ok"><?php echo h($flashMessage); ?></div><?php } ?>
    <?php if ($flashError !== '') { ?><div class="notice notice-error"><?php echo h($flashError); ?></div><?php } ?>

    <div class="profile-card">
        <div class="avatar">
            <?php if ($studentPhotoSrc !== '') { ?>
                <img src="<?php echo $studentPhotoSrc; ?>" alt="Foto estudiante">
            <?php } else { ?>
                <span><?php echo h($studentInitials); ?></span>
            <?php } ?>
        </div>

        <div class="profile-body">
            <p class="welcome">Bienvenido, <strong><?php echo h($studentName); ?></strong>.</p>
            <span class="badge">Modo: <?php echo h($studentPermission === 'editor' ? 'Editor' : 'Consulta'); ?> (solo lectura recomendado)</span>

            <form method="post" enctype="multipart/form-data" class="profile-form" style="margin-top:12px;">
                <input type="hidden" name="action" value="upload_student_photo">
                <input type="file" name="student_photo" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <button class="btn" type="submit" style="margin-top:0;">Subir foto</button>
            </form>
        </div>
    </div>

    <h2 class="section-title">Mis cursos y programas</h2>

    <?php if (empty($myAssignments)) { ?>
        <div class="empty">No tienes cursos asignados aún.</div>
    <?php } else { ?>
        <div class="grid">
            <?php foreach ($myAssignments as $assignment) { ?>
                <?php
                $assignmentId = (string) ($assignment['id'] ?? '');
                $program = (string) ($assignment['program'] ?? 'technical');
                $programLabel = $program === 'english' ? 'Inglés' : 'Técnico';
                $courseName = trim((string) ($assignment['course_name'] ?? ''));
                if ($courseName === '') {
                    $courseName = 'Curso';
                }
                $unitName = trim((string) ($assignment['unit_name'] ?? ''));
                if ($unitName === '' && $program === 'english') {
                    $unitName = 'Unidades por fase';
                }
                $scoreSummary = $scoreSummaryByAssignment[$assignmentId] ?? null;
                ?>
                <div class="card">
                    <h3><?php echo h($courseName); ?></h3>
                    <p>Programa: <strong><?php echo h($programLabel); ?></strong></p>
                    <p>Docente: <strong><?php echo h((string) ($assignment['teacher_name'] ?? 'Docente')); ?></strong></p>
                    <p>Periodo: <strong><?php echo h((string) ($assignment['period'] ?? '')); ?></strong></p>
                    <?php if ($unitName !== '') { ?>
                        <p>Unidad: <strong><?php echo h($unitName); ?></strong></p>
                    <?php } ?>
                    <?php if (is_array($scoreSummary)) { ?>
                        <p>Puntaje promedio: <strong><?php echo (int) ($scoreSummary['avg_percent'] ?? 0); ?>%</strong></p>
                        <?php if ((int) ($scoreSummary['total_questions'] ?? 0) > 0) { ?>
                            <p>Errores en quizzes: <strong><?php echo (int) ($scoreSummary['total_errors'] ?? 0); ?>/<?php echo (int) ($scoreSummary['total_questions'] ?? 0); ?></strong></p>
                        <?php } ?>
                    <?php } ?>

                    <div class="actions">
                        <a class="btn" href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>">Entrar al curso</a>
                        <a class="btn secondary" href="student_quiz.php?assignment=<?php echo urlencode($assignmentId); ?>">Ver puntajes</a>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
</body>
</html>

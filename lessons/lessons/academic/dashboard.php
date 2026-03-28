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

function teacher_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'DC';
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

    return $initials !== '' ? $initials : 'DC';
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

    $normalize = static function (string $value): string {
        if ($value === '') {
            return $value;
        }

        return preg_replace_callback('/ingl(?:e|é)s\s+t(?:e|é)cnico/iu', static function (): string {
            return 'INGLÉS TÉCNICO';
        }, $value) ?? $value;
    };

    $toUpper = static function (string $value): string {
        if ($value === '') {
            return $value;
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    };

    if ($programType === 'technical' && $unitName !== '') {
        return $toUpper($normalize($courseName)) . ' · ' . $toUpper($normalize($unitName));
    }

    if ($programType === 'technical') {
        return $toUpper($normalize($courseName));
    }

    return $normalize($courseName);
}

function resolve_teacher_photo_src(string $teacherPhoto): string
{
    $teacherPhoto = trim($teacherPhoto);

    if ($teacherPhoto === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $teacherPhoto)) {
        return $teacherPhoto;
    }

    $fullPath = __DIR__ . '/' . ltrim($teacherPhoto, '/');
    if (is_file($fullPath)) {
        return h($teacherPhoto);
    }

    return '';
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

$teacherPhotoSrc = resolve_teacher_photo_src($teacherPhoto);
$teacherInitials = teacher_initials($teacherName);

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
$selectedUnitId = trim((string) ($_GET['unit'] ?? ''));
$selectedUnit = null;

if ($selectedAssignment) {
    $todayTitle = build_assignment_title($selectedAssignment);
    $todayProgramLabel = ((string) ($selectedAssignment['program_type'] ?? '') === 'english') ? 'English' : 'INGLÉS TÉCNICO';
    $todayUnits = load_units_for_assignment($selectedAssignment);

    if (!empty($todayUnits)) {
        foreach ($todayUnits as $unit) {
            if ((string) ($unit['id'] ?? '') === $selectedUnitId) {
                $selectedUnit = $unit;
                break;
            }
        }

        if (!$selectedUnit) {
            $selectedUnit = $todayUnits[0];
            $selectedUnitId = (string) ($selectedUnit['id'] ?? '');
        }
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
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

:root{
    --bg:#eef5ff;
    --card:#ffffff;
    --line:#d6e4ff;
    --text:#16325c;
    --title:#16325c;
    --muted:#5f7294;
    --radius:12px;
    --green:#3b82f6;
    --green-dark:#1d4ed8;
    --green-soft:#eaf2ff;
    --red:#dc2626;
    --red-dark:#b91c1c;
    --blue:#1d4ed8;
    --orange:#d97706;
    --shadow:0 10px 24px rgba(0,0,0,.08);
    --shadow-sm:0 2px 8px rgba(0,0,0,.06);
    /* legacy aliases kept for any inline usage */
    --primary:#3b82f6;
    --primary-dark:#1d4ed8;
    --primary-light:#eaf2ff;
    --success:#60a5fa;
    --success-dark:#2563eb;
    --warning:#f59e0b;
    --warning-dark:#d97706;
    --danger:#ef4444;
    --danger-dark:#dc2626;
    --shadow:0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06);
    --shadow-md:0 4px 6px rgba(0,0,0,.1), 0 2px 4px rgba(0,0,0,.06);
    --shadow-lg:0 10px 15px rgba(0,0,0,.1), 0 4px 6px rgba(0,0,0,.05);
}

*{ box-sizing:border-box; }
body{ margin:0; font-family:'Nunito','Segoe UI',sans-serif; background:var(--bg); color:var(--text); }
.page{ max-width:1400px; margin:0 auto; padding:20px 20px 40px; }

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    padding-bottom:20px;
    border-bottom:1px solid var(--line);
    margin-bottom:32px;
}

.header h1{ margin:0; font-size:32px; font-weight:800; color:var(--title); font-family:'Fredoka','Trebuchet MS',sans-serif; }
.logout-btn{
    display:inline-block;
    text-decoration:none;
    color:#fff;
    font-size:13px;
    font-weight:700;
    border-radius:10px;
    padding:10px 16px;
    background:linear-gradient(180deg,#3b82f6,#1d4ed8);
    box-shadow:var(--shadow-sm);
    transition:filter .2s, transform .15s;
}
.logout-btn:hover{
    filter:brightness(1.06);
    transform:translateY(-1px);
}

.layout{ display:grid; grid-template-columns:340px 1fr; gap:32px; }

.panel,
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.panel{ padding:28px 24px; }
.profile-box{ text-align:center; }

.avatar{
    width:180px;
    height:180px;
    margin:0 auto 24px;
    border-radius:50%;
    overflow:hidden;
    background:linear-gradient(135deg, var(--primary-light) 0%, #e0e7ff 100%);
    border:4px solid #f0f4ff;
    box-shadow:0 8px 20px rgba(59, 130, 246, 0.15);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:72px;
}

.avatar-image{ width:100%; height:100%; object-fit:cover; display:block; }
.avatar-fallback{
    display:none;
    width:100%;
    height:100%;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    color:var(--primary-dark);
    font-size:44px;
    font-weight:800;
    letter-spacing:.08em;
}
.teacher-name{ font-size:22px; font-weight:700; color:var(--title); margin:8px 0 6px; }
.teacher-role{ font-size:13px; color:var(--muted); font-weight:600; margin-bottom:20px; }

.profile-meta{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:10px;
    margin:0 0 20px;
}

.profile-stat{
    padding:12px;
    border:1px solid var(--line);
    border-radius:10px;
    background:#f8fafc;
    text-align:left;
}

.profile-stat-label{
    display:block;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--muted);
    margin-bottom:4px;
}

.profile-stat-value{
    display:block;
    font-size:16px;
    font-weight:700;
    color:var(--title);
}

.side-button{
    display:block;
    width:100%;
    margin-top:12px;
    padding:12px 16px;
    border-radius:12px;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    color:#fff;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    text-align:center;
    transition:filter .2s, transform .15s;
    box-shadow:var(--shadow-sm);
}
.side-button:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}

.side-button.green{
    background:linear-gradient(180deg,#60a5fa,#2563eb);
}

.side-button.gray{
    background:linear-gradient(180deg,#7b8b7f,#66756a);
}

.sidebar-section-title{
    margin:16px 0 8px;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--muted);
}

.sidebar-course-list{
    display:flex;
    flex-direction:column;
    gap:8px;
}

.sidebar-course-btn{
    display:block;
    width:100%;
    text-decoration:none;
    color:#fff;
    font-size:12px;
    font-weight:700;
    line-height:1.35;
    padding:10px 12px;
    border-radius:10px;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    box-shadow:var(--shadow-sm);
    transition:filter .2s, transform .15s;
}

.sidebar-course-btn:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}

.sidebar-course-btn.active{
    background:linear-gradient(180deg,#1d4ed8,#1e3a8a);
}

.upload-form{ margin-top:20px; margin-bottom:12px; text-align:left; }
.upload-label{ display:block; font-size:13px; color:var(--muted); font-weight:600; margin-bottom:8px; }
.upload-input{ width:100%; margin-bottom:10px; padding:8px; border:1px solid var(--line); border-radius:8px; }
.upload-btn{ width:100%; border:none; border-radius:10px; padding:11px; color:#fff; cursor:pointer; font-size:13px; font-weight:600; background:linear-gradient(180deg,#60a5fa,#2563eb); transition:all .3s; box-shadow:var(--shadow); }
.upload-btn:hover{ transform:translateY(-2px); box-shadow:var(--shadow-md); }
.flash{ border-radius:10px; padding:12px 14px; margin-bottom:14px; font-size:13px; }
.flash.ok{ background:#eff6ff; border:1px solid #93c5fd; color:#1d4ed8; }
.flash.error{ background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }

.main-section-title{ display:flex; align-items:center; gap:12px; font-size:24px; font-weight:800; color:var(--title); margin:32px 0 20px; font-family:'Fredoka','Trebuchet MS',sans-serif; }
.main-section-title::after{ content:""; flex:1; height:2px; background:linear-gradient(90deg, var(--line) 0%, transparent 100%); }

.card{ padding:28px; margin-bottom:20px; }
.hero-card{
    position:relative;
    overflow:hidden;
    background:linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
}

.hero-card::before{
    content:"";
    position:absolute;
    inset:auto -80px -80px auto;
    width:220px;
    height:220px;
    border-radius:50%;
    background:radial-gradient(circle, rgba(59,130,246,.18) 0%, rgba(59,130,246,0) 70%);
}

.hero-content{
    position:relative;
    z-index:1;
}

.activity-topline{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:10px;
    margin-bottom:10px;
}

.activity-title{ margin:0 0 14px; font-size:20px; font-weight:700; color:var(--title); font-family:'Fredoka','Trebuchet MS',sans-serif; }
.activity-text{ margin:0 0 22px; font-size:15px; color:var(--text); line-height:1.6; }

.actions{ display:flex; flex-wrap:wrap; gap:14px; }
.btn{
    display:inline-block;
    padding:12px 20px;
    border-radius:10px;
    text-decoration:none;
    color:#fff;
    font-size:14px;
    font-weight:600;
    transition:all .3s;
    box-shadow:var(--shadow);
    border:none;
    cursor:pointer;
}
.btn:hover{ transform:translateY(-2px); box-shadow:var(--shadow-md); }
.btn-green{
    background:linear-gradient(135deg, #60a5fa 0%, #2563eb 100%);
}
.btn-orange{
    background:linear-gradient(135deg, var(--warning) 0%, var(--warning-dark) 100%);
}
.btn-blue{
    background:linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
}

.course-grid{ display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:20px; }

.course-card{
    border-radius:14px;
    padding:24px 20px;
    color:#fff;
    text-decoration:none;
    min-height:150px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:10px;
    box-shadow:var(--shadow-lg);
    transition:all .3s;
}
.course-card:hover{
    transform:translateY(-4px);
    box-shadow:var(--shadow-lg);
}

.course-blue{ background:linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); }
.course-yellow{ background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.course-green{ background:linear-gradient(135deg, #60a5fa 0%, #2563eb 100%); }
.course-name{ font-size:20px; font-weight:700; line-height:1.3; }
.course-sub{ font-size:13px; opacity:.9; }
.course-meta{ font-size:11px; font-weight:700; opacity:.85; text-transform:uppercase; letter-spacing:.05em; }

.unit-list{ margin-top:20px; }

.unit-item{
    display:block;
    border:1px solid #cddfff;
    border-radius:14px;
    margin-bottom:12px;
    overflow:hidden;
    background:#fff;
    transition:all .2s;
    text-decoration:none;
    box-shadow:0 8px 18px rgba(37, 99, 235, 0.18);
}
.unit-item.active{
    border:1px solid var(--primary);
    box-shadow:0 10px 22px rgba(37, 99, 235, 0.26);
}
.unit-item:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 22px rgba(37, 99, 235, 0.26);
}

.unit-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:16px 18px;
    background:#fff;
    transition:background .2s;
}
.unit-item.active .unit-header{
    background:#fff;
}

.unit-title{
    font-size:15px;
    font-weight:700;
    color:var(--title);
    margin:0;
}

.unit-status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:92px;
    height:28px;
    padding:0 10px;
    border-radius:999px;
    background:#e9f1ff;
    color:var(--primary-dark);
    font-size:11px;
    font-weight:800;
    letter-spacing:.06em;
    text-transform:uppercase;
    flex-shrink:0;
}
.unit-item.active .unit-status{
    background:linear-gradient(180deg,#3d73ee,#2563eb);
    color:#fff;
}

.empty{ background:#f8fafc; border:1px dashed var(--line); border-radius:14px; padding:32px 24px; text-align:center; color:var(--muted); font-size:15px; }
.badge-row{ display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.badge{
    display:inline-block;
    padding:6px 12px;
    border-radius:999px;
    background:var(--primary-light);
    color:var(--primary-dark);
    font-size:12px;
    font-weight:700;
}

.current-unit-panel{
    margin-top:20px;
    padding:18px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#f8fafc;
}

.current-unit-label{
    display:block;
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--muted);
    margin-bottom:6px;
}

.current-unit-name{
    font-size:18px;
    font-weight:700;
    color:var(--title);
    margin-bottom:14px;
}

.section-note{
    margin:0 0 18px;
    color:var(--muted);
    font-size:14px;
}

.secondary-actions{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
}

@media (max-width: 1024px){
    .layout{ grid-template-columns:1fr; }
    .course-grid{ grid-template-columns:repeat(2, 1fr); }
}

@media (max-width: 768px){
    .page{ padding:20px; }
    .header{ flex-direction:column; align-items:flex-start; gap:12px; }
    .course-grid{ grid-template-columns:1fr; }
    .actions{ flex-direction:column; }
    .secondary-actions{ flex-direction:column; }
    .btn{ width:100%; text-align:center; }
    .profile-meta{ grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <h1>Perfil del Docente</h1>
        <a class="logout-btn" href="logout.php">Cerrar sesión</a>
    </div>

    <div class="layout">
        <aside class="panel">
            <div class="profile-box">
                <div class="avatar">
                    <?php if ($teacherPhotoSrc !== '') { ?>
                        <img
                            class="avatar-image"
                            src="<?php echo $teacherPhotoSrc; ?>"
                            alt="Foto de <?php echo h($teacherName); ?>"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='block';"
                        >
                    <?php } ?>
                    <span class="avatar-fallback" aria-hidden="true" style="<?php echo $teacherPhotoSrc === '' ? 'display:flex;' : ''; ?>"><?php echo h($teacherInitials); ?></span>
                </div>

                <?php if ($flashMessage !== '') { ?>
                    <div class="flash ok"><?php echo h($flashMessage); ?></div>
                <?php } ?>

                <?php if ($flashError !== '') { ?>
                    <div class="flash error"><?php echo h($flashError); ?></div>
                <?php } ?>

                <div class="teacher-name"><?php echo h($teacherName); ?></div>
                <div class="teacher-role">Docente</div>

                <div class="profile-meta">
                    <div class="profile-stat">
                        <span class="profile-stat-label">Cursos</span>
                        <span class="profile-stat-value"><?php echo count($assignments); ?></span>
                    </div>
                    <div class="profile-stat">
                        <span class="profile-stat-label">Permiso</span>
                        <span class="profile-stat-value"><?php echo h($teacherPermission === 'editor' ? 'Editor' : 'Consulta'); ?></span>
                    </div>
                </div>

                <form class="upload-form" method="post" enctype="multipart/form-data">
                    <label class="upload-label" for="teacher_photo">Subir / cambiar foto</label>
                    <input class="upload-input" type="file" id="teacher_photo" name="teacher_photo" accept="image/*" required>
                    <input type="hidden" name="action" value="upload_teacher_photo">
                    <button type="submit" class="upload-btn">Guardar foto</button>
                </form>

                <a class="side-button green" href="teacher_groups.php">Lista de Estudiantes</a>
                <a class="side-button gray" href="teacher_groups.php">Progreso del Estudiante</a>

                <div class="sidebar-section-title">Mis cursos</div>
                <div class="sidebar-course-list">
                    <?php if (empty($assignments)) { ?>
                        <span class="upload-label">No tienes cursos asignados.</span>
                    <?php } else { ?>
                        <?php foreach ($assignments as $assignment) { ?>
                            <?php
                            $assignmentId = (string) ($assignment['id'] ?? '');
                            $isActiveAssignment = $assignmentId === $selectedAssignmentId;
                            $programType = (string) ($assignment['program_type'] ?? '');
                            $courseLabel = $programType === 'english' ? 'English' : 'INGLÉS TÉCNICO';
                            $courseTitle = build_assignment_title($assignment);
                            ?>
                            <a
                                class="sidebar-course-btn<?php echo $isActiveAssignment ? ' active' : ''; ?>"
                                href="dashboard.php?assignment=<?php echo urlencode($assignmentId); ?>#unidades-curso"
                            >
                                <?php echo h($courseLabel); ?> · <?php echo h($courseTitle); ?>
                            </a>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
        </aside>

        <main>
            <h2 class="main-section-title">Actividad para Hoy</h2>

            <?php if ($selectedAssignment) { ?>
                <div class="card hero-card">
                    <div class="hero-content">
                        <div class="activity-topline">
                            <span class="badge"><?php echo h($todayProgramLabel); ?></span>
                            <span class="badge"><?php echo h($teacherPermission === 'editor' ? 'Puede editar' : 'Solo ver'); ?></span>
                        </div>

                        <h3 class="activity-title">Tema: "<?php echo h($todayTitle); ?>"</h3>

                        <p class="activity-text">
                            Ingresa al curso para proyectar las actividades en modo presentación y avanzar con Next. La unidad activa queda destacada abajo para que accedas rápido sin repetir acciones.
                        </p>

                        <div class="actions">
                        <a class="btn btn-green"
                           href="teacher_course.php?assignment=<?php echo urlencode((string) ($selectedAssignment['id'] ?? '')); ?>&unit=<?php echo urlencode($selectedUnitId); ?>">
                            Iniciar Presentación
                        </a>

                        <?php if ($teacherPermission === 'editor') { ?>
                            <a class="btn btn-blue"
                               href="teacher_unit.php?assignment=<?php echo urlencode((string) ($selectedAssignment['id'] ?? '')); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=edit">
                                Editar unidad activa
                            </a>
                        <?php } ?>
                        </div>

                        <?php if ($selectedUnit) { ?>
                            <div class="current-unit-panel">
                                <span class="current-unit-label">Unidad activa</span>
                                <div class="current-unit-name"><?php echo h((string) ($selectedUnit['name'] ?? 'Unidad')); ?></div>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <div class="card" id="unidades-curso">
                    <h3 class="activity-title">Unidades del curso</h3>
                    <p class="section-note">Cada unidad se despliega para mostrar solo las acciones necesarias y mantener el panel más limpio.</p>

                    <?php if (empty($todayUnits)) { ?>
                        <div class="empty">No hay unidades encontradas para esta asignación.</div>
                    <?php } else { ?>
                        <div class="unit-list">
                            <?php foreach ($todayUnits as $unit) { ?>
                                <?php
                                $unitId = (string) ($unit['id'] ?? '');
                                $isActiveUnit = $unitId === $selectedUnitId;
                                ?>
                                <a
                                    class="unit-item<?php echo $isActiveUnit ? ' active' : ''; ?>"
                                    href="dashboard.php?assignment=<?php echo urlencode((string) ($selectedAssignment['id'] ?? '')); ?>&unit=<?php echo urlencode($unitId); ?>#unidades-curso"
                                >
                                    <div class="unit-header">
                                        <h4 class="unit-title"><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></h4>
                                        <span class="unit-status"><?php echo $isActiveUnit ? 'Activa' : 'Disponible'; ?></span>
                                    </div>
                                </a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <div class="empty">No tienes cursos asignados todavía.</div>
            <?php } ?>
        </main>
    </div>
</div>
</body>
</html>

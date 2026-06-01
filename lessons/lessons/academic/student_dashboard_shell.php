<?php
// ─────────────────────────────────────────────
// STEP 1 — PHP LOGIC (from student_dashboard.php)
// ─────────────────────────────────────────────
session_start();
require_once __DIR__ . '/../core/cloudinary_upload.php';

if (!isset($_SESSION['student_logged']) || $_SESSION['student_logged'] !== true) {
    header('Location: login_student.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password'])) {
    header('Location: change_password_student.php');
    exit;
}

$studentId   = trim((string) ($_SESSION['student_id']   ?? ''));
$studentName = trim((string) ($_SESSION['student_name'] ?? 'Student'));
$flashMessage = '';
$flashError   = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function upper_label(string $value): string
{
    $normalized = strtr($value, [
        'á' => 'Á', 'é' => 'É', 'í' => 'Í',
        'ó' => 'Ó', 'ú' => 'Ú', 'ü' => 'Ü', 'ñ' => 'Ñ',
    ]);
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($normalized, 'UTF-8')
        : strtoupper($normalized);
}

function student_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'ST';
    }
    $parts    = preg_split('/\s+/', $name) ?: [];
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
    return $initials !== '' ? $initials : 'ST';
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }
    static $cachedPdo = null;
    static $loaded    = false;
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
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public'
                AND table_name   = :table_name
                AND column_name  = :column_name
              LIMIT 1"
        );
        $stmt->execute(['table_name' => $tableName, 'column_name' => $columnName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_student_photo_column(): void
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE student_accounts ADD COLUMN IF NOT EXISTS student_photo TEXT");
    } catch (Throwable $e) {
    }
}

function upload_student_photo_to_cloud(string $tmpName): string
{
    if ($tmpName === '' || !is_file($tmpName) || !function_exists('upload_to_cloudinary')) {
        return '';
    }
    $cloudName = (string) (getenv('CLOUDINARY_CLOUD_NAME') ?: ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? ''));
    $apiKey    = (string) (getenv('CLOUDINARY_API_KEY')    ?: ($_ENV['CLOUDINARY_API_KEY']    ?? ''));
    $apiSecret = (string) (getenv('CLOUDINARY_API_SECRET') ?: ($_ENV['CLOUDINARY_API_SECRET'] ?? ''));
    if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
        return '';
    }
    try {
        $uploaded = upload_to_cloudinary($tmpName);
        return is_string($uploaded) ? trim($uploaded) : '';
    } catch (Throwable $e) {
        return '';
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
    file_put_contents(
        student_photos_store_file(),
        json_encode($photos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function load_student_photo_from_database(string $studentId): string
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '' || !table_has_column($pdo, 'student_accounts', 'student_photo')) {
        return '';
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT student_photo FROM student_accounts
              WHERE student_id = :student_id
              ORDER BY updated_at DESC NULLS LAST
              LIMIT 1"
        );
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
        $stmt = $pdo->prepare(
            "UPDATE student_accounts SET student_photo = :student_photo WHERE student_id = :student_id"
        );
        $stmt->execute(['student_photo' => $photoPath, 'student_id' => $studentId]);
    } catch (Throwable $e) {
    }
}

function load_student_photo(string $studentId): string
{
    if ($studentId === '') {
        return '';
    }
    $fromDatabase = load_student_photo_from_database($studentId);
    if ($fromDatabase !== '') {
        $store = load_student_photos_store();
        if ((string) ($store[$studentId] ?? '') !== $fromDatabase) {
            $store[$studentId] = $fromDatabase;
            save_student_photos_store($store);
        }
        return $fromDatabase;
    }
    $store     = load_student_photos_store();
    $fromStore = trim((string) ($store[$studentId] ?? ''));
    if ($fromStore !== '') {
        return $fromStore;
    }
    return '';
}

function save_student_photo(string $studentId, string $photoPath): void
{
    if ($studentId === '') {
        return;
    }
    save_student_photo_to_database($studentId, $photoPath);
    $store              = load_student_photos_store();
    $store[$studentId]  = $photoPath;
    save_student_photos_store($store);
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
        $stmt = $pdo->prepare(
            "SELECT permission FROM student_accounts
              WHERE student_id = :student_id
              ORDER BY updated_at DESC NULLS LAST
              LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $permission = (string) $stmt->fetchColumn();
        return $permission === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        return 'viewer';
    }
}

/**
 * Returns the direct quiz-viewer URL for the best qualifying unit in an assignment,
 * or '' if the student has not yet unlocked the quiz for this assignment.
 */
function build_assignment_quiz_href(PDO $pdo, string $studentId, string $assignmentId, array $assignmentRow): string
{
    if ($studentId === '' || $assignmentId === '') {
        return '';
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM teacher_quiz_unlocks
              WHERE student_id = :sid AND assignment_id = :aid LIMIT 1"
        );
        $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
        $unlocked = (bool) $stmt->fetchColumn();

        if (!$unlocked) {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM student_unit_results
                  WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 LIMIT 1"
            );
            $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
            $unlocked = (bool) $stmt->fetchColumn();
        }

        if (!$unlocked) {
            return '';
        }

        $stmt = $pdo->prepare(
            "SELECT unit_id FROM student_unit_results
              WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60
              ORDER BY updated_at DESC NULLS LAST LIMIT 1"
        );
        $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
        $unitId = (string) ($stmt->fetchColumn() ?: '');

        if ($unitId === '') {
            $unitId = (string) ($assignmentRow['unit_id'] ?? '');
        }
        if ($unitId === '') {
            return 'student_course.php?assignment=' . urlencode($assignmentId);
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM activities WHERE unit_id::text = :uid AND type = 'quiz' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute(['uid' => $unitId]);
        $quizActId = (string) ($stmt->fetchColumn() ?: '');

        $returnTo = 'student_course.php?' . http_build_query([
            'assignment' => $assignmentId,
            'unit'       => $unitId,
            'step'       => '9999',
        ]);

        if ($quizActId !== '') {
            return '../activities/quiz/viewer.php?' . http_build_query([
                'id'         => $quizActId,
                'unit'       => $unitId,
                'assignment' => $assignmentId,
                'return_to'  => '../../academic/' . $returnTo,
            ]);
        }
        return $returnTo;
    } catch (Throwable $e) {
        return '';
    }
}

function load_student_assignments(string $studentId): array
{
    $pdo = get_pdo_connection();
    if (!$pdo || $studentId === '') {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT sa.id, sa.teacher_id, sa.course_id, sa.period, sa.unit_id, sa.level_id, sa.program, sa.updated_at,
                   t.name  AS teacher_name,
                   c.name  AS course_name,
                   u.name  AS unit_name,
                   m.name  AS module_name
            FROM student_assignments sa
            LEFT JOIN teachers          t ON t.id         = sa.teacher_id
            LEFT JOIN courses           c ON c.id::text   = sa.course_id
            LEFT JOIN units             u ON u.id::text   = sa.unit_id
            LEFT JOIN technical_modules m ON m.id         = u.module_id
            WHERE sa.student_id = :student_id
            ORDER BY sa.updated_at DESC NULLS LAST, sa.id DESC
        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        // Deduplicate: keep only the most-recent row per (course_id, unit_id) pair.
        $seen   = [];
        $deduped = [];
        foreach ($rows as $row) {
            $key = ($row['course_id'] ?? '') . '|' . ($row['unit_id'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[]  = $row;
        }
        return $deduped;
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
        $stmt = $pdo->prepare("
            SELECT assignment_id,
                   AVG(completion_percent)::numeric(5,2) AS avg_percent,
                   SUM(quiz_errors)                      AS total_errors,
                   SUM(quiz_total)                       AS total_questions
            FROM student_unit_results
            WHERE student_id = :student_id
            GROUP BY assignment_id
        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = [];
        foreach ($rows as $row) {
            $assignmentId = (string) ($row['assignment_id'] ?? '');
            if ($assignmentId === '') {
                continue;
            }
            $summary[$assignmentId] = [
                'avg_percent'      => (int) round((float) ($row['avg_percent']     ?? 0)),
                'total_errors'     => (int) ($row['total_errors']    ?? 0),
                'total_questions'  => (int) ($row['total_questions'] ?? 0),
            ];
        }
        return $summary;
    } catch (Throwable $e) {
        return [];
    }
}

// ── Photo upload POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'upload_student_photo') {
    if ($studentId === '') {
        $flashError = 'Student session was not found.';
    } elseif (!isset($_FILES['student_photo']) || !is_array($_FILES['student_photo'])) {
        $flashError = 'You must select an image.';
    } else {
        $file      = $_FILES['student_photo'];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            $flashError = 'Image upload failed. Please try again.';
        } else {
            $tmpName  = (string) ($file['tmp_name'] ?? '');
            $size     = (int)    ($file['size']     ?? 0);
            $maxBytes = 5 * 1024 * 1024;

            if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0 || $size > $maxBytes) {
                $flashError = 'Image size must be 5 MB or less.';
            } else {
                $mime         = (string) mime_content_type($tmpName);
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];

                if (!isset($allowedMimes[$mime])) {
                    $flashError = 'Unsupported format. Use JPG, PNG, WEBP, or GIF.';
                } else {
                    $oldPhoto      = trim((string) ($_SESSION['student_photo'] ?? load_student_photo($studentId)));
                    $cloudPhotoUrl = upload_student_photo_to_cloud($tmpName);

                    if ($cloudPhotoUrl !== '') {
                        save_student_photo($studentId, $cloudPhotoUrl);
                        $_SESSION['student_photo'] = $cloudPhotoUrl;
                        maybe_delete_local_student_photo($oldPhoto);
                        $flashMessage = 'Photo updated successfully.';
                    } else {
                        $extension        = $allowedMimes[$mime];
                        $safeStudentId    = preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId) ?: 'student';
                        $newFilename      = $safeStudentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                        $newFileAbsolute  = student_photos_directory() . '/' . $newFilename;
                        $newFileRelative  = 'data/student_photos/' . $newFilename;

                        if (!move_uploaded_file($tmpName, $newFileAbsolute)) {
                            $flashError = 'Unable to save the uploaded image.';
                        } else {
                            save_student_photo($studentId, $newFileRelative);
                            $_SESSION['student_photo'] = $newFileRelative;
                            maybe_delete_local_student_photo($oldPhoto);
                            $flashMessage = 'Photo updated successfully.';
                        }
                    }
                }
            }
        }
    }
}

ensure_student_photo_column();

$studentPhoto = trim((string) ($_SESSION['student_photo'] ?? ''));
if ($studentPhoto === '') {
    $studentPhoto = load_student_photo($studentId);
    if ($studentPhoto !== '') {
        $_SESSION['student_photo'] = $studentPhoto;
    }
}

$studentPhotoSrc   = resolve_student_photo_src($studentPhoto);
$studentPermission = load_student_permission($studentId);
$_SESSION['student_permission'] = $studentPermission;
$studentInitials   = student_initials($studentName);
$myAssignments     = load_student_assignments($studentId);
$scoreSummaryByAssignment = load_assignment_score_summary($studentId);

// ── Quiz unlock check for topbar quick-action ─────────────────────────────
$firstAssignmentId = (string) (($myAssignments[0] ?? [])['id'] ?? '');
$quizUnlocked = false;
$quizGoHref   = '';

if ($firstAssignmentId !== '') {
    $pdoQuiz = get_pdo_connection();
    if ($pdoQuiz) {
        try {
            $qStmt = $pdoQuiz->prepare(
                "SELECT 1 FROM teacher_quiz_unlocks
                  WHERE student_id = :sid AND assignment_id = :aid LIMIT 1"
            );
            $qStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
            $quizUnlocked = (bool) $qStmt->fetchColumn();

            if (!$quizUnlocked) {
                $scoreStmt = $pdoQuiz->prepare(
                    "SELECT 1 FROM student_unit_results
                      WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 LIMIT 1"
                );
                $scoreStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
                $quizUnlocked = (bool) $scoreStmt->fetchColumn();
            }

            if ($quizUnlocked) {
                $unitStmt = $pdoQuiz->prepare(
                    "SELECT unit_id FROM student_unit_results
                      WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60
                      ORDER BY updated_at DESC NULLS LAST LIMIT 1"
                );
                $unitStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
                $qualUnitId = (string) ($unitStmt->fetchColumn() ?: '');

                if ($qualUnitId === '') {
                    $qualUnitId = (string) (($myAssignments[0] ?? [])['unit_id'] ?? '');
                }

                if ($qualUnitId !== '') {
                    $actStmt = $pdoQuiz->prepare(
                        "SELECT id FROM activities WHERE unit_id::text = :uid AND type = 'quiz' ORDER BY id ASC LIMIT 1"
                    );
                    $actStmt->execute(['uid' => $qualUnitId]);
                    $quizActId = (string) ($actStmt->fetchColumn() ?: '');

                    $returnTo = 'student_course.php?' . http_build_query([
                        'assignment' => $firstAssignmentId,
                        'unit'       => $qualUnitId,
                        'step'       => '9999',
                    ]);

                    if ($quizActId !== '') {
                        $quizGoHref = '../activities/quiz/viewer.php?' . http_build_query([
                            'id'         => $quizActId,
                            'unit'       => $qualUnitId,
                            'assignment' => $firstAssignmentId,
                            'return_to'  => '../../academic/' . $returnTo,
                        ]);
                    } else {
                        $quizGoHref = $returnTo;
                    }
                } else {
                    $quizGoHref = 'student_course.php?assignment=' . urlencode($firstAssignmentId);
                }
            }
        } catch (Throwable $e) {
        }
    }
}

// ── Build per-program course arrays (Steps 5–9) ───────────────────────────
$pdoCards        = get_pdo_connection();
$english_courses  = [];
$technical_courses = [];

foreach ($myAssignments as $assignment) {
    $aId          = (string) ($assignment['id']      ?? '');
    $program      = (string) ($assignment['program'] ?? 'technical');
    $scoreSummary = $scoreSummaryByAssignment[$aId] ?? [];
    $quizHref     = $pdoCards ? build_assignment_quiz_href($pdoCards, $studentId, $aId, $assignment) : '';

    $course = [
        'id'              => $aId,
        'period'          => (string) ($assignment['period']       ?? ''),
        'unit_name'       => trim((string) ($assignment['unit_name']    ?? '')),
        'teacher_name'    => trim((string) ($assignment['teacher_name'] ?? 'Teacher')),
        'course_name'     => trim((string) ($assignment['course_name']  ?? 'Course')),
        'average_score'   => (int) ($scoreSummary['avg_percent']     ?? 0),
        'score'           => (int) ($scoreSummary['avg_percent']     ?? 0),
        'errors'          => (int) ($scoreSummary['total_errors']    ?? 0),
        'total_questions' => (int) ($scoreSummary['total_questions'] ?? 0),
        'has_quiz'        => $quizHref !== '',
        'quiz_href'       => $quizHref,
    ];

    if ($program === 'english') {
        $english_courses[] = $course;
    } else {
        $technical_courses[] = $course;
    }
}

// ── Step 7 — Progress metrics ─────────────────────────────────────────────
$all_courses     = array_merge($english_courses, $technical_courses);
$total_score     = array_sum(array_column($all_courses, 'score'));
$max_score       = count($all_courses) * 100;
$avg_score       = $total_score / max(count($all_courses), 1);
$total_errors    = array_sum(array_column($all_courses, 'errors'));
$total_questions = array_sum(array_column($all_courses, 'total_questions'));
$error_rate      = round($total_errors / max($total_questions, 1) * 100, 1);
$perfect_units   = count(array_filter($all_courses, fn($c) => (int) $c['score'] === 100));

// English teacher label for grid-meta
$english_teacher = !empty($english_courses) ? h($english_courses[0]['teacher_name']) : '';
$technical_teacher = !empty($technical_courses) ? h($technical_courses[0]['teacher_name']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Dashboard</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap');
/* Tabler Icons CDN */
@import url('https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css');

:root{
    --bg:#f8f7ff;
    --card:#ffffff;
    --line:#E8E6F8;
    --title:#7F77DD;
    --text:#271B5D;
    --muted:#9B94BE;
    --orange:#F97316;
    --orange-dark:#C2580A;
    --purple:#7F77DD;
    --purple-dark:#534AB7;
    --green:#3B6D11;
    --danger:#E24B4A;
    --soft:#EEEDFE;
    --shadow:0 8px 40px rgba(127,119,221,.12);
    --shadow-sm:0 4px 14px rgba(127,119,221,.09);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito','Segoe UI',sans-serif;background:var(--bg);color:var(--text);}

/* ── TOPBAR ── */
.topbar{
    display:flex;align-items:center;justify-content:space-between;
    gap:16px;padding:12px 28px;
    background:var(--card);border-bottom:1px solid var(--line);
    box-shadow:var(--shadow-sm);position:sticky;top:0;z-index:100;
}
.topbar-brand{
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:22px;font-weight:700;color:var(--orange);
    text-decoration:none;
}
.topbar-tabs{display:flex;gap:6px;}
.tab-btn{
    border:none;background:none;cursor:pointer;
    padding:8px 18px;border-radius:999px;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:13px;font-weight:900;color:var(--muted);
    transition:background .2s,color .2s;
}
.tab-btn.active,.tab-btn:hover{background:var(--soft);color:var(--purple-dark);}
.topbar-right{display:flex;align-items:center;gap:14px;}
.topbar-student-info{text-align:right;}
.topbar-student-name{font-size:14px;font-weight:800;color:var(--text);}
.topbar-student-id{font-size:11px;color:var(--muted);font-weight:700;}
.profile-avatar{
    width:38px;height:38px;border-radius:50%;overflow:hidden;
    background:linear-gradient(180deg,#7F77DD,#534AB7);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-size:15px;font-weight:800;border:2px solid var(--soft);
    flex-shrink:0;
}
.profile-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
.btn-logout{
    display:inline-block;text-decoration:none;color:#fff;
    font-size:12px;font-weight:900;border-radius:999px;padding:8px 14px;
    background:var(--purple);box-shadow:0 4px 14px rgba(127,119,221,.2);
    transition:filter .2s,transform .15s;border:none;cursor:pointer;
    font-family:'Nunito','Segoe UI',sans-serif;
}
.btn-logout:hover{filter:brightness(1.07);transform:translateY(-1px);}

/* ── LAYOUT ── */
.page{max-width:1440px;margin:0 auto;padding:24px 20px 48px;display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;}
@media(max-width:1024px){.page{grid-template-columns:1fr;}}

/* ── SIDEBAR ── */
.sidebar{
    background:var(--card);border:1px solid var(--line);
    border-radius:24px;box-shadow:var(--shadow);padding:22px 18px;
    position:sticky;top:68px;
}
.sidebar-avatar{
    width:90px;height:90px;border-radius:50%;overflow:hidden;
    background:linear-gradient(180deg,#7F77DD,#534AB7);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-size:32px;font-weight:800;border:3px solid var(--soft);
    box-shadow:0 6px 18px rgba(127,119,221,.2);margin:0 auto 12px;
}
.sidebar-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
.sidebar-name{font-size:17px;font-weight:700;color:var(--orange);font-family:'Fredoka','Trebuchet MS',sans-serif;text-align:center;margin-bottom:2px;}
.sidebar-role{font-size:12px;color:var(--muted);font-weight:700;text-align:center;margin-bottom:14px;}
.sidebar-stats{display:flex;justify-content:center;gap:18px;margin-bottom:16px;}
.stat-item{text-align:center;}
.stat-value{font-size:20px;font-weight:800;color:var(--purple);}
.stat-label{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;}
.upload-form{text-align:left;margin:8px 0 4px;}
.upload-label{display:block;font-size:10px;color:var(--muted);font-weight:900;margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em;}
.upload-input{width:100%;margin-bottom:6px;padding:7px;border:1px solid var(--line);border-radius:8px;font-size:12px;}
.upload-btn{
    width:100%;border:none;border-radius:999px;padding:9px;color:#fff;cursor:pointer;
    font-size:12px;font-weight:900;background:var(--purple);
    box-shadow:0 4px 14px rgba(127,119,221,.2);transition:filter .2s;
    font-family:'Nunito','Segoe UI',sans-serif;
}
.upload-btn:hover{filter:brightness(1.07);}
.sidebar-section-title{margin:16px 0 8px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);}
.side-button{
    display:block;width:100%;margin-top:7px;padding:11px 14px;
    border-radius:999px;text-decoration:none;font-size:12px;font-weight:900;
    color:#fff;background:var(--purple);text-align:center;
    transition:filter .2s,transform .15s;
    box-shadow:0 4px 14px rgba(127,119,221,.18);
    border:none;cursor:pointer;font-family:'Nunito','Segoe UI',sans-serif;line-height:1;
}
.side-button:hover{filter:brightness(1.07);transform:translateY(-1px);}
.side-button.orange{background:var(--orange);box-shadow:0 4px 14px rgba(249,115,22,.2);}
.side-button.locked{background:linear-gradient(180deg,#b0b0b0,#888);cursor:default;opacity:.78;box-shadow:none;}
.side-button.locked:hover{filter:none;transform:none;}
.notice{padding:10px 12px;border-radius:10px;margin-bottom:14px;font-weight:800;font-size:13px;}
.notice-ok{background:#ebfff0;border:1px solid #c2efcf;color:#1b7a39;}
.notice-error{background:#fff0f0;border:1px solid #f3c4c4;color:#b42323;}

/* ── MAIN CONTENT ── */
.main-content{}
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* ── COURSE GRID ── */
.grid-meta{font-size:12px;color:var(--muted);font-weight:800;margin-bottom:14px;}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px;}
.card{
    background:var(--card);border:1px solid var(--line);
    border-radius:20px;padding:18px;box-shadow:var(--shadow-sm);
    display:flex;flex-direction:column;gap:8px;
}
.course-badge{
    display:inline-block;padding:3px 10px;border-radius:999px;
    font-size:10px;font-weight:900;letter-spacing:.06em;margin-bottom:2px;
}
.badge-english{background:#EEF2FF;color:#4338CA;border:1px solid #C7D2FE;}
.badge-technical{background:#FFF7ED;color:#C2580A;border:1px solid #FED7AA;}
.course-unit{font-size:17px;font-weight:800;color:var(--text);line-height:1.25;}
.course-teacher{font-size:13px;color:var(--muted);font-weight:700;}
.score-bar{height:6px;background:#F0EEF8;border-radius:999px;overflow:hidden;margin-top:2px;}
.score-bar-fill{height:100%;border-radius:999px;transition:width .4s;}
.score-pct{font-size:22px;font-weight:800;font-family:'Fredoka','Trebuchet MS',sans-serif;}
.errors-chip{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;
}
.chip-good{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.chip-warn{background:#FFF7ED;color:#9A3412;border:1px solid #FED7AA;}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
.btn{
    display:inline-block;padding:9px 15px;text-decoration:none;border-radius:999px;
    font-weight:900;font-size:12px;border:none;cursor:pointer;
    box-shadow:0 4px 12px rgba(249,115,22,.2);transition:filter .2s,transform .15s;
    background:var(--orange);color:#fff;
}
.btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
.btn.secondary{background:var(--purple);box-shadow:0 4px 12px rgba(127,119,221,.2);}
.btn.quiz-btn{background:linear-gradient(180deg,#22c55e,#15803d);box-shadow:0 4px 12px rgba(22,163,74,.2);}
.empty-state{
    background:var(--card);border:1px solid var(--line);border-radius:20px;
    padding:40px;text-align:center;color:var(--muted);font-weight:700;box-shadow:var(--shadow-sm);
}

/* ── PROGRESS TAB ── */
.hero-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:28px;}
.hero-card{
    background:var(--card);border:1px solid var(--line);border-radius:20px;
    padding:20px 18px;box-shadow:var(--shadow-sm);
}
.hero-label{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:6px;}
.hero-value{font-size:26px;font-weight:800;color:var(--text);font-family:'Fredoka','Trebuchet MS',sans-serif;line-height:1;}
.hero-sub{font-size:12px;color:var(--muted);font-weight:700;margin-top:4px;}
.hero-bar{height:5px;background:#F0EEF8;border-radius:999px;overflow:hidden;margin-top:10px;}
.hero-bar-fill{height:100%;border-radius:999px;background:var(--purple);}

/* ── UNIT TABLE ── */
.section-title{font-size:15px;font-weight:900;color:var(--text);margin-bottom:12px;font-family:'Fredoka','Trebuchet MS',sans-serif;}
.unit-table{width:100%;border-collapse:collapse;font-size:13px;}
.unit-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);border-bottom:2px solid var(--line);}
.unit-table td{padding:10px 12px;border-bottom:1px solid var(--line);font-weight:700;vertical-align:middle;}
.unit-table tr:last-child td{border-bottom:none;}
.score-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:900;}
.score-green{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.score-orange{background:#FFF7ED;color:#9A3412;border:1px solid #FED7AA;}
.score-red{background:#FFF1F2;color:#9F1239;border:1px solid #FECDD3;}
.total-row td{font-weight:900;background:var(--soft);color:var(--purple-dark);}

/* ── STRENGTHS & WEAKNESSES ── */
.sw-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px;}
@media(max-width:700px){.sw-grid{grid-template-columns:1fr;}}
.sw-card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:var(--shadow-sm);}
.sw-card-title{font-size:13px;font-weight:900;margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.sw-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);}
.sw-item:last-child{border-bottom:none;}
.sw-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.sw-dot.green{background:#3B6D11;}
.sw-dot.orange{background:#F97316;}
.sw-name{font-size:13px;font-weight:800;flex:1;color:var(--text);}
.sw-stat{font-size:12px;font-weight:700;color:var(--muted);}
</style>
</head>
<body>

<!-- ── TOPBAR (Step 2) ─────────────────────────────────────────────────── -->
<header class="topbar">
    <a class="topbar-brand" href="student_dashboard_shell.php">ONES</a>

    <!-- STEP 4 — Phase tab visibility -->
    <nav class="topbar-tabs">
        <button class="tab-btn active" onclick="switchTab('english')">Inglés</button>
        <?php if (!empty($technical_courses)): ?>
        <button class="tab-btn" onclick="switchTab('technical')">Técnico</button>
        <?php endif; ?>
        <button class="tab-btn" onclick="switchTab('progress')">Progreso</button>
    </nav>

    <div class="topbar-right">
        <div class="topbar-student-info">
            <div class="topbar-student-name"><?= h($studentName) ?></div>
            <div class="topbar-student-id"><?= h($studentId) ?></div>
        </div>
        <div class="profile-avatar">
            <?php if ($studentPhotoSrc !== ''): ?>
                <img src="<?= $studentPhotoSrc ?>" alt="Foto de perfil">
            <?php else: ?>
                <?= h($studentInitials) ?>
            <?php endif; ?>
        </div>
        <a class="btn-logout" href="logout.php">Salir</a>
    </div>
</header>

<!-- ── PAGE BODY ──────────────────────────────────────────────────────── -->
<div class="page">

    <!-- ── SIDEBAR (Steps 3 & 10) ──────────────────────────────────────── -->
    <aside class="sidebar">
        <div class="sidebar-avatar">
            <?php if ($studentPhotoSrc !== ''): ?>
                <img src="<?= $studentPhotoSrc ?>" alt="Foto de perfil">
            <?php else: ?>
                <?= h($studentInitials) ?>
            <?php endif; ?>
        </div>
        <div class="sidebar-name"><?= h($studentName) ?></div>
        <div class="sidebar-role">Estudiante</div>

        <!-- STEP 3 — Sidebar stats -->
        <div class="sidebar-stats">
            <div class="stat-item">
                <div class="stat-value"><?= count($english_courses) + count($technical_courses) ?></div>
                <div class="stat-label">Cursos</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= round($avg_score) ?>%</div>
                <div class="stat-label">Promedio</div>
            </div>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="notice notice-ok"><?= h($flashMessage) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="notice notice-error"><?= h($flashError) ?></div>
        <?php endif; ?>

        <!-- Photo upload form (original field names preserved) -->
        <form method="post" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="action" value="upload_student_photo">
            <label class="upload-label">Foto de perfil</label>
            <input type="file" name="student_photo" class="upload-input" accept="image/jpeg,image/png,image/webp,image/gif" required>
            <button class="upload-btn" type="submit">Actualizar foto</button>
        </form>

        <!-- STEP 10 — Sidebar quick actions -->
        <div class="sidebar-section-title">Acciones rápidas</div>

        <?php if ($quizUnlocked && $quizGoHref !== ''): ?>
            <a class="side-button" href="<?= h($quizGoHref) ?>">Ir al Quiz</a>
        <?php else: ?>
            <span class="side-button locked" title="Pide a tu profesor que desbloquee el quiz">Quiz bloqueado 🔒</span>
        <?php endif; ?>

        <a class="side-button orange" href="change_password_student.php">Cambiar clave</a>
        <a class="side-button" href="logout.php">Cerrar sesión</a>
    </aside>

    <!-- ── MAIN CONTENT ─────────────────────────────────────────────────── -->
    <main class="main-content">

        <!-- ═══════════════════════════════════════
             STEP 5 — ENGLISH COURSE CARDS (#tab-english)
             ═══════════════════════════════════════ -->
        <div id="tab-english" class="tab-panel active">
            <p class="grid-meta">
                <?= count($english_courses) ?> cursos · Teacher: <?= $english_teacher ?>
            </p>

            <?php if (empty($english_courses)): ?>
                <div class="empty-state">No tienes cursos de inglés asignados.</div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($english_courses as $course):
                        $score      = (int) $course['average_score'];
                        $bar_color  = $score >= 90 ? '#3B6D11' : '#F97316';
                        $pct_color  = $bar_color;
                        $chip_class = ($score >= 90 && (int) $course['errors'] === 0) ? 'chip-good' : 'chip-warn';
                        $chip_icon  = $chip_class === 'chip-good' ? 'ti-circle-check' : 'ti-alert-triangle';
                    ?>
                        <div class="card">
                            <span class="course-badge badge-english">INGLÉS · P<?= h($course['period']) ?></span>
                            <div class="course-unit"><?= h($course['unit_name'] !== '' ? $course['unit_name'] : $course['course_name']) ?></div>
                            <div class="course-teacher">Teacher: <b><?= h($course['teacher_name']) ?></b></div>
                            <div class="score-bar">
                                <div class="score-bar-fill" style="width:<?= $score ?>%; background:<?= $bar_color ?>;"></div>
                            </div>
                            <div class="score-pct" style="color:<?= $pct_color ?>;"><?= $score ?>%</div>
                            <span class="errors-chip <?= $chip_class ?>">
                                <i class="ti <?= $chip_icon ?>"></i>
                                <?= $course['errors'] ?> / <?= $course['total_questions'] ?>
                            </span>
                            <div class="actions">
                                <a class="btn" href="student_course.php?assignment=<?= urlencode($course['id']) ?>">Entrar</a>
                                <?php if ($course['has_quiz']): ?>
                                    <a class="btn quiz-btn" href="<?= h($course['quiz_href']) ?>">Quiz</a>
                                <?php endif; ?>
                                <a class="btn secondary" href="student_quiz.php?assignment=<?= urlencode($course['id']) ?>">Puntajes</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════
             STEP 6 — TECHNICAL COURSE CARDS (#tab-technical)
             ═══════════════════════════════════════ -->
        <?php if (!empty($technical_courses)): ?>
        <div id="tab-technical" class="tab-panel">
            <p class="grid-meta">
                <?= count($technical_courses) ?> cursos · Teacher: <?= $technical_teacher ?>
            </p>
            <div class="grid">
                <?php foreach ($technical_courses as $course):
                    $score      = (int) $course['average_score'];
                    $bar_color  = $score >= 90 ? '#3B6D11' : '#F97316';
                    $pct_color  = $bar_color;
                    $chip_class = ($score >= 90 && (int) $course['errors'] === 0) ? 'chip-good' : 'chip-warn';
                    $chip_icon  = $chip_class === 'chip-good' ? 'ti-circle-check' : 'ti-alert-triangle';
                ?>
                    <div class="card">
                        <span class="course-badge badge-technical">TÉCNICO · P<?= h($course['period']) ?></span>
                        <div class="course-unit"><?= h($course['unit_name'] !== '' ? $course['unit_name'] : $course['course_name']) ?></div>
                        <div class="course-teacher">Teacher: <b><?= h($course['teacher_name']) ?></b></div>
                        <div class="score-bar">
                            <div class="score-bar-fill" style="width:<?= $score ?>%; background:<?= $bar_color ?>;"></div>
                        </div>
                        <div class="score-pct" style="color:<?= $pct_color ?>;"><?= $score ?>%</div>
                        <span class="errors-chip <?= $chip_class ?>">
                            <i class="ti <?= $chip_icon ?>"></i>
                            <?= $course['errors'] ?> / <?= $course['total_questions'] ?>
                        </span>
                        <div class="actions">
                            <a class="btn" href="student_course.php?assignment=<?= urlencode($course['id']) ?>">Entrar</a>
                            <?php if ($course['has_quiz']): ?>
                                <a class="btn quiz-btn" href="<?= h($course['quiz_href']) ?>">Quiz</a>
                            <?php endif; ?>
                            <a class="btn secondary" href="student_quiz.php?assignment=<?= urlencode($course['id']) ?>">Puntajes</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div id="tab-technical" class="tab-panel">
            <div class="empty-state">No tienes cursos técnicos asignados.</div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════
             STEPS 7–9 — PROGRESS TAB (#tab-progress)
             ═══════════════════════════════════════ -->
        <div id="tab-progress" class="tab-panel">

            <!-- STEP 7 — Hero metrics -->
            <div class="hero-grid">
                <div class="hero-card">
                    <div class="hero-label">Promedio general</div>
                    <div class="hero-value"><?= round($avg_score) ?>%</div>
                    <div class="hero-bar">
                        <div class="hero-bar-fill" style="width:<?= round($avg_score) ?>%;"></div>
                    </div>
                </div>
                <div class="hero-card">
                    <div class="hero-label">Puntaje total</div>
                    <div class="hero-value"><?= $total_score ?> / <?= $max_score ?></div>
                    <div class="hero-bar">
                        <div class="hero-bar-fill" style="width:<?= $max_score > 0 ? round($total_score / $max_score * 100) : 0 ?>%;"></div>
                    </div>
                </div>
                <div class="hero-card">
                    <div class="hero-label">Errores totales</div>
                    <div class="hero-value"><?= $total_errors ?> / <?= $total_questions ?></div>
                    <div class="hero-sub">Tasa de error: <?= $error_rate ?>%</div>
                    <div class="hero-bar">
                        <div class="hero-bar-fill" style="width:<?= $error_rate ?>%; background:#F97316;"></div>
                    </div>
                </div>
                <div class="hero-card">
                    <div class="hero-label">Unidades perfectas</div>
                    <div class="hero-value"><?= $perfect_units ?> / <?= count($all_courses) ?></div>
                    <div class="hero-bar">
                        <div class="hero-bar-fill" style="width:<?= count($all_courses) > 0 ? round($perfect_units / count($all_courses) * 100) : 0 ?>%; background:#3B6D11;"></div>
                    </div>
                </div>
            </div>

            <!-- STEP 8 — Unit table (sorted by score DESC) -->
            <?php if (!empty($all_courses)):
                $sorted_courses = $all_courses;
                usort($sorted_courses, fn($a, $b) => $b['score'] <=> $a['score']);
            ?>
            <div class="section-title">Detalle por unidad</div>
            <table class="unit-table">
                <thead>
                    <tr>
                        <th>Unidad</th>
                        <th>Período</th>
                        <th>Puntaje</th>
                        <th>Errores</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sorted_courses as $c):
                        $s    = (int) $c['score'];
                        $bar  = $s >= 90 ? '#3B6D11' : ($s >= 80 ? '#F97316' : '#E24B4A');
                        $pill = $s >= 90 ? 'score-green' : ($s >= 80 ? 'score-orange' : 'score-red');
                        $icon = $s === 100 ? 'ti-star' : ($s >= 85 ? 'ti-circle-check' : 'ti-alert-circle');
                        $icol = $s === 100 ? '#F97316' : ($s >= 85 ? '#3B6D11' : '#F97316');
                    ?>
                        <tr>
                            <td><?= h($c['unit_name'] !== '' ? $c['unit_name'] : $c['course_name']) ?></td>
                            <td><?= h($c['period']) ?></td>
                            <td>
                                <div class="score-bar" style="width:80px;display:inline-block;vertical-align:middle;margin-right:8px;">
                                    <div class="score-bar-fill" style="width:<?= $s ?>%; background:<?= $bar ?>;"></div>
                                </div>
                                <span class="score-pill <?= $pill ?>"><?= $s ?>%</span>
                            </td>
                            <td><?= $c['errors'] ?> / <?= $c['total_questions'] ?></td>
                            <td><i class="ti <?= $icon ?>" style="color:<?= $icol ?>;font-size:16px;"></i></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2">Total</td>
                        <td><?= $total_score ?> / <?= $max_score ?> pts · <?= round($avg_score) ?>% promedio</td>
                        <td colspan="2"><?= $total_errors ?> errores</td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- STEP 9 — Strengths & Weaknesses -->
            <?php if (!empty($all_courses)):
                // Top 4 by score (strengths)
                $sw_courses = $all_courses;
                usort($sw_courses, fn($a, $b) => $b['score'] <=> $a['score']);
                $strengths = array_slice($sw_courses, 0, 4);

                // Bottom 4 by score (weaknesses)
                usort($sw_courses, fn($a, $b) => $a['score'] <=> $b['score']);
                $weaknesses = array_slice($sw_courses, 0, 4);
            ?>
            <div class="sw-grid">
                <div class="sw-card">
                    <div class="sw-card-title">
                        <span style="color:#3B6D11;">✦</span> Fortalezas
                    </div>
                    <?php foreach ($strengths as $item): ?>
                        <div class="sw-item">
                            <span class="sw-dot green"></span>
                            <span class="sw-name"><?= h($item['unit_name'] !== '' ? $item['unit_name'] : $item['course_name']) ?></span>
                            <span class="sw-stat"><?= $item['score'] ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="sw-card">
                    <div class="sw-card-title">
                        <span style="color:#F97316;">⚠</span> Áreas a mejorar
                    </div>
                    <?php foreach ($weaknesses as $item): ?>
                        <div class="sw-item">
                            <span class="sw-dot orange"></span>
                            <span class="sw-name"><?= h($item['unit_name'] !== '' ? $item['unit_name'] : $item['course_name']) ?></span>
                            <span class="sw-stat"><?= $item['errors'] ?> / <?= $item['total_questions'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /#tab-progress -->

    </main>
</div><!-- /.page -->

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(function(p) {
        p.classList.remove('active');
    });
    document.querySelectorAll('.tab-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    var panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    event.currentTarget.classList.add('active');
}
</script>
</body>
</html>

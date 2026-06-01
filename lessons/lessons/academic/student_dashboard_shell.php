
<?php
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

$studentId = trim((string) ($_SESSION['student_id'] ?? ''));
$studentName = trim((string) ($_SESSION['student_name'] ?? 'Student'));
$flashMessage = '';
$flashError = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function upper_label(string $value): string
{
    $normalized = strtr($value, [
        'á' => 'Á',
        'é' => 'É',
        'í' => 'Í',
        'ó' => 'Ó',
        'ú' => 'Ú',
        'ü' => 'Ü',
        'ñ' => 'Ñ',
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

    return $initials !== '' ? $initials : 'ST';
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
    $apiKey = (string) (getenv('CLOUDINARY_API_KEY') ?: ($_ENV['CLOUDINARY_API_KEY'] ?? ''));
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

    $fromDatabase = load_student_photo_from_database($studentId);
    if ($fromDatabase !== '') {
        $store = load_student_photos_store();
        if ((string) ($store[$studentId] ?? '') !== $fromDatabase) {
            $store[$studentId] = $fromDatabase;
            save_student_photos_store($store);
        }
        return $fromDatabase;
    }

    $store = load_student_photos_store();
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

    $store = load_student_photos_store();
    $store[$studentId] = $photoPath;
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
        $stmt = $pdo->prepare("SELECT permission FROM student_accounts WHERE student_id = :student_id ORDER BY updated_at DESC NULLS LAST LIMIT 1");
        $stmt->execute(['student_id' => $studentId]);
        $permission = (string) $stmt->fetchColumn();
        return $permission === 'editor' ? 'editor' : 'viewer';
    } catch (Throwable $e) {
        return 'viewer';
    }
}

function build_assignment_quiz_href(PDO $pdo, string $studentId, string $assignmentId, array $assignmentRow): string
{
    if ($studentId === '' || $assignmentId === '') return '';

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM teacher_quiz_unlocks WHERE student_id = :sid AND assignment_id = :aid LIMIT 1");
        $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
        $unlocked = (bool) $stmt->fetchColumn();

        if (!$unlocked) {
            $stmt = $pdo->prepare("SELECT 1 FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 LIMIT 1");
            $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
            $unlocked = (bool) $stmt->fetchColumn();
        }

        if (!$unlocked) return '';

        $stmt = $pdo->prepare("SELECT unit_id FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 ORDER BY updated_at DESC NULLS LAST LIMIT 1");
        $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
        $unitId = (string) ($stmt->fetchColumn() ?: '');

        if ($unitId === '') {
            $unitId = (string) ($assignmentRow['unit_id'] ?? '');
        }
        if ($unitId === '') return 'student_course.php?assignment=' . urlencode($assignmentId);

        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id::text = :uid AND type = 'quiz' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['uid' => $unitId]);
        $quizActId = (string) ($stmt->fetchColumn() ?: '');

        $returnTo = 'student_course.php?' . http_build_query(['assignment' => $assignmentId, 'unit' => $unitId, 'step' => '9999']);

        if ($quizActId !== '') {
            return '../activities/quiz/viewer.php?' . http_build_query([
                'id' => $quizActId,
                'unit' => $unitId,
                'assignment' => $assignmentId,
                'return_to' => '../../academic/' . $returnTo,
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
                   t.name AS teacher_name,
                   c.name AS course_name,
                   u.name AS unit_name,
                   m.name AS module_name
            FROM student_assignments sa
            LEFT JOIN teachers t ON t.id = sa.teacher_id
            LEFT JOIN courses c ON c.id::text = sa.course_id
            LEFT JOIN units u ON u.id::text = sa.unit_id
            LEFT JOIN technical_modules m ON m.id = u.module_id
            WHERE sa.student_id = :student_id
            ORDER BY sa.updated_at DESC NULLS LAST, sa.id DESC
        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            $key = ($row['course_id'] ?? '') . '|' . ($row['unit_id'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $deduped[] = $row;
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
                   SUM(quiz_errors) AS total_errors,
                   SUM(quiz_total) AS total_questions
            FROM student_unit_results
            WHERE student_id = :student_id
            GROUP BY assignment_id
        ");
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
        $flashError = 'Student session was not found.';
    } elseif (!isset($_FILES['student_photo']) || !is_array($_FILES['student_photo'])) {
        $flashError = 'You must select an image.';
    } else {
        $file = $_FILES['student_photo'];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            $flashError = 'Image upload failed. Please try again.';
        } else {
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            $maxBytes = 5 * 1024 * 1024;
            if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0 || $size > $maxBytes) {
                $flashError = 'Image size must be 5 MB or less.';
            } else {
                $mime = (string) mime_content_type($tmpName);
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                ];
                if (!isset($allowedMimes[$mime])) {
                    $flashError = 'Unsupported format. Use JPG, PNG, WEBP, or GIF.';
                } else {
                    $oldPhoto = trim((string) ($_SESSION['student_photo'] ?? load_student_photo($studentId)));
                    $cloudPhotoUrl = upload_student_photo_to_cloud($tmpName);
                    if ($cloudPhotoUrl !== '') {
                        save_student_photo($studentId, $cloudPhotoUrl);
                        $_SESSION['student_photo'] = $cloudPhotoUrl;
                        maybe_delete_local_student_photo($oldPhoto);
                        $flashMessage = 'Photo updated successfully.';
                    } else {
                        $extension = $allowedMimes[$mime];
                        $safeStudentId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId) ?: 'student';
                        $newFilename = $safeStudentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                        $newFileAbsolute = student_photos_directory() . '/' . $newFilename;
                        $newFileRelative = 'data/student_photos/' . $newFilename;
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

$studentPhotoSrc = resolve_student_photo_src($studentPhoto);
$studentPermission = load_student_permission($studentId);
$_SESSION['student_permission'] = $studentPermission;
$studentInitials = student_initials($studentName);
$myAssignments = load_student_assignments($studentId);
$scoreSummaryByAssignment = load_assignment_score_summary($studentId);

$firstAssignmentId = (string) (($myAssignments[0] ?? [])['id'] ?? '');
$quizUnlocked = false;
$quizGoHref = '';
if ($firstAssignmentId !== '') {
    $pdoQuiz = get_pdo_connection();
    if ($pdoQuiz) {
        try {
            $qStmt = $pdoQuiz->prepare("SELECT 1 FROM teacher_quiz_unlocks WHERE student_id = :sid AND assignment_id = :aid LIMIT 1");
            $qStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
            $quizUnlocked = (bool) $qStmt->fetchColumn();
            if (!$quizUnlocked) {
                $scoreStmt = $pdoQuiz->prepare("SELECT 1 FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 LIMIT 1");
                $scoreStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
                $quizUnlocked = (bool) $scoreStmt->fetchColumn();
            }
            if ($quizUnlocked) {
                $unitStmt = $pdoQuiz->prepare("SELECT unit_id FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 ORDER BY updated_at DESC NULLS LAST LIMIT 1");
                $unitStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
                $qualUnitId = (string) ($unitStmt->fetchColumn() ?: '');
                if ($qualUnitId === '') {
                    $qualUnitId = (string) (($myAssignments[0] ?? [])['unit_id'] ?? '');
                }
                if ($qualUnitId !== '') {
                    $actStmt = $pdoQuiz->prepare("SELECT id FROM activities WHERE unit_id::text = :uid AND type = 'quiz' ORDER BY id ASC LIMIT 1");
                    $actStmt->execute(['uid' => $qualUnitId]);
                    $quizActId = (string) ($actStmt->fetchColumn() ?: '');
                    $returnTo = 'student_course.php?' . http_build_query(['assignment' => $firstAssignmentId, 'unit' => $qualUnitId, 'step' => '9999']);
                    if ($quizActId !== '') {
                        $quizGoHref = '../activities/quiz/viewer.php?' . http_build_query([
                            'id' => $quizActId,
                            'unit' => $qualUnitId,
                            'assignment' => $firstAssignmentId,
                            'return_to' => '../../academic/' . $returnTo,
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

$englishCourses = [];
$technicalCourses = [];
$teacherNames = [];
$pdoCard = get_pdo_connection();

foreach ($myAssignments as $assignment) {
    $assignmentId = (string) ($assignment['id'] ?? '');
    $program = strtolower(trim((string) ($assignment['program'] ?? 'technical')));
    $program = $program === 'english' ? 'english' : 'technical';
    $scoreSummary = $scoreSummaryByAssignment[$assignmentId] ?? ['avg_percent' => 0, 'total_errors' => 0, 'total_questions' => 0];
    $score = (int) ($scoreSummary['avg_percent'] ?? 0);
    $errors = (int) ($scoreSummary['total_errors'] ?? 0);
    $totalQuestions = (int) ($scoreSummary['total_questions'] ?? 0);
    $teacherName = trim((string) ($assignment['teacher_name'] ?? 'Teacher'));
    $teacherNames[] = $teacherName;
    $courseRow = [
        'assignment_id' => $assignmentId,
        'period' => (string) ($assignment['period'] ?? ''),
        'unit_name' => upper_label(trim((string) ($assignment['unit_name'] ?? 'Unit'))),
        'teacher_name' => $teacherName,
        'module_name' => upper_label(trim((string) ($assignment['module_name'] ?? ''))),
        'score' => max(0, min(100, $score)),
        'errors' => max(0, $errors),
        'total_questions' => max(0, $totalQuestions),
        'course_name' => upper_label(trim((string) ($assignment['course_name'] ?? 'Course'))),
        'enter_href' => 'student_course.php?assignment=' . urlencode($assignmentId),
        'quiz_href' => $pdoCard ? build_assignment_quiz_href($pdoCard, $studentId, $assignmentId, $assignment) : '',
        'scores_href' => 'student_quiz.php?assignment=' . urlencode($assignmentId),
    ];
    if ($program === 'english') {
        $englishCourses[] = $courseRow;
    } else {
        $technicalCourses[] = $courseRow;
    }
}

$allCourses = array_merge($englishCourses, $technicalCourses);
$courseCount = count($allCourses);
$totalScore = array_sum(array_column($allCourses, 'score'));
$maxScore = $courseCount * 100;
$avgScore = $courseCount > 0 ? (int) round($totalScore / $courseCount) : 0;
$totalErrors = array_sum(array_column($allCourses, 'errors'));
$totalQuestions = array_sum(array_column($allCourses, 'total_questions'));
$errorRate = $totalQuestions > 0 ? round(($totalErrors / $totalQuestions) * 100, 1) : 0.0;
$perfectUnits = count(array_filter($allCourses, static fn(array $course): bool => (int) ($course['score'] ?? 0) === 100));

$progressRows = $allCourses;
usort($progressRows, static fn(array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)));

$strengths = $progressRows;
$strengths = array_values(array_filter($strengths, static fn(array $course): bool => (int) ($course['score'] ?? 0) >= 90));
$strengths = array_slice($strengths, 0, 4);

$weaknessRows = $allCourses;
usort($weaknessRows, static function (array $a, array $b): int {
    $scoreCmp = ((int) ($a['score'] ?? 0)) <=> ((int) ($b['score'] ?? 0));
    if ($scoreCmp !== 0) {
        return $scoreCmp;
    }
    $aRate = (int) ($a['total_questions'] ?? 0) > 0 ? ((int) ($a['errors'] ?? 0) / (int) $a['total_questions']) : 0;
    $bRate = (int) ($b['total_questions'] ?? 0) > 0 ? ((int) ($b['errors'] ?? 0) / (int) $b['total_questions']) : 0;
    return $bRate <=> $aRate;
});
$weaknesses = array_slice($weaknessRows, 0, 4);

$teacherMeta = '';
if (!empty($teacherNames)) {
    $teacherMeta = (string) ($teacherNames[0] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Dashboard</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap');
*{box-sizing:border-box}
body{margin:0;background:#f6f7fb;color:#2e2f53;font-family:'Nunito','Segoe UI',sans-serif}
.page{max-width:1320px;margin:0 auto;padding:20px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;background:#fff;border:1px solid #eceaf7;border-radius:18px;padding:14px 18px}
.brand{display:flex;align-items:center;gap:12px}
.brand-bubble{width:34px;height:34px;border-radius:12px;background:#7f77dd;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}
.brand-title{font-weight:900;color:#f97316;letter-spacing:.02em}
.student-chip{display:flex;align-items:center;gap:10px;background:#f8f6ff;border:1px solid #e8e4ff;border-radius:14px;padding:8px 10px}
.student-meta{line-height:1.15}
.student-name{font-weight:900}
.student-id{font-size:12px;color:#8d88b0}
.profile-avatar{width:36px;height:36px;border-radius:50%;overflow:hidden;background:#7f77dd;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}
.profile-avatar img{width:100%;height:100%;object-fit:cover}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;border-radius:10px;border:1px solid transparent;padding:10px 14px;font-weight:900;font-size:13px;line-height:1}
.btn-primary{background:#f97316;color:#fff}
.btn-outline-green{border-color:#3b6d11;color:#3b6d11;background:#fff}
.btn-outline-purple{border-color:#7f77dd;color:#7f77dd;background:#fff}
.btn-logout{background:#7f77dd;color:#fff}
.layout{display:grid;grid-template-columns:290px 1fr;gap:18px;margin-top:16px}
.sidebar,.content{background:#fff;border:1px solid #eceaf7;border-radius:18px}
.sidebar{padding:16px}
.profile-top{display:flex;align-items:center;gap:10px}
.profile-title{font-weight:900;color:#f97316}
.profile-sub{font-size:12px;color:#8d88b0}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0}
.stat{background:#f8f6ff;border:1px solid #e8e4ff;border-radius:12px;padding:10px}
.stat-k{font-size:11px;color:#8d88b0;text-transform:uppercase;font-weight:900}
.stat-v{font-size:20px;font-weight:900;color:#2f2f5a}
.upload-form{margin-top:10px}
.upload-label{font-size:12px;font-weight:900;color:#8d88b0;display:block;margin-bottom:6px}
.upload-input{width:100%;padding:8px;border:1px solid #e4e5f2;border-radius:10px;background:#fff;font-size:12px}
.upload-btn{margin-top:8px;width:100%}
.section-label{margin:14px 0 6px;font-size:11px;font-weight:900;color:#8d88b0;text-transform:uppercase}
.quick-actions .btn{width:100%;margin-top:8px}
.notice{padding:10px 12px;border-radius:10px;margin-bottom:10px;font-weight:900;font-size:13px}
.notice-ok{background:#ebfff0;border:1px solid #c2efcf;color:#1b7a39}
.notice-error{background:#fff0f0;border:1px solid #f3c4c4;color:#b42323}
.content{padding:16px}
.phase-tabs{display:flex;align-items:center;gap:14px;border-bottom:1px solid #eceaf7;padding-bottom:10px}
.phase-tab{border:none;background:transparent;color:#8d88b0;font-weight:900;padding:4px 2px;cursor:pointer;border-bottom:3px solid transparent}
.phase-tab.is-active{color:#f97316;border-bottom-color:#f97316}
.tab-panel{display:none;padding-top:14px}
.tab-panel.is-active{display:block}
.grid-meta{font-size:13px;color:#8d88b0;font-weight:800;margin-bottom:10px}
.course-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px}
.course-card{border:1px solid #eceaf7;border-radius:14px;padding:14px}
.course-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:900;color:#fff}
.badge-english{background:#7f77dd}
.badge-technical{background:#f97316}
.badge-progress{background:#3b6d11}
.course-title{margin:10px 0 6px;font-weight:900;color:#2f2f5a}
.course-line{font-size:13px;color:#7772a0;margin:2px 0}
.score-row{display:flex;justify-content:space-between;align-items:center;margin-top:10px}
.score-bar{height:8px;background:#f0eef9;border-radius:999px;overflow:hidden;margin-top:6px}
.score-bar-fill{height:100%}
.score-pct{font-size:13px;font-weight:900}
.errors-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900}
.chip-good{background:#ebfff0;color:#3b6d11}
.chip-warn{background:#fff3e8;color:#f97316}
.course-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.hero-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.hero-card{border:1px solid #eceaf7;border-radius:14px;padding:12px}
.hero-k{font-size:12px;color:#8d88b0;font-weight:900;text-transform:uppercase}
.hero-v{font-size:24px;font-weight:900;color:#2f2f5a;margin-top:4px}
.hero-sub{font-size:12px;color:#8d88b0}
.table-wrap{overflow:auto;margin-top:12px;border:1px solid #eceaf7;border-radius:12px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 10px;border-bottom:1px solid #f1eff8;text-align:left}
th{background:#fbfaff;color:#7a739f;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.score-pill{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-weight:900;font-size:11px}
.score-green{background:#ebfff0;color:#3b6d11}
.score-orange{background:#fff3e8;color:#f97316}
.score-red{background:#fff0f0;color:#e24b4a}
.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.dot-green{background:#3b6d11}
.dot-orange{background:#f97316}
.insights{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.insight{border:1px solid #eceaf7;border-radius:12px;padding:10px}
.insight h4{margin:0 0 8px;color:#2f2f5a}
.insight ul{margin:0;padding-left:16px}
.insight li{margin:6px 0;color:#6f6899}
.empty-state{padding:14px;border:1px dashed #dad6ef;border-radius:12px;color:#8d88b0;font-weight:800;background:#fcfcff}
@media (max-width:980px){.layout{grid-template-columns:1fr}.topbar{flex-wrap:wrap}.insights{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">
            <div class="brand-bubble">💬</div>
            <div class="brand-title">ONES</div>
        </div>
        <div class="student-chip">
            <div class="student-meta">
                <div class="student-name"><?php echo h($studentName); ?></div>
                <div class="student-id"><?php echo h($studentId !== '' ? $studentId : 'Student'); ?></div>
            </div>
            <div class="profile-avatar">
                <?php if ($studentPhotoSrc !== '') { ?>
                    <img src="<?php echo h($studentPhotoSrc); ?>" alt="Foto de perfil">
                <?php } else { ?>
                    <?php echo h($studentInitials); ?>
                <?php } ?>
            </div>
        </div>
        <a class="btn btn-logout" href="logout.php">Salir</a>
    </header>

    <?php if ($flashMessage !== '') { ?>
        <div class="notice notice-ok"><?php echo h($flashMessage); ?></div>
    <?php } ?>
    <?php if ($flashError !== '') { ?>
        <div class="notice notice-error"><?php echo h($flashError); ?></div>
    <?php } ?>

    <div class="layout">
        <aside class="sidebar">
            <div class="profile-top">
                <div class="profile-avatar">
                    <?php if ($studentPhotoSrc !== '') { ?>
                        <img src="<?php echo h($studentPhotoSrc); ?>" alt="Foto de perfil">
                    <?php } else { ?>
                        <?php echo h($studentInitials); ?>
                    <?php } ?>
                </div>
                <div>
                    <div class="profile-title"><?php echo h($studentName); ?></div>
                    <div class="profile-sub"><?php echo h($studentPermission === 'editor' ? 'Editor mode' : 'View mode'); ?></div>
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-k">Cursos</div>
                    <div class="stat-v"><?php echo $courseCount; ?></div>
                </div>
                <div class="stat">
                    <div class="stat-k">Promedio</div>
                    <div class="stat-v"><?php echo $avgScore; ?>%</div>
                </div>
            </div>

            <form method="post" enctype="multipart/form-data" class="upload-form">
                <input type="hidden" name="action" value="upload_student_photo">
                <label class="upload-label">Foto de perfil</label>
                <input type="file" name="student_photo" class="upload-input" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <button class="btn btn-logout upload-btn" type="submit">Actualizar foto</button>
            </form>

            <div class="section-label">Acciones rápidas</div>
            <div class="quick-actions">
                <?php if ($quizUnlocked && $quizGoHref !== '') { ?>
                    <a class="btn btn-outline-green" href="<?php echo h($quizGoHref); ?>">Ir al Quiz</a>
                <?php } else { ?>
                    <span class="btn btn-outline-green" style="opacity:.6;pointer-events:none">Quiz bloqueado</span>
                <?php } ?>
                <a class="btn btn-primary" href="change_password_student.php">Cambiar clave</a>
                <a class="btn btn-logout" href="logout.php">Cerrar sesión</a>
            </div>
        </aside>

        <main class="content">
            <div class="phase-tabs">
                <button class="phase-tab is-active" onclick="switchTab('english', this)" aria-controls="tab-english">English</button>
                <?php if (!empty($technicalCourses)) { ?>
                    <button class="phase-tab" onclick="switchTab('technical', this)" aria-controls="tab-technical">Technical</button>
                <?php } ?>
                <button class="phase-tab" onclick="switchTab('progress', this)" aria-controls="tab-progress">Progress</button>
            </div>

            <section id="tab-english" class="tab-panel is-active">
                <div class="grid-meta"><?php echo count($englishCourses); ?> cursos<?php echo $teacherMeta !== '' ? ' · Teacher: ' . h($teacherMeta) : ''; ?></div>
                <?php if (empty($englishCourses)) { ?>
                    <div class="empty-state">No hay cursos de English asignados.</div>
                <?php } else { ?>
                    <div class="course-grid">
                        <?php foreach ($englishCourses as $course) { ?>
                            <?php
                            $score = (int) ($course['score'] ?? 0);
                            $barColor = $score >= 90 ? '#3B6D11' : '#F97316';
                            $chipClass = ($score >= 90 && (int) ($course['errors'] ?? 0) === 0) ? 'chip-good' : 'chip-warn';
                            ?>
                            <article class="course-card">
                                <span class="course-badge badge-english">INGLÉS · P<?php echo h((string) ($course['period'] ?? '')); ?></span>
                                <div class="course-title"><?php echo h((string) ($course['unit_name'] ?? '')); ?></div>
                                <div class="course-line">Teacher: <b><?php echo h((string) ($course['teacher_name'] ?? 'Teacher')); ?></b></div>
                                <div class="course-line">Curso: <?php echo h((string) ($course['course_name'] ?? 'Course')); ?></div>
                                <div class="score-row">
                                    <span class="errors-chip <?php echo $chipClass; ?>"><?php echo (int) ($course['errors'] ?? 0); ?> / <?php echo (int) ($course['total_questions'] ?? 0); ?> errores</span>
                                    <span class="score-pct" style="color:<?php echo h($barColor); ?>"><?php echo $score; ?>%</span>
                                </div>
                                <div class="score-bar"><div class="score-bar-fill" style="width:<?php echo $score; ?>%;background:<?php echo h($barColor); ?>"></div></div>
                                <div class="course-actions">
                                    <a class="btn btn-primary" href="<?php echo h((string) ($course['enter_href'] ?? '#')); ?>">Entrar</a>
                                    <?php if ((string) ($course['quiz_href'] ?? '') !== '') { ?>
                                        <a class="btn btn-outline-green" href="<?php echo h((string) $course['quiz_href']); ?>">Quiz</a>
                                    <?php } ?>
                                    <a class="btn btn-outline-purple" href="<?php echo h((string) ($course['scores_href'] ?? '#')); ?>">Puntajes</a>
                                </div>
                            </article>
                        <?php } ?>
                    </div>
                <?php } ?>
            </section>

            <section id="tab-technical" class="tab-panel">
                <div class="grid-meta"><?php echo count($technicalCourses); ?> cursos técnicos</div>
                <?php if (empty($technicalCourses)) { ?>
                    <div class="empty-state">No hay cursos técnicos asignados.</div>
                <?php } else { ?>
                    <div class="course-grid">
                        <?php foreach ($technicalCourses as $course) { ?>
                            <?php
                            $score = (int) ($course['score'] ?? 0);
                            $barColor = $score >= 90 ? '#3B6D11' : '#F97316';
                            $chipClass = ($score >= 90 && (int) ($course['errors'] ?? 0) === 0) ? 'chip-good' : 'chip-warn';
                            ?>
                            <article class="course-card">
                                <span class="course-badge badge-technical">TÉCNICO · P<?php echo h((string) ($course['period'] ?? '')); ?></span>
                                <?php if ((string) ($course['module_name'] ?? '') !== '') { ?>
                                    <div class="course-line"><?php echo h((string) $course['module_name']); ?></div>
                                <?php } ?>
                                <div class="course-title"><?php echo h((string) ($course['unit_name'] ?? '')); ?></div>
                                <div class="course-line">Teacher: <b><?php echo h((string) ($course['teacher_name'] ?? 'Teacher')); ?></b></div>
                                <div class="score-row">
                                    <span class="errors-chip <?php echo $chipClass; ?>"><?php echo (int) ($course['errors'] ?? 0); ?> / <?php echo (int) ($course['total_questions'] ?? 0); ?> errores</span>
                                    <span class="score-pct" style="color:<?php echo h($barColor); ?>"><?php echo $score; ?>%</span>
                                </div>
                                <div class="score-bar"><div class="score-bar-fill" style="width:<?php echo $score; ?>%;background:<?php echo h($barColor); ?>"></div></div>
                                <div class="course-actions">
                                    <a class="btn btn-primary" href="<?php echo h((string) ($course['enter_href'] ?? '#')); ?>">Entrar</a>
                                    <?php if ((string) ($course['quiz_href'] ?? '') !== '') { ?>
                                        <a class="btn btn-outline-green" href="<?php echo h((string) $course['quiz_href']); ?>">Quiz</a>
                                    <?php } ?>
                                    <a class="btn btn-outline-purple" href="<?php echo h((string) ($course['scores_href'] ?? '#')); ?>">Puntajes</a>
                                </div>
                            </article>
                        <?php } ?>
                    </div>
                <?php } ?>
            </section>

            <section id="tab-progress" class="tab-panel">
                <div class="hero-grid">
                    <article class="hero-card">
                        <div class="hero-k">Promedio general</div>
                        <div class="hero-v"><?php echo $avgScore; ?>%</div>
                        <div class="score-bar"><div class="score-bar-fill" style="width:<?php echo $avgScore; ?>%;background:#7f77dd"></div></div>
                    </article>
                    <article class="hero-card">
                        <div class="hero-k">Puntaje total</div>
                        <div class="hero-v"><?php echo $totalScore; ?> / <?php echo $maxScore; ?></div>
                        <div class="hero-sub">Suma acumulada de unidades</div>
                    </article>
                    <article class="hero-card">
                        <div class="hero-k">Errores totales</div>
                        <div class="hero-v"><?php echo $totalErrors; ?> / <?php echo $totalQuestions; ?></div>
                        <div class="hero-sub">Tasa de error: <?php echo h((string) $errorRate); ?>%</div>
                        <div class="score-bar"><div class="score-bar-fill" style="width:<?php echo min(100, max(0, (float) $errorRate)); ?>%;background:#f97316"></div></div>
                    </article>
                    <article class="hero-card">
                        <div class="hero-k">Unidades perfectas</div>
                        <div class="hero-v"><?php echo $perfectUnits; ?> / <?php echo $courseCount; ?></div>
                        <?php $perfectRate = $courseCount > 0 ? (int) round(($perfectUnits / $courseCount) * 100) : 0; ?>
                        <div class="score-bar"><div class="score-bar-fill" style="width:<?php echo $perfectRate; ?>%;background:#3b6d11"></div></div>
                    </article>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Unidad</th>
                                <th>Periodo</th>
                                <th>Score</th>
                                <th>Progreso</th>
                                <th>Errores</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($progressRows)) { ?>
                                <tr><td colspan="6">Sin datos aún.</td></tr>
                            <?php } else { ?>
                                <?php foreach ($progressRows as $row) { ?>
                                    <?php
                                    $score = (int) ($row['score'] ?? 0);
                                    $pill = $score >= 90 ? 'score-green' : ($score >= 80 ? 'score-orange' : 'score-red');
                                    $bar = $score >= 90 ? '#3B6D11' : ($score >= 80 ? '#F97316' : '#E24B4A');
                                    $dotClass = $score >= 90 ? 'dot-green' : 'dot-orange';
                                    $statusText = $score === 100 ? 'Perfecto' : ($score >= 85 ? 'Bien' : 'Reforzar');
                                    ?>
                                    <tr>
                                        <td><?php echo h((string) ($row['unit_name'] ?? 'Unidad')); ?></td>
                                        <td>P<?php echo h((string) ($row['period'] ?? '')); ?></td>
                                        <td><span class="score-pill <?php echo $pill; ?>"><?php echo $score; ?>%</span></td>
                                        <td><div class="score-bar"><div class="score-bar-fill" style="width:<?php echo $score; ?>%;background:<?php echo h($bar); ?>"></div></div></td>
                                        <td><?php echo (int) ($row['errors'] ?? 0); ?> / <?php echo (int) ($row['total_questions'] ?? 0); ?></td>
                                        <td><span class="status-dot <?php echo $dotClass; ?>"></span><?php echo h($statusText); ?></td>
                                    </tr>
                                <?php } ?>
                                <tr>
                                    <td colspan="6"><b>Total:</b> <?php echo $totalScore; ?> / <?php echo $maxScore; ?> pts · <?php echo $avgScore; ?>% promedio · <?php echo $totalErrors; ?> errores</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <div class="insights">
                    <article class="insight">
                        <h4>Fortalezas</h4>
                        <ul>
                            <?php if (empty($strengths)) { ?>
                                <li>Sin unidades destacadas todavía.</li>
                            <?php } else { ?>
                                <?php foreach ($strengths as $row) { ?>
                                    <li><span class="status-dot dot-green"></span><b><?php echo h((string) ($row['unit_name'] ?? 'Unidad')); ?></b> — <?php echo (int) ($row['score'] ?? 0); ?>%</li>
                                <?php } ?>
                            <?php } ?>
                        </ul>
                    </article>
                    <article class="insight">
                        <h4>Debilidades</h4>
                        <ul>
                            <?php if (empty($weaknesses)) { ?>
                                <li>Sin unidades por reforzar todavía.</li>
                            <?php } else { ?>
                                <?php foreach ($weaknesses as $row) { ?>
                                    <li><span class="status-dot dot-orange"></span><b><?php echo h((string) ($row['unit_name'] ?? 'Unidad')); ?></b> — <?php echo (int) ($row['errors'] ?? 0); ?> / <?php echo (int) ($row['total_questions'] ?? 0); ?> errores</li>
                                <?php } ?>
                            <?php } ?>
                        </ul>
                    </article>
                </div>
            </section>
        </main>
    </div>
</div>
<script>
function switchTab(tabName, button) {
    document.querySelectorAll('.tab-panel').forEach((el) => el.classList.remove('is-active'));
    document.querySelectorAll('.phase-tab').forEach((el) => el.classList.remove('is-active'));
    const panel = document.getElementById('tab-' + tabName);
    if (panel) panel.classList.add('is-active');
    if (button) button.classList.add('is-active');
}
</script>
</body>
</html>

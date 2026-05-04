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

/**
 * Returns the direct quiz-viewer URL for the best qualifying unit in an assignment,
 * or '' if the student has not yet unlocked the quiz for this assignment.
 */
function build_assignment_quiz_href(PDO $pdo, string $studentId, string $assignmentId, array $assignmentRow): string
{
    if ($studentId === '' || $assignmentId === '') return '';

    try {
        // Check teacher unlock
        $stmt = $pdo->prepare("SELECT 1 FROM teacher_quiz_unlocks WHERE student_id = :sid AND assignment_id = :aid LIMIT 1");
        $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
        $unlocked = (bool) $stmt->fetchColumn();

        // Auto-unlock via score
        if (!$unlocked) {
            $stmt = $pdo->prepare("SELECT 1 FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 LIMIT 1");
            $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
            $unlocked = (bool) $stmt->fetchColumn();
        }

        if (!$unlocked) return '';

        // Find best qualifying unit
        $stmt = $pdo->prepare("SELECT unit_id FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 ORDER BY updated_at DESC NULLS LAST LIMIT 1");
        $stmt->execute(['sid' => $studentId, 'aid' => $assignmentId]);
        $unitId = (string) ($stmt->fetchColumn() ?: '');

        if ($unitId === '') {
            $unitId = (string) ($assignmentRow['unit_id'] ?? '');
        }
        if ($unitId === '') return 'student_course.php?assignment=' . urlencode($assignmentId);

        // Look for quiz activity
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id::text = :uid AND type = 'quiz' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['uid' => $unitId]);
        $quizActId = (string) ($stmt->fetchColumn() ?: '');

        $returnTo = 'student_course.php?' . http_build_query(['assignment' => $assignmentId, 'unit' => $unitId, 'step' => '9999']);

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
        $stmt = $pdo->prepare("\n            SELECT sa.id, sa.teacher_id, sa.course_id, sa.period, sa.unit_id, sa.level_id, sa.program, sa.updated_at,\n                   t.name AS teacher_name,\n                   c.name AS course_name,\n                   u.name AS unit_name,\n                   m.name AS module_name\n            FROM student_assignments sa\n            LEFT JOIN teachers t ON t.id = sa.teacher_id\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            LEFT JOIN units u ON u.id::text = sa.unit_id\n            LEFT JOIN technical_modules m ON m.id = u.module_id\n            WHERE sa.student_id = :student_id\n            ORDER BY sa.updated_at DESC NULLS LAST, sa.id DESC\n        ");
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        // Deduplicate: keep only the most recent row per (course_id, unit_id) pair.
        // Rows are already sorted newest-first, so first occurrence wins.
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

// Check quiz unlock status for the first assignment.
// Unlocked if: teacher explicitly unlocked it, OR student achieved >= 60% in any unit.
$firstAssignmentId = (string) (($myAssignments[0] ?? [])['id'] ?? '');
$quizUnlocked = false;
$quizGoHref   = '';   // URL that takes the student directly to the quiz
if ($firstAssignmentId !== '') {
    $pdoQuiz = get_pdo_connection();
    if ($pdoQuiz) {
        try {
            // 1. Teacher-granted unlock
            $qStmt = $pdoQuiz->prepare("SELECT 1 FROM teacher_quiz_unlocks WHERE student_id = :sid AND assignment_id = :aid LIMIT 1");
            $qStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
            $quizUnlocked = (bool) $qStmt->fetchColumn();

            // 2. Auto-unlock: student scored >= 60% on any unit in this assignment
            if (!$quizUnlocked) {
                $scoreStmt = $pdoQuiz->prepare("SELECT 1 FROM student_unit_results WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60 LIMIT 1");
                $scoreStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
                $quizUnlocked = (bool) $scoreStmt->fetchColumn();
            }

            // 3. Build the quiz-viewer URL: use the first unit that scored >= 60%,
            //    or fall back to the assignment's own unit_id, or any unlocked unit.
            if ($quizUnlocked) {
                // Try to find the most-recent qualifying unit
                $unitStmt = $pdoQuiz->prepare(
                    "SELECT unit_id FROM student_unit_results
                      WHERE student_id = :sid AND assignment_id = :aid AND completion_percent >= 60
                      ORDER BY updated_at DESC NULLS LAST LIMIT 1"
                );
                $unitStmt->execute(['sid' => $studentId, 'aid' => $firstAssignmentId]);
                $qualUnitId = (string) ($unitStmt->fetchColumn() ?: '');

                // Fall back to the unit baked into the assignment row
                if ($qualUnitId === '') {
                    $qualUnitId = (string) (($myAssignments[0] ?? [])['unit_id'] ?? '');
                }

                if ($qualUnitId !== '') {
                    // Check if there is an explicit quiz activity in that unit
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
                        // No explicit quiz activity — open the unit at the last step
                        // so student_course.php can redirect to the quiz viewer
                        $quizGoHref = $returnTo;
                    }
                } else {
                    // No unit known — send to student_course.php and let it sort it out
                    $quizGoHref = 'student_course.php?assignment=' . urlencode($firstAssignmentId);
                }
            }
        } catch (Throwable $e) {}
    }
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
:root{
    --bg:#ffffff;
    --card:#ffffff;
    --line:#F0EEF8;
    --title:#7F77DD;
    --text:#271B5D;
    --muted:#9B94BE;
    --orange:#F97316;
    --orange-dark:#C2580A;
    --purple:#7F77DD;
    --purple-dark:#534AB7;
    --danger:#c42828;
    --soft:#EEEDFE;
    --shadow:0 8px 40px rgba(127,119,221,.13);
    --shadow-sm:0 4px 14px rgba(127,119,221,.10);
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:'Nunito','Segoe UI',sans-serif;
    background:#ffffff;
    color:var(--text);
}
.page{max-width:1400px;margin:0 auto;padding:20px 20px 40px;}
.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    padding-bottom:20px;
    border-bottom:1px solid var(--line);
    margin-bottom:28px;
}
.header h1{
    margin:0;
    font-size:32px;
    font-weight:700;
    color:var(--orange);
    font-family:'Fredoka','Trebuchet MS',sans-serif;
}
.logout-btn{
    display:inline-block;
    text-decoration:none;
    color:#fff;
    font-size:13px;
    font-weight:800;
    border-radius:999px;
    padding:10px 16px;
    background:#7F77DD;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
    transition:filter .2s,transform .15s;
}
.logout-btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
/* layout */
.layout{display:grid;grid-template-columns:300px 1fr;gap:28px;align-items:start;}
/* sidebar panel */
.panel{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:24px;
    box-shadow:var(--shadow);
    padding:24px 20px;
    position:sticky;
    top:20px;
}
.profile-box{text-align:center;}
.avatar{
    width:160px;
    height:160px;
    margin:0 auto 16px;
    border-radius:50%;
    overflow:hidden;
    background:linear-gradient(180deg,#7F77DD,#534AB7);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:54px;
    font-weight:800;
    border:4px solid #EEEDFE;
    box-shadow:0 8px 20px rgba(127,119,221,.22);
}
.avatar img{width:100%;height:100%;object-fit:cover;display:block;}
.student-name{
    font-size:20px;
    font-weight:700;
    color:var(--orange);
    margin:0 0 4px;
    font-family:'Fredoka','Trebuchet MS',sans-serif;
}
.student-role{font-size:13px;color:var(--muted);font-weight:700;margin-bottom:14px;}
.badge{
    display:inline-flex;
    align-items:center;
    padding:5px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    background:#EEEDFE;
    color:#534AB7;
    border:1px solid #C4B5FD;
    margin-bottom:16px;
}
.upload-form{text-align:left;margin:8px 0 4px;}
.upload-label{display:block;font-size:11px;color:var(--muted);font-weight:900;margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em;}
.upload-input{width:100%;margin-bottom:8px;padding:7px;border:1px solid var(--line);border-radius:8px;font-size:13px;}
.upload-btn{
    width:100%;border:none;border-radius:999px;padding:10px;color:#fff;cursor:pointer;
    font-size:13px;font-weight:900;
    background:#7F77DD;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
    transition:filter .2s,transform .15s;
    font-family:'Nunito','Segoe UI',sans-serif;
}
.upload-btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
/* sidebar action buttons */
.sidebar-section-title{
    margin:18px 0 8px;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--muted);
}
.side-button{
    display:block;
    width:100%;
    margin-top:8px;
    padding:12px 16px;
    border-radius:999px;
    text-decoration:none;
    font-size:13px;
    font-weight:900;
    color:#fff;
    background:#7F77DD;
    text-align:center;
    transition:filter .2s,transform .15s;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
    border:none;
    cursor:pointer;
    font-family:'Nunito','Segoe UI',sans-serif;
    line-height:1;
}
.side-button:hover{filter:brightness(1.07);transform:translateY(-1px);}
.side-button.green{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);}
.side-button.locked{background:linear-gradient(180deg,#b0b0b0,#888);cursor:default;opacity:.78;box-shadow:none;}
.side-button.locked:hover{filter:none;transform:none;}
.side-button.orange{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22);}
/* notices */
.notice{padding:10px 12px;border-radius:10px;margin-bottom:14px;font-weight:800;font-size:13px;}
.notice-ok{background:#ebfff0;border:1px solid #c2efcf;color:#1b7a39;}
.notice-error{background:#fff0f0;border:1px solid #f3c4c4;color:#b42323;}
/* main area */
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;}
.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:20px;
    padding:18px;
    box-shadow:var(--shadow);
}
.card h3{margin:0 0 10px;font-size:20px;color:var(--orange);font-family:'Fredoka','Trebuchet MS',sans-serif;font-weight:700;}
.student-module-label{margin:0 0 10px;font-size:12px;font-weight:900;color:#534AB7;letter-spacing:.04em;font-family:'Nunito','Segoe UI',sans-serif;}
.card p{margin:6px 0;color:var(--muted);font-size:15px;font-weight:700;}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.btn{
    display:inline-block;
    padding:10px 16px;
    background:#F97316;
    color:#fff;
    text-decoration:none;
    border-radius:999px;
    font-weight:900;
    font-size:13px;
    border:none;
    cursor:pointer;
    box-shadow:0 6px 18px rgba(249,115,22,.22);
    transition:filter .2s,transform .15s;
}
.btn:hover{filter:brightness(1.07);transform:translateY(-1px);}
.btn.secondary{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18);}
.btn.quiz-btn{background:linear-gradient(180deg,#22c55e,#15803d);box-shadow:0 6px 18px rgba(22,163,74,.22);}
.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    padding:18px;
    color:var(--muted);
    font-weight:700;
    box-shadow:var(--shadow);
}
@media (max-width:1024px){
    .layout{grid-template-columns:1fr;}
    .panel{position:static;}
}
@media (max-width:768px){
    .page{padding:14px;}
    .header{flex-direction:column;align-items:flex-start;gap:10px;}
    .header h1{font-size:26px;}
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <h1>Student Dashboard</h1>
        <a class="logout-btn" href="logout.php">Log out</a>
    </div>

    <?php if ($flashMessage !== '') { ?>
        <div class="notice notice-ok"><?php echo h($flashMessage); ?></div>
    <?php } ?>
    <?php if ($flashError !== '') { ?>
        <div class="notice notice-error"><?php echo h($flashError); ?></div>
    <?php } ?>

    <div class="layout">
        <aside class="panel">
            <div class="profile-box">
                <div class="avatar">
                    <?php if ($studentPhotoSrc !== '') { ?>
                        <img src="<?php echo $studentPhotoSrc; ?>" alt="Student photo">
                    <?php } else { ?>
                        <?php echo h($studentInitials); ?>
                    <?php } ?>
                </div>
                <div class="student-name"><?php echo h($studentName); ?></div>
                <div class="student-role">Student</div>
                <span class="badge"><?php echo h($studentPermission === 'editor' ? 'Editor mode' : 'View mode'); ?></span>

                <form method="post" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="action" value="upload_student_photo">
                    <label class="upload-label">Profile photo</label>
                    <input type="file" name="student_photo" class="upload-input" accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <button class="upload-btn" type="submit">Update photo</button>
                </form>
            </div>

            <div class="sidebar-section-title">Quick Actions</div>

            <?php if ($quizUnlocked && $quizGoHref !== '') { ?>
                <a class="side-button green" href="<?php echo h($quizGoHref); ?>">Go to Quiz</a>
            <?php } else { ?>
                <span class="side-button locked" title="Ask your teacher to unlock the quiz">Quiz Locked 🔒</span>
            <?php } ?>

            <a class="side-button orange" href="change_password_student.php">Change Password</a>
            <a class="side-button" href="logout.php">Log out</a>
        </aside>

        <main class="main-area">
            <?php if (empty($myAssignments)) { ?>
                <div class="empty">You do not have assigned courses yet.</div>
            <?php } else { ?>
                <div class="grid">
                    <?php foreach ($myAssignments as $assignment) { ?>
                        <?php
                        $assignmentId = (string) ($assignment['id'] ?? '');
                        $program = (string) ($assignment['program'] ?? 'technical');
                        $programLabel = upper_label($program === 'english' ? 'inglés' : 'técnico');
                        $courseName = trim((string) ($assignment['course_name'] ?? ''));
                        if ($courseName === '') {
                            $courseName = 'Course';
                        }
                        $courseName = upper_label($courseName);
                        $unitName = trim((string) ($assignment['unit_name'] ?? ''));
                        if ($unitName === '' && $program === 'english') {
                            $unitName = 'Units by phase';
                        }
                        $unitName = upper_label($unitName);
                        $moduleName = upper_label(trim((string) ($assignment['module_name'] ?? '')));
                        $periodLabel = upper_label((string) ($assignment['period'] ?? ''));
                        $scoreSummary = $scoreSummaryByAssignment[$assignmentId] ?? null;
                        $cardPdo = get_pdo_connection();
                        $cardQuizHref = $cardPdo ? build_assignment_quiz_href($cardPdo, $studentId, $assignmentId, $assignment) : '';
                        ?>
                        <div class="card">
                            <h3><?php echo h($courseName); ?></h3>
                            <?php if ($moduleName !== '') { ?>
                                <p class="student-module-label"><?php echo h($moduleName); ?></p>
                            <?php } ?>
                            <p>Program: <strong><?php echo h($programLabel); ?></strong></p>
                            <p>Teacher: <strong><?php echo h((string) ($assignment['teacher_name'] ?? 'Teacher')); ?></strong></p>
                            <p>Period: <strong><?php echo h($periodLabel); ?></strong></p>
                            <?php if ($unitName !== '') { ?>
                                <p>Unit: <strong><?php echo h($unitName); ?></strong></p>
                            <?php } ?>
                            <?php if (is_array($scoreSummary)) { ?>
                                <p>Average score: <strong><?php echo (int) ($scoreSummary['avg_percent'] ?? 0); ?>%</strong></p>
                                <?php if ((int) ($scoreSummary['total_questions'] ?? 0) > 0) { ?>
                                    <p>Quiz errors: <strong><?php echo (int) ($scoreSummary['total_errors'] ?? 0); ?>/<?php echo (int) ($scoreSummary['total_questions'] ?? 0); ?></strong></p>
                                <?php } ?>
                            <?php } ?>
                            <div class="actions">
                                <a class="btn" href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>">Enter course</a>
                                <?php if ($cardQuizHref !== '') { ?>
                                    <a class="btn quiz-btn" href="<?php echo h($cardQuizHref); ?>">Go to Quiz</a>
                                <?php } ?>
                                <a class="btn secondary" href="student_quiz.php?assignment=<?php echo urlencode($assignmentId); ?>">View scores</a>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        </main>
    </div>
</div>
</body>
</html>

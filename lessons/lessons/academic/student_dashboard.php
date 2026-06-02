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

function lower_label(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function normalize_label_spaces(string $value): string
{
    return trim((string) preg_replace('/\s+/', ' ', $value));
}

function extract_first_number(string $value): ?int
{
    if (preg_match('/(\d+)/u', $value, $matches)) {
        return (int) $matches[1];
    }

    return null;
}

function is_generic_course_label(string $value): bool
{
    $label = strtolower(normalize_label_spaces($value));
    if ($label === '') {
        return true;
    }

    $generic = [
        'english course',
        'english courses',
        'technical course',
        'technical courses',
        'course',
        'courses',
        'curso',
        'cursos',
    ];

    return in_array($label, $generic, true);
}

function resolve_phase_label(array $assignment): string
{
    $program = strtolower(trim((string) ($assignment['program'] ?? 'technical')));
    $phaseName = normalize_label_spaces((string) ($assignment['phase_name'] ?? ''));
    $moduleName = normalize_label_spaces((string) ($assignment['module_name'] ?? ''));
    $courseName = normalize_label_spaces((string) ($assignment['course_name'] ?? ''));
    $period = normalize_label_spaces((string) ($assignment['period'] ?? ''));

    // Use the actual phase name from the database when available.
    if ($phaseName !== '') {
        return $phaseName;
    }

    if ($program === 'technical' && $moduleName !== '') {
        return $moduleName;
    }

    if ($courseName !== '' && !is_generic_course_label($courseName)) {
        return $courseName;
    }

    if ($period !== '') {
        $periodNumber = extract_first_number($period);
        if ($program === 'english') {
            if ($periodNumber !== null) {
                return 'Basic ' . $periodNumber;
            }
            return 'Basic ' . $period;
        }
        if ($program === 'technical') {
            if ($periodNumber !== null) {
                return 'Semestre ' . $periodNumber;
            }
            return 'Semestre ' . $period;
        }
        return $period;
    }

    return $program === 'english' ? 'Basic' : ($program === 'technical' ? 'Semestre' : 'Curso');
}

function resolve_unit_label(array $assignment): string
{
    $unitName = normalize_label_spaces((string) ($assignment['unit_name'] ?? ''));
    if ($unitName !== '') {
        return $unitName;
    }

    $unitId = trim((string) ($assignment['unit_id'] ?? ''));
    if ($unitId !== '' && preg_match('/^\d+$/', $unitId)) {
        return 'Unit ' . $unitId;
    }

    return 'Unit';
}

function phase_sort_order(array $assignment, string $phaseLabel): int
{
    $number = extract_first_number($phaseLabel);
    if ($number === null) {
        $number = extract_first_number((string) ($assignment['period'] ?? ''));
    }
    if ($number === null) {
        return 9999;
    }

    return $number;
}

function unit_sort_order(string $unitLabel): int
{
    $number = extract_first_number($unitLabel);
    if ($number === null) {
        return 9999;
    }

    return $number;
}

function build_assignment_sections(array $assignments): array
{
    $sectionsByKey = [];

    foreach ($assignments as $assignment) {
        $program = strtolower(trim((string) ($assignment['program'] ?? 'technical')));
        $phaseLabel = resolve_phase_label($assignment);
        $unitLabel = resolve_unit_label($assignment);
        $phaseSort = phase_sort_order($assignment, $phaseLabel);
        $unitSort = unit_sort_order($unitLabel);
        $phaseCreatedAt = normalize_label_spaces((string) ($assignment['phase_created_at'] ?? ''));

        $sectionKey = $program . '|' . lower_label($phaseLabel);
        if (!isset($sectionsByKey[$sectionKey])) {
            $sectionsByKey[$sectionKey] = [
                'program' => $program,
                'phase_label' => $phaseLabel,
                'phase_sort' => $phaseSort,
                'phase_created_at' => $phaseCreatedAt,
                'assignments' => [],
            ];
        }

        $assignment['_resolved_unit_label'] = $unitLabel;
        $assignment['_unit_sort'] = $unitSort;
        $sectionsByKey[$sectionKey]['assignments'][] = $assignment;
    }

    $sections = array_values($sectionsByKey);

    foreach ($sections as &$section) {
        usort($section['assignments'], static function (array $a, array $b): int {
            $aSort = (int) ($a['_unit_sort'] ?? 9999);
            $bSort = (int) ($b['_unit_sort'] ?? 9999);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            $aUnit = lower_label((string) ($a['_resolved_unit_label'] ?? ''));
            $bUnit = lower_label((string) ($b['_resolved_unit_label'] ?? ''));
            if ($aUnit !== $bUnit) {
                return $aUnit <=> $bUnit;
            }

            return ((string) ($a['id'] ?? '')) <=> ((string) ($b['id'] ?? ''));
        });
    }
    unset($section);

    usort($sections, static function (array $a, array $b): int {
        $programWeight = static function (string $program): int {
            if ($program === 'english') {
                return 1;
            }
            if ($program === 'technical') {
                return 2;
            }
            return 3;
        };

        $aProgram = (string) ($a['program'] ?? '');
        $bProgram = (string) ($b['program'] ?? '');
        $aWeight = $programWeight($aProgram);
        $bWeight = $programWeight($bProgram);
        if ($aWeight !== $bWeight) {
            return $aWeight <=> $bWeight;
        }

        // Sort phases oldest to newest using DB creation timestamp when available.
        $aCreated = (string) ($a['phase_created_at'] ?? '');
        $bCreated = (string) ($b['phase_created_at'] ?? '');
        if ($aCreated !== '' && $bCreated !== '' && $aCreated !== $bCreated) {
            return $aCreated <=> $bCreated;
        }

        $aSort = (int) ($a['phase_sort'] ?? 9999);
        $bSort = (int) ($b['phase_sort'] ?? 9999);
        if ($aSort !== $bSort) {
            return $aSort <=> $bSort;
        }

        return lower_label((string) ($a['phase_label'] ?? '')) <=> lower_label((string) ($b['phase_label'] ?? ''));
    });

    return $sections;
}

function build_sidebar_module_links(array $assignments): array
{
    $modulesById = [];

    foreach ($assignments as $assignment) {
        $program = strtolower(trim((string) ($assignment['program'] ?? 'technical')));
        if ($program !== 'technical') {
            continue;
        }

        $moduleId = trim((string) ($assignment['module_id'] ?? ''));
        $moduleName = normalize_label_spaces((string) ($assignment['module_name'] ?? ''));
        $assignmentId = trim((string) ($assignment['id'] ?? ''));
        if ($moduleId === '' || $moduleName === '' || $assignmentId === '') {
            continue;
        }

        if (isset($modulesById[$moduleId])) {
            continue;
        }

        $modulesById[$moduleId] = [
            'id' => $moduleId,
            'name' => $moduleName,
            'href' => 'student_course.php?assignment=' . urlencode($assignmentId) . '&module=' . urlencode($moduleId),
        ];
    }

    return array_values($modulesById);
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
        $stmt = $pdo->prepare("
            SELECT sa.id, sa.teacher_id, sa.course_id, sa.period, sa.unit_id, sa.level_id, sa.program, sa.updated_at,
                   t.name AS teacher_name,
                   c.name AS course_name,
                   u.name AS unit_name,
                   u.module_id AS module_id,
                   m.name AS module_name,
                   ep.name AS phase_name,
                   ep.created_at AS phase_created_at
            FROM student_assignments sa
            LEFT JOIN teachers t ON t.id = sa.teacher_id
            LEFT JOIN courses c ON c.id::text = sa.course_id
            LEFT JOIN units u ON u.id::text = sa.unit_id
            LEFT JOIN technical_modules m ON m.id = u.module_id
            LEFT JOIN english_phases ep ON ep.id = u.phase_id
            WHERE sa.student_id = :student_id
            ORDER BY sa.updated_at DESC NULLS LAST, sa.id DESC
        ");
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
$sidebarModuleLinks = build_sidebar_module_links($myAssignments);
$sidebarSelectedModuleId = trim((string) ($_GET['module'] ?? ''));
if ($sidebarSelectedModuleId === '' && !empty($sidebarModuleLinks)) {
    $sidebarSelectedModuleId = (string) ($sidebarModuleLinks[0]['id'] ?? '');
}
$assignmentSections = build_assignment_sections($myAssignments);
$scoreSummaryByAssignment = load_assignment_score_summary($studentId);

// Presentation-only stats for sidebar and header meta
$totalCourses    = count($myAssignments);
$_allAvgs        = array_filter(array_column($scoreSummaryByAssignment, 'avg_percent'), static fn($v) => $v > 0);
$overallAvg      = count($_allAvgs) > 0 ? (int) round(array_sum($_allAvgs) / count($_allAvgs)) : 0;
$firstTeacherName = trim((string) (($myAssignments[0] ?? [])['teacher_name'] ?? ''));
$firstPeriodLabel = upper_label((string) (($myAssignments[0] ?? [])['period'] ?? ''));

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
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
/* ─── Reset ─── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ─── Variables ─── */
:root {
  --primary:    #7B6EE6;
  --secondary:  #30248F;
  --orange:     #FF6B18;
  --green:      #35751F;
  --bg:         #FAF8FF;
  --border:     #EAE6FF;
  --white:      #ffffff;
  --text-dark:  #30248F;
  --text-mid:   #6B63B5;
  --text-muted: #9B93CC;
  --orange-bg:  #FFF4EE;
  --green-bg:   #EEF6E8;
}

/* ─── Base ─── */
body {
  font-family: 'Inter', 'Segoe UI', sans-serif;
  background: var(--bg);
  color: var(--text-dark);
  min-height: 100vh;
}

/* ─── Header ─── */
.sd-header {
  background: var(--white);
  border-bottom: 2px solid var(--border);
  padding: 0 28px;
  height: 64px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
}
.sd-brand { display: flex; align-items: center; gap: 10px; }
.sd-brand-name {
  font-size: 26px; font-weight: 800; color: #FF6B18;
  line-height: 1; letter-spacing: 0.03em;
}
.sd-brand-sub {
  font-size: 7.5px; font-weight: 700; color: #AFA9EC;
  letter-spacing: 0.18em; text-transform: uppercase;
  margin-top: 3px; line-height: 1.4;
}
.sd-header-right { display: flex; align-items: center; gap: 12px; }
.sd-header-student { text-align: right; }
.sd-header-student-name {
  font-size: 15px; font-weight: 800; color: #30248F; line-height: 1.2;
}
.sd-header-student-role { font-size: 11px; color: var(--text-muted); font-weight: 500; }
.sd-header-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  background: #EAE6FF; border: 2px solid var(--primary);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 800; color: #30248F;
  overflow: hidden; flex-shrink: 0;
}
.sd-header-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sd-logout-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 16px; border: 1.5px solid #C0B8F0;
  border-radius: 10px; background: var(--white);
  color: #30248F; font-size: 13px; font-weight: 700;
  text-decoration: none; cursor: pointer; transition: background 0.15s;
  font-family: 'Inter', sans-serif;
}
.sd-logout-btn:hover { background: #F5F3FF; }

/* ─── Phase Bar ─── */
.sd-phase-bar {
  background: var(--white);
  border-bottom: 1px solid var(--border);
  padding: 10px 28px;
  display: flex; gap: 10px;
}
.sd-phase-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 24px; border: 1.5px solid #D8D3F0;
  border-radius: 12px; background: var(--white);
  color: #444; font-size: 15px; font-weight: 700;
  font-family: 'Inter', sans-serif; cursor: pointer;
  box-shadow: 0 2px 8px rgba(123,110,230,0.07);
  transition: background 0.15s, border-color 0.15s, color 0.15s;
  line-height: 1;
}
.sd-phase-btn::before {
  content: ''; display: inline-block; width: 12px; height: 12px;
  border: 1.5px solid currentColor; border-radius: 2px; opacity: 0.45;
}
.sd-phase-btn.active { background: var(--white); border-color: #444; color: #222; }
.sd-phase-btn:hover:not(.active) { background: #F5F3FF; border-color: var(--primary); }

/* ─── Body Layout ─── */
.sd-body {
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 20px;
  padding: 20px 28px 40px;
  align-items: start;
}

/* ─── Sidebar ─── */
.sd-sidebar {
  display: flex; flex-direction: column; gap: 14px;
  position: sticky; top: 80px;
}
.sd-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 20px 16px;
}

/* Profile card */
.sd-profile-avatar {
  width: 90px; height: 90px; border-radius: 50%;
  background: #EAE6FF; border: 3px solid var(--primary);
  display: flex; align-items: center; justify-content: center;
  font-size: 30px; font-weight: 800; color: #30248F;
  overflow: hidden; margin: 0 auto 12px;
}
.sd-profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.sd-profile-name {
  font-size: 16px; font-weight: 700; color: #FF6B18;
  text-align: center; margin-bottom: 3px;
}
.sd-profile-role { font-size: 12px; color: var(--text-muted); text-align: center; margin-bottom: 14px; }
.sd-stats-row {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 8px; margin-bottom: 16px;
}
.sd-stat-box {
  border: 1px solid var(--border); border-radius: 10px;
  padding: 10px 8px; text-align: center; background: #FDFCFF;
}
.sd-stat-value {
  font-size: 20px; font-weight: 800; color: #FF6B18;
  display: block; line-height: 1; margin-bottom: 3px;
}
.sd-stat-label {
  font-size: 10px; font-weight: 600; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: 0.05em;
}
.sd-photo-label {
  font-size: 10px; font-weight: 700; color: #B0A8D8;
  letter-spacing: 0.1em; text-transform: uppercase;
  margin-bottom: 8px; display: block;
}
.sd-photo-input {
  width: 100%; font-size: 12px; margin-bottom: 8px;
  padding: 6px 8px; border: 1px solid var(--border);
  border-radius: 8px; color: #555;
}
.sd-photo-btn {
  width: 100%; padding: 10px; border: none; border-radius: 10px;
  background: #30248F; color: #fff; font-size: 13px; font-weight: 700;
  font-family: 'Inter', sans-serif; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 7px;
  transition: opacity 0.15s;
}
.sd-photo-btn::before {
  content: ''; display: inline-block; width: 11px; height: 11px;
  border: 1.5px solid rgba(255,255,255,0.65); border-radius: 2px;
}
.sd-photo-btn:hover { opacity: 0.88; }

/* Actions card */
.sd-actions-label {
  font-size: 10px; font-weight: 700; color: #B0A8D8;
  letter-spacing: 0.12em; text-transform: uppercase;
  margin-bottom: 10px; display: block;
}
.sd-action-btn {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 11px 14px;
  border: 1.5px solid var(--border); border-radius: 10px;
  background: var(--white); color: #30248F;
  font-size: 13px; font-weight: 700; font-family: 'Inter', sans-serif;
  text-decoration: none; cursor: pointer; margin-bottom: 8px;
  transition: background 0.15s;
}
.sd-action-btn:last-child { margin-bottom: 0; }
.sd-action-btn::before {
  content: ''; display: inline-block; width: 11px; height: 11px;
  border: 1.5px solid #B0A8D8; border-radius: 2px; flex-shrink: 0;
}
.sd-action-btn:hover { background: #F5F3FF; }
.sd-action-btn.locked { color: #B0A8D8; cursor: default; }
.sd-action-btn.locked:hover { background: var(--white); }
.sd-module-select {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: var(--white);
  color: #30248F;
  font-size: 13px;
  font-weight: 700;
  font-family: 'Inter', sans-serif;
  margin-bottom: 10px;
}

/* ─── Main Content ─── */
.sd-main { min-width: 0; }
.sd-notice {
  padding: 10px 14px; border-radius: 10px;
  margin-bottom: 14px; font-size: 13px; font-weight: 700;
}
.sd-notice-ok    { background: #EEF6E8; border: 1px solid #B5D9A0; color: #35751F; }
.sd-notice-error { background: #FEF2F2; border: 1px solid #FCCACA; color: #B91C1C; }
.sd-main-header {
  display: flex; align-items: flex-start;
  justify-content: space-between; margin-bottom: 16px;
}
.sd-main-title { font-size: 22px; font-weight: 700; color: var(--primary); }
.sd-main-meta { font-size: 12px; color: #B0A8D8; text-align: right; line-height: 1.6; }

/* ─── Phase Sections ─── */
.sd-phase-sections {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.sd-phase-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.sd-section-head {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.sd-section-program {
  font-size: 10px;
  font-weight: 700;
  color: #9B93CC;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.sd-section-phase {
  font-size: 16px;
  font-weight: 800;
  color: #30248F;
}

/* ─── Course Grid ─── */
.sd-course-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
}

/* ─── Course Card ─── */
.sd-course-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 16px;
  display: flex; flex-direction: column; gap: 10px;
}
.sd-course-badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px; border-radius: 6px;
  background: #EAE6FF; color: #30248F;
  font-size: 10px; font-weight: 700; letter-spacing: 0.04em;
  width: fit-content;
}
.sd-course-badge::before {
  content: ''; display: inline-block; width: 9px; height: 9px;
  border: 1.5px solid #7B6EE6; border-radius: 2px; flex-shrink: 0;
}
.sd-unit-name {
  font-size: 15px; font-weight: 700; color: #30248F; line-height: 1.3;
}
.sd-course-teacher { font-size: 12px; color: var(--text-muted); font-weight: 500; }
.sd-course-teacher strong { color: #30248F; font-weight: 700; }
.sd-progress-row { display: flex; align-items: center; gap: 8px; }
.sd-progress-track {
  flex: 1; height: 7px; background: #EAE6FF;
  border-radius: 4px; overflow: hidden;
}
.sd-progress-fill { height: 100%; border-radius: 4px; transition: width 0.4s; }
.sd-progress-fill.green  { background: #35751F; }
.sd-progress-fill.orange { background: #FF6B18; }
.sd-progress-pct { font-size: 13px; font-weight: 800; min-width: 38px; text-align: right; }
.sd-progress-pct.green  { color: #35751F; }
.sd-progress-pct.orange { color: #FF6B18; }
.sd-errors-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 6px;
  font-size: 11px; font-weight: 600; width: fit-content;
}
.sd-errors-badge::before {
  content: ''; display: inline-block; width: 9px; height: 9px;
  border: 1.5px solid currentColor; border-radius: 2px;
  flex-shrink: 0; opacity: 0.6;
}
.sd-errors-badge.warn { background: #FFF4EE; border: 1px solid #FFD4BC; color: #B84A0A; }
.sd-errors-badge.good { background: #EEF6E8; border: 1px solid #B5D9A0; color: #35751F; }
.sd-card-actions { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 2px; }
.sd-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 14px; border: 1.5px solid var(--border);
  border-radius: 8px; background: var(--white); color: #30248F;
  font-size: 12px; font-weight: 700; font-family: 'Inter', sans-serif;
  text-decoration: none; cursor: pointer; transition: background 0.15s;
}
.sd-btn::before {
  content: ''; display: inline-block; width: 9px; height: 9px;
  border: 1.5px solid #9B93CC; border-radius: 2px; flex-shrink: 0;
}
.sd-btn:hover { background: #F5F3FF; }
.sd-empty {
  grid-column: 1 / -1; background: var(--white);
  border: 1px solid var(--border); border-radius: 14px;
  padding: 32px; text-align: center;
  color: var(--text-muted); font-size: 14px; font-weight: 600;
}

/* ─── Responsive ─── */
@media (max-width: 1024px) {
  .sd-body { grid-template-columns: 1fr; padding: 16px 20px 32px; }
  .sd-sidebar { position: static; flex-direction: row; flex-wrap: wrap; }
  .sd-card { flex: 1; min-width: 200px; }
}
@media (max-width: 768px) {
  .sd-header { padding: 0 16px; }
  .sd-phase-bar { padding: 8px 16px; overflow-x: auto; }
  .sd-body { padding: 14px 16px 28px; grid-template-columns: 1fr; }
  .sd-course-grid { grid-template-columns: 1fr 1fr; }
  .sd-sidebar { flex-direction: column; }
}
@media (max-width: 540px) {
  .sd-course-grid { grid-template-columns: 1fr; }
  .sd-main-header { flex-direction: column; gap: 6px; }
  .sd-main-meta { text-align: left; }
}
</style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
<header class="sd-header">
    <div class="sd-brand">
        <svg width="48" height="48" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect width="36" height="36" rx="9" fill="#FFF0E6"/>
            <circle cx="17" cy="15" r="8.5" fill="#F97316"/>
            <polygon points="12,22 7,30 21,26" fill="#F97316"/>
            <circle cx="17" cy="15" r="4.5" fill="#FFF0E6"/>
            <circle cx="24" cy="9" r="3.5" fill="#7B6EE6"/>
            <circle cx="24" cy="9" r="1.75" fill="#ffffff"/>
        </svg>
        <div>
            <div class="sd-brand-name">ONES</div>
            <div class="sd-brand-sub">ONLINE ENGLISH<br>SOLUTION</div>
        </div>
    </div>
    <div class="sd-header-right">
        <div class="sd-header-student">
            <div class="sd-header-student-name"><?php echo h($studentName); ?></div>
            <div class="sd-header-student-role">Estudiante · ID: <?php echo h($studentId !== '' ? $studentId : 'N/A'); ?></div>
        </div>
        <div class="sd-header-avatar">
            <?php if ($studentPhotoSrc !== '') { ?>
                <img src="<?php echo $studentPhotoSrc; ?>" alt="Foto de perfil">
            <?php } else { ?>
                <?php echo h($studentInitials); ?>
            <?php } ?>
        </div>
        <a class="sd-logout-btn" href="logout.php">↪ Salir</a>
    </div>
</header>

<!-- ═══ PHASE BAR ═══ -->
<div class="sd-phase-bar">
    <button class="sd-phase-btn active" data-tab="english">English</button>
    <button class="sd-phase-btn" data-tab="technical">Technical</button>
    <button class="sd-phase-btn" data-tab="lifeskills">Progress</button>
</div>

<!-- ═══ BODY ═══ -->
<div class="sd-body">

    <!-- SIDEBAR -->
    <aside class="sd-sidebar">

        <!-- Profile card -->
        <div class="sd-card">
            <div class="sd-profile-avatar">
                <?php if ($studentPhotoSrc !== '') { ?>
                    <img src="<?php echo $studentPhotoSrc; ?>" alt="Foto de perfil">
                <?php } else { ?>
                    <?php echo h($studentInitials); ?>
                <?php } ?>
            </div>
            <div class="sd-profile-name"><?php echo h($studentName); ?></div>
            <div class="sd-profile-role">Estudiante</div>

            <div class="sd-stats-row">
                <div class="sd-stat-box">
                    <span class="sd-stat-value"><?php echo $totalCourses; ?></span>
                    <span class="sd-stat-label">Cursos</span>
                </div>
                <div class="sd-stat-box">
                    <span class="sd-stat-value"><?php echo $overallAvg; ?>%</span>
                    <span class="sd-stat-label">Prom.</span>
                </div>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_student_photo">
                <span class="sd-photo-label">Foto de perfil</span>
                <input type="file" name="student_photo" class="sd-photo-input" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <button class="sd-photo-btn" type="submit">Actualizar foto</button>
            </form>
        </div>

        <!-- Actions card -->
        <div class="sd-card">
            <?php if (!empty($sidebarModuleLinks)) { ?>
                <span class="sd-actions-label">Módulos</span>
                <select class="sd-module-select" aria-label="Módulo técnico" onchange="if (this.value) { window.location.href = this.value; }">
                    <?php foreach ($sidebarModuleLinks as $moduleLink) { ?>
                        <?php
                        $moduleId = (string) ($moduleLink['id'] ?? '');
                        $moduleName = (string) ($moduleLink['name'] ?? 'Módulo');
                        $moduleHref = (string) ($moduleLink['href'] ?? '');
                        ?>
                        <option value="<?php echo h($moduleHref); ?>" <?php echo $moduleId === $sidebarSelectedModuleId ? 'selected' : ''; ?>>
                            <?php echo h($moduleName); ?>
                        </option>
                    <?php } ?>
                </select>
            <?php } ?>

            <span class="sd-actions-label">Acciones</span>

            <?php if ($quizUnlocked && $quizGoHref !== '') { ?>
                <a class="sd-action-btn" href="<?php echo h($quizGoHref); ?>">Ir al Quiz</a>
            <?php } else { ?>
                <span class="sd-action-btn locked" title="Pide a tu profesor que desbloquee el quiz">Ir al Quiz</span>
            <?php } ?>

            <a class="sd-action-btn" href="change_password_student.php">Cambiar clave</a>
            <a class="sd-action-btn" href="logout.php">Cerrar sesión</a>
        </div>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="sd-main">

        <?php if ($flashMessage !== '') { ?>
            <div class="sd-notice sd-notice-ok"><?php echo h($flashMessage); ?></div>
        <?php } ?>
        <?php if ($flashError !== '') { ?>
            <div class="sd-notice sd-notice-error"><?php echo h($flashError); ?></div>
        <?php } ?>

        <div class="sd-main-header">
            <h2 class="sd-main-title" id="sd-main-title">English Courses</h2>
            <span class="sd-main-meta">
                <?php echo $totalCourses; ?> cursos
                <?php if ($firstPeriodLabel !== '') { ?> · Período <?php echo h($firstPeriodLabel); ?><?php } ?>
                <?php if ($firstTeacherName !== '') { ?> · Teacher: <?php echo h($firstTeacherName); ?><?php } ?>
            </span>
        </div>

        <div class="sd-phase-sections">
            <?php if (empty($assignmentSections)) { ?>
                <div class="sd-empty">No tienes cursos asignados aún.</div>
            <?php } else { ?>
                <?php $cardPdo = get_pdo_connection(); ?>
                <?php foreach ($assignmentSections as $section) { ?>
                    <?php
                    $sectionProgram = (string) ($section['program'] ?? 'technical');
                    $sectionProgramLabel = upper_label($sectionProgram === 'english' ? 'basic program' : ($sectionProgram === 'technical' ? 'technical program' : 'programa'));
                    $sectionPhaseLabel = upper_label((string) ($section['phase_label'] ?? ''));
                    ?>
                    <section class="sd-phase-section" data-program="<?php echo h($sectionProgram); ?>">
                        <div class="sd-section-head">
                            <span class="sd-section-program"><?php echo h($sectionProgramLabel); ?></span>
                            <h3 class="sd-section-phase"><?php echo h($sectionPhaseLabel); ?></h3>
                        </div>
                        <div class="sd-course-grid">
                            <?php foreach ((array) ($section['assignments'] ?? []) as $assignment) { ?>
                                <?php
                                $assignmentId   = (string) ($assignment['id'] ?? '');
                                $program        = (string) ($assignment['program'] ?? 'technical');
                                $programLabel   = upper_label($program === 'english' ? 'inglés' : 'técnico');
                                $unitName       = upper_label((string) ($assignment['_resolved_unit_label'] ?? 'Unit'));
                                $scoreSummary   = $scoreSummaryByAssignment[$assignmentId] ?? null;
                                $cardQuizHref   = $cardPdo ? build_assignment_quiz_href($cardPdo, $studentId, $assignmentId, $assignment) : '';
                                $avgPct         = is_array($scoreSummary) ? (int) ($scoreSummary['avg_percent'] ?? 0) : 0;
                                $totalErrors    = is_array($scoreSummary) ? (int) ($scoreSummary['total_errors'] ?? 0) : 0;
                                $totalQuestions = is_array($scoreSummary) ? (int) ($scoreSummary['total_questions'] ?? 0) : 0;
                                $progressColor  = $avgPct >= 95 ? 'green' : 'orange';
                                $errorsClass    = $totalErrors === 0 ? 'good' : 'warn';
                                ?>
                                <div class="sd-course-card" data-program="<?php echo h($program); ?>">

                                    <div class="sd-course-badge">
                                        <?php echo h($sectionPhaseLabel); ?>
                                    </div>

                                    <h3 class="sd-unit-name"><?php echo h($unitName); ?></h3>

                                    <p class="sd-course-teacher">
                                        Teacher: <strong><?php echo h((string) ($assignment['teacher_name'] ?? 'Teacher')); ?></strong>
                                    </p>

                                    <?php if ($avgPct > 0) { ?>
                                        <div class="sd-progress-row">
                                            <div class="sd-progress-track">
                                                <div class="sd-progress-fill <?php echo $progressColor; ?>" style="width:<?php echo min($avgPct, 100); ?>%"></div>
                                            </div>
                                            <span class="sd-progress-pct <?php echo $progressColor; ?>"><?php echo $avgPct; ?>%</span>
                                        </div>
                                    <?php } ?>

                                    <?php if ($totalQuestions > 0) { ?>
                                        <div class="sd-errors-badge <?php echo $errorsClass; ?>">
                                            <?php echo $totalErrors; ?> / <?php echo $totalQuestions; ?> errores
                                        </div>
                                    <?php } ?>

                                    <div class="sd-card-actions">
                                        <a class="sd-btn" href="student_course.php?assignment=<?php echo urlencode($assignmentId); ?>">Entrar</a>
                                        <?php if ($cardQuizHref !== '') { ?>
                                            <a class="sd-btn" href="<?php echo h($cardQuizHref); ?>">Quiz</a>
                                        <?php } ?>
                                        <a class="sd-btn" href="student_quiz.php?assignment=<?php echo urlencode($assignmentId); ?>">Puntajes</a>
                                    </div>

                                </div>
                            <?php } ?>
                        </div>
                    </section>
                <?php } ?>
            <?php } ?>
        </div>

    </main>
</div>

<script>
(function () {
    var btns  = document.querySelectorAll('.sd-phase-btn');
    var sections = document.querySelectorAll('.sd-phase-section');
    var title = document.getElementById('sd-main-title');
    var labels = { english: 'English Courses', technical: 'Technical Courses', lifeskills: 'Life Skills' };

    function applyTab(tab) {
        btns.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
        sections.forEach(function (section) {
            var prog = section.dataset.program || '';
            var show = (tab === 'english'    && prog === 'english')
                    || (tab === 'technical'  && prog === 'technical')
                    || (tab === 'lifeskills');
            section.style.display = show ? '' : 'none';
        });
        if (title) { title.textContent = labels[tab] || 'Courses'; }
    }

    btns.forEach(function (btn) {
        btn.addEventListener('click', function () { applyTab(this.dataset.tab); });
    });

    applyTab('english');
}());
</script>
</body>
</html>

<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function teacher_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'DC';
    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) === 2) break;
    }
    return $initials !== '' ? $initials : 'DC';
}

function resolve_photo_src(string $photo): string
{
    $photo = trim($photo);
    if ($photo === '') return '';
    if (preg_match('/^https?:\/\//i', $photo)) return $photo;
    $full = __DIR__ . '/' . ltrim($photo, '/');
    return is_file($full) ? htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') : '';
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

function get_activity_base_path(string $type): ?string
{
    if (!preg_match('/^[a-z0-9_]+$/i', $type)) {
        return null;
    }

    $absolute = __DIR__ . '/../activities/' . $type;
    if (!is_dir($absolute)) {
        return null;
    }

    return '../activities/' . rawurlencode($type);
}

function load_teacher_permission_from_accounts(PDO $pdo, string $teacherId, ?string $scope = null, ?string $targetId = null): string
{
    if ($teacherId === '') {
        return 'viewer';
    }

    try {
        if (!table_exists($pdo, 'teacher_accounts')) {
            return 'viewer';
        }

        $scope = trim((string) $scope);
        $targetId = trim((string) $targetId);

        if (
          $scope !== '' &&
          $targetId !== '' &&
          column_exists($pdo, 'teacher_accounts', 'scope') &&
          column_exists($pdo, 'teacher_accounts', 'target_id')
        ) {
          $stmtScoped = $pdo->prepare("
            SELECT permission
            FROM teacher_accounts
            WHERE teacher_id = :teacher_id
              AND scope = :scope
              AND target_id = :target_id
            ORDER BY updated_at DESC NULLS LAST
            LIMIT 1
          ");
          $stmtScoped->execute([
            'teacher_id' => $teacherId,
            'scope' => $scope,
            'target_id' => $targetId,
          ]);
          $permissionScoped = (string) $stmtScoped->fetchColumn();

          if ($permissionScoped !== '') {
            return $permissionScoped === 'editor' ? 'editor' : 'viewer';
          }
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

function load_assignment(PDO $pdo, string $assignmentId): ?array
{
    if ($assignmentId === '') {
        return null;
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
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function load_english_units(PDO $pdo, string $phaseId): array
{
    if ($phaseId === '') {
        return [];
    }

    if (!table_exists($pdo, 'units') || !column_exists($pdo, 'units', 'phase_id')) {
        return [];
    }

    try {
        $orderBy = column_exists($pdo, 'units', 'position')
            ? 'ORDER BY position ASC, id ASC'
            : 'ORDER BY id ASC';

        $stmt = $pdo->prepare("
            SELECT id, name
            FROM units
            WHERE phase_id = :phase_id
            {$orderBy}
        ");
        $stmt->execute(['phase_id' => $phaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_technical_units(PDO $pdo, string $courseId, ?string $preferredUnitId = null, ?string $preferredUnitName = null): array
{
    $preferredUnitId = trim((string) $preferredUnitId);
    $preferredUnitName = trim((string) $preferredUnitName);

    if ($preferredUnitId !== '') {
        return [[
            'id' => $preferredUnitId,
            'name' => $preferredUnitName !== '' ? $preferredUnitName : 'Unidad',
        ]];
    }

    if ($courseId === '') {
        return [];
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

        try {
            $orderBy = column_exists($pdo, $table, 'position')
                ? 'ORDER BY position ASC, id ASC'
                : 'ORDER BY id ASC';

            $stmt = $pdo->prepare("
                SELECT id, {$nameColumn} AS name
                FROM {$table}
                WHERE {$courseColumn} = :course_id
                {$orderBy}
            ");
            $stmt->execute(['course_id' => $courseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!empty($rows)) {
                return $rows;
            }
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}

function load_activities_for_units(PDO $pdo, array $unitIds): array
{
    $unitIds = array_values(array_filter(array_map('strval', $unitIds), static fn ($v): bool => $v !== ''));
    if (empty($unitIds)) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));

        $orderBy = 'unit_id ASC, id ASC';
        if (column_exists($pdo, 'activities', 'position')) {
            $orderBy = 'unit_id ASC, COALESCE(position, 0) ASC, id ASC';
        }

        $selectFields = 'id, type, unit_id';
        if (column_exists($pdo, 'activities', 'data')) {
          $selectFields .= ', data';
        }

        $sql = "
          SELECT {$selectFields}
            FROM activities
            WHERE unit_id IN ($placeholders)
            ORDER BY {$orderBy}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($unitIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function decode_activity_data(array $activity): array
{
  $raw = $activity['data'] ?? null;
  if (!is_string($raw) || trim($raw) === '') {
    return [];
  }

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function is_passive_activity(array $activity): bool
{
  $type = strtolower(trim((string) ($activity['type'] ?? '')));
  $passiveTypes = ['downloadable', 'video_lesson', 'flipbooks', 'external', 'powerpoint'];
  if (in_array($type, $passiveTypes, true)) {
    return true;
  }

  if ($type === 'video_comprehension') {
    $data = decode_activity_data($activity);
    $mode = strtolower(trim((string) ($data['mode'] ?? '')));
    if (in_array($mode, ['video', 'video_only', 'only_video'], true)) {
      return true;
    }
  }

  return false;
}

function activity_mix(array $activities): array
{
  $mix = [
    'total' => 0,
    'passive' => 0,
    'evaluable' => 0,
  ];

  foreach ($activities as $activity) {
    $mix['total']++;
    if (is_passive_activity((array) $activity)) {
      $mix['passive']++;
      continue;
    }
    $mix['evaluable']++;
  }

  return $mix;
}

function read_unit_performance(string $teacherId, string $assignmentId, string $unitId): array
{
  $key = $teacherId . '|' . $assignmentId . '|' . $unitId;
  $all = $_SESSION['teacher_unit_performance'] ?? [];
  if (!is_array($all) || !isset($all[$key]) || !is_array($all[$key])) {
    return [];
  }
  return $all[$key];
}

function read_activity_scores(string $teacherId, string $assignmentId, string $unitId): array
{
  $key = $teacherId . '|' . $assignmentId . '|' . $unitId;
  $all = $_SESSION['teacher_activity_scores'] ?? [];
  if (!is_array($all) || !isset($all[$key]) || !is_array($all[$key])) {
    return [];
  }
  return $all[$key];
}

function ensure_performance_tables(PDO $pdo): void
{
  try {
    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS teacher_unit_results (\n        teacher_id TEXT NOT NULL,\n        assignment_id TEXT NOT NULL,\n        unit_id TEXT NOT NULL,\n        completion_percent INTEGER NOT NULL DEFAULT 0,\n        quiz_errors INTEGER NOT NULL DEFAULT 0,\n        quiz_total INTEGER NOT NULL DEFAULT 0,\n        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n        PRIMARY KEY (teacher_id, assignment_id, unit_id)\n      )\n    ");

    $pdo->exec("\n      CREATE TABLE IF NOT EXISTS teacher_activity_results (\n        teacher_id TEXT NOT NULL,\n        assignment_id TEXT NOT NULL,\n        unit_id TEXT NOT NULL,\n        activity_id TEXT NOT NULL,\n        percent INTEGER NOT NULL DEFAULT 0,\n        errors_count INTEGER NOT NULL DEFAULT 0,\n        total_questions INTEGER NOT NULL DEFAULT 0,\n        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),\n        PRIMARY KEY (teacher_id, assignment_id, unit_id, activity_id)\n      )\n    ");
  } catch (Throwable $e) {
  }
}

function save_unit_performance_db(PDO $pdo, string $teacherId, string $assignmentId, string $unitId, int $completionPercent, int $quizErrors, int $quizTotal): void
{
  if ($teacherId === '' || $assignmentId === '' || $unitId === '') {
    return;
  }

  try {
    $stmt = $pdo->prepare("\n      INSERT INTO teacher_unit_results (teacher_id, assignment_id, unit_id, completion_percent, quiz_errors, quiz_total, updated_at)\n      VALUES (:teacher_id, :assignment_id, :unit_id, :completion_percent, :quiz_errors, :quiz_total, NOW())\n      ON CONFLICT (teacher_id, assignment_id, unit_id)\n      DO UPDATE SET\n        completion_percent = EXCLUDED.completion_percent,\n        quiz_errors = EXCLUDED.quiz_errors,\n        quiz_total = EXCLUDED.quiz_total,\n        updated_at = NOW()\n    ");

    $stmt->execute([
      'teacher_id' => $teacherId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
      'completion_percent' => max(0, min(100, $completionPercent)),
      'quiz_errors' => max(0, $quizErrors),
      'quiz_total' => max(0, $quizTotal),
    ]);
  } catch (Throwable $e) {
  }
}

function save_activity_score_db(PDO $pdo, string $teacherId, string $assignmentId, string $unitId, string $activityId, int $percent, int $errors, int $total): void
{
  if ($teacherId === '' || $assignmentId === '' || $unitId === '' || $activityId === '') {
    return;
  }

  try {
    $stmt = $pdo->prepare("\n      INSERT INTO teacher_activity_results (teacher_id, assignment_id, unit_id, activity_id, percent, errors_count, total_questions, updated_at)\n      VALUES (:teacher_id, :assignment_id, :unit_id, :activity_id, :percent, :errors_count, :total_questions, NOW())\n      ON CONFLICT (teacher_id, assignment_id, unit_id, activity_id)\n      DO UPDATE SET\n        percent = EXCLUDED.percent,\n        errors_count = EXCLUDED.errors_count,\n        total_questions = EXCLUDED.total_questions,\n        updated_at = NOW()\n    ");

    $stmt->execute([
      'teacher_id' => $teacherId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
      'activity_id' => $activityId,
      'percent' => max(0, min(100, $percent)),
      'errors_count' => max(0, $errors),
      'total_questions' => max(0, $total),
    ]);
  } catch (Throwable $e) {
  }
}

function load_unit_performance_db(PDO $pdo, string $teacherId, string $assignmentId, string $unitId): array
{
  if ($teacherId === '' || $assignmentId === '' || $unitId === '') {
    return [];
  }

  try {
    $stmt = $pdo->prepare("\n      SELECT completion_percent, quiz_errors, quiz_total\n      FROM teacher_unit_results\n      WHERE teacher_id = :teacher_id\n        AND assignment_id = :assignment_id\n        AND unit_id = :unit_id\n      LIMIT 1\n    ");
    $stmt->execute([
      'teacher_id' => $teacherId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  } catch (Throwable $e) {
    return [];
  }
}

function load_activity_scores_db(PDO $pdo, string $teacherId, string $assignmentId, string $unitId): array
{
  if ($teacherId === '' || $assignmentId === '' || $unitId === '') {
    return [];
  }

  try {
    $stmt = $pdo->prepare("\n      SELECT activity_id, percent, errors_count, total_questions\n      FROM teacher_activity_results\n      WHERE teacher_id = :teacher_id\n        AND assignment_id = :assignment_id\n        AND unit_id = :unit_id\n      ORDER BY updated_at DESC\n    ");
    $stmt->execute([
      'teacher_id' => $teacherId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $mapped = [];
    foreach ($rows as $row) {
      $activityId = (string) ($row['activity_id'] ?? '');
      if ($activityId === '') {
        continue;
      }
      $mapped[$activityId] = [
        'percent' => (int) ($row['percent'] ?? 0),
        'errors' => (int) ($row['errors_count'] ?? 0),
        'total' => (int) ($row['total_questions'] ?? 0),
      ];
    }

    return $mapped;
  } catch (Throwable $e) {
    return [];
  }
}

function build_lowest_scored_activities(array $activities, array $scoresByActivityId, array $activityTypeLabels, string $assignmentId, string $unitId, int $limit = 3): array
{
  $rows = [];
  foreach ($activities as $index => $activity) {
    $activity = (array) $activity;
    if (is_passive_activity($activity)) {
      continue;
    }

    $activityId = (string) ($activity['id'] ?? '');
    if ($activityId === '' || !isset($scoresByActivityId[$activityId]) || !is_array($scoresByActivityId[$activityId])) {
      continue;
    }

    $score = (int) ($scoresByActivityId[$activityId]['percent'] ?? -1);
    if ($score < 0) {
      continue;
    }

    $score = max(0, min(100, $score));
    $type = strtolower(trim((string) ($activity['type'] ?? 'actividad')));
    $label = $activityTypeLabels[$type] ?? ucwords(str_replace('_', ' ', $type));

    $rows[] = [
      'activity_id' => $activityId,
      'type_label' => $label,
      'percent' => $score,
      'step' => (int) $index,
      'practice_href' => 'teacher_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unitId) . '&step=' . urlencode((string) $index),
    ];
  }

  usort($rows, static function (array $a, array $b): int {
    if ((int) $a['percent'] === (int) $b['percent']) {
      return (int) $a['step'] <=> (int) $b['step'];
    }
    return (int) $a['percent'] <=> (int) $b['percent'];
  });

  if ($limit < 1) {
    return $rows;
  }

  return array_slice($rows, 0, $limit);
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

ensure_performance_tables($pdo);

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$selectedUnitId = trim((string) ($_GET['unit'] ?? ''));
$teacherId = (string) ($_SESSION['teacher_id'] ?? '');
$mode = (string) ($_GET['mode'] ?? 'view');
$mode = $mode === 'edit' ? 'edit' : 'view';
$step = max(0, (int) ($_GET['step'] ?? 0));

if ($assignmentId === '') {
  die('Asignacion docente no especificada.');
}

$assignment = load_assignment($pdo, $assignmentId);

if (!$assignment || (string) ($assignment['teacher_id'] ?? '') !== $teacherId) {
    die('No tienes permiso para este curso.');
}

$programType = (string) ($assignment['program_type'] ?? 'technical');
$courseId = (string) ($assignment['course_id'] ?? '');
$assignmentUnitId = (string) ($assignment['unit_id'] ?? '');
$assignmentUnitName = (string) ($assignment['unit_name'] ?? '');
$scope = $programType === 'english' ? 'english' : 'technical';

$permission = load_teacher_permission_from_accounts($pdo, $teacherId, $scope, $courseId);
if ($mode === 'edit' && $permission !== 'editor') {
  $mode = 'view';
}

if ($programType === 'english') {
    $allUnits = load_english_units($pdo, $courseId);
} else {
    $allUnits = load_technical_units($pdo, $courseId, $assignmentUnitId, $assignmentUnitName);
}

$units = [];
if ($selectedUnitId !== '') {
    foreach ($allUnits as $unit) {
        if ((string) ($unit['id'] ?? '') === $selectedUnitId) {
            $units[] = $unit;
            break;
        }
    }

    if (empty($units) && $selectedUnitId !== '') {
        $units[] = [
            'id' => $selectedUnitId,
            'name' => $assignmentUnitName !== '' ? $assignmentUnitName : 'Unidad',
        ];
    }
} else {
    $units = $allUnits;
    if (!empty($units)) {
        $selectedUnitId = (string) ($units[0]['id'] ?? '');
    }
}

$unitIds = array_values(array_filter(array_map(
    static fn ($unit) => (string) ($unit['id'] ?? ''),
    $units
)));

$activities = load_activities_for_units($pdo, $unitIds);
$mix = activity_mix($activities);

$quizTotalRaw = isset($_GET['quiz_total']) ? (int) $_GET['quiz_total'] : -1;
$quizErrorsRaw = isset($_GET['quiz_errors']) ? (int) $_GET['quiz_errors'] : -1;
$quizPercentRaw = isset($_GET['quiz_percent']) ? (int) $_GET['quiz_percent'] : -1;
$quizActivityId = trim((string) ($_GET['quiz_activity_id'] ?? ''));

if ($selectedUnitId !== '' && $quizTotalRaw >= 0) {
  $quizTotal = max(0, $quizTotalRaw);
  $quizErrors = max(0, $quizErrorsRaw);
  if ($quizTotal > 0 && $quizErrors > $quizTotal) {
    $quizErrors = $quizTotal;
  }

  $quizPercent = $quizTotal > 0
    ? max(0, min(100, (int) round((($quizTotal - $quizErrors) / $quizTotal) * 100)))
    : max(0, min(100, $quizPercentRaw));

  $key = $teacherId . '|' . $assignmentId . '|' . $selectedUnitId;
  if (!isset($_SESSION['teacher_unit_performance']) || !is_array($_SESSION['teacher_unit_performance'])) {
    $_SESSION['teacher_unit_performance'] = [];
  }

  $_SESSION['teacher_unit_performance'][$key] = [
    'quiz_total' => $quizTotal,
    'quiz_errors' => $quizErrors,
    'completion_percent' => $quizPercent,
    'updated_at' => time(),
  ];

  save_unit_performance_db($pdo, $teacherId, $assignmentId, $selectedUnitId, $quizPercent, $quizErrors, $quizTotal);

  if ($quizActivityId !== '') {
    if (!isset($_SESSION['teacher_activity_scores']) || !is_array($_SESSION['teacher_activity_scores'])) {
      $_SESSION['teacher_activity_scores'] = [];
    }
    if (!isset($_SESSION['teacher_activity_scores'][$key]) || !is_array($_SESSION['teacher_activity_scores'][$key])) {
      $_SESSION['teacher_activity_scores'][$key] = [];
    }

    $_SESSION['teacher_activity_scores'][$key][$quizActivityId] = [
      'percent' => $quizPercent,
      'errors' => $quizErrors,
      'total' => $quizTotal,
      'updated_at' => time(),
    ];

    save_activity_score_db($pdo, $teacherId, $assignmentId, $selectedUnitId, $quizActivityId, $quizPercent, $quizErrors, $quizTotal);
  }
}

$total = count($activities);
$isCompleted = $total > 0 && $step >= $total;
if ($step < 0) {
  $step = 0;
}

$current = (!$isCompleted && $total > 0) ? $activities[$step] : null;
$prevStep = max(0, $step - 1);
$nextStep = $step + 1;
$hasPrev = $step > 0;
$hasNext = $nextStep < $total;
$isLastActivity = !$isCompleted && $total > 0 && $step === ($total - 1);
$unitPerformance = read_unit_performance($teacherId, $assignmentId, $selectedUnitId);
$dbUnitPerformance = load_unit_performance_db($pdo, $teacherId, $assignmentId, $selectedUnitId);
if (!empty($dbUnitPerformance)) {
  $unitPerformance = array_merge($unitPerformance, [
    'completion_percent' => (int) ($dbUnitPerformance['completion_percent'] ?? ($unitPerformance['completion_percent'] ?? 0)),
    'quiz_errors' => (int) ($dbUnitPerformance['quiz_errors'] ?? ($unitPerformance['quiz_errors'] ?? 0)),
    'quiz_total' => (int) ($dbUnitPerformance['quiz_total'] ?? ($unitPerformance['quiz_total'] ?? 0)),
  ]);
}

$completionPercent = (int) ($unitPerformance['completion_percent'] ?? 0);
$quizErrors = (int) ($unitPerformance['quiz_errors'] ?? 0);
$quizTotal = (int) ($unitPerformance['quiz_total'] ?? 0);
$hasUnitResult = $quizTotal > 0;

$activityTypeLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice',
    'video_comprehension' => 'Video Comprehension',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Video Lesson',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'powerpoint' => 'PowerPoint',
    'build_sentence' => 'Build the Sentence',
];

$activityScores = read_activity_scores($teacherId, $assignmentId, $selectedUnitId);
$dbActivityScores = load_activity_scores_db($pdo, $teacherId, $assignmentId, $selectedUnitId);
if (!empty($dbActivityScores)) {
  $activityScores = array_merge($activityScores, $dbActivityScores);
}

$lowestScoredActivities = build_lowest_scored_activities(
  $activities,
  $activityScores,
  $activityTypeLabels,
  $assignmentId,
  $selectedUnitId,
  3
);

$viewerHref = null;
$currentTypeLabel = 'Actividad';

if ($current) {
    $type = (string) ($current['type'] ?? '');
    $activityPath = get_activity_base_path($type);

    if ($activityPath) {
        $query = http_build_query([
            'id' => (string) ($current['id'] ?? ''),
            'unit' => (string) ($current['unit_id'] ?? ''),
            'embedded' => '1',
            'from' => 'teacher_course',
            'assignment' => $assignmentId,
        ]);

        $viewerHref = $activityPath . '/viewer.php?' . $query;

    }

    $currentType = strtolower($type);
    $currentTypeLabel = $activityTypeLabels[$currentType] ?? ucwords(str_replace('_', ' ', $type));
}

$teacherName    = trim((string) ($_SESSION['teacher_name'] ?? 'Docente'));
$teacherInitials = teacher_initials($teacherName);
$teacherPhotoRaw = trim((string) ($_SESSION['teacher_photo'] ?? ''));
$teacherPhotoSrc = resolve_photo_src($teacherPhotoRaw);

$backDashboard = 'dashboard.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '#unidades-curso';
$completedStep = max(9999, $total);
$completedHref = 'teacher_course.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '&step=' . urlencode((string) $completedStep);
$quizHref = 'teacher_quiz.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '&return_to=' . urlencode($completedHref);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($currentTypeLabel); ?> - <?php echo h((string)($assignment['course_name'] ?? 'Curso')); ?></title>
<style>
:root{
  --bg:#eef5ff;
  --card:#ffffff;
  --line:#d8e2f2;
  --text:#1b3050;
  --title:#0f1f42;
  --muted:#5d6f8f;
  --blue:#2563eb;
  --blue-dark:#1d4ed8;
  --blue-soft:#e9f1ff;
  --blue-pale:#f8fbff;
  --warning:#f59e0b;
  --warning-dark:#d97706;
  --danger:#dc2626;
  --shadow:0 10px 24px rgba(0,0,0,.08);
  --shadow-sm:0 2px 8px rgba(0,0,0,.06);
  --radius:18px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:var(--bg);color:var(--text)}

.topbar{
  background:linear-gradient(180deg, var(--blue), var(--blue-dark));
  color:#fff;
  padding:16px 24px;
}

.topbar-inner{
  max-width:1280px;
  margin:0 auto;
  display:grid;
  grid-template-columns:180px 1fr;
  align-items:center;
  gap:12px;
}

.top-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:10px 14px;
  border-radius:10px;
  text-decoration:none;
  font-size:13px;
  font-weight:700;
  color:#fff;
  box-shadow:var(--shadow);
  background:rgba(255,255,255,.2);
}

.top-btn.back{ justify-self:start; }

.topbar-title{
  font-size:28px;
  font-weight:800;
  text-align:center;
}

.topbar-sub{display:none}

.page{
  max-width:1280px;
  margin:0 auto;
  padding:18px 20px 24px;
}

.layout{
  display:grid;
  grid-template-columns:1fr;
  gap:18px;
  align-items:start;
}

.sidebar{
  display:none;
  background:#e3ecff;
  border-radius:20px;
  padding:18px 14px;
  box-shadow:var(--shadow);
  min-height:calc(100vh - 150px);
}

.logo-wrap{
  text-align:center;
  margin-bottom:16px;
}

.avatar{
  width:90px;
  height:90px;
  border-radius:50%;
  margin:0 auto;
  overflow:hidden;
  background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  border:3px solid #f0f4ff;
  display:flex;
  align-items:center;
  justify-content:center;
  box-shadow:0 8px 20px rgba(59,130,246,.15);
}

.avatar-image{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}

.avatar-fallback{
  display:none;
  width:100%;
  height:100%;
  align-items:center;
  justify-content:center;
  color:var(--blue-dark);
  font-size:24px;
  font-weight:800;
  letter-spacing:.08em;
}

.side-btn{
  display:block;
  width:100%;
  text-align:center;
  text-decoration:none;
  color:#fff;
  font-weight:700;
  font-size:14px;
  padding:12px 10px;
  border-radius:12px;
  margin-bottom:12px;
  box-shadow:var(--shadow-sm);
}

.side-btn.blue{ background:linear-gradient(180deg,#3d73ee,#2563eb); }
.side-btn.gray{ background:linear-gradient(180deg,#7b8b9e,#66758b); }
.side-btn.red{ background:linear-gradient(180deg,#ef4444,#dc2626); }

.content{display:flex;flex-direction:column;gap:18px;min-width:0}

.hero-card,
.viewer-shell,
.empty-shell{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:22px;
  box-shadow:var(--shadow);
}

.hero-card{
  position:relative;
  overflow:hidden;
  padding:16px 18px;
  background:linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
}

.hero-card::before{
  content:"";
  position:absolute;
  inset:auto -80px -100px auto;
  width:240px;
  height:240px;
  background:radial-gradient(circle, rgba(59,130,246,.18) 0%, rgba(59,130,246,0) 70%);
  pointer-events:none;
}

.activity-topline{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:5px 10px;
  border-radius:999px;
  background:var(--blue-soft);
  color:var(--blue-dark);
  font-size:11px;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:.08em;
}

.hero-title{
  margin:10px 0 8px;
  font-size:20px;
  font-weight:800;
  color:var(--title);
}

.hero-text{
  margin:0;
  font-size:14px;
  line-height:1.6;
  color:var(--text);
  max-width:760px;
}

.hero-badges{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  margin-top:10px;
}

.hero-badge{
  display:inline-flex;
  align-items:center;
  padding:7px 12px;
  border-radius:999px;
  background:var(--blue-soft);
  color:var(--blue-dark);
  font-size:12px;
  font-weight:800;
}

.hero-badge.warn{
  background:#fff3d9;
  color:var(--warning-dark);
}

.viewer-shell{padding:18px}

.viewer-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  margin-bottom:14px;
  flex-wrap:wrap;
}

.section-title{
  display:flex;
  align-items:center;
  gap:12px;
  font-size:24px;
  font-weight:800;
  color:var(--title);
}

.section-title::after{
  content:"";
  flex:1;
  height:2px;
  min-width:60px;
  background:linear-gradient(90deg, var(--line) 0%, transparent 100%);
}

.act-badge{
  display:inline-flex;
  align-items:center;
  padding:7px 12px;
  border-radius:999px;
  background:var(--blue-soft);
  color:var(--blue-dark);
  font-size:12px;
  font-weight:800;
  letter-spacing:.04em;
  text-transform:uppercase;
}

.frame-wrap{
  border-radius:18px;
  overflow:hidden;
  background:#fff;
  border:1px solid var(--line);
  box-shadow:var(--shadow-sm);
  min-height:78vh;
}

.frame-wrap iframe{display:block;width:100%;height:78vh;border:0;background:#fff}

.controls{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding-top:16px;
}

.step-counter{
  font-size:13px;
  font-weight:700;
  color:var(--muted);
  text-align:center;
}

.step-counter strong{color:var(--title)}

.ctrl-btn,
.empty-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  min-width:130px;
  padding:12px 18px;
  border-radius:12px;
  text-decoration:none;
  color:#fff;
  font-size:14px;
  font-weight:700;
  background:linear-gradient(180deg,#3d73ee,#2563eb);
  box-shadow:var(--shadow-sm);
  transition:filter .15s, transform .15s;
}

.ctrl-btn.warn{
  background:linear-gradient(180deg,#fbbf24,#d97706);
}

.ctrl-btn:hover,
.empty-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.ctrl-btn.disabled{opacity:.38;pointer-events:none}

.empty-shell{
  padding:40px 24px;
  text-align:center;
}

.empty-state{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:14px;
}

.empty-icon{font-size:46px}
.empty-title{font-size:24px;font-weight:800;color:var(--title)}
.empty-text{max-width:480px;font-size:15px;line-height:1.6;color:var(--muted)}

.low-score-wrap{
  width:100%;
  max-width:780px;
  margin-top:8px;
  padding:14px;
  border:1px solid var(--line);
  border-radius:14px;
  background:#f8fbff;
  text-align:left;
}

.low-score-title{
  margin:0 0 10px;
  font-size:14px;
  font-weight:800;
  color:var(--title);
}

.low-score-row{
  display:flex;
  align-items:center;
  gap:10px;
  justify-content:space-between;
  padding:10px 0;
  border-top:1px solid #dbe8ff;
}

.low-score-row:first-of-type{border-top:none;padding-top:0}

.low-score-meta{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.low-score-pill{
  display:inline-flex;
  align-items:center;
  padding:6px 10px;
  border-radius:999px;
  background:var(--blue-soft);
  color:var(--blue-dark);
  font-size:12px;
  font-weight:800;
}

.low-practice-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:9px 12px;
  border-radius:10px;
  text-decoration:none;
  color:#fff;
  font-size:12px;
  font-weight:800;
  background:linear-gradient(180deg,#3d73ee,#2563eb);
  box-shadow:var(--shadow-sm);
}

@media (max-width: 1100px){
  .layout{grid-template-columns:1fr}
  .sidebar{min-height:auto}
}

@media (max-width: 768px){
  .topbar{ padding:14px; }
  .topbar-inner{
    grid-template-columns:1fr;
    text-align:center;
  }
  .top-btn.back{ justify-self:center; }
  .topbar-title{ font-size:24px; }
  .page{padding:12px}
  .hero-card,.viewer-shell,.empty-shell{border-radius:18px}
  .hero-card{padding:20px}
  .section-title{font-size:20px}
  .frame-wrap{min-height:56vh}
  .frame-wrap iframe{height:56vh}
  .controls{flex-wrap:wrap}
  .ctrl-btn,.empty-btn{flex:1 1 100%;min-width:0}
  .step-counter{width:100%;order:-1}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a class="top-btn back" href="<?php echo h($backDashboard); ?>">← Volver</a>
    <h1 class="topbar-title">Presentación del Curso</h1>
  </div>
</header>

<div class="page">
<div class="layout">
  <nav class="sidebar">
    <div class="logo-wrap">
      <div class="avatar">
        <?php if ($teacherPhotoSrc !== '') { ?>
          <img
            class="avatar-image"
            src="<?php echo h($teacherPhotoSrc); ?>"
            alt="Foto de <?php echo h($teacherName); ?>"
            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
          >
        <?php } ?>
        <span class="avatar-fallback" aria-hidden="true" style="<?php echo $teacherPhotoSrc === '' ? 'display:flex;' : ''; ?>"><?php echo h($teacherInitials); ?></span>
      </div>
    </div>

    <a class="side-btn blue" href="<?php echo h($backDashboard); ?>">📚 Volver a mis cursos</a>

    <?php if ($permission === 'editor') { ?>
      <a class="side-btn blue"
         href="teacher_unit.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=edit">
        ✏️ Editar curso
      </a>
    <?php } ?>

    <a class="side-btn gray" href="teacher_assignments.php">🧾 Mis asignaciones</a>

    <a class="side-btn red" href="/lessons/lessons/academic/logout.php">🚪 Cerrar sesión</a>
  </nav>

  <?php if ($isCompleted) { ?>
    <main class="content">
      <section class="hero-card">
        <div class="activity-topline">Unidad finalizada</div>
        <h1 class="hero-title">✅ Completed</h1>
        <p class="hero-text">Terminaste todas las actividades de la unidad. El porcentaje final se calcula por errores en actividades evaluables y excluye actividades pasivas (por ejemplo: descargables y video solo).</p>
        <div class="hero-badges">
          <span class="hero-badge"><?php echo h((string)($assignment['course_name'] ?? 'Curso')); ?></span>
          <?php if (trim((string)($assignment['unit_name'] ?? '')) !== '') { ?>
            <span class="hero-badge warn"><?php echo h((string)$assignment['unit_name']); ?></span>
          <?php } ?>
          <span class="hero-badge">Porcentaje: <?php echo $completionPercent; ?>%</span>
          <?php if ($hasUnitResult) { ?>
            <span class="hero-badge">Errores: <?php echo $quizErrors; ?>/<?php echo $quizTotal; ?></span>
          <?php } ?>
          <span class="hero-badge">Pasivas excluidas: <?php echo (int) $mix['passive']; ?></span>
        </div>
      </section>

      <section class="empty-shell">
        <div class="empty-state">
          <div class="empty-icon">🏁</div>
          <div class="empty-title">Unidad completada</div>
          <div class="empty-text"><?php echo $hasUnitResult ? 'Resultado calculado. Si deseas mejorar el porcentaje, puedes repetir el quiz de la unidad.' : 'Aún no hay resultado guardado para esta unidad. Abre Quiz time para registrar errores y porcentaje final.'; ?></div>
          <?php if (!empty($lowestScoredActivities)) { ?>
            <div class="low-score-wrap">
              <h3 class="low-score-title">Actividades con menor puntuación</h3>
              <?php foreach ($lowestScoredActivities as $lowItem) { ?>
                <div class="low-score-row">
                  <div class="low-score-meta">
                    <span class="low-score-pill"><?php echo h((string) ($lowItem['type_label'] ?? 'Actividad')); ?></span>
                    <span class="low-score-pill">Puntaje: <?php echo (int) ($lowItem['percent'] ?? 0); ?>%</span>
                  </div>
                  <a class="low-practice-btn" href="<?php echo h((string) ($lowItem['practice_href'] ?? '#')); ?>">Practicar nuevamente</a>
                </div>
              <?php } ?>
            </div>
          <?php } ?>
          <div class="controls" style="padding-top:0; width:100%; justify-content:center;">
            <a class="empty-btn" href="<?php echo h($backDashboard); ?>">&larr; Volver al panel docente</a>
            <a class="empty-btn ctrl-btn warn" href="<?php echo h($quizHref); ?>">Quiz time</a>
          </div>
        </div>
      </section>
    </main>
  <?php } elseif (!$current || !$viewerHref) { ?>
    <main class="content">
      <section class="hero-card">
        <div class="activity-topline">Vista del curso</div>
        <h1 class="hero-title"><?php echo h((string)($assignment['course_name'] ?? 'Curso')); ?></h1>
        <p class="hero-text">Aquí se presenta la actividad actual con el mismo estilo visual del panel académico, priorizando lectura clara, navegación lateral y el visor al centro.</p>
        <div class="hero-badges">
          <span class="hero-badge"><?php echo h($currentTypeLabel); ?></span>
          <?php if (trim((string)($assignment['unit_name'] ?? '')) !== '') { ?>
            <span class="hero-badge warn"><?php echo h((string)$assignment['unit_name']); ?></span>
          <?php } ?>
        </div>
      </section>

      <section class="empty-shell">
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <div class="empty-title">Sin actividades disponibles</div>
          <div class="empty-text">Esta unidad aún no tiene actividades para presentar o el tipo de actividad no cuenta con visor configurado.</div>
          <a class="empty-btn" href="<?php echo h($backDashboard); ?>">&larr; Volver al panel docente</a>
        </div>
      </section>
    </main>
  <?php } else { ?>
    <main class="content">
      <section class="hero-card">
        <div class="activity-topline">Actividad para hoy</div>
        <h1 class="hero-title"><?php echo h($currentTypeLabel); ?></h1>
        <p class="hero-text">Presentación del curso en modo docente con navegación secuencial entre actividades, visor central y contexto del curso siempre visible.</p>
        <div class="hero-badges">
          <span class="hero-badge"><?php echo h((string)($assignment['course_name'] ?? 'Curso')); ?></span>
          <?php if (trim((string)($assignment['unit_name'] ?? '')) !== '') { ?>
            <span class="hero-badge warn"><?php echo h((string)$assignment['unit_name']); ?></span>
          <?php } ?>
          <span class="hero-badge">Paso <?php echo ($step + 1); ?> de <?php echo $total; ?></span>
        </div>
      </section>

      <section class="viewer-shell">
        <div class="viewer-top">
          <h2 class="section-title">Presentación de actividades</h2>
          <span class="act-badge">Actividad <?php echo ($step + 1); ?> / <?php echo $total; ?></span>
        </div>

        <div class="frame-wrap">
          <iframe
            id="activityViewer"
            src="<?php echo h($viewerHref); ?>"
            title="Visor de actividad"
          ></iframe>
        </div>

        <div class="controls">
          <a class="ctrl-btn <?php echo $hasPrev ? '' : 'disabled'; ?>"
             href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasPrev ? $prevStep : $step; ?>">
            &larr; Anterior
          </a>
          <div class="step-counter">
            <strong><?php echo ($step + 1); ?></strong> / <?php echo $total; ?>
          </div>
          <a class="ctrl-btn <?php echo ($hasNext || $isLastActivity) ? '' : 'disabled'; ?>"
             href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $isLastActivity ? $total : ($hasNext ? $nextStep : $step); ?>">
            <?php echo $isLastActivity ? 'Finalizar unidad' : 'Siguiente →'; ?>
          </a>
        </div>
      </section>
    </main>
  <?php } ?>
</div>
</div>

<script>
(function () {
    const iframe = document.getElementById('activityViewer');
    if (!iframe) return;

    function hideEmbeddedBackButton() {
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return;

            const selectors = [
                '.back','.btn-volver','.back-button','.btn.back','.back-btn',
              '[class*="back"]','[id*="back"]',
                'a[href*="dashboard"]','a[href*="unit_view"]',
              'a[href*="technical_units"]','a[href*="english_structure_units"]',
              'a[href*="teacher_course"]','a[href*="course.php"]'
            ];

            selectors.forEach((selector) => {
                doc.querySelectorAll(selector).forEach((el) => {
                    const text = (el.textContent || '').toLowerCase();
                    const href = (el.getAttribute('href') || '').toLowerCase();
                    if (
                      text.includes('volver') || text.includes('back') ||
                      text.includes('regresar') || text.includes('mis cursos') ||
                        href.includes('dashboard') || href.includes('unit_view') ||
                      href.includes('technical_units') || href.includes('english_structure_units') ||
                      href.includes('teacher_course') || href.includes('course.php')
                    ) {
                        el.style.display = 'none';
                    }
                });
            });

            doc.querySelectorAll('a, button').forEach((el) => {
                const text = (el.textContent || '').trim().toLowerCase();
                  if (
                    text === 'back' || text === 'volver' || text === 'regresar' ||
                    text.includes('mis cursos') || text.includes('volver al')
                  ) {
                    el.style.display = 'none';
                }
            });

            const style = doc.createElement('style');
            style.innerHTML = `body{ margin-top:0 !important; padding-top:0 !important; }`;
            doc.head.appendChild(style);
        } catch (e) {
            // Ignorar errores cross-origin
        }
    }

    iframe.addEventListener('load', hideEmbeddedBackButton);
})();
</script>
</body>
</html>

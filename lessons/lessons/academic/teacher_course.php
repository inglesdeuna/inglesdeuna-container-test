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

function load_teacher_permission_from_accounts(PDO $pdo, string $teacherId): string
{
    if ($teacherId === '') {
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

        $sql = "
            SELECT id, type, unit_id
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

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

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

$permission = load_teacher_permission_from_accounts($pdo, $teacherId);
if ($mode === 'edit' && $permission !== 'editor') {
    $mode = 'view';
}

$programType = (string) ($assignment['program_type'] ?? 'technical');
$courseId = (string) ($assignment['course_id'] ?? '');
$assignmentUnitId = (string) ($assignment['unit_id'] ?? '');
$assignmentUnitName = (string) ($assignment['unit_name'] ?? '');

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

$total = count($activities);
if ($step > max(0, $total - 1)) {
    $step = max(0, $total - 1);
}

$current = $total > 0 ? $activities[$step] : null;
$prevStep = max(0, $step - 1);
$nextStep = $step + 1;
$hasPrev = $step > 0;
$hasNext = $nextStep < $total;

$activityTypeLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Video Lesson',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'build_sentence' => 'Build the Sentence',
];

$viewerHref = null;
$editorHref = null;
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

        if ($mode === 'edit' && $permission === 'editor') {
            $editorAbsolute = __DIR__ . '/../activities/' . $type . '/editor.php';
            if (file_exists($editorAbsolute)) {
                $editorHref = $activityPath . '/editor.php?' . $query;
            }
        }
    }

    $currentType = strtolower($type);
    $currentTypeLabel = $activityTypeLabels[$currentType] ?? ucwords(str_replace('_', ' ', $type));
}

$teacherName    = trim((string) ($_SESSION['teacher_name'] ?? 'Docente'));
$teacherInitials = teacher_initials($teacherName);
$teacherPhotoRaw = trim((string) ($_SESSION['teacher_photo'] ?? ''));
$teacherPhotoSrc = resolve_photo_src($teacherPhotoRaw);

$backDashboard = 'dashboard.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($selectedUnitId) . '#unidades-curso';
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
  --line:#d6e4ff;
  --text:#16325c;
  --title:#0f1f42;
  --muted:#5f7294;
  --primary:#2563eb;
  --primary-dark:#1d4ed8;
  --primary-light:#eff6ff;
  --shadow:0 8px 24px rgba(0,0,0,.09);
  --shadow-sm:0 2px 8px rgba(0,0,0,.06);
  --radius:16px;
  --topbar-h:64px;
  --sidebar-w:220px;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:Arial,sans-serif;background:var(--bg);color:var(--text)}

/* TOPBAR */
.topbar{
  position:sticky;top:0;z-index:100;
  height:var(--topbar-h);
  background:linear-gradient(180deg,#2d5fd4,#1a45b8);
  display:flex;align-items:center;
  padding:0 20px;gap:14px;
  box-shadow:0 2px 12px rgba(26,69,184,.35);
}
.topbar-back{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 14px;border-radius:10px;
  background:rgba(255,255,255,.15);
  color:#fff;text-decoration:none;
  font-size:13px;font-weight:700;flex-shrink:0;
  transition:background .2s;
}
.topbar-back:hover{background:rgba(255,255,255,.26)}
.topbar-center{flex:1;text-align:center;min-width:0}
.topbar-title{
  font-size:16px;font-weight:800;color:#fff;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.topbar-sub{font-size:12px;color:rgba(255,255,255,.75);margin-top:2px}
.topbar-avatar{
  flex-shrink:0;width:40px;height:40px;border-radius:50%;overflow:hidden;
  background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4);
  display:flex;align-items:center;justify-content:center;
  font-size:14px;font-weight:800;color:#fff;letter-spacing:.05em;
  cursor:default;
}
.topbar-avatar img{width:100%;height:100%;object-fit:cover;display:block}

/* OUTER */
.outer{
  display:flex;
  height:calc(100dvh - var(--topbar-h));
  overflow:hidden;
}

/* SIDEBAR */
.sidebar{
  width:var(--sidebar-w);flex-shrink:0;
  background:var(--card);border-right:1px solid var(--line);
  display:flex;flex-direction:column;overflow-y:auto;
  padding:16px 12px 20px;gap:6px;
}
.sidebar-profile{
  text-align:center;padding:12px 8px 16px;
  border-bottom:1px solid var(--line);margin-bottom:6px;
}
.sidebar-avatar{
  width:72px;height:72px;border-radius:50%;margin:0 auto 10px;
  overflow:hidden;
  background:linear-gradient(135deg,var(--primary-light),#dbeafe);
  border:3px solid #e0eeff;
  display:flex;align-items:center;justify-content:center;
  font-size:24px;font-weight:800;color:var(--primary-dark);
}
.sidebar-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.sidebar-name{font-size:13px;font-weight:700;color:var(--title);margin-bottom:3px}
.sidebar-role{font-size:11px;color:var(--muted)}
.sidebar-label{
  font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.1em;color:var(--muted);
  padding:10px 4px 4px;
}
.nav-link{
  display:flex;align-items:center;gap:8px;
  padding:10px 12px;border-radius:10px;
  text-decoration:none;color:var(--text);
  font-size:13px;font-weight:600;
  transition:background .15s,color .15s;
}
.nav-link:hover{background:var(--primary-light);color:var(--primary-dark)}
.nav-link.primary{
  background:linear-gradient(180deg,#3d73ee,#2054d4);
  color:#fff;
}
.nav-link.primary:hover{filter:brightness(1.07)}
.nav-link.accent{
  background:linear-gradient(180deg,#fbbf24,#d97706);
  color:#fff;
}
.nav-link.accent:hover{filter:brightness(1.07)}
.nav-link.danger{color:#dc2626}
.nav-link.danger:hover{background:#fff0f0;color:#b91c1c}

/* MAIN CONTENT */
.view-area{
  flex:1;display:flex;flex-direction:column;
  overflow:hidden;padding:14px 16px 0;gap:10px;
}
.act-header{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  flex-shrink:0;flex-wrap:wrap;
}
.act-title{font-size:18px;font-weight:800;color:var(--title)}
.act-meta{display:flex;align-items:center;gap:8px;flex-shrink:0}
.act-badge{
  display:inline-flex;align-items:center;
  padding:5px 12px;border-radius:999px;
  background:var(--primary-light);color:var(--primary-dark);
  font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
}
.edit-link{
  display:inline-flex;align-items:center;gap:5px;
  padding:6px 12px;border-radius:8px;
  background:linear-gradient(180deg,#fbbf24,#d97706);
  color:#fff;font-size:12px;font-weight:700;text-decoration:none;
  box-shadow:var(--shadow-sm);transition:filter .15s;
}
.edit-link:hover{filter:brightness(1.08)}

/* IFRAME */
.frame-wrap{
  flex:1;border-radius:var(--radius);overflow:hidden;
  background:#fff;border:1px solid var(--line);
  box-shadow:var(--shadow);min-height:0;
}
.frame-wrap iframe{display:block;width:100%;height:100%;border:0}

/* CONTROLS */
.controls{
  flex-shrink:0;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:10px 0 14px;
}
.step-counter{font-size:13px;font-weight:700;color:var(--muted);text-align:center}
.step-counter strong{color:var(--title)}
.ctrl-btn{
  display:inline-flex;align-items:center;gap:6px;
  min-width:110px;justify-content:center;
  padding:11px 18px;border-radius:10px;
  text-decoration:none;color:#fff;font-size:14px;font-weight:700;
  background:linear-gradient(180deg,#3d73ee,#2054d4);
  box-shadow:var(--shadow-sm);transition:filter .15s,transform .15s;
}
.ctrl-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.ctrl-btn.disabled{opacity:.38;pointer-events:none}

/* EMPTY */
.empty-state{
  flex:1;display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  padding:40px 20px;text-align:center;gap:14px;
}
.empty-icon{font-size:52px}
.empty-title{font-size:22px;font-weight:800;color:var(--title)}
.empty-text{font-size:15px;color:var(--muted);max-width:400px}
.empty-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:12px 20px;border-radius:10px;
  background:linear-gradient(180deg,#3d73ee,#2054d4);
  color:#fff;text-decoration:none;font-size:14px;font-weight:700;
  box-shadow:var(--shadow-sm);
}

/* RESPONSIVE */
@media (max-width:768px){
  .sidebar{display:none}
  .topbar-title{font-size:14px}
  .topbar-sub{display:none}
  .view-area{padding:10px 10px 0}
  .act-title{font-size:15px}
  .ctrl-btn{min-width:90px;padding:10px 12px;font-size:13px}
}
@media (max-width:480px){
  .topbar{padding:0 12px}
  .topbar-back .topbar-back-label{display:none}
  .controls{gap:8px}
}
</style>
</head>
<body>

<header class="topbar">
  <a class="topbar-back" href="<?php echo h($backDashboard); ?>">
    &larr; <span class="topbar-back-label">Mis Cursos</span>
  </a>
  <div class="topbar-center">
    <div class="topbar-title"><?php echo h((string)($assignment['course_name'] ?? 'Curso')); ?></div>
    <?php if (trim((string)($assignment['unit_name'] ?? '')) !== '') { ?>
      <div class="topbar-sub"><?php echo h((string)$assignment['unit_name']); ?></div>
    <?php } ?>
  </div>
  <div class="topbar-avatar">
    <?php if ($teacherPhotoSrc !== '') { ?>
      <img src="<?php echo $teacherPhotoSrc; ?>" alt="<?php echo h($teacherName); ?>"
           onerror="this.style.display='none';this.parentElement.textContent='<?php echo h($teacherInitials); ?>'">
    <?php } else { ?>
      <?php echo h($teacherInitials); ?>
    <?php } ?>
  </div>
</header>

<div class="outer">
  <nav class="sidebar">
    <div class="sidebar-profile">
      <div class="sidebar-avatar">
        <?php if ($teacherPhotoSrc !== '') { ?>
          <img src="<?php echo $teacherPhotoSrc; ?>" alt="<?php echo h($teacherName); ?>"
               onerror="this.style.display='none'">
        <?php } else { ?>
          <?php echo h($teacherInitials); ?>
        <?php } ?>
      </div>
      <div class="sidebar-name"><?php echo h($teacherName); ?></div>
      <div class="sidebar-role">Docente</div>
    </div>

    <span class="sidebar-label">Navegacion</span>

    <a class="nav-link primary" href="<?php echo h($backDashboard); ?>">
      Mis Cursos
    </a>

    <?php if ($permission === 'editor') { ?>
      <a class="nav-link accent"
         href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=edit&step=<?php echo $step; ?>">
        Editar Curso
      </a>
    <?php } ?>

    <a class="nav-link" href="/lessons/lessons/academic/teacher_groups.php">
      Mis Estudiantes
    </a>

    <a class="nav-link danger" href="/lessons/lessons/academic/logout.php">
      Cerrar sesion
    </a>
  </nav>

  <?php if (!$current || !$viewerHref) { ?>
    <div style="flex:1;display:flex;">
      <div class="empty-state">
        <div class="empty-icon">Sin actividades</div>
        <div class="empty-title">Sin actividades</div>
        <div class="empty-text">Esta unidad aun no tiene actividades para presentar o el tipo de actividad no tiene visor configurado.</div>
        <a class="empty-btn" href="<?php echo h($backDashboard); ?>">&larr; Volver al panel docente</a>
      </div>
    </div>
  <?php } else { ?>
    <main class="view-area">
      <div class="act-header">
        <h1 class="act-title"><?php echo h($currentTypeLabel); ?></h1>
        <div class="act-meta">
          <span class="act-badge">Actividad <?php echo ($step + 1); ?> / <?php echo $total; ?></span>
          <?php if ($editorHref !== null) { ?>
            <a class="edit-link" target="_blank" rel="noopener noreferrer"
               href="<?php echo h($editorHref); ?>">
              Editar
            </a>
          <?php } ?>
        </div>
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
        <a class="ctrl-btn <?php echo $hasNext ? '' : 'disabled'; ?>"
           href="teacher_course.php?assignment=<?php echo urlencode($assignmentId); ?>&unit=<?php echo urlencode($selectedUnitId); ?>&mode=<?php echo urlencode($mode); ?>&step=<?php echo $hasNext ? $nextStep : $step; ?>">
          Siguiente &rarr;
        </a>
      </div>
    </main>
  <?php } ?>
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
                'a[href*="dashboard"]','a[href*="unit_view"]',
                'a[href*="technical_units"]','a[href*="english_structure_units"]'
            ];

            selectors.forEach((selector) => {
                doc.querySelectorAll(selector).forEach((el) => {
                    const text = (el.textContent || '').toLowerCase();
                    const href = (el.getAttribute('href') || '').toLowerCase();
                    if (
                        text.includes('volver') || text.includes('back') ||
                        href.includes('dashboard') || href.includes('unit_view') ||
                        href.includes('technical_units') || href.includes('english_structure_units')
                    ) {
                        el.style.display = 'none';
                    }
                });
            });

            doc.querySelectorAll('a, button').forEach((el) => {
                const text = (el.textContent || '').trim().toLowerCase();
                if (text === 'back' || text === 'volver') {
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

<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

require_once "../config/db.php";

$unit_id = trim((string) ($_GET['unit'] ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? 'view'));
$mode = $mode === 'edit' ? 'edit' : 'view';

if ($unit_id === '') {
    die('Unidad no especificada.');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));
$allowEdit = false;

try {
    $stmtPermission = $pdo->prepare("
        SELECT permission
        FROM teacher_accounts
        WHERE teacher_id = :teacher_id
        ORDER BY updated_at DESC NULLS LAST
        LIMIT 1
    ");
    $stmtPermission->execute(['teacher_id' => $teacherId]);
    $permission = trim((string) $stmtPermission->fetchColumn());
    $allowEdit = $permission === 'editor';
} catch (Throwable $e) {
    $allowEdit = false;
}

if ($mode === 'edit' && !$allowEdit) {
    $mode = 'view';
}

/* ===============================
   OBTENER UNIDAD
=============================== */
$stmt = $pdo->prepare("
    SELECT id, course_id, phase_id, name
    FROM units
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die('Unidad no encontrada.');
}

/* ===============================
   DETECTAR CONTEXTO
=============================== */
if (!empty($unit['course_id'])) {
    $programLabel = 'Técnico';
} elseif (!empty($unit['phase_id'])) {
    $programLabel = 'English';
} else {
    $programLabel = 'Curso';
}

/* ===============================
   CARGAR ACTIVIDADES SEGUN COLUMNAS REALES
=============================== */
$hasTitle = table_has_column($pdo, 'activities', 'title');
$hasName = table_has_column($pdo, 'activities', 'name');
$hasPosition = table_has_column($pdo, 'activities', 'position');

$selectFields = ['id', 'type'];

if ($hasTitle) {
    $selectFields[] = 'title';
}

if ($hasName) {
    $selectFields[] = 'name';
}

$orderBy = $hasPosition
    ? 'COALESCE(position, 0) ASC, id ASC'
    : 'id ASC';

$sql = "
    SELECT " . implode(', ', $selectFields) . "
    FROM activities
    WHERE unit_id = :unit_id
    ORDER BY {$orderBy}
";

$stmtActivities = $pdo->prepare($sql);
$stmtActivities->execute(['unit_id' => $unit_id]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   LABELS
=============================== */
$activityLabels = [
    'drag_drop' => 'Drag & Drop',
    'flashcards' => 'Flashcards',
    'match' => 'Match',
    'multiple_choice' => 'Multiple Choice',
    'hangman' => 'Hangman',
    'listen_order' => 'Listen Order',
    'pronunciation' => 'Pronunciation',
    'external' => 'External',
    'flipbooks' => 'Flipbooks',
    'quiz' => 'Quiz',
    'video_lesson' => 'Video Lesson',
    'build_sentence' => 'Build Sentence',
];

$backHref = 'dashboard.php';
if ($assignmentId !== '') {
    $backHref .= '?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unit_id) . '#unidades-curso';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></title>
<style>
body{
    font-family: Arial, sans-serif;
    background:#eef2f7;
    padding:30px;
    margin:0;
}
.container{
    max-width:900px;
    margin:auto;
}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:20px;
}
h1{
    color:#1f3c75;
    margin:0;
}
.back{
    display:inline-block;
    color:#2563eb;
    text-decoration:none;
    font-weight:700;
}
.meta{
    margin:0 0 20px;
    color:#5b6577;
}
.card{
    background:#fff;
    padding:16px;
    border-radius:12px;
    margin-top:12px;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
    border:1px solid #dce4f0;
}
.btn{
    display:inline-block;
    padding:8px 12px;
    background:#2563eb;
    color:#fff;
    text-decoration:none;
    border-radius:8px;
    margin-right:8px;
    font-weight:700;
    font-size:14px;
}
.btn.edit{
    background:#16a34a;
}
.empty{
    background:#fff;
    padding:16px;
    border-radius:12px;
    border:1px solid #dce4f0;
    color:#5b6577;
}
.badge{
    display:inline-block;
    padding:5px 10px;
    border-radius:999px;
    background:#eef2ff;
    color:#1f4ec9;
    font-size:12px;
    font-weight:700;
    margin-right:8px;
    margin-bottom:8px;
}
.actions{
    margin-top:12px;
}
</style>
</head>
<body>

<div class="container">
    <div class="top">
        <h1><?php echo h((string) ($unit['name'] ?? 'Unidad')); ?></h1>
        <a class="back" href="<?php echo h($backHref); ?>">← Volver</a>
    </div>

    <div>
        <span class="badge"><?php echo h($programLabel); ?></span>
        <span class="badge"><?php echo h($mode === 'edit' ? 'Modo edición' : 'Modo visualización'); ?></span>
    </div>

    <p class="meta">Unidad vinculada al docente.</p>

    <?php if (empty($activities)) { ?>
        <div class="empty">No hay actividades registradas en esta unidad.</div>
    <?php } else { ?>
        <?php foreach ($activities as $act) { ?>
            <?php
            $activityId = (string) ($act['id'] ?? '');
            $type = strtolower((string) ($act['type'] ?? 'activity'));
            $label = $activityLabels[$type] ?? ucwords(str_replace('_', ' ', $type));

            $title = '';
            if ($hasTitle && !empty($act['title'])) {
                $title = (string) $act['title'];
            } elseif ($hasName && !empty($act['name'])) {
                $title = (string) $act['name'];
            } else {
                $title = $label;
            }

            $viewerPath = "../activities/" . $type . "/viewer.php";
            ?>
            <div class="card">
                <h3><?php echo h($title); ?></h3>
                <p>Tipo: <strong><?php echo h($label); ?></strong></p>

                <div class="actions">
                    <?php if (file_exists(__DIR__ . "/../activities/" . $type . "/viewer.php")) { ?>
                        <a
                            class="btn"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h($viewerPath . '?id=' . urlencode($activityId) . '&unit=' . urlencode($unit_id)); ?>"
                        >
                            Ver actividad
                        </a>
                    <?php } ?>

                    <?php if ($allowEdit) { ?>
                        <a
                            class="btn edit"
                            target="_blank"
                            rel="noopener noreferrer"
                            href="<?php echo h('teacher_activity_edit.php?activity=' . urlencode($activityId) . '&unit=' . urlencode($unit_id)); ?>"
                        >
                            Editar
                        </a>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    <?php } ?>
</div>

</body>
</html>

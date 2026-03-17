<?php
session_start();

if (!isset($_SESSION['academic_logged'])) {
    header('Location: login.php');
    exit;
}

require_once "../config/db.php";

$unit_id = $_GET['unit'] ?? '';
$assignmentId = $_GET['assignment'] ?? '';
$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';

if (!$unit_id) {
    die("Unidad no especificada.");
}

/* ===============================
   OBTENER UNIDAD (igual HUB)
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
    die("Unidad no encontrada.");
}

/* ===============================
   DETECTAR CONTEXTO (igual HUB)
=============================== */
if (!empty($unit['course_id'])) {
    $programLabel = "Técnico";
} elseif (!empty($unit['phase_id'])) {
    $programLabel = "English";
} else {
    $programLabel = "Curso";
}

/* ===============================
   CARGAR ACTIVIDADES (CLAVE)
=============================== */
$stmtActivities = $pdo->prepare("
    SELECT id, type, title, name, position
    FROM activities
    WHERE unit_id = :unit_id
    ORDER BY COALESCE(position, 0) ASC, id ASC
");
$stmtActivities->execute(['unit_id' => $unit_id]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   LABELS
=============================== */
$activityLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Quiz',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Flipbooks',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'build_sentence' => 'Build Sentence'
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($unit['name']); ?></title>

<style>
body{
    font-family: Arial;
    background:#eef2f7;
    padding:30px;
}
.container{
    max-width:900px;
    margin:auto;
}
h1{
    color:#1f3c75;
}
.card{
    background:#fff;
    padding:15px;
    border-radius:12px;
    margin-top:12px;
    box-shadow:0 8px 20px rgba(0,0,0,.08);
}
.btn{
    display:inline-block;
    padding:8px 12px;
    background:#2563eb;
    color:#fff;
    text-decoration:none;
    border-radius:8px;
    margin-right:8px;
}
.btn.edit{
    background:#16a34a;
}
.empty{
    background:#fff;
    padding:15px;
    border-radius:12px;
}
</style>

</head>
<body>

<div class="container">

<h1><?php echo htmlspecialchars($unit['name']); ?></h1>
<p><?php echo $programLabel; ?> | Modo: <?php echo $mode; ?></p>

<?php if (empty($activities)) { ?>
    <div class="empty">No hay actividades en esta unidad.</div>
<?php } else { ?>

    <?php foreach ($activities as $act) { 
        $type = strtolower($act['type']);
        $label = $activityLabels[$type] ?? $type;
        $title = $act['title'] ?: $act['name'] ?: $label;
    ?>

    <div class="card">
        <h3><?php echo htmlspecialchars($title); ?></h3>
        <p><?php echo htmlspecialchars($label); ?></p>

        <?php
        $viewer = "../activities/$type/viewer.php";
        ?>

        <a class="btn" target="_blank"
           href="<?php echo $viewer; ?>?id=<?php echo $act['id']; ?>&unit=<?php echo $unit_id; ?>">
           Ver actividad
        </a>

        <?php if ($mode === 'edit') { ?>
            <a class="btn edit" target="_blank"
               href="teacher_activity_edit.php?activity=<?php echo $act['id']; ?>&unit=<?php echo $unit_id; ?>">
               Editar
            </a>
        <?php } ?>

    </div>

    <?php } ?>

<?php } ?>

</div>

</body>
</html>

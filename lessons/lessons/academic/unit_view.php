<?php
session_start();
require_once "../config/db.php";

$unit_id = $_GET['unit'] ?? null;

if (!$unit_id) {
    die("Unidad no especificada.");
}

/* ==========================
   ELIMINAR ACTIVIDAD
========================== */
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'];

    $stmtDelete = $pdo->prepare("DELETE FROM activities WHERE id = :id");
    $stmtDelete->execute(['id' => $delete_id]);

    header("Location: unit_view.php?unit=" . urlencode($unit_id));
    exit;
}

/* ==========================
   ACTUALIZAR ORDEN (DRAG)
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    $order = $_POST['order'];

    foreach ($order as $position => $id) {
        $stmtUpdate = $pdo->prepare("
            UPDATE activities 
            SET position = :position 
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            'position' => $position + 1,
            'id' => $id
        ]);
    }

    exit;
}

/* ==========================
   OBTENER UNIT
========================== */
$stmt = $pdo->prepare("SELECT * FROM units WHERE id = :id");
$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ==========================
   OBTENER CURSO
========================== */
$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
$stmtCourse->execute(['id' => $unit['course_id']]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

/* ==========================
   OBTENER ACTIVIDADES
========================== */
$stmtActivities = $pdo->prepare("
    SELECT * FROM activities
    WHERE unit_id = :unit_id
    ORDER BY position ASC
");
$stmtActivities->execute(['unit_id' => $unit_id]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($unit['name']); ?></title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:20px;
}

.back{
    display:inline-block;
    background:#6b7280;
    margin-bottom:20px;
    padding:8px 14px;
    border-radius:8px;
    color:#ffffff;
    text-decoration:none;
    font-weight:600;
}

.activity-box{
    background:#16a34a;
    border-radius:12px;
    padding:18px;
    margin-bottom:14px;
    color:#ffffff;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.activity-left{ flex:1; }

.activity-title{
    font-weight:bold;
    font-size:16px;
}

.activity-meta{
    font-size:13px;
    opacity:0.9;
    margin-top:6px;
}

.activity-actions{
    display:flex;
    gap:10px;
    align-items:center;
}

.btn{
    padding:10px 16px;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    color:#ffffff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-width:110px;
    height:40px;
    box-sizing:border-box;
    border:none;
    transition:0.2s ease;
    cursor:pointer;
}

.btn-open{ background:#14532d; }
.btn-open:hover{ background:#166534; }

.btn-edit{ background:#1d4ed8; }
.btn-edit:hover{ background:#2563eb; }

.btn-delete{ background:#dc2626; }
.btn-delete:hover{ background:#ef4444; }

.draggable{ cursor:grab; }
.draggable:active{ cursor:grabbing; }
</style>
</head>

<body>

<a class="back" href="technical_units_view.php?course=<?= urlencode($course['id']); ?>">
Volver
</a>

<div class="card">
    <h2><?= htmlspecialchars($unit['name']); ?></h2>
    <p><strong>Curso:</strong> <?= htmlspecialchars($course['name']); ?></p>
</div>

<div class="card">
    <h3>Arrastra para ordenar</h3>
    <div id="activityContainer">

    <?php foreach ($activities as $activity): ?>
        <?php
        $typeRaw = $activity['type'];

        $icons = [
            'hangman' => '🎯',
            'drag_drop' => '🧩',
            'flashcards' => '🃏',
            'match' => '🔗',
            'multiple_choice' => '✅',
            'listen_order' => '🎧',
            'pronunciation' => '🎤',
            'external' => '🌐',
            'flipbooks' => '📖'
        ];

        $icon = $icons[$typeRaw] ?? '📘';

        $data = [];
        if (!empty($activity['data']) && is_string($activity['data'])) {
            $decoded = json_decode($activity['data'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $activityTitle = $data['title'] ?? strtoupper(str_replace('_',' ',$typeRaw));
        ?>

        <div class="activity-box draggable" draggable="true" data-id="<?= $activity['id']; ?>">
            <div class="activity-left">
                <div class="activity-title">
                    <?= $icon . " " . htmlspecialchars($activityTitle); ?>
                </div>
                <div class="activity-meta">
                    Tipo: <?= strtoupper(str_replace('_',' ',$typeRaw)); ?><br>
                    Creado: <?= htmlspecialchars($activity['created_at']); ?>
                </div>
            </div>

            <div class="activity-actions">
                <a class="btn btn-open"
                   href="../activities/<?= htmlspecialchars($typeRaw); ?>/viewer.php?id=<?= htmlspecialchars($activity['id']); ?>&unit=<?= urlencode($unit_id); ?>">
                    Abrir
                </a>

                <a class="btn btn-edit"
                   href="../activities/<?= htmlspecialchars($typeRaw); ?>/editor.php?id=<?= htmlspecialchars($activity['id']); ?>&unit=<?= urlencode($unit_id); ?>">
                    Editar
                </a>

                <a class="btn btn-delete"
                   href="unit_view.php?unit=<?= urlencode($unit_id); ?>&delete=<?= htmlspecialchars($activity['id']); ?>"
                   onclick="return confirm('¿Eliminar esta actividad?');">
                    Eliminar
                </a>
            </div>
        </div>
    <?php endforeach; ?>

    </div>
</div>

<script>
const container = document.getElementById('activityContainer');
let dragged;

container.addEventListener('dragstart', e => {
    dragged = e.target;
});

container.addEventListener('dragover', e => {
    e.preventDefault();
    const afterElement = getDragAfterElement(container, e.clientY);
    if (afterElement == null) {
        container.appendChild(dragged);
    } else {
        container.insertBefore(dragged, afterElement);
    }
});

container.addEventListener('drop', () => {
    const items = document.querySelectorAll('.draggable');
    let order = [];

    items.forEach(item => {
        order.push(item.dataset.id);
    });

    fetch("unit_view.php?unit=<?= $unit_id ?>", {
        method: "POST",
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({order: order})
    });
});

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.draggable:not(.dragging)')];

    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}
</script>

</body>
</html>

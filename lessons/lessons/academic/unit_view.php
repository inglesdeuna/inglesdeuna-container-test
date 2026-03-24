<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: /lessons/lessons/admin/login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$unit_id = $_GET['unit'] ?? '';
$source  = $_GET['source'] ?? '';

if (empty($unit_id)) {
    die("Unidad no especificada.");
}

/* ==========================
   ELIMINAR ACTIVIDAD
========================== */
if (isset($_GET['delete'])) {
    $delete_id = $_GET['delete'] ?? '';

    if (!empty($delete_id)) {
        $stmtDelete = $pdo->prepare("DELETE FROM activities WHERE id = :id");
        $stmtDelete->execute(['id' => $delete_id]);
    }

    header("Location: unit_view.php?unit=" . urlencode($unit_id) . (!empty($source) ? "&source=" . urlencode($source) : ""));
    exit;
}

/* ==========================
   ACTUALIZAR ORDEN (DRAG)
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    foreach ($_POST['order'] as $position => $id) {
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

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];

    $stmt = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'activities'
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function activity_display_title(array $activity): string
{
    $type = strtolower((string) ($activity['type'] ?? ''));
    $defaultMap = [
        'flashcards' => 'Flashcards',
        'quiz' => 'Quiz',
        'multiple_choice' => 'Multiple Choice',
        'video_lesson' => 'Video Lesson',
        'flipbooks' => 'Flipbooks',
        'hangman' => 'Hangman',
        'pronunciation' => 'Pronunciation',
        'listen_order' => 'Listen & Order',
        'drag_drop' => 'Drag & Drop',
        'match' => 'Match',
        'external' => 'External',
        'powerpoint' => 'PowerPoint',
        'build_sentence' => 'Build the Sentence',
    ];

    $fallback = $defaultMap[$type] ?? ucwords(str_replace('_', ' ', $type));

    if (!empty($activity['title'])) {
        return trim((string) $activity['title']);
    }

    if (!empty($activity['name'])) {
        return trim((string) $activity['name']);
    }

    $rawData = $activity['data'] ?? null;
    if (is_string($rawData) && trim($rawData) !== '') {
        $decoded = json_decode($rawData, true);
        if (is_array($decoded) && !empty($decoded['title'])) {
            return trim((string) $decoded['title']);
        }
    }

    return $fallback;
}

/* ===============================
   OBTENER UNIT + CONTEXTO
=============================== */
$stmt = $pdo->prepare("
    SELECT u.*,
           c.name AS course_name,
           p.name AS phase_name,
           l.name AS level_name
    FROM units u
    LEFT JOIN courses c ON u.course_id = c.id
    LEFT JOIN english_phases p ON u.phase_id = p.id
    LEFT JOIN english_levels l ON p.level_id = l.id
    WHERE u.id = :id
    LIMIT 1
");

$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ===============================
   BOTÓN VOLVER INTELIGENTE
=============================== */
if (!empty($unit['course_id'])) {
    $backUrl = "technical_units_view.php?course=" . urlencode($unit['course_id'] ?? '');
} elseif (!empty($unit['phase_id'])) {
    if ($source === "created") {
        $backUrl = "english_units_view.php?phase=" . urlencode($unit['phase_id'] ?? '');
    } else {
        $backUrl = "english_structure_units.php?phase=" . urlencode($unit['phase_id'] ?? '');
    }
} else {
    $backUrl = "/lessons/lessons/admin/dashboard.php";
}

/* ===============================
   OBTENER ACTIVIDADES
=============================== */
$columns = activities_columns($pdo);
$selectFields = ['id', 'unit_id', 'type', 'position', 'created_at'];

if (in_array('data', $columns, true)) {
    $selectFields[] = 'data';
}
if (in_array('title', $columns, true)) {
    $selectFields[] = 'title';
}
if (in_array('name', $columns, true)) {
    $selectFields[] = 'name';
}

$stmtActivities = $pdo->prepare("
    SELECT " . implode(', ', $selectFields) . "
    FROM activities
    WHERE unit_id = :unit_id
    ORDER BY position ASC NULLS LAST, id ASC
");

$stmtActivities->execute(['unit_id' => $unit_id]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);

$activityLabels = [
    'flashcards' => 'Flashcards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Flipbooks',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'powerpoint' => 'PowerPoint',
    'build_sentence' => 'Build the Sentence',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($unit['name'] ?? 'Unidad'); ?></title>

<style>
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --muted:#5d7465;
    --green:#2f9e44;
    --green-dark:#237a35;
    --green-soft:#e9f8ee;
    --red:#dc2626;
    --red-dark:#b91c1c;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}

*{ box-sizing:border-box; }

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
}

.topbar{
    background:linear-gradient(180deg, var(--green), var(--green-dark));
    color:#fff;
    padding:16px 24px;
}

.topbar-inner{
    max-width:1280px;
    margin:0 auto;
    display:grid;
    grid-template-columns:160px 1fr 160px;
    align-items:center;
    gap:12px;
}

.topbar-title{
    margin:0;
    text-align:center;
    font-size:28px;
    font-weight:800;
}

.top-btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:10px;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    color:#fff;
    box-shadow:var(--shadow);
}

.top-btn.back{
    background:rgba(255,255,255,.18);
    justify-self:start;
}

.top-btn.dashboard{
    background:rgba(255,255,255,.18);
    justify-self:end;
}

.page{
    max-width:1280px;
    margin:0 auto;
    padding:18px 20px 24px;
}

.layout{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:18px;
    align-items:start;
}

.sidebar{
    background:#e7f6eb;
    border-radius:20px;
    padding:18px 14px;
    box-shadow:var(--shadow);
    min-height:calc(100vh - 150px);
}

.logo-wrap{
    text-align:center;
    margin-bottom:16px;
}

.logo-badge{
    width:90px;
    height:90px;
    margin:0 auto;
    border-radius:18px;
    background:linear-gradient(180deg,#ffffff,#dff4e5);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:36px;
    box-shadow:var(--shadow);
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
    box-shadow:var(--shadow);
}

.side-btn.green{
    background:linear-gradient(180deg,#41b95a,#2f9e44);
}

.side-btn.gray{
    background:linear-gradient(180deg,#7b8b7f,#66756a);
}

.side-btn.red{
    background:linear-gradient(180deg,#ef4444,#dc2626);
}

.content{
    padding:0;
}

.info-card,
.activities-shell{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:22px;
    box-shadow:var(--shadow);
}

.info-card{
    padding:20px 22px;
    margin-bottom:18px;
}

.info-card h2{
    margin:0 0 12px;
    color:var(--green-dark);
    font-size:28px;
}

.info-meta{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:12px;
}

.badge{
    display:inline-block;
    padding:7px 12px;
    border-radius:999px;
    background:var(--green-soft);
    color:var(--green-dark);
    font-size:12px;
    font-weight:800;
}

.info-card p{
    margin:8px 0 0;
    color:var(--muted);
    font-size:15px;
}

.activities-shell{
    padding:18px;
}

.section-title{
    margin:0 0 14px;
    color:var(--green-dark);
    font-size:22px;
    font-weight:800;
}

.helper{
    margin:0 0 16px;
    color:var(--muted);
    font-size:14px;
}

.activity-list{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.activity-box{
    background:linear-gradient(180deg,#3bb151,#259741);
    border-radius:16px;
    padding:18px;
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    box-shadow:var(--shadow);
}

.activity-box.dragging{
    opacity:.55;
}

.activity-main{
    min-width:0;
}

.activity-title{
    margin:0 0 6px;
    font-size:20px;
    font-weight:800;
    line-height:1.2;
}

.activity-type{
    margin:0 0 4px;
    font-size:14px;
    opacity:.95;
}

.activity-created{
    margin:0;
    font-size:13px;
    opacity:.88;
}

.activity-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.btn{
    padding:10px 16px;
    border-radius:10px;
    text-decoration:none;
    font-weight:700;
    color:#fff;
    font-size:14px;
    box-shadow:var(--shadow);
}

.btn-open{
    background:#14532d;
}

.btn-edit{
    background:#1d4ed8;
}

.btn-delete{
    background:var(--red);
}

.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:16px;
    padding:18px;
    color:var(--muted);
    box-shadow:var(--shadow);
}

.draggable{
    cursor:grab;
}

@media (max-width: 980px){
    .topbar-inner{
        grid-template-columns:1fr;
        text-align:center;
    }

    .top-btn.back,
    .top-btn.dashboard{
        justify-self:center;
    }

    .layout{
        grid-template-columns:1fr;
    }

    .sidebar{
        min-height:auto;
    }
}

@media (max-width: 768px){
    .page{
        padding:12px;
    }

    .topbar{
        padding:14px;
    }

    .topbar-title{
        font-size:24px;
    }

    .activity-box{
        flex-direction:column;
        align-items:flex-start;
    }

    .activity-actions{
        width:100%;
        justify-content:stretch;
    }

    .activity-actions .btn{
        flex:1 1 auto;
        text-align:center;
    }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="topbar-inner">
        <a class="top-btn back" href="<?= htmlspecialchars($backUrl); ?>">← Volver</a>
        <h1 class="topbar-title">Gestión de Unidad</h1>
        <a class="top-btn dashboard" href="/lessons/lessons/admin/dashboard.php">Dashboard</a>
    </div>
</header>

<div class="page">
    <div class="layout">
        <aside class="sidebar">
            <div class="logo-wrap">
                <div class="logo-badge">🛠️</div>
            </div>

            <a class="side-btn green" href="<?= htmlspecialchars($backUrl); ?>">📚 Volver a unidades</a>
            <a class="side-btn gray" href="/lessons/lessons/activities/hub/index.php?unit=<?= urlencode($unit_id); ?>">➕ Crear actividades</a>
            <a class="side-btn red" href="/lessons/lessons/admin/dashboard.php">🏠 Ir al dashboard</a>
        </aside>

        <main class="content">
            <section class="info-card">
                <h2><?= htmlspecialchars($unit['name'] ?? ''); ?></h2>

                <div class="info-meta">
                    <?php if (!empty($unit['course_name'])): ?>
                        <span class="badge">Curso: <?= htmlspecialchars($unit['course_name']); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($unit['level_name'])): ?>
                        <span class="badge">Level: <?= htmlspecialchars($unit['level_name']); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($unit['phase_name'])): ?>
                        <span class="badge">Phase: <?= htmlspecialchars($unit['phase_name']); ?></span>
                    <?php endif; ?>

                    <span class="badge">Unit ID: <?= htmlspecialchars((string) $unit_id); ?></span>
                </div>

                <p>Arrastra las actividades para reorganizarlas. Puedes abrir, editar o eliminar cada actividad.</p>
            </section>

            <section class="activities-shell">
                <h3 class="section-title">Actividades de la unidad</h3>
                <p class="helper">Ordena las actividades arrastrando cada tarjeta.</p>

                <?php if (empty($activities)): ?>
                    <div class="empty">No hay actividades creadas todavía en esta unidad.</div>
                <?php else: ?>
                    <div id="activityContainer" class="activity-list">
                        <?php foreach ($activities as $activity): ?>
                            <?php
                            $type = strtolower((string) ($activity['type'] ?? ''));
                            $typeLabel = $activityLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
                            $displayTitle = activity_display_title($activity);
                            ?>
                            <div class="activity-box draggable" draggable="true" data-id="<?= htmlspecialchars((string) $activity['id']); ?>">
                                <div class="activity-main">
                                    <h4 class="activity-title"><?= htmlspecialchars($displayTitle); ?></h4>
                                    <p class="activity-type">Tipo: <strong><?= htmlspecialchars($typeLabel); ?></strong></p>
                                    <p class="activity-created">Creado: <?= htmlspecialchars((string) ($activity['created_at'] ?? '')); ?></p>
                                </div>

                                <div class="activity-actions">
                                    <a class="btn btn-open"
                                       href="../activities/<?= htmlspecialchars($type); ?>/viewer.php?id=<?= urlencode((string) $activity['id']); ?>&unit=<?= urlencode($unit_id); ?>&source=<?= urlencode($source); ?>">
                                        Abrir
                                    </a>

                                    <a class="btn btn-edit"
                                       href="../activities/<?= htmlspecialchars($type); ?>/editor.php?id=<?= urlencode((string) $activity['id']); ?>&unit=<?= urlencode($unit_id); ?>&source=<?= urlencode($source); ?>">
                                        Editar
                                    </a>

                                    <a class="btn btn-delete"
                                       href="unit_view.php?unit=<?= urlencode($unit_id); ?>&delete=<?= urlencode((string) $activity['id']); ?>&source=<?= urlencode($source); ?>"
                                       onclick="return confirm('¿Eliminar esta actividad?');">
                                        Eliminar
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<script>
const container = document.getElementById('activityContainer');
let dragged = null;

if (container) {
    container.addEventListener('dragstart', e => {
        const target = e.target.closest('.draggable');
        if (!target) return;
        dragged = target;
        target.classList.add('dragging');
    });

    container.addEventListener('dragend', e => {
        const target = e.target.closest('.draggable');
        if (!target) return;
        target.classList.remove('dragging');
    });

    container.addEventListener('dragover', e => {
        e.preventDefault();
        const afterElement = getDragAfterElement(container, e.clientY);
        if (!dragged) return;

        if (afterElement == null) {
            container.appendChild(dragged);
        } else {
            container.insertBefore(dragged, afterElement);
        }
    });

    container.addEventListener('drop', () => {
        const items = document.querySelectorAll('.draggable');
        let order = [];

        items.forEach(item => order.push(item.dataset.id));

        fetch("unit_view.php?unit=<?= urlencode($unit_id); ?>&source=<?= urlencode($source); ?>", {
            method: "POST",
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({order: order})
        });
    });
}

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.draggable:not(.dragging)')];

    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        }
        return closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}
</script>

</body>
</html>

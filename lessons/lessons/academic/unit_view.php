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
    header('Content-Type: application/json; charset=UTF-8');

    $rawOrder = $_POST['order'] ?? [];
    if (is_string($rawOrder)) {
        $rawOrder = explode(',', $rawOrder);
    }

    $order = is_array($rawOrder)
        ? array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $rawOrder
        ), static fn (string $value): bool => $value !== '')))
        : [];

    if (empty($order)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'No se recibió un orden válido.']);
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($order), '?'));
        $verifySql = "
            SELECT id
            FROM activities
            WHERE unit_id = ?
              AND id IN ({$placeholders})
        ";

        $verifyStmt = $pdo->prepare($verifySql);
        $verifyStmt->execute(array_merge([$unit_id], $order));
        $validIds = array_map('strval', $verifyStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (count($validIds) !== count($order)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Algunas actividades no pertenecen a esta unidad.']);
            exit;
        }

        $pdo->beginTransaction();

        $stmtUpdate = $pdo->prepare("
            UPDATE activities
            SET position = :position
            WHERE id = :id
              AND unit_id = :unit_id
        ");

        foreach ($order as $position => $id) {
            $stmtUpdate->execute([
                'position' => $position + 1,
                'id' => $id,
                'unit_id' => $unit_id,
            ]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'No fue posible guardar el orden.']);
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
        'memory_cards' => 'Memory Cards',
        'quiz' => 'Quiz',
        'multiple_choice' => 'Multiple Choice',
        'video_comprehension' => 'Video Comprehension',
        'video_lesson' => 'Video Lesson',
        'flipbooks' => 'Flipbooks',
        'hangman' => 'Hangman',
        'pronunciation' => 'Pronunciation',
        'listen_order' => 'Listen & Order',
        'order_sentences' => 'Order the Sentences',
        'drag_drop' => 'Drag & Drop',
        'match' => 'Match',
        'external' => 'External',
        'powerpoint' => 'PowerPoint',
        'crossword' => 'Crossword Puzzle',
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
    'memory_cards' => 'Memory Cards',
    'quiz' => 'Quiz',
    'multiple_choice' => 'Multiple Choice',
    'video_comprehension' => 'Video Comprehension',
    'video_lesson' => 'Video Lesson',
    'flipbooks' => 'Flipbooks',
    'hangman' => 'Hangman',
    'pronunciation' => 'Pronunciation',
    'listen_order' => 'Listen & Order',
    'order_sentences' => 'Order the Sentences',
    'drag_drop' => 'Drag & Drop',
    'match' => 'Match',
    'external' => 'External',
    'powerpoint' => 'PowerPoint',
    'crossword' => 'Crossword Puzzle',
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
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

:root{
    --bg:#eef5ff;
    --card:#ffffff;
    --line:#d8e2f2;
    --text:#1b3050;
    --muted:#5d6f8f;
    --blue:#2563eb;
    --blue-dark:#1d4ed8;
    --blue-soft:#e9f1ff;
    --red:#dc2626;
    --red-dark:#b91c1c;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}

*{ box-sizing:border-box; }

body{
    margin:0;
    font-family:'Nunito', 'Segoe UI', sans-serif;
    background:
        radial-gradient(circle at top left, rgba(255,255,255,.72), rgba(255,255,255,0) 28%),
        radial-gradient(circle at top right, rgba(255,255,255,.6), rgba(255,255,255,0) 24%),
        linear-gradient(135deg, #dff5ff 0%, #fff4db 48%, #f8d9e6 100%);
    color:var(--text);
}

.topbar{
    background:linear-gradient(180deg, var(--blue), var(--blue-dark));
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
    font-size:clamp(22px, 2.2vw, 30px);
    font-weight:800;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    letter-spacing:.04em;
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
    background:#ffffff;
    border:1px solid var(--line);
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
    background:linear-gradient(180deg,#ffffff,#dfe9fb);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:36px;
    box-shadow:var(--shadow);
    color:var(--blue-dark);
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
    background:linear-gradient(180deg,#3d73ee,#2563eb);
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
    color:var(--blue-dark);
    font-size:30px;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
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
    background:var(--blue-soft);
    color:var(--blue-dark);
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
    color:var(--blue-dark);
    font-size:24px;
    font-weight:800;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
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
    background:linear-gradient(135deg,#eff6ff 0%,#ffffff 100%);
    border:1px solid #bfdbfe;
    border-radius:16px;
    padding:18px;
    color:var(--text);
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
    color:#1e3a8a;
}

.activity-type{
    margin:0 0 4px;
    font-size:14px;
    color:#334155;
}

.activity-created{
    margin:0;
    font-size:13px;
    color:#64748b;
}

.activity-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.btn{
    padding:10px 16px;
    border-radius:999px;
    text-decoration:none;
    font-weight:700;
    color:#fff;
    font-size:14px;
    box-shadow:var(--shadow);
}

.btn-open{
    background:linear-gradient(180deg,#3b82f6,#1d4ed8);
}

.btn-edit{
    background:linear-gradient(180deg,#60a5fa,#2563eb);
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
            <a class="side-btn red" href="/lessons/lessons/admin/dashboard.php">🏠 Dashboard</a>
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

                <p>Arrastra las actividades para reorganizarlas. Usa abrir, editar o eliminar según necesites.</p>
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

    container.addEventListener('drop', async () => {
        const items = container.querySelectorAll('.draggable');
        const order = [];

        items.forEach(item => {
            if (item.dataset.id) {
                order.push(item.dataset.id);
            }
        });

        const payload = new URLSearchParams();
        order.forEach(id => payload.append('order[]', id));

        try {
            const response = await fetch("unit_view.php?unit=<?= urlencode($unit_id); ?>&source=<?= urlencode($source); ?>", {
                method: "POST",
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: payload.toString()
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status !== 'success') {
                console.error('Failed to save activity order', { status: response.status, data });
            }
        } catch (error) {
            console.error('Failed to save activity order', error);
        }
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

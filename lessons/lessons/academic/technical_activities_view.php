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
        echo json_encode(['status' => 'error', 'message' => 'No valid order received.']);
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
            echo json_encode(['status' => 'error', 'message' => 'Some activities are not in this unit.']);
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
        echo json_encode(['status' => 'error', 'message' => 'Unable to save order.']);
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($unit['name']); ?></title>

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
    background:rgba(255,255,255,.18);
}

.top-btn.back{ justify-self:start; }
.top-btn.dashboard{ justify-self:end; }

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

.side-btn.green{ background:linear-gradient(180deg,#41b95a,#2f9e44); }
.side-btn.gray{ background:linear-gradient(180deg,#7b8b7f,#66756a); }
.side-btn.red{ background:linear-gradient(180deg,#ef4444,#dc2626); }

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

.activities-shell{ padding:18px; }

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

.activity-box.dragging{ opacity:.55; }

.activity-main{ min-width:0; }

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

.btn-open{ background:#14532d; }
.btn-edit{ background:#1d4ed8; }
.btn-delete{ background:var(--red); }

.empty{
    background:#fff;
    border:1px solid var(--line);
    border-radius:16px;
    padding:18px;
    color:var(--muted);
    box-shadow:var(--shadow);
}

.draggable{ cursor:grab; }

@media (max-width: 980px){
    .topbar-inner{
        grid-template-columns:1fr;
        text-align:center;
    }

    .top-btn.back,
    .top-btn.dashboard{
        justify-self:center;
    }

    .layout{ grid-template-columns:1fr; }

    .sidebar{ min-height:auto; }
}

@media (max-width: 768px){
    .page{ padding:12px; }

    .topbar{ padding:14px; }

    .topbar-title{ font-size:24px; }

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
        <a class="top-btn back" href="technical_units_view.php?course=<?= urlencode($course['id']); ?>">← Volver</a>
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

            <a class="side-btn green" href="technical_units_view.php?course=<?= urlencode($course['id']); ?>">📚 Volver a unidades</a>
            <a class="side-btn gray" href="/lessons/lessons/activities/hub/index.php?unit=<?= urlencode($unit_id); ?>">➕ Crear actividades</a>
            <a class="side-btn red" href="/lessons/lessons/admin/dashboard.php">🏠 Ir al dashboard</a>
        </aside>

        <main class="content">
            <section class="info-card">
                <h2><?= htmlspecialchars($unit['name']); ?></h2>

                <div class="info-meta">
                    <span class="badge">Curso: <?= htmlspecialchars($course['name'] ?? ''); ?></span>
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
                        $typeRaw = strtolower((string) ($activity['type'] ?? ''));

                        $icons = [
                            'hangman' => '🎯',
                            'drag_drop' => '🧩',
                            'flashcards' => '🃏',
                            'match' => '🔗',
                            'multiple_choice' => '✅',
                            'listen_order' => '🎧',
                            'pronunciation' => '🎤',
                            'external' => '🌐',
                            'flipbooks' => '📖',
                            'powerpoint' => '🖥️'
                        ];

                        $icon = $icons[$typeRaw] ?? '📘';

                        $data = [];
                        if (!empty($activity['data']) && is_string($activity['data'])) {
                            $decoded = json_decode($activity['data'], true);
                            if (is_array($decoded)) {
                                $data = $decoded;
                            }
                        }

                        $activityTitle = $data['title'] ?? strtoupper(str_replace('_', ' ', $typeRaw));
                        ?>

                        <div class="activity-box draggable" draggable="true" data-id="<?= htmlspecialchars((string) $activity['id']); ?>">
                            <div class="activity-main">
                                <h4 class="activity-title"><?= $icon . ' ' . htmlspecialchars($activityTitle); ?></h4>
                                <p class="activity-type">Tipo: <strong><?= htmlspecialchars(strtoupper(str_replace('_', ' ', $typeRaw))); ?></strong></p>
                                <p class="activity-created">Creado: <?= htmlspecialchars($activity['created_at'] ?? ''); ?></p>
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
            const response = await fetch("technical_activities_view.php?unit=<?= urlencode($unit_id); ?>", {
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

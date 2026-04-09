<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$phaseId = isset($_GET["phase"]) && ctype_digit((string) $_GET["phase"]) ? (int) $_GET["phase"] : null;
if (!$phaseId) die("Phase no especificada.");

/* ===============================
   OBTENER PHASE + LEVEL
=============================== */
$stmtPhase = $pdo->prepare("
    SELECT p.id AS phase_id, p.name AS phase_name, l.id AS level_id, l.name AS level_name
    FROM english_phases p
    JOIN english_levels l ON p.level_id = l.id
    WHERE p.id = :id
    LIMIT 1
");
$stmtPhase->execute(["id" => $phaseId]);
$phase = $stmtPhase->fetch(PDO::FETCH_ASSOC);
if (!$phase) die("Phase no encontrada.");

/* ===============================
   CREAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])
    && ($_POST["action"] ?? '') !== "rename_unit") {
    $unitName = mb_strtoupper(trim($_POST["unit_name"]), "UTF-8");

    // Check for duplicate
    $check = $pdo->prepare("SELECT id FROM units WHERE phase_id = :pid AND name = :name LIMIT 1");
    $check->execute(["pid" => $phaseId, "name" => $unitName]);
    $existingId = $check->fetchColumn();

    if ($existingId) {
        header("Location: ../activities/hub/index_english.php?unit=" . urlencode($existingId));
        exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO units (name, phase_id, created_at, active, position)
        VALUES (:name, :phase_id, NOW(), true, 0)
        RETURNING id
    ");
    $ins->execute(["name" => $unitName, "phase_id" => $phaseId]);
    $newUnitId = $ins->fetchColumn();

    header("Location: ../activities/hub/index_english.php?unit=" . urlencode($newUnitId));
    exit;
}

/* ===============================
   RENOMBRAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"]) && $_POST["action"] === "rename_unit"
    && ctype_digit((string) ($_POST["rename_id"] ?? ''))
    && trim((string) ($_POST["rename_name"] ?? '')) !== '') {
    $renameId   = (int) $_POST["rename_id"];
    $renameName = mb_strtoupper(trim((string) $_POST["rename_name"]), "UTF-8");
    $pdo->prepare("UPDATE units SET name = :name WHERE id = :id")
        ->execute(["name" => $renameName, "id" => $renameId]);
    // Sync cached name in teacher_assignments
    try {
        $pdo->prepare("UPDATE teacher_assignments SET unit_name = :name WHERE unit_id = :unit_id")
            ->execute(["name" => $renameName, "unit_id" => (string) $renameId]);
    } catch (Throwable $e) {}
    header("Location: english_structure_units.php?phase=" . urlencode($phaseId) . "&renamed=1");
    exit;
}

/* ===============================
   ELIMINAR UNIT (solo si sin actividades)
=============================== */
if (isset($_GET["delete_unit"]) && ctype_digit((string) $_GET["delete_unit"])) {
    $delId = (int) $_GET["delete_unit"];
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE unit_id = :id");
    $cnt->execute(["id" => $delId]);
    if ((int) $cnt->fetchColumn() === 0) {
        $pdo->prepare("DELETE FROM units WHERE id = :id")->execute(["id" => $delId]);
    }
    header("Location: english_structure_units.php?phase=" . urlencode($phaseId));
    exit;
}

/* ===============================
   LISTAR UNITS con conteo de actividades
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT u.*, (SELECT COUNT(*) FROM activities a WHERE a.unit_id = u.id) AS activity_count
    FROM units u
    WHERE u.phase_id = :phase_id
    ORDER BY u.id ASC
");
$stmtUnits->execute(["phase_id" => $phaseId]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(mb_strtoupper($phase["phase_name"], "UTF-8")) ?> — Units</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --muted:#5d7465;
    --green:#2f9e44;
    --green-dark:#237a35;
    --green-soft:#f0faf3;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}

*{ box-sizing:border-box; }

body{
    font-family:'Nunito','Arial',sans-serif;
    background:var(--bg);
    margin:0;
    padding:32px 20px 40px;
    color:var(--text);
}

.container{ max-width:860px; margin:0 auto; }

.back{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-bottom:10px;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    color:#fff;
    padding:10px 18px;
    border-radius:10px;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
    box-shadow:var(--shadow);
}

.breadcrumb{
    font-size:13px;
    color:var(--muted);
    margin-bottom:22px;
    font-weight:600;
}
.breadcrumb span{ color:var(--green); }

.card{
    background:var(--card);
    padding:28px 30px;
    border-radius:18px;
    box-shadow:var(--shadow);
    border:1px solid var(--line);
    margin-bottom:24px;
}
.card h2{
    margin:0 0 20px;
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:24px;
    color:#1a4229;
}

.create-form{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.create-form input[type=text]{
    flex:1;
    min-width:200px;
    padding:11px 14px;
    border-radius:10px;
    border:1px solid var(--line);
    font-size:14px;
    font-family:inherit;
}
.create-form button{
    padding:11px 20px;
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#fff;
    border:none;
    border-radius:10px;
    font-weight:700;
    font-size:14px;
    cursor:pointer;
    font-family:inherit;
    box-shadow:var(--shadow);
    transition:filter .15s;
}
.create-form button:hover{ filter:brightness(1.06); }

.unit-item{
    background:var(--green-soft);
    border:1px solid var(--line);
    padding:14px 18px;
    border-radius:13px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}
.unit-info{ display:flex; flex-direction:column; gap:2px; }
.unit-name{ font-size:15px; font-weight:800; color:#1a4229; }
.unit-meta{ font-size:12px; color:var(--muted); font-weight:600; }
.unit-actions{ display:flex; gap:8px; align-items:center; }

.btn-view{
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#fff;
    padding:9px 16px;
    border-radius:9px;
    text-decoration:none;
    font-weight:700;
    font-size:13px;
    box-shadow:var(--shadow);
}
.btn-del{
    background:#dc2626;
    color:#fff;
    border:none;
    width:32px;
    height:32px;
    border-radius:8px;
    font-size:16px;
    font-weight:700;
    cursor:pointer;
    line-height:1;
}
.btn-del:disabled{ background:#d1d5db; cursor:not-allowed; }

.btn-edit{
    background:linear-gradient(180deg,#f59e0b,#d97706);
    color:#fff;
    border:none;
    padding:9px 14px;
    border-radius:9px;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    line-height:1;
    box-shadow:var(--shadow);
    transition:filter .15s;
}
.btn-edit:hover{ filter:brightness(1.08); }

.rename-form{
    margin-top:10px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.rename-form input[type=text]{
    flex:1;
    min-width:180px;
    padding:8px 12px;
    border-radius:8px;
    border:1px solid var(--line);
    font-size:13px;
    font-family:inherit;
}
.btn-save-rename{
    padding:8px 14px;
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:700;
    font-size:13px;
    cursor:pointer;
}
.btn-cancel-rename{
    padding:8px 14px;
    background:#6b7280;
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:700;
    font-size:13px;
    cursor:pointer;
}

.empty{ color:var(--muted); font-size:14px; padding:6px 0; }

.banner-success{
    background:#dcfce7;
    border:1px solid #86efac;
    color:#166534;
    padding:12px 18px;
    border-radius:10px;
    margin-bottom:18px;
    font-weight:700;
    font-size:14px;
}

@media(max-width:600px){
    .card{ padding:18px 14px; }
    .unit-item{ flex-direction:column; align-items:flex-start; }
    .unit-actions{ width:100%; }
    .btn-view{ flex:1; text-align:center; }
}
</style>
</head>
<body>
<div class="container">

<a class="back" href="english_structure_phases.php?level=<?= urlencode($phase["level_id"]) ?>">← Volver a Phases</a>

<p class="breadcrumb">
    Cursos de Inglés
    › <?= htmlspecialchars(mb_strtoupper($phase["level_name"], "UTF-8")) ?>
    › <?= htmlspecialchars(mb_strtoupper($phase["phase_name"], "UTF-8")) ?>
    › <span>Units</span>
</p>

<?php if (isset($_GET["renamed"])): ?>
    <div class="banner-success">✔ Nombre actualizado correctamente.</div>
<?php endif; ?>

<div class="card">
    <h2>➕ Crear Unit</h2>
    <form method="POST" class="create-form">
        <input type="text" name="unit_name" required placeholder="Ej: UNIT 1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📚 Units — <?= htmlspecialchars(mb_strtoupper($phase["phase_name"], "UTF-8")) ?></h2>

    <?php if (empty($units)): ?>
        <p class="empty">No hay unidades creadas en esta phase.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
        <div class="unit-item">
            <div class="unit-info">
                <span class="unit-name"><?= htmlspecialchars(mb_strtoupper($unit["name"], "UTF-8")) ?></span>
                <span class="unit-meta">
                    <?= (int) ($unit["activity_count"] ?? 0) ?> actividad<?= (int) ($unit["activity_count"] ?? 0) !== 1 ? 'es' : '' ?>
                </span>
                <form method="POST" class="rename-form"
                      id="rename-unit-<?= $unit['id'] ?>" style="display:none;">
                    <input type="hidden" name="action" value="rename_unit">
                    <input type="hidden" name="rename_id" value="<?= htmlspecialchars($unit['id']) ?>">
                    <input type="hidden" name="phase" value="<?= htmlspecialchars($phaseId) ?>">
                    <input type="text" name="rename_name" required
                           value="<?= htmlspecialchars($unit['name']) ?>">
                    <button type="submit" class="btn-save-rename">Guardar</button>
                    <button type="button" class="btn-cancel-rename"
                            onclick="toggleRename('rename-unit-<?= $unit['id'] ?>')">Cancelar</button>
                </form>
            </div>
            <div class="unit-actions">
                <button type="button" class="btn-edit"
                        onclick="toggleRename('rename-unit-<?= $unit['id'] ?>')"
                        title="Renombrar unidad">✎ Editar</button>
                <a class="btn-view"
                   href="../activities/hub/index_english.php?unit=<?= urlencode($unit["id"]) ?>">
                    Ver Actividades →
                </a>
                <form method="GET" style="margin:0;"
                      onsubmit="return confirm('¿Eliminar esta unidad? Solo posible si no tiene actividades.');">
                    <input type="hidden" name="phase" value="<?= htmlspecialchars($phaseId) ?>">
                    <input type="hidden" name="delete_unit" value="<?= htmlspecialchars($unit["id"]) ?>">
                    <button type="submit" class="btn-del"
                            <?= (int) ($unit["activity_count"] ?? 0) > 0 ? 'disabled title="Tiene actividades"' : '' ?>>✕</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>
<script>
function toggleRename(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'flex' : 'none';
}
</script>
</body>
</html>

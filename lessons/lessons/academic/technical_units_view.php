<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ════════════════════════════════════════
   RESOLVE CONTEXT: module-based (new) OR course-based (legacy)
   ════════════════════════════════════════ */
$moduleId = isset($_GET["module"]) && ctype_digit((string) $_GET["module"])
            ? (int) $_GET["module"] : null;
$courseId = isset($_GET["course"]) && ctype_digit((string) $_GET["course"])
            ? (int) $_GET["course"] : null;

$module = null;
$course = null;

if ($moduleId !== null) {
    // New flow: module-based
    $stmtMod = $pdo->prepare("
        SELECT m.*, c.name AS course_name, c.id AS course_id
        FROM technical_modules m
        JOIN courses c ON c.id = m.course_id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmtMod->execute(["id" => $moduleId]);
    $module = $stmtMod->fetch(PDO::FETCH_ASSOC);

    if (!$module) die("Módulo no encontrado.");

    $courseId = (int) $module["course_id"];

    $stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
    $stmtCourse->execute(["id" => $courseId]);
    $course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

} elseif ($courseId !== null) {
    // Legacy flow: course-based
    $stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
    $stmtCourse->execute(["id" => $courseId]);
    $course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

    if (!$course) die("Curso no encontrado.");
} else {
    die("Parámetro requerido: module o course.");
}

/* ════════════════════════════════════════
   CREATE UNIT (POST)
   ════════════════════════════════════════ */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {
    $unitName = mb_strtoupper(trim($_POST["unit_name"]), "UTF-8");

    // Check for duplicate
    if ($moduleId !== null) {
        $check = $pdo->prepare("
            SELECT id FROM units
            WHERE module_id = :mid AND name = :name
            LIMIT 1
        ");
        $check->execute(["mid" => $moduleId, "name" => $unitName]);
    } else {
        $check = $pdo->prepare("
            SELECT id FROM units
            WHERE course_id = :cid AND name = :name
            LIMIT 1
        ");
        $check->execute(["cid" => $courseId, "name" => $unitName]);
    }
    $existing = $check->fetchColumn();

    if ($existing) {
        // Go straight to hub for this unit
        header("Location: ../activities/hub/index.php?unit=" . urlencode($existing));
        exit;
    }

    // Insert new unit
    if ($moduleId !== null) {
        $ins = $pdo->prepare("
            INSERT INTO units (course_id, module_id, name, created_at)
            VALUES (:cid, :mid, :name, NOW())
        ");
        $ins->execute(["cid" => $courseId, "mid" => $moduleId, "name" => $unitName]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO units (course_id, name, created_at)
            VALUES (:cid, :name, NOW())
        ");
        $ins->execute(["cid" => $courseId, "name" => $unitName]);
    }

    $newUnitId = $pdo->lastInsertId();
    if (!$newUnitId) {
        // PostgreSQL fallback
        $newUnitId = $pdo->query("SELECT lastval()")->fetchColumn();
    }

    header("Location: ../activities/hub/index.php?unit=" . urlencode($newUnitId));
    exit;
}

/* ════════════════════════════════════════
   DELETE UNIT
   ════════════════════════════════════════ */
if (isset($_GET["delete_unit"]) && ctype_digit((string) $_GET["delete_unit"])) {
    $delId = (int) $_GET["delete_unit"];

    // Only delete if no activities
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE unit_id = :id");
    $cnt->execute(["id" => $delId]);
    if ((int) $cnt->fetchColumn() === 0) {
        $pdo->prepare("DELETE FROM units WHERE id = :id")->execute(["id" => $delId]);
    }

    $redirect = $moduleId !== null
        ? "technical_units_view.php?module=" . urlencode($moduleId)
        : "technical_units_view.php?course=" . urlencode($courseId);
    header("Location: $redirect");
    exit;
}

/* ════════════════════════════════════════
   LIST UNITS
   ════════════════════════════════════════ */
if ($moduleId !== null) {
    $stmtUnits = $pdo->prepare("
        SELECT u.*, (SELECT COUNT(*) FROM activities a WHERE a.unit_id = u.id) AS activity_count
        FROM units u
        WHERE u.module_id = :mid
        ORDER BY u.created_at ASC
    ");
    $stmtUnits->execute(["mid" => $moduleId]);
} else {
    $stmtUnits = $pdo->prepare("
        SELECT u.*, (SELECT COUNT(*) FROM activities a WHERE a.unit_id = u.id) AS activity_count
        FROM units u
        WHERE u.course_id = :cid
        ORDER BY u.created_at ASC
    ");
    $stmtUnits->execute(["cid" => $courseId]);
}
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

// Back URL
$backUrl = $moduleId !== null
    ? "technical_modules_view.php?course=" . urlencode($courseId)
    : "technical_courses_created.php";

$pageTitle = $moduleId !== null
    ? mb_strtoupper($module["name"], "UTF-8")
    : mb_strtoupper($course["name"] ?? "", "UTF-8");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — Unidades</title>

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
.btn-del:disabled{
    background:#d1d5db;
    cursor:not-allowed;
}

.empty{ color:var(--muted); font-size:14px; padding:6px 0; }

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

<a class="back" href="<?= htmlspecialchars($backUrl) ?>">← Volver<?= $moduleId !== null ? ' a Módulos' : ' a Semestres' ?></a>

<?php if ($moduleId !== null): ?>
<p class="breadcrumb">
    Programa Técnico
    › <?= htmlspecialchars(mb_strtoupper($course["name"] ?? "", "UTF-8")) ?>
    › <span><?= htmlspecialchars(mb_strtoupper($module["name"], "UTF-8")) ?></span>
    › Unidades
</p>
<?php endif; ?>

<!-- Create unit -->
<div class="card">
    <h2>➕ Crear Unidad</h2>
    <form method="POST" class="create-form">
        <input type="text" name="unit_name" required placeholder="Ej: UNIDAD 1">
        <button type="submit">Crear</button>
    </form>
</div>

<!-- Unit list -->
<div class="card">
    <h2>📚 Unidades — <?= htmlspecialchars($pageTitle) ?></h2>

    <?php if (empty($units)): ?>
        <p class="empty">No hay unidades creadas<?= $moduleId !== null ? ' en este módulo' : '' ?>.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
        <div class="unit-item">
            <div class="unit-info">
                <span class="unit-name"><?= htmlspecialchars(mb_strtoupper($unit["name"], "UTF-8")) ?></span>
                <span class="unit-meta">
                    <?= (int) ($unit["activity_count"] ?? 0) ?> actividad<?= (int) ($unit["activity_count"] ?? 0) !== 1 ? 'es' : '' ?>
                </span>
            </div>
            <div class="unit-actions">
                <a class="btn-view"
                   href="technical_activities_view.php?unit=<?= urlencode($unit["id"]) ?>">
                   Ver Actividades →
                </a>
                <form method="GET" style="margin:0;"
                      onsubmit="return confirm('¿Eliminar esta unidad? Solo es posible si no tiene actividades.');">
                    <input type="hidden" name="<?= $moduleId !== null ? 'module' : 'course' ?>"
                           value="<?= htmlspecialchars((string)($moduleId ?? $courseId)) ?>">
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
</body>
</html>

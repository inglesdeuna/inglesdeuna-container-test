<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId || !ctype_digit($courseId)) {
    die("Curso no válido.");
}

/* ════════════════════════════════════════
   AUTO-MIGRATE: ensure schema exists
   ════════════════════════════════════════ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS technical_modules (
            id         SERIAL PRIMARY KEY,
            course_id  INTEGER NOT NULL,
            name       TEXT    NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Throwable $e) { /* already exists */ }

try {
    $pdo->exec("
        ALTER TABLE units ADD COLUMN IF NOT EXISTS module_id INTEGER
    ");
} catch (Throwable $e) { /* already exists */ }

/* ════════════════════════════════════════
   GET COURSE (semester)
   ════════════════════════════════════════ */
$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id LIMIT 1");
$stmtCourse->execute(["id" => $courseId]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) die("Curso no encontrado.");

/* ════════════════════════════════════════
   AUTO-ASSIGN orphaned units to default module
   If units exist for this course with no module_id,
   create "MÓDULO 1" (if needed) and assign them.
   ════════════════════════════════════════ */
$orphanStmt = $pdo->prepare("
    SELECT COUNT(*) FROM units
    WHERE course_id = :cid AND module_id IS NULL
");
$orphanStmt->execute(["cid" => $courseId]);
$orphanCount = (int) $orphanStmt->fetchColumn();

if ($orphanCount > 0) {
    // Get first existing module for this course, or create one
    $firstMod = $pdo->prepare("
        SELECT id FROM technical_modules
        WHERE course_id = :cid
        ORDER BY created_at ASC LIMIT 1
    ");
    $firstMod->execute(["cid" => $courseId]);
    $defModId = $firstMod->fetchColumn();

    if (!$defModId) {
        $ins = $pdo->prepare("
            INSERT INTO technical_modules (course_id, name, created_at)
            VALUES (:cid, 'MÓDULO 1', CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $ins->execute(["cid" => $courseId]);
        $defModId = $ins->fetchColumn();
    }

    $pdo->prepare("
        UPDATE units SET module_id = :mid
        WHERE course_id = :cid AND module_id IS NULL
    ")->execute(["mid" => $defModId, "cid" => $courseId]);
}

/* ════════════════════════════════════════
   CREATE MODULE
   ════════════════════════════════════════ */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["module_name"])) {
    $moduleName = mb_strtoupper(trim($_POST["module_name"]), "UTF-8");

    $pdo->prepare("
        INSERT INTO technical_modules (course_id, name, created_at)
        VALUES (:cid, :name, CURRENT_TIMESTAMP)
    ")->execute(["cid" => $courseId, "name" => $moduleName]);

    header("Location: technical_modules_view.php?course=" . urlencode($courseId) . "&created=1");
    exit;
}

/* ════════════════════════════════════════
   DELETE MODULE (only if empty)
   ════════════════════════════════════════ */
if (isset($_GET["delete_module"]) && ctype_digit((string) $_GET["delete_module"])) {
    $modId = (int) $_GET["delete_module"];

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM units WHERE module_id = :mid");
    $cnt->execute(["mid" => $modId]);
    if ((int) $cnt->fetchColumn() === 0) {
        $pdo->prepare("
            DELETE FROM technical_modules WHERE id = :id AND course_id = :cid
        ")->execute(["id" => $modId, "cid" => $courseId]);
    }

    header("Location: technical_modules_view.php?course=" . urlencode($courseId));
    exit;
}

/* ════════════════════════════════════════
   RENAME MODULE
   ════════════════════════════════════════ */
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"]) && $_POST["action"] === "rename_module"
    && ctype_digit((string) ($_POST["rename_id"] ?? ''))
    && trim((string) ($_POST["rename_name"] ?? '')) !== '') {
    $renameId   = (int) $_POST["rename_id"];
    $renameName = mb_strtoupper(trim((string) $_POST["rename_name"]), "UTF-8");
    $pdo->prepare("UPDATE technical_modules SET name = :name WHERE id = :id AND course_id = :cid")
        ->execute(["name" => $renameName, "id" => $renameId, "cid" => $courseId]);
    header("Location: technical_modules_view.php?course=" . urlencode($courseId) . "&renamed=1");
    exit;
}

/* ════════════════════════════════════════
   LIST MODULES (with unit count)
   ════════════════════════════════════════ */
$stmtMods = $pdo->prepare("
    SELECT m.id, m.name, m.created_at, COUNT(u.id) AS unit_count
    FROM technical_modules m
    LEFT JOIN units u ON u.module_id = m.id
    WHERE m.course_id = :cid
    GROUP BY m.id, m.name, m.created_at
    ORDER BY m.created_at ASC, m.id ASC
");
$stmtMods->execute(["cid" => $courseId]);
$modules = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(mb_strtoupper($course["name"], "UTF-8")) ?> — Módulos</title>

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

.container{
    max-width:860px;
    margin:0 auto;
}

/* back btn */
.back{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-bottom:26px;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    color:#fff;
    padding:10px 18px;
    border-radius:10px;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
    box-shadow:var(--shadow);
}

/* breadcrumb */
.breadcrumb{
    font-size:13px;
    color:var(--muted);
    margin-bottom:22px;
    font-weight:600;
}
.breadcrumb span{ color:var(--green); }

/* card */
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

/* create form */
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

/* module row */
.module-row{
    background:var(--green-soft);
    border:1px solid var(--line);
    padding:16px 20px;
    border-radius:14px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
}

.module-info{
    display:flex;
    flex-direction:column;
    gap:3px;
}

.module-name{
    font-size:16px;
    font-weight:800;
    color:#1a4229;
}

.module-meta{
    font-size:12px;
    color:var(--muted);
    font-weight:600;
}

.module-actions{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

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

.empty{
    color:var(--muted);
    font-size:14px;
    padding:10px 0;
}

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
    .module-row{ flex-direction:column; align-items:flex-start; }
    .module-actions{ width:100%; }
    .btn-view{ flex:1; text-align:center; }
}
</style>
</head>

<body>
<div class="container">

<a class="back" href="technical_courses_created.php">← Volver a Semestres</a>

<p class="breadcrumb">
    Programa Técnico
    › <span><?= htmlspecialchars(mb_strtoupper($course["name"], "UTF-8")) ?></span>
    › Módulos
</p>

<?php if (isset($_GET["created"])): ?>
    <div class="banner-success">✔ Módulo creado correctamente.</div>
<?php endif; ?>
<?php if (isset($_GET["renamed"])): ?>
    <div class="banner-success">✔ Nombre actualizado correctamente.</div>
<?php endif; ?>

<!-- Create module -->
<div class="card">
    <h2>➕ Crear Módulo</h2>
    <form method="POST" class="create-form">
        <input type="text" name="module_name" required
               placeholder="Ej: MÓDULO 2 — COMUNICACIÓN ORAL">
        <button type="submit">Crear</button>
    </form>
</div>

<!-- Module list -->
<div class="card">
    <h2>📦 Módulos — <?= htmlspecialchars(mb_strtoupper($course["name"], "UTF-8")) ?></h2>

    <?php if (empty($modules)): ?>
        <p class="empty">No hay módulos creados para este semestre.</p>
    <?php else: ?>
        <?php foreach ($modules as $mod): ?>
        <div class="module-row">
            <div class="module-info">
                <span class="module-name"><?= htmlspecialchars(mb_strtoupper($mod["name"], "UTF-8")) ?></span>
                <span class="module-meta">
                    <?= (int) $mod["unit_count"] ?> unidad<?= (int) $mod["unit_count"] !== 1 ? 'es' : '' ?>
                </span>
                <!-- Inline rename form -->
                <form method="POST" class="rename-form"
                      id="rename-mod-<?= $mod['id'] ?>" style="display:none;">
                    <input type="hidden" name="action" value="rename_module">
                    <input type="hidden" name="rename_id" value="<?= htmlspecialchars($mod['id']) ?>">
                    <input type="text" name="rename_name" required
                           value="<?= htmlspecialchars($mod['name']) ?>">
                    <button type="submit" class="btn-save-rename">Guardar</button>
                    <button type="button" class="btn-cancel-rename"
                            onclick="toggleRename('rename-mod-<?= $mod['id'] ?>')">Cancelar</button>
                </form>
            </div>
            <div class="module-actions">
                <button type="button" class="btn-edit"
                        onclick="toggleRename('rename-mod-<?= $mod['id'] ?>')"
                        title="Renombrar módulo">✎ Editar</button>
                <a class="btn-view"
                   href="technical_units_view.php?module=<?= urlencode($mod["id"]) ?>">
                   Ver Unidades →
                </a>
                <form method="GET" style="margin:0;"
                      onsubmit="return confirm('¿Eliminar este módulo? Solo se puede eliminar si no tiene unidades.');">
                    <input type="hidden" name="course" value="<?= htmlspecialchars($courseId) ?>">
                    <input type="hidden" name="delete_module" value="<?= htmlspecialchars($mod["id"]) ?>">
                    <button type="submit" class="btn-del"
                            <?= (int) $mod["unit_count"] > 0 ? 'disabled title="Tiene unidades asignadas"' : '' ?>>✕</button>
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

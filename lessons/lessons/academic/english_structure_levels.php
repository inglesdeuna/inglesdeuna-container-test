<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA ENGLISH
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT * FROM programs
    WHERE slug = 'prog_english_courses'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa de inglés no encontrado.");
}

/* ===============================
   CREAR LEVEL
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["level_name"])
    && ($_POST["action"] ?? '') !== "rename_level") {
    $levelName = mb_strtoupper(trim($_POST["level_name"]), "UTF-8");
    $pdo->prepare("
        INSERT INTO english_levels (program_id, name, created_at)
        VALUES (:program_id, :name, NOW())
    ")->execute(["program_id" => $program["id"], "name" => $levelName]);
    header("Location: english_structure_levels.php?created=1");
    exit;
}

/* ===============================
   RENOMBRAR LEVEL
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"]) && $_POST["action"] === "rename_level"
    && ctype_digit((string) ($_POST["rename_id"] ?? ''))
    && trim((string) ($_POST["rename_name"] ?? '')) !== '') {
    $renameId   = (int) $_POST["rename_id"];
    $renameName = mb_strtoupper(trim((string) $_POST["rename_name"]), "UTF-8");
    $pdo->prepare("UPDATE english_levels SET name = :name WHERE id = :id AND program_id = :pid")
        ->execute(["name" => $renameName, "id" => $renameId, "pid" => $program["id"]]);
    header("Location: english_structure_levels.php?renamed=1");
    exit;
}

/* ===============================
   ELIMINAR LEVEL (solo si sin phases)
=============================== */
if (isset($_GET["delete_level"]) && ctype_digit((string) $_GET["delete_level"])) {
    $delId = (int) $_GET["delete_level"];
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM english_phases WHERE level_id = :id");
    $cnt->execute(["id" => $delId]);
    if ((int) $cnt->fetchColumn() === 0) {
        $pdo->prepare("DELETE FROM english_levels WHERE id = :id AND program_id = :pid")
            ->execute(["id" => $delId, "pid" => $program["id"]]);
    }
    header("Location: english_structure_levels.php");
    exit;
}

/* ===============================
   LISTAR LEVELS con conteo de phases
=============================== */
$stmtLevels = $pdo->prepare("
    SELECT l.*, COUNT(p.id) AS phase_count
    FROM english_levels l
    LEFT JOIN english_phases p ON p.level_id = l.id
    WHERE l.program_id = :program_id
    GROUP BY l.id, l.name, l.program_id, l.created_at
    ORDER BY l.created_at ASC
");
$stmtLevels->execute(["program_id" => $program["id"]]);
$levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>English — Gestionar Levels</title>
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

.level-row{
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
.level-info{ display:flex; flex-direction:column; gap:3px; }
.level-name{ font-size:16px; font-weight:800; color:#1a4229; }
.level-meta{ font-size:12px; color:var(--muted); font-weight:600; }
.level-actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

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

.empty{ color:var(--muted); font-size:14px; padding:10px 0; }

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
    .level-row{ flex-direction:column; align-items:flex-start; }
    .level-actions{ width:100%; }
    .btn-view{ flex:1; text-align:center; }
}
</style>
</head>
<body>
<div class="container">

<a class="back" href="../admin/dashboard.php">← Volver</a>

<p class="breadcrumb">
    Cursos de Inglés › <span>Levels</span>
</p>

<?php if (isset($_GET["created"])): ?>
    <div class="banner-success">✔ Level creado correctamente.</div>
<?php endif; ?>
<?php if (isset($_GET["renamed"])): ?>
    <div class="banner-success">✔ Nombre actualizado correctamente.</div>
<?php endif; ?>

<div class="card">
    <h2>➕ Crear Level</h2>
    <form method="POST" class="create-form">
        <input type="text" name="level_name" required placeholder="Ej: A1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Levels creados</h2>

    <?php if (empty($levels)): ?>
        <p class="empty">No hay levels creados.</p>
    <?php else: ?>
        <?php foreach ($levels as $level): ?>
        <div class="level-row">
            <div class="level-info">
                <span class="level-name"><?= htmlspecialchars(mb_strtoupper($level["name"], "UTF-8")) ?></span>
                <span class="level-meta">
                    <?= (int) $level["phase_count"] ?> phase<?= (int) $level["phase_count"] !== 1 ? 's' : '' ?>
                </span>
                <form method="POST" class="rename-form"
                      id="rename-lv-<?= $level['id'] ?>" style="display:none;">
                    <input type="hidden" name="action" value="rename_level">
                    <input type="hidden" name="rename_id" value="<?= htmlspecialchars($level['id']) ?>">
                    <input type="text" name="rename_name" required
                           value="<?= htmlspecialchars($level['name']) ?>">
                    <button type="submit" class="btn-save-rename">Guardar</button>
                    <button type="button" class="btn-cancel-rename"
                            onclick="toggleRename('rename-lv-<?= $level['id'] ?>')">Cancelar</button>
                </form>
            </div>
            <div class="level-actions">
                <button type="button" class="btn-edit"
                        onclick="toggleRename('rename-lv-<?= $level['id'] ?>')"
                        title="Renombrar level">✎ Editar</button>
                <a class="btn-view"
                   href="english_structure_phases.php?level=<?= urlencode($level["id"]) ?>">
                    Administrar →
                </a>
                <form method="GET" style="margin:0;"
                      onsubmit="return confirm('¿Eliminar este level? Solo posible si no tiene phases.');">
                    <input type="hidden" name="delete_level" value="<?= htmlspecialchars($level["id"]) ?>">
                    <button type="submit" class="btn-del"
                            <?= (int) $level["phase_count"] > 0 ? 'disabled title="Tiene phases asignadas"' : '' ?>>✕</button>
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

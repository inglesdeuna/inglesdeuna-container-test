<?php
session_start();
require_once "../../config/db.php";

$unit_id = $_GET['unit'] ?? null;

if (!$unit_id) {
    die("Unidad no especificada.");
}

/* ===============================
   OBTENER UNIT (SOLO ENGLISH)
=============================== */
$stmt = $pdo->prepare("
    SELECT id, phase_id
    FROM units
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

if (empty($unit['phase_id'])) {
    die("Esta unidad no pertenece a English Structure.");
}

/* ===============================
   URL VOLVER (ENGLISH)
=============================== */
$backUrl = "../../academic/english_structure_units.php?phase=" . urlencode($unit['phase_id']);

/* ===============================
   TIPOS DE ACTIVIDADES
=============================== */
$activityTypes = [
    "drag_drop" => "Drag & Drop",
    "flashcards" => "Flashcards",
    "memory_cards" => "Memory Cards",
    "match" => "Match",
    "multiple_choice" => "Multiple Choice",
    "video_comprehension" => "Video Comprehension",
    "hangman" => "Hangman",
    "listen_order" => "Listen Order",
    "order_sentences" => "Order the Sentences",
    "pronunciation" => "Pronunciation",
    "dictation" => "Dictation",
    "external" => "External",
    "flipbooks" => "Flipbooks",
    "powerpoint" => "PowerPoint",
    "writing_practice" => "Writing Practice",
];

/* ===============================
   ACTIVIDADES YA CREADAS
=============================== */
$stmtActivities = $pdo->prepare("
    SELECT type, COUNT(*) AS cnt
    FROM activities
    WHERE unit_id = :unit_id
    GROUP BY type
");
$stmtActivities->execute(['unit_id' => $unit_id]);
$createdCounts = [];
foreach ($stmtActivities->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $createdCounts[(string) $row['type']] = (int) $row['cnt'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hub de Actividades (English)</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

:root{
    --primary:#3b82f6;
    --primary-dark:#1d4ed8;
    --primary-light:#eaf2ff;
    --line:#bfdbfe;
    --ink:#0f172a;
    --muted:#475569;
    --success:#16a34a;
    --success-bg:#eff6ff;
    --shadow:0 18px 40px rgba(15,23,42,.12);
}

*{box-sizing:border-box}

body{
    margin:0;
    min-height:100vh;
    font-family:'Nunito','Segoe UI',sans-serif;
    color:var(--ink);
    background:
        radial-gradient(circle at top left, rgba(255,255,255,.72), rgba(255,255,255,0) 28%),
        radial-gradient(circle at top right, rgba(255,255,255,.6), rgba(255,255,255,0) 24%),
        linear-gradient(135deg, #dff5ff 0%, #fff4db 48%, #f8d9e6 100%);
    padding:24px 18px 32px;
}

.page{
    max-width:640px;
    margin:0 auto;
}

.topbar{
    display:flex;
    justify-content:flex-start;
    margin-bottom:10px;
}

.btn-volver{
    display:inline-block;
    background:linear-gradient(180deg,#94a3b8,#64748b);
    color:#fff;
    padding:10px 16px;
    border-radius:999px;
    text-decoration:none;
    font-weight:800;
    font-size:14px;
    box-shadow:0 10px 22px rgba(15,23,42,.14);
    transition:transform .18s ease, filter .18s ease;
}

.btn-volver:hover{
    transform:translateY(-1px);
    filter:brightness(1.04);
}

.intro{
    margin-bottom:10px;
    padding:14px 18px;
    border-radius:22px;
    border:1px solid #dbeafe;
    background:linear-gradient(135deg,#eff6ff 0%,#f5f3ff 45%,#fff7ed 100%);
    box-shadow:0 16px 34px rgba(15,23,42,.09);
    text-align:center;
}

.intro h1{
    margin:0 0 8px;
    color:var(--primary-dark);
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:clamp(24px,2.3vw,30px);
    line-height:1.1;
}

.intro p{
    margin:0;
    color:var(--muted);
    font-size:15px;
    line-height:1.55;
}

.card{
    max-width:100%;
    margin:0 auto;
    background:linear-gradient(135deg,#ffffff 0%,#f8fbff 100%);
    padding:18px;
    border-radius:22px;
    border:1px solid rgba(255,255,255,.75);
    box-shadow:var(--shadow);
}

.card h2{
    text-align:center;
    margin:0 0 14px;
    color:var(--primary-dark);
    font-family:'Fredoka','Trebuchet MS',sans-serif;
    font-size:22px;
}

.list{
    list-style:none;
    padding:0;
    margin:0 0 24px 0;
    display:grid;
    gap:10px;
}

.list li{
    padding:12px 14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:linear-gradient(135deg,#eff6ff 0%,#ffffff 100%);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.list label{
    display:flex;
    align-items:center;
    gap:10px;
    font-weight:800;
    color:#1e3a8a;
    cursor:pointer;
}

.list input[type="checkbox"]{
    width:18px;
    height:18px;
    accent-color:var(--primary);
}

.row-right{
    display:flex;
    align-items:center;
    gap:8px;
}

.qty-wrap{
    display:none;
    align-items:center;
}

.qty-input{
    width:52px;
    padding:4px 8px;
    border:1.5px solid var(--line);
    border-radius:8px;
    font-size:15px;
    font-weight:700;
    text-align:center;
    color:var(--primary-dark);
    background:#f0f7ff;
}

.created{
    color:var(--primary-dark);
    background:var(--success-bg);
    border:1px solid #93c5fd;
    padding:6px 10px;
    border-radius:999px;
    font-weight:800;
    white-space:nowrap;
}

.btn-submit{
    width:100%;
    background:linear-gradient(180deg,#3b82f6,#1d4ed8);
    color:#fff;
    padding:13px 16px;
    border:none;
    border-radius:12px;
    font-weight:800;
    font-size:15px;
    cursor:pointer;
    box-shadow:0 12px 26px rgba(29,78,216,.28);
    transition:transform .18s ease, filter .18s ease;
}

.btn-submit:hover{
    transform:translateY(-1px);
    filter:brightness(1.04);
}

@media (max-width:640px){
    body{padding:14px 12px 20px}
    .intro{padding:16px 14px}
    .card{padding:16px}
    .list li{flex-direction:column;align-items:flex-start}
    .created{align-self:flex-start}
}
</style>
</head>

<body>

<div class="page">
    <div class="topbar">
        <a class="btn-volver" href="<?= $backUrl; ?>">
            ← Volver
        </a>
    </div>

    <section class="intro">
        <h1>Escoger Actividades</h1>
        <p>Selecciona las actividades que quieres crear para esta unidad usando la misma configuración visual del panel administrativo.</p>
    </section>

    <div class="card">

        <h2>Lista de Actividades</h2>

        <form method="POST" action="../create_activity.php">
            <input type="hidden" name="unit" value="<?= htmlspecialchars($unit_id); ?>">

            <ul class="list">
                <?php foreach ($activityTypes as $type => $label): ?>
                    <li>
                        <label>
                            <input type="checkbox" class="type-cb" name="checked_types[]" value="<?= htmlspecialchars($type); ?>">
                            <?= htmlspecialchars($label); ?>
                        </label>

                        <div class="row-right">
                            <?php $cnt = $createdCounts[$type] ?? 0; if ($cnt > 0): ?>
                                <span class="created">✓ <?= $cnt; ?> <?= $cnt === 1 ? 'creada' : 'creadas'; ?></span>
                            <?php endif; ?>
                            <span class="qty-wrap" id="qty_<?= htmlspecialchars($type); ?>">
                                <input type="number" name="qty[<?= htmlspecialchars($type); ?>]" value="1" min="1" max="9" class="qty-input">
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <button type="submit" class="btn-submit">
                CREAR ACTIVIDADES →
            </button>
        </form>

    </div>
</div>

<script>
document.querySelectorAll('.type-cb').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var wrap = document.getElementById('qty_' + this.value);
        if (wrap) wrap.style.display = this.checked ? 'flex' : 'none';
    });
});
</script>
</body>
</html>

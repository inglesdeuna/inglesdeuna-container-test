<?php
/**
 * placement/placement_results.php — Placement Test Results
 * Requires admin session. Shows results for all placement exams with search by name or CC.
 */
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// Ensure is_placement column exists (idempotent)
try {
    $pdo->exec("ALTER TABLE eval_exams ADD COLUMN IF NOT EXISTS is_placement BOOLEAN DEFAULT FALSE");
} catch (Throwable $e) { /* already exists */ }

// ─── Filters ──────────────────────────────────────────────────────────────────
$search      = trim($_GET['q']       ?? '');
$filterLevel = trim($_GET['level']   ?? '');
$filterModal = trim($_GET['modality'] ?? '');
$filterDate  = trim($_GET['date']    ?? '');

// ─── Load placement exams ─────────────────────────────────────────────────────
$placementExams = $pdo->query(
    "SELECT id, title, cefr_level FROM eval_exams WHERE is_placement=TRUE ORDER BY cefr_level"
)->fetchAll(PDO::FETCH_ASSOC);

// ─── Build results query ───────────────────────────────────────────────────────
$sql    = "SELECT r.*, e.cefr_level AS exam_level, e.title AS exam_title
           FROM eval_results r
           JOIN eval_exams e ON e.id = r.exam_id
           WHERE e.is_placement = TRUE";
$params = [];

if ($search !== '') {
    $sql .= " AND (r.student_name ILIKE ? OR r.student_doc ILIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($filterLevel !== '') {
    $sql .= " AND e.cefr_level = ?";
    $params[] = $filterLevel;
}
if ($filterModal !== '') {
    $sql .= " AND r.modality = ?";
    $params[] = $filterModal;
}
if ($filterDate !== '') {
    $sql .= " AND DATE(COALESCE(r.submitted_at, r.started_at)) = ?";
    $params[] = $filterDate;
}
$sql .= " ORDER BY COALESCE(r.submitted_at, r.started_at) DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Stats ────────────────────────────────────────────────────────────────────
$statsTotal     = count($results);
$statsSubmitted = count(array_filter($results, fn($r) => $r['status'] === 'submitted'));
$avgPct         = $statsSubmitted > 0
    ? round(array_sum(array_column(array_filter($results, fn($r) => $r['pct'] !== null), 'pct')) / $statsSubmitted, 1)
    : 0;

$cefrColors = ['A1'=>'#6c757d','A2'=>'#17a2b8','B1'=>'#28a745','B2'=>'#007bff','C1'=>'#6f42c1','C2'=>'#dc3545'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Resultados — Placement Tests</title>
<style>
:root{
  --bg:#eef7f0;--card:#ffffff;--line:#d8e8dc;--text:#1f3b28;--title:#1f3b28;
  --muted:#5d7465;--green:#2f9e44;--green-dark:#237a35;--green-soft:#e9f8ee;
  --green-bright:#41b95a;--shadow:0 10px 24px rgba(0,0,0,.08);
  --shadow-sm:0 2px 8px rgba(0,0,0,.06);--radius:18px;
}
*{box-sizing:border-box;}
body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
.topbar{background:linear-gradient(180deg,var(--green),var(--green-dark));color:#fff;padding:16px 24px;}
.topbar-inner{max-width:1400px;margin:0 auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.topbar-title{margin:0;font-size:22px;font-weight:800;flex:1;}
.back-btn{display:inline-block;text-decoration:none;color:#fff;font-size:13px;font-weight:700;
  border-radius:12px;padding:9px 16px;background:linear-gradient(180deg,#4b8b5b,#356844);
  box-shadow:var(--shadow-sm);transition:filter .2s,transform .15s;}
.back-btn:hover{filter:brightness(1.05);transform:translateY(-1px);}
.page{max-width:1400px;margin:0 auto;padding:20px 20px 40px;}
.layout{display:grid;grid-template-columns:240px 1fr;gap:24px;align-items:start;}
.sidebar{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow);padding:18px;position:sticky;top:20px;}
.sidebar-title{margin:14px 0 8px;font-size:11px;font-weight:800;text-transform:uppercase;
  letter-spacing:.08em;color:var(--muted);}
.sidebar-title:first-child{margin-top:0;}
.nav-list{display:flex;flex-direction:column;gap:6px;}
.nav-link{display:block;text-decoration:none;color:#fff;font-size:13px;font-weight:700;
  padding:9px 12px;border-radius:12px;background:linear-gradient(180deg,#41b95a,#2f9e44);
  box-shadow:var(--shadow-sm);transition:filter .2s,transform .15s;}
.nav-link.secondary{background:linear-gradient(180deg,#7b8b7f,#66756a);}
.nav-link:hover{filter:brightness(1.06);transform:translateY(-1px);}
.main-content{min-width:0;}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow);padding:24px;margin-bottom:18px;}
.card h3{margin:0 0 16px;font-size:18px;font-weight:800;color:var(--green-dark);}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;}
.stat-box{background:var(--card);border:1px solid var(--line);border-radius:14px;
  box-shadow:var(--shadow-sm);padding:16px;text-align:center;}
.stat-label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.08em;color:var(--muted);margin-bottom:4px;}
.stat-value{display:block;font-size:26px;font-weight:800;color:var(--green-dark);}
.search-bar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.search-bar input,.search-bar select{padding:9px 13px;border:1px solid var(--line);border-radius:10px;
  font-size:13px;font-family:Arial,sans-serif;}
.search-bar input[type=text]{flex:1;min-width:180px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{text-align:left;padding:10px 12px;background:var(--green-soft);color:var(--green-dark);
  font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--line);}
td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f7fcf8;}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;color:#fff;}
.badge-online{background:#17a2b8;}
.badge-printed{background:#fd7e14;}
.badge-submitted{background:#28a745;}
.badge-started{background:#6c757d;}
.cefr-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;color:#fff;}
.btn{display:inline-block;text-decoration:none;color:#fff;font-size:13px;font-weight:700;
  padding:8px 14px;border-radius:10px;box-shadow:var(--shadow-sm);border:none;cursor:pointer;
  transition:filter .2s,transform .15s;}
.btn:hover{filter:brightness(1.06);transform:translateY(-1px);}
.btn-primary{background:linear-gradient(180deg,#41b95a,#2f9e44);}
.btn-secondary{background:linear-gradient(180deg,#7b8b7f,#66756a);}
.btn-sm{padding:5px 10px;font-size:12px;}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted);font-size:15px;}
@media(max-width:900px){
  .layout{grid-template-columns:1fr;}.sidebar{position:static;}
  .stats-row{grid-template-columns:1fr 1fr;}
}
@media(max-width:600px){.stats-row{grid-template-columns:1fr;}}
@media print{
  .topbar,.sidebar,.search-bar,.btn{display:none!important;}
  .layout{grid-template-columns:1fr;}
  .page{padding:0;}
  .card{box-shadow:none;border:1px solid #ccc;}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <h1 class="topbar-title">📋 Resultados — Placement Tests</h1>
    <a href="index.php" class="back-btn">← Gestionar Placement</a>
    <a href="/lessons/lessons/admin/dashboard.php" class="back-btn">← Dashboard</a>
  </div>
</header>

<main class="page">
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-title">Niveles</div>
    <div class="nav-list">
      <a class="nav-link <?= $filterLevel === '' ? '' : 'secondary' ?>" href="placement_results.php">Todos los niveles</a>
      <?php foreach ($placementExams as $ex): ?>
      <a class="nav-link <?= $filterLevel === $ex['cefr_level'] ? '' : 'secondary' ?>"
         href="placement_results.php?level=<?= h($ex['cefr_level']) ?>">
        <?= h($ex['cefr_level']) ?> — <?= h($ex['title']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="sidebar-title">Acciones</div>
    <div class="nav-list">
      <a class="nav-link secondary" href="index.php">Gestionar placement</a>
      <a class="nav-link secondary" href="/lessons/lessons/admin/dashboard.php">← Dashboard</a>
    </div>
  </aside>

  <!-- Panel principal -->
  <section class="main-content">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-box">
        <span class="stat-label">Total presentados</span>
        <span class="stat-value"><?= $statsTotal ?></span>
      </div>
      <div class="stat-box">
        <span class="stat-label">Completados</span>
        <span class="stat-value"><?= $statsSubmitted ?></span>
      </div>
      <div class="stat-box">
        <span class="stat-label">Promedio %</span>
        <span class="stat-value"><?= $avgPct ?>%</span>
      </div>
    </div>

    <!-- Results card -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <h3 style="margin:0;">
          Resultados<?= $filterLevel !== '' ? ' — Nivel ' . h($filterLevel) : '' ?>
        </h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-secondary btn-sm" onclick="exportCsv()">📊 Exportar CSV</button>
          <button class="btn btn-secondary btn-sm" onclick="window.print()">🖨️ Imprimir</button>
        </div>
      </div>

      <!-- Search & filters -->
      <form method="GET" class="search-bar" id="filter-form">
        <?php if ($filterLevel !== ''): ?>
        <input type="hidden" name="level" value="<?= h($filterLevel) ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?= h($search) ?>"
               placeholder="🔍 Buscar por nombre o CC..."
               oninput="document.getElementById('filter-form').submit()">
        <select name="modality" onchange="document.getElementById('filter-form').submit()">
          <option value="">Todas las modalidades</option>
          <option value="online"  <?= $filterModal === 'online'  ? 'selected' : '' ?>>Online</option>
          <option value="printed" <?= $filterModal === 'printed' ? 'selected' : '' ?>>Impreso</option>
        </select>
        <input type="date" name="date" value="<?= h($filterDate) ?>"
               onchange="document.getElementById('filter-form').submit()" title="Filtrar por fecha">
        <?php if ($search !== '' || $filterModal !== '' || $filterDate !== '' || $filterLevel !== ''): ?>
        <a href="placement_results.php" class="btn btn-secondary btn-sm">✕ Limpiar</a>
        <?php endif; ?>
      </form>

      <?php if (empty($results)): ?>
      <div class="empty-state">
        <?= $search !== '' || $filterLevel !== '' || $filterModal !== '' || $filterDate !== ''
            ? 'No se encontraron resultados con los filtros aplicados.'
            : 'Todavía no hay resultados de placement registrados.' ?>
      </div>
      <?php else: ?>
      <div style="overflow-x:auto;">
      <table id="results-table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>CC / Doc</th>
            <th>Nivel</th>
            <th>Modalidad</th>
            <th>Fecha</th>
            <th>Puntaje</th>
            <th>%</th>
            <th>MCER sugerido</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r):
          $cc      = $cefrColors[$r['cefr_suggested'] ?? ''] ?? '#6c757d';
          $dateStr = $r['submitted_at']
            ? date('d/m/Y H:i', strtotime($r['submitted_at']))
            : ($r['started_at'] ? date('d/m/Y', strtotime($r['started_at'])) : '-');
        ?>
        <tr>
          <td><strong><?= h($r['student_name'] ?? '-') ?></strong></td>
          <td><?= h($r['student_doc'] ?? '-') ?></td>
          <td>
            <span class="cefr-badge" style="background:<?= $cefrColors[$r['exam_level'] ?? ''] ?? '#6c757d' ?>">
              <?= h($r['exam_level'] ?? '-') ?>
            </span>
          </td>
          <td>
            <span class="badge badge-<?= $r['modality'] === 'printed' ? 'printed' : 'online' ?>">
              <?= h($r['modality']) ?>
            </span>
          </td>
          <td><?= h($dateStr) ?></td>
          <td>
            <?= $r['score'] !== null
              ? number_format((float)$r['score'], 1) . ' / ' . number_format((float)$r['max_score'], 1)
              : '-' ?>
          </td>
          <td>
            <?php if ($r['pct'] !== null): ?>
            <strong><?= number_format((float)$r['pct'], 1) ?>%</strong>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td>
            <?php if ($r['cefr_suggested']): ?>
            <span class="cefr-badge" style="background:<?= $cc ?>">
              <?= h($r['cefr_suggested']) ?>
            </span>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td>
            <span class="badge badge-<?= $r['status'] === 'submitted' ? 'submitted' : 'started' ?>">
              <?= h($r['status']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

  </section>
</div>
</main>

<script>
function exportCsv() {
  const rows = [['Nombre','CC / Doc','Nivel','Modalidad','Fecha','Puntaje','%','MCER sugerido','Estado']];
  document.querySelectorAll('#results-table tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length < 9) return;
    rows.push([
      cells[0].textContent.trim(),
      cells[1].textContent.trim(),
      cells[2].textContent.trim(),
      cells[3].textContent.trim(),
      cells[4].textContent.trim(),
      cells[5].textContent.trim(),
      cells[6].textContent.trim(),
      cells[7].textContent.trim(),
      cells[8].textContent.trim(),
    ]);
  });
  const csv  = rows.map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
  const blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = 'placement_results<?= $filterLevel !== '' ? '_' . $filterLevel : '' ?>.csv';
  a.click();
  URL.revokeObjectURL(url);
}
</script>
</body>
</html>

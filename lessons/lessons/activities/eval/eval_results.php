<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$examId = (int) ($_GET['exam_id'] ?? 0);
$msg    = '';

// ─── POST: Registrar nota impresa ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'register_printed') {
        $eid    = (int) ($_POST['exam_id'] ?? 0);
        $name   = trim($_POST['student_name'] ?? '');
        $doc    = trim($_POST['student_doc'] ?? '');
        $score  = (float) ($_POST['score'] ?? 0);
        $maxSc  = (float) ($_POST['max_score'] ?? 100);
        $pct    = $maxSc > 0 ? round($score / $maxSc * 100, 2) : 0;

        // Sugerir MCER
        $cefrStmt = $pdo->prepare(
            "SELECT cefr_level FROM eval_cefr_ranges
             WHERE is_global=TRUE AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1"
        );
        $cefrStmt->execute([$pct]);
        $cefrRow = $cefrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cefrRow && $eid > 0) {
            $cefrStmt2 = $pdo->prepare(
                "SELECT cefr_level FROM eval_cefr_ranges
                 WHERE exam_id=? AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1"
            );
            $cefrStmt2->execute([$eid, $pct]);
            $cefrRow = $cefrStmt2->fetch(PDO::FETCH_ASSOC);
        }
        $cefr = $cefrRow ? $cefrRow['cefr_level'] : 'A1';

        $pdo->prepare(
            "INSERT INTO eval_results (exam_id, student_name, student_doc, modality,
             score, max_score, pct, cefr_suggested, status, submitted_at)
             VALUES (?,?,?,'printed',?,?,?,?,'submitted',CURRENT_TIMESTAMP)"
        )->execute([$eid, $name, $doc, $score, $maxSc, $pct, $cefr]);
        $msg    = 'Nota impresa registrada correctamente.';
        $examId = $eid;
    }
}

// ─── Cargar datos ─────────────────────────────────────────────────────────────
$exam    = null;
$results = [];
$exams   = $pdo->query("SELECT id, title, cefr_level FROM eval_exams ORDER BY created_at DESC")
               ->fetchAll(PDO::FETCH_ASSOC);

// Filtros
$filterDate   = trim($_GET['date'] ?? '');
$filterCefr   = trim($_GET['cefr'] ?? '');
$filterModal  = trim($_GET['modality'] ?? '');

if ($examId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM eval_exams WHERE id=?");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    $sql = "SELECT * FROM eval_results WHERE exam_id=?";
    $params = [$examId];
    if ($filterDate !== '') {
        $sql .= " AND DATE(COALESCE(submitted_at, started_at)) = ?";
        $params[] = $filterDate;
    }
    if ($filterCefr !== '') {
        $sql .= " AND cefr_suggested = ?";
        $params[] = $filterCefr;
    }
    if ($filterModal !== '') {
        $sql .= " AND modality = ?";
        $params[] = $filterModal;
    }
    $sql .= " ORDER BY COALESCE(submitted_at, started_at) DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Stats del grupo
$statsTotal    = count($results);
$statsSubmitted = count(array_filter($results, fn($r) => $r['status'] === 'submitted'));
$avgPct        = $statsSubmitted > 0
    ? round(array_sum(array_column(array_filter($results, fn($r) => $r['pct'] !== null), 'pct')) / $statsSubmitted, 1)
    : 0;
$statsOnline   = count(array_filter($results, fn($r) => $r['modality'] === 'online'));
$statsPrinted  = count(array_filter($results, fn($r) => $r['modality'] === 'printed'));

$cefrColors = ['A1'=>'#6c757d','A2'=>'#17a2b8','B1'=>'#28a745','B2'=>'#007bff','C1'=>'#6f42c1','C2'=>'#dc3545'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Resultados — <?= $exam ? h($exam['title']) : 'Evaluaciones' ?></title>
<style>
:root{
  --bg:#eef7f0;--card:#ffffff;--line:#d8e8dc;--text:#1f3b28;--title:#1f3b28;
  --muted:#5d7465;--green:#2f9e44;--green-dark:#237a35;--green-soft:#e9f8ee;
  --green-bright:#41b95a;--gray:#6f7e73;--shadow:0 10px 24px rgba(0,0,0,.08);
  --shadow-sm:0 2px 8px rgba(0,0,0,.06);--radius:18px;
}
*{box-sizing:border-box;}
body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
.topbar{background:linear-gradient(180deg,var(--green),var(--green-dark));color:#fff;padding:16px 24px;}
.topbar-inner{max-width:1400px;margin:0 auto;display:flex;align-items:center;gap:16px;}
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
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px;}
.stat-box{background:var(--card);border:1px solid var(--line);border-radius:14px;
  box-shadow:var(--shadow-sm);padding:16px;text-align:center;}
.stat-label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;
  letter-spacing:.08em;color:var(--muted);margin-bottom:4px;}
.stat-value{display:block;font-size:26px;font-weight:800;color:var(--green-dark);}
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
.btn{display:inline-block;text-decoration:none;color:#fff;font-size:13px;font-weight:700;
  padding:8px 14px;border-radius:10px;box-shadow:var(--shadow-sm);border:none;cursor:pointer;
  transition:filter .2s,transform .15s;}
.btn:hover{filter:brightness(1.06);transform:translateY(-1px);}
.btn-primary{background:linear-gradient(180deg,#41b95a,#2f9e44);}
.btn-secondary{background:linear-gradient(180deg,#7b8b7f,#66756a);}
.btn-sm{padding:5px 10px;font-size:12px;}
.filter-row{display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.filter-row input,.filter-row select{padding:8px 12px;border:1px solid var(--line);border-radius:10px;
  font-size:13px;}
.cefr-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;color:#fff;}
.msg{padding:10px 16px;border-radius:10px;margin-bottom:14px;font-size:14px;font-weight:700;
  background:#e9f8ee;color:var(--green-dark);border:1px solid var(--line);}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;
  align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:#fff;border-radius:var(--radius);padding:28px;max-width:480px;width:95%;
  box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal h3{margin:0 0 18px;font-size:20px;font-weight:800;color:var(--green-dark);}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:4px;}
.form-group input,.form-group select{width:100%;padding:9px 12px;border:1px solid var(--line);
  border-radius:10px;font-size:14px;font-family:Arial,sans-serif;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:900px){.layout{grid-template-columns:1fr;}.sidebar{position:static;}
  .stats-row{grid-template-columns:1fr 1fr;}}
@media print{
  .topbar,.sidebar,.filter-row,.btn,.modal-bg{display:none!important;}
  .layout{grid-template-columns:1fr;}
  .page{padding:0;}
  .card{box-shadow:none;border:1px solid #ccc;}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <h1 class="topbar-title">
      Resultados<?= $exam ? ' — ' . h($exam['title']) : '' ?>
    </h1>
    <a href="admin_eval.php<?= $examId ? '?tab=results&exam_id=' . $examId : '' ?>" class="back-btn">← Módulo Eval</a>
    <a href="/lessons/lessons/admin/dashboard.php" class="back-btn">← Dashboard</a>
  </div>
</header>

<main class="page">
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-title">Exámenes</div>
    <div class="nav-list">
      <?php foreach ($exams as $ex): ?>
      <a class="nav-link <?= $ex['id'] == $examId ? '' : 'secondary' ?>"
         href="eval_results.php?exam_id=<?= $ex['id'] ?>">
        <?= h($ex['title']) ?>
        <?php if ($ex['cefr_level']): ?><small>(<?= h($ex['cefr_level']) ?>)</small><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="sidebar-title">Acciones</div>
    <div class="nav-list">
      <a class="nav-link secondary" href="admin_eval.php">← Volver al módulo</a>
      <a class="nav-link secondary" href="/lessons/lessons/admin/dashboard.php">← Dashboard</a>
    </div>
  </aside>

  <!-- Panel principal -->
  <section class="main-content">

    <?php if ($msg): ?>
    <div class="msg"><?= h($msg) ?></div>
    <?php endif; ?>

    <?php if (!$examId): ?>
    <div class="card">
      <h3>Selecciona un examen</h3>
      <p style="color:var(--muted);">Haz clic en un examen de la barra lateral para ver sus resultados.</p>
    </div>
    <?php else: ?>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-box"><span class="stat-label">Total presentados</span><span class="stat-value"><?= $statsTotal ?></span></div>
      <div class="stat-box"><span class="stat-label">Completados</span><span class="stat-value"><?= $statsSubmitted ?></span></div>
      <div class="stat-box"><span class="stat-label">Promedio %</span><span class="stat-value"><?= $avgPct ?>%</span></div>
      <div class="stat-box"><span class="stat-label">Online / Impreso</span><span class="stat-value"><?= $statsOnline ?> / <?= $statsPrinted ?></span></div>
    </div>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <h3 style="margin:0;">Resultados del grupo</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-secondary" onclick="exportCsv()">📊 Exportar CSV</button>
          <button class="btn btn-secondary" onclick="window.print()">🖨️ Exportar PDF</button>
          <button class="btn btn-primary" onclick="document.getElementById('printed-modal').classList.add('open')">+ Nota impresa</button>
        </div>
      </div>

      <!-- Filtros -->
      <form method="GET" class="filter-row">
        <input type="hidden" name="exam_id" value="<?= $examId ?>">
        <input type="date" name="date" value="<?= h($filterDate) ?>" title="Filtrar por fecha">
        <select name="cefr">
          <option value="">Todos los niveles</option>
          <?php foreach (['A1','A2','B1','B2','C1','C2'] as $lvl): ?>
          <option value="<?= $lvl ?>" <?= $filterCefr === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
          <?php endforeach; ?>
        </select>
        <select name="modality">
          <option value="">Todas las modalidades</option>
          <option value="online" <?= $filterModal === 'online' ? 'selected' : '' ?>>Online</option>
          <option value="printed" <?= $filterModal === 'printed' ? 'selected' : '' ?>>Impreso</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <a href="eval_results.php?exam_id=<?= $examId ?>" class="btn btn-secondary btn-sm">Limpiar</a>
      </form>

      <table id="results-table">
        <thead>
          <tr>
            <th>Nombre</th><th>Doc</th><th>Modalidad</th><th>Fecha</th>
            <th>Puntaje</th><th>%</th><th>Nivel MCER</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $r):
          $cc = $cefrColors[$r['cefr_suggested'] ?? ''] ?? '#6c757d';
          $dateStr = $r['submitted_at']
            ? date('d/m/Y H:i', strtotime($r['submitted_at']))
            : ($r['started_at'] ? date('d/m/Y', strtotime($r['started_at'])) : '-');
        ?>
        <tr>
          <td><strong><?= h($r['student_name'] ?? '-') ?></strong></td>
          <td><?= h($r['student_doc'] ?? '-') ?></td>
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
            <?php if ($r['status'] !== 'submitted' && $r['student_phone']): ?>
            <a class="btn btn-primary btn-sm" style="margin-left:6px;"
               href="https://wa.me/<?= h(preg_replace('/\D/', '', $r['student_phone'])) ?>?text=<?= urlencode('Hola ' . ($r['student_name'] ?? '') . ', recuerda completar tu evaluación.') ?>"
               target="_blank">WA</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($results)): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px;">Sin resultados todavía.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>
</main>

<!-- Modal: Nota impresa -->
<div class="modal-bg" id="printed-modal">
  <div class="modal">
    <h3>Registrar nota impresa</h3>
    <form method="POST">
      <input type="hidden" name="action" value="register_printed">
      <input type="hidden" name="exam_id" value="<?= $examId ?>">
      <div class="form-group"><label>Nombre del estudiante *</label><input type="text" name="student_name" required></div>
      <div class="form-group"><label>Documento</label><input type="text" name="student_doc"></div>
      <div class="form-row">
        <div class="form-group"><label>Puntaje obtenido *</label><input type="number" name="score" step="0.01" min="0" required></div>
        <div class="form-group"><label>Puntaje máximo *</label><input type="number" name="max_score" step="0.01" min="0.01" value="100" required></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:4px;">
        <button type="submit" class="btn btn-primary">Registrar</button>
        <button type="button" class="btn btn-secondary"
          onclick="document.getElementById('printed-modal').classList.remove('open')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.modal-bg').forEach(bg => {
  bg.addEventListener('click', e => { if (e.target === bg) bg.classList.remove('open'); });
});

function exportCsv() {
  const rows = [['Nombre','Documento','Modalidad','Fecha','Puntaje','Max','%','MCER','Status']];
  document.querySelectorAll('#results-table tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length < 8) return;
    rows.push([
      cells[0].textContent.trim(),
      cells[1].textContent.trim(),
      cells[2].textContent.trim(),
      cells[3].textContent.trim(),
      cells[4].textContent.trim(),
      '',
      cells[5].textContent.trim(),
      cells[6].textContent.trim(),
      cells[7].textContent.trim(),
    ]);
  });
  const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
  const blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = 'resultados_<?= $examId ?>.csv'; a.click();
  URL.revokeObjectURL(url);
}
</script>
</body>
</html>

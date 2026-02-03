<?php
/* ===============================
   EXTERNAL ACTIVITIES – VIEWER
   =============================== */

$file = __DIR__ . "/../../admin/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>External Activities</title>

<style>
body{
  font-family:Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  color:#2563eb;
  margin-bottom:30px;
  text-align:center;
}

.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:20px;
}

.card{
  background:#fff;
  padding:20px;
  border-radius:14px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  text-align:center;
}

.card h3{
  margin-top:0;
  font-size:18px;
}

.card a{
  display:inline-block;
  margin-top:12px;
  padding:10px 16px;
  border-radius:10px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  font-weight:bold;
  font-size:14px;
}

.empty{
  text-align:center;
  font-size:18px;
  color:#555;
}
</style>
</head>

<body>

<h1>🌐 External Activities</h1>

<?php if (empty($data)): ?>
  <p class="empty">No hay actividades configuradas.</p>
<?php else: ?>

<div class="grid">

<?php foreach ($data as $item): ?>
  <?php if (empty($item['url'])) continue; ?>

  <div class="card">
    <h3><?= htmlspecialchars($item['title'] ?? 'External activity') ?></h3>

    <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank">
      ▶️ Open activity
    </a>
  </div>
<?php endforeach; ?>

</div>

<?php endif; ?>

</body>
</html>

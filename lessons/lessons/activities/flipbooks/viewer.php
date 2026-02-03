<?php
/* ===============================
   FLIPBOOKS – VIEWER (ESTUDIANTE)
   =============================== */

$jsonFile = __DIR__ . "/../../admin/flipbooks.json";
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

$selectedFile = $_GET['file'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Flipbooks</title>

<style>
body{
  font-family: Arial, sans-serif;
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

.viewer{
  margin-top:30px;
}

iframe{
  width:100%;
  height:650px;
  border:1px solid #ccc;
  border-radius:10px;
}
</style>
</head>

<body>

<h1>📘 Flipbooks</h1>

<?php if (empty($data)): ?>
  <p>No hay flipbooks configurados.</p>
<?php else: ?>

<div class="grid">
<?php foreach ($data as $item): ?>
  <?php if (empty($item['file'])) continue; ?>
  <div class="card">
    <h3><?= htmlspecialchars($item['title'] ?? 'Flipbook') ?></h3>
    <a href="?file=<?= urlencode($item['file']) ?>">
      ▶️ Ver flipbook
    </a>
  </div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php if ($selectedFile): ?>
  <div class="viewer">
    <h2>Vista del flipbook</h2>
    <iframe src="../../admin/uploads/<?= htmlspecialchars($selectedFile) ?>"></iframe>
  </div>
<?php endif; ?>

</body>
</html>

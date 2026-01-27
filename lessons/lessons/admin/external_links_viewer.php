<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades</title>
<style>
body{font-family:Arial;background:#f5f7fb}
.card{background:#fff;padding:20px;border-radius:12px;margin:30px}
iframe{width:100%;height:420px;border:none;border-radius:10px}
</style>
</head>

<body>

<?php if (!$data): ?>
<p>No hay actividades configuradas.</p>
<?php endif; ?>

<?php foreach ($data as $a): ?>
<div class="card">
<h2>
<?= $a["type"] === "embed" ? "▶️" : "🔗" ?>
<?= htmlspecialchars($a["title"]) ?>
</h2>

<?php if ($a["type"] === "embed"): ?>
<iframe src="<?= htmlspecialchars($a["url"]) ?>"></iframe>
<?php else: ?>
<p>
<a href="<?= htmlspecialchars($a["url"]) ?>" target="_blank">
Abrir actividad externa
</a>
</p>
<?php endif; ?>

</div>
<?php endforeach; ?>

</body>
</html>

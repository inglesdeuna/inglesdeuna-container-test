<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$role = $_GET["role"] ?? "student"; // student | teacher
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades</title>
<style>
body{
  font-family: Arial, sans-serif;
  background:#f5f7fb;
  margin:0;
  padding:30px;
}
.card{
  background:#fff;
  padding:20px;
  border-radius:14px;
  margin-bottom:30px;
  box-shadow:0 6px 14px rgba(0,0,0,.12);
}
iframe{
  width:100%;
  height:420px;
  border:none;
  border-radius:10px;
}
.btn{
  display:inline-block;
  padding:10px 18px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  border-radius:10px;
  margin-top:10px;
}
.badge{
  display:inline-block;
  padding:4px 10px;
  border-radius:12px;
  font-size:12px;
  background:#eef2ff;
  color:#333;
  margin-left:8px;
}
.actions a{
  margin-right:12px;
  text-decoration:none;
}
</style>
</head>

<body>

<h1>
<?= $role === "teacher" ? "👩‍🏫 Actividades (Docente)" : "👧 Actividades" ?>
</h1>

<?php if (!$data): ?>
<p>No hay actividades configuradas.</p>
<?php endif; ?>

<?php foreach ($data as $i => $a): ?>
<div class="card">

  <h2>
    <?= $a["type"] === "embed" ? "▶️" : "🔗" ?>
    <?= htmlspecialchars($a["title"]) ?>

    <span class="badge">
      <?= $a["type"] === "embed" ? "Integrada" : "Enlace externo" ?>
    </span>
  </h2>

  <?php if ($a["type"] === "embed"): ?>
    <iframe src="<?= htmlspecialchars($a["url"]) ?>"></iframe>
  <?php else: ?>
    <a class="btn" href="<?= htmlspecialchars($a["url"]) ?>" target="_blank">
      Abrir actividad
    </a>
  <?php endif; ?>

  <?php if ($role === "teacher"): ?>
  <div class="actions" style="margin-top:12px">
    <a href="external_links.php?edit=<?= $i ?>">✏️ Editar</a>
    <a href="external_links.php?delete=<?= $i ?>" onclick="return confirm('¿Eliminar actividad?')">🗑 Eliminar</a>
  </div>
  <?php endif; ?>

</div>
<?php endforeach; ?>

</body>
</html>

<?php
// ============================
// CONFIG
// ============================
$file = __DIR__ . "/external_links.json";
$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

$role = $_GET["role"] ?? "student"; // teacher | student

// ============================
// Detectar si es embeddable
// ============================
function isEmbeddable($url) {
  return (
    str_contains($url, "youtube.com/embed") ||
    str_contains($url, "youtu.be") ||
    str_contains($url, "codepen.io") ||
    str_contains($url, "scratch.mit.edu/projects")
  );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f4f7fb;
  padding:30px;
}

.activity{
  background:#fff;
  border-radius:12px;
  padding:20px;
  margin-bottom:30px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

h2{
  margin:0 0 6px;
}

.badge{
  font-size:14px;
  margin-bottom:10px;
}

iframe{
  width:100%;
  height:420px;
  border:none;
  border-radius:10px;
}

.btn{
  display:inline-block;
  margin-top:10px;
  margin-right:10px;
  padding:8px 14px;
  background:#2563eb;
  color:#fff;
  text-decoration:none;
  border-radius:8px;
  font-size:14px;
}

.btn.secondary{
  background:#6b7280;
}

.btn.danger{
  background:#dc2626;
}
.meta{
  font-size:13px;
  color:#555;
  margin-bottom:10px;
}
</style>
</head>

<body>

<?php if (!$data || count($data) === 0): ?>
  <p>No hay actividades configuradas.</p>
<?php else: ?>

<?php foreach ($data as $i => $item): ?>
<?php
  $title = htmlspecialchars($item["title"]);
  $url   = trim($item["url"]);
  $embed = isEmbeddable($url);
?>
<div class="activity">

  <h2><?= $title ?></h2>

  <div class="badge">
    <?= $embed ? "▶️ Integrada" : "🔗 Enlace externo" ?>
  </div>

  <?php if ($role === "teacher"): ?>
    <!-- ================= DOCENTE ================= -->
    <div class="meta">
      <strong>URL:</strong> <?= htmlspecialchars($url) ?>
    </div>

    <a href="external_links_editor.php?edit=<?= $i ?>" class="btn secondary">
      ✏️ Editar
    </a>

    <a href="external_links_editor.php?delete=<?= $i ?>" class="btn danger"
       onclick="return confirm('¿Eliminar esta actividad?')">
      🗑 Eliminar
    </a>

  <?php else: ?>
    <!-- ================= ESTUDIANTE ================= -->
    <?php if ($embed): ?>
      <iframe src="<?= htmlspecialchars($url) ?>"></iframe>
    <?php else: ?>
      <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="btn">
        Abrir actividad
      </a>
    <?php endif; ?>
  <?php endif; ?>

</div>
<?php endforeach; ?>

<?php endif; ?>

</body>
</html>

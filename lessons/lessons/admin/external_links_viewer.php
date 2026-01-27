<?php
// ============================
// Cargar actividades
// ============================
$file = __DIR__ . "/external_links.json";
$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!$data || count($data) === 0) {
  echo "<p style='text-align:center'>Actividad no configurada</p>";
  exit;
}

// ============================
// Detectar si un enlace se puede embeber
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
<title>Actividades Externas</title>

<style>
  body {
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    padding: 30px;
  }

  .activity {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
  }

  h2 {
    margin: 0 0 8px;
    font-size: 20px;
  }

  .badge {
    font-size: 14px;
    margin-bottom: 10px;
  }

  iframe {
    width: 100%;
    height: 420px;
    border: none;
    border-radius: 10px;
    background: #e0e0e0;
  }

  .btn {
    display: inline-block;
    margin-top: 12px;
    padding: 10px 16px;
    background: #2563eb;
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
    font-weight: bold;
  }

  .btn:hover {
    background: #1e40af;
  }
</style>
</head>

<body>

<?php foreach ($data as $item): ?>
  <?php
    $title = htmlspecialchars($item["title"] ?? "");
    $url   = trim($item["url"] ?? "");
    $isEmbed = isEmbeddable($url);
  ?>

  <div class="activity">
    <h2><?= $title ?></h2>

    <div class="badge">
      <?php if ($isEmbed): ?>
        ▶️ Integrada
      <?php else: ?>
        🔗 Enlace externo
      <?php endif; ?>
    </div>

    <?php if ($isEmbed): ?>
      <iframe src="<?= htmlspecialchars($url) ?>"></iframe>
    <?php else: ?>
      <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="btn">
        Abrir actividad
      </a>
    <?php endif; ?>
  </div>

<?php endforeach; ?>

</body>
</html>


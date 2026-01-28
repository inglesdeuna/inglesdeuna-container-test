<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file)
    ? json_decode(file_get_contents($file), true)
    : [];

if (!$data || count($data) === 0) {
    echo "<p style='text-align:center'>No hay actividades configuradas.</p>";
    exit;
}

function isEmbeddable($url) {
    $domains = [
        "youtube.com",
        "youtu.be",
        "wordwall.net",
        "canva.com",
        "codepen.io"
    ];
    foreach ($domains as $d) {
        if (str_contains($url, $d)) return true;
    }
    return false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividades</title>
<style>
body{font-family:Arial;background:#f5f7fb}
.container{max-width:900px;margin:40px auto}
.card{background:#fff;padding:20px;border-radius:12px;margin-bottom:30px}
iframe{width:100%;height:450px;border:none;border-radius:10px}
.title{font-size:20px;font-weight:bold;margin-bottom:10px}
.external a{color:#2563eb;text-decoration:none;font-weight:bold}
.icon{margin-right:6px}
</style>
</head>
<body>

<div class="container">

<?php foreach ($data as $a):

  // Compatibilidad con actividades antiguas
  $type = $a["type"] ?? "link";
  $title = $a["title"] ?? "Actividad";
  $url = $a["url"] ?? "";

?>

<div class="card">
  <div class="title">
    <?php if ($type === "embed"): ?>
      ▶️ <?= htmlspecialchars($a["title"]) ?>
    <?php else: ?>
      🔗 <?= htmlspecialchars($a["title"]) ?>
    <?php endif; ?>
  </div>

  <?php if ($a["type"] === "embed" && isEmbeddable($a["url"])): ?>
    <iframe src="<?= htmlspecialchars($a["url"]) ?>"></iframe>
  <?php else: ?>
    <div class="external">
      <a href="<?= htmlspecialchars($a["url"]) ?>" target="_blank">
        Abrir actividad externa
      </a>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

</div>
</body>
</html>

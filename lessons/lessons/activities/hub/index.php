<?php
echo "<!-- HUB VERSION 4 ACTIVITIES -->";

$activities = [
  [
    "title" => "Multiple Choice",
    "path"  => "../multiple_choice/viewer.php",
    "icon"  => "📝"
  ],
  [
    "title" => "Hangman",
    "path"  => "../../hangman/index.php",
    "icon"  => "🎯"
  ],
  [
    "title" => "External Activities",
    "path"  => "../../admin/external_links_viewer.php",
    "icon"  => "🔗"
  ],
  [
    "title" => "Flipbooks",
    "path"  => "../../admin/flipbook.php",
    "icon"  => "📘"
  ]
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Activities Hub</title>
<style>
body{font-family:Arial;background:#f5f7fb;margin:0}
.container{max-width:900px;margin:40px auto}
.card{
  background:#fff;
  padding:20px;
  border-radius:12px;
  margin-bottom:15px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.card a{
  text-decoration:none;
  color:#2563eb;
  font-weight:bold;
}
.icon{font-size:26px;margin-right:10px}
</style>
</head>
<body>

<div class="container">
<h1>🧩 Activities Hub</h1>

<?php foreach ($activities as $a): ?>
  <div class="card">
    <div>
      <span class="icon"><?= $a["icon"] ?></span>
      <?= htmlspecialchars($a["title"]) ?>
    </div>
    <a href="<?= $a["path"] ?>">Abrir</a>
  </div>
<?php endforeach; ?>

</div>
</body>
</html>

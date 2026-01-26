<?php
$file = __DIR__ . "/external_links.json";

if (!file_exists($file)) {
    $data = null;
} else {
    $json = file_get_contents($file);
    $data = json_decode($json, true);
}

$title = $data['title'] ?? '';
$url   = $data['url'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title ?: 'Actividad Externa'); ?></title>

<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f7fb;
}
.container {
    max-width: 1100px;
    margin: 40px auto;
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,.1);
}
h1 {
    color: #2563eb;
    margin-bottom: 20px;
}
iframe {
    width: 100%;
    height: 600px;
    border: none;
    border-radius: 10px;
    background: #eee;
}
.empty {
    text-align: center;
    color: #999;
    padding: 100px 0;
}
</style>
</head>

<body>
<div class="container">

<?php if (!$url): ?>
    <div class="empty">
        Actividad no configurada
    </div>
<?php else: ?>
    <h1><?php echo htmlspecialchars($title); ?></h1>
    <iframe src="<?php echo htmlspecialchars($url); ?>"></iframe>
<?php endif; ?>

</div>
</body>
</html>

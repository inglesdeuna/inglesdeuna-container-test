<?php
/* ======================
   LOAD DATA
====================== */
$file = __DIR__ . "/external_links.json";
$data = file_exists($file)
    ? json_decode(file_get_contents($file), true)
    : [];

if (!is_array($data) || count($data) === 0) {
    echo "<p style='padding:20px'>No hay actividades configuradas.</p>";
    exit;
}

/* ======================
   HELPERS
====================== */
function isEmbeddable($url) {
    return preg_match(
        '/(youtube\.com|youtu\.be|codepen\.io|canva\.com|genially\.com)/i',
        $url
    );
}

/* ======================
   ROLE (future use)
====================== */
$role = $_GET["role"] ?? "student";
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
    padding:20px;
}

.container{
    max-width:1000px;
    margin:auto;
}

.card{
    background:#fff;
    border-radius:12px;
    padding:20px;
    margin-bottom:25px;
    box-shadow:0 10px 25px rgba(0,0,0,.06);
}

.title{
    font-size:18px;
    font-weight:bold;
    margin-bottom:12px;
    display:flex;
    align-items:center;
    gap:8px;
}

iframe{
    width:100%;
    height:420px;
    border:none;
    border-radius:10px;
}

.external{
    margin-top:10px;
}

.external a{
    display:inline-block;
    background:#2563eb;
    color:#fff;
    text-decoration:none;
    padding:10px 16px;
    border-radius:8px;
    font-size:14px;
}

.external a:hover{
    background:#1e4fd6;
}
</style>
</head>

<body>

<div class="container">

<?php foreach ($data as $a): 

    // 🛡️ Safe defaults (NO WARNINGS)
    $title = $a["title"] ?? "Actividad";
    $url   = $a["url"]   ?? "";
    $type  = $a["type"]  ?? "link";

    if ($url === "") continue;
?>

<div class="card">

    <div class="title">
        <?php if ($type === "embed"): ?>
            ▶️
        <?php else: ?>
            🔗
        <?php endif; ?>

        <?= htmlspecialchars($title) ?>
    </div>

    <?php if ($type === "embed" && isEmbeddable($url)): ?>

        <iframe
            src="<?= htmlspecialchars($url) ?>"
            loading="lazy"
            allowfullscreen>
        </iframe>

    <?php else: ?>

        <div class="external">
            <a href="<?= htmlspecialchars($url) ?>" target="_blank">
                Abrir actividad externa
            </a>
        </div>

    <?php endif; ?>

</div>

<?php endforeach; ?>

</div>

</body>
</html>

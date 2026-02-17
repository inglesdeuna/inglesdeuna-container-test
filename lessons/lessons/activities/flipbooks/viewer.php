<?php
session_start();

require_once __DIR__ . "/../../core/db.php";

/* ===========================
   VALIDAR UNIT
=========================== */
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ===========================
   OBTENER DESDE DB
=========================== */
$stmt = $pdo->prepare("
    SELECT data 
    FROM activities 
    WHERE unit_id = :unit 
    AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute(['unit' => $unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$pdfPath = "";

if ($row) {
    $content = json_decode($row['data'], true);
    $pdfPath = $content['pdf'] ?? "";
}

/* ===========================
   VERIFICAR ARCHIVO FISICO
=========================== */
$absoluteFilePath = __DIR__ . "/../../" . $pdfPath;

$fileExists = $pdfPath && file_exists($absoluteFilePath);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flipbooks</title>

<style>
body{
    margin:0;
    background:#eef6ff;
    font-family:Arial;
}

.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    padding:8px 14px;
    border:none;
    border-radius:10px;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

.viewer-container{
    max-width:1100px;
    margin:100px auto 40px auto;
    background:white;
    padding:30px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    text-align:center;
}

h1{
    color:#0b5ed7;
    margin-bottom:10px;
}

.subtitle{
    color:#6b7280;
    margin-bottom:25px;
}

.pdf-frame{
    width:100%;
    height:650px;
    border:none;
    border-radius:12px;
}
</style>
</head>

<body>

<button 
class="back-btn"
onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

<div class="viewer-container">

<h1>📖 Flipbooks</h1>
<div class="subtitle">Let's read together and explore a new story.</div>

<?php if($fileExists): ?>

    <iframe 
        src="/lessons/lessons/<?= htmlspecialchars($pdfPath) ?>" 
        class="pdf-frame">
    </iframe>

<?php else: ?>

    <p style="color:#ef4444; font-weight:bold;">
        No PDF uploaded for this unit.
    </p>

<?php endif; ?>

</div>

</body>
</html>

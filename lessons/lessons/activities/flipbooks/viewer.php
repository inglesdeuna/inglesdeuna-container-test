<?php
session_start();

require_once __DIR__ . "/../../core/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ===== CARGAR DESDE DB ===== */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute([":unit" => $unit]);
$row = $stmt->fetchColumn();

$pdfPath = "";

if ($row) {
    $decoded = json_decode($row, true);
    $pdfPath = $decoded["pdf"] ?? "";
}
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
    max-width:1000px;
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
    color:#666;
    margin-bottom:30px;
}

.pdf-frame{
    width:100%;
    height:600px;
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
<p class="subtitle">Let's read together and explore a new story.</p>

<?php if ($pdfPath): ?>
    <iframe 
        src="/<?= htmlspecialchars($pdfPath) ?>" 
        class="pdf-frame">
    </iframe>
<?php else: ?>
    <p>No flipbook uploaded yet.</p>
<?php endif; ?>

</div>

</body>
</html>

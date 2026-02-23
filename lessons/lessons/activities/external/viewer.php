<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unit not specified");

$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'external'
");

$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "{}", true);

$url = $data["url"] ?? null;

if (!$url) die("No URL configured");
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>External Resource</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef6ff;
    padding:40px 20px;
    text-align:center;
}

/* Title unified */
.title{
    color:#0b5ed7;
    font-size:28px;
    font-weight:bold;
    margin-bottom:8px;
}

/* Container */
.box{
    background:white;
    padding:30px;
    border-radius:18px;
    max-width:600px;
    margin:20px auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}

/* Primary button */
.primary-btn{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:12px 26px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    transition:0.2s ease;
}

.primary-btn:hover{
    background:#084298;
}

/* Back button - same as Drag & Drop */
.back-btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:12px 28px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    min-width:120px;
    transition:0.2s ease;
}

.back-btn:hover{
    background:#15803d;
}
</style>
</head>

<body>

<h1 class="title">🌐 External Resource</h1>

<div class="box">

<p>Click the button below to open the resource.</p>

<br>

<button class="primary-btn" onclick="openExternal()">
🔗 Open Resource
</button>

<br><br>

<button 
class="back-btn"
onclick="window.location.href='../../academic/unit_view.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

</div>

<script>
function openExternal(){
    window.open("<?= htmlspecialchars($url) ?>", "_blank");
}
</script>

</body>
</html>

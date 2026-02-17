<?php
if (!isset($unit)) {
    die("Unit not specified");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $activityTitle ?? 'Editor' ?></title>
<link rel="stylesheet" href="../../assets/css/ui.css">

<style>
body{
    margin:0;
    font-family: Arial, Helvetica, sans-serif;
    background:#c7d2fe;
    min-height:100vh;
}

.back-btn{
    position:absolute;
    top:30px;
    left:30px;
    background:#16a34a;
    color:white;
    padding:10px 18px;
    border-radius:10px;
    font-weight:600;
    text-decoration:none;
    box-shadow:0 4px 10px rgba(0,0,0,0.1);
    transition:0.2s;
}

.back-btn:hover{
    background:#15803d;
}

.editor-header{
    text-align:center;
    margin-top:80px;
    margin-bottom:30px;
}

.editor-title{
    font-size:28px;
    font-weight:700;
    color:#1d4ed8;
    margin-bottom:8px;
}

.editor-subtitle{
    font-size:14px;
    color:#475569;
}

.editor-container{
    max-width:900px;
    margin:0 auto 60px auto;
    padding:25px;
    background:white;
    border-radius:18px;
    box-shadow:0 8px 25px rgba(0,0,0,0.08);
}
</style>
</head>

<body>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>" class="back-btn">
    ↩ Back
</a>

<div class="editor-header">
    <div class="editor-title">
        <?= $activityTitle ?? 'Activity Editor' ?>
    </div>

    <?php if(!empty($activitySubtitle)): ?>
        <div class="editor-subtitle">
            <?= $activitySubtitle ?>
        </div>
    <?php endif; ?>
</div>

<div class="editor-container">
    <?= $editorContent ?? '' ?>
</div>

</body>
</html>

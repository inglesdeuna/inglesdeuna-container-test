<?php

function render_activity_editor($title, $icon, $content) {
    $unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
    $assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';
    $source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

    if ($assignment !== '') {
        $backUrl = '../../academic/teacher_unit.php?assignment=' . urlencode($assignment) . '&unit=' . urlencode($unit);
    } else {
        $backUrl = '../../academic/unit_view.php?unit=' . urlencode($unit);
        if ($source !== '') {
            $backUrl .= '&source=' . urlencode($source);
        }
    }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>

<style>
body{
    margin:0;
    background:#eef6ff;
    font-family:Arial, sans-serif;
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
    text-decoration:none;
    display:inline-block;
}

.editor-container{
    max-width:900px;
    margin:100px auto 40px auto;
    background:white;
    padding:30px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    text-align:center;
}

h1{
    color:#0b5ed7;
    margin-bottom:25px;
}

input[type="file"]{
    margin:20px 0;
}

button.save-btn{
    padding:10px 20px;
    background:#0b5ed7;
    border:none;
    border-radius:8px;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

.saved-row{
    margin-top:25px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:15px;
    font-size:14px;
}

.file-name{
    background:#f3f4f6;
    padding:8px 12px;
    border-radius:8px;
}

.delete-btn{
    background:#ef4444;
    border:none;
    color:white;
    border-radius:50%;
    width:28px;
    height:28px;
    cursor:pointer;
    font-weight:bold;
}

.delete-btn:hover{
    background:#dc2626;
}
</style>
</head>

<body>

<a class="back-btn" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">↩ Back</a>

<div class="editor-container">
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <?= $content ?>
</div>

</body>
</html>
<?php
}
?>

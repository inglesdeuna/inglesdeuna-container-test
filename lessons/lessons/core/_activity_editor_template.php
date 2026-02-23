<?php

function render_activity_editor($title, $icon, $content) {
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>

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

<?php
$unit = $_GET['unit'] ?? '';
?>

<button 
class="back-btn"
onclick="window.location.href='../../academic/unit_view.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

<div class="editor-container">

<h1><?= htmlspecialchars($title) ?></h1>

<?= $content ?>

</div>

</body>
</html>
<?php
}
?>

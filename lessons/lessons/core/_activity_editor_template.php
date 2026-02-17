<?php
if (!isset($unit)) {
    $unit = $_GET['unit'] ?? null;
}

if (!$unit) {
    die("Unit not specified");
}
?>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef6ff;
    margin:0;
    padding:40px 20px;
}

/* BACK BUTTON (EXACTO IGUAL EN TODOS) */
.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    color:#fff;
    padding:10px 18px;
    border-radius:12px;
    text-decoration:none;
    font-weight:bold;
    font-size:14px;
}

/* CENTER BOX */
.editor-wrapper{
    max-width:720px;
    margin:0 auto;
}

.editor-card{
    background:#fff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 8px 20px rgba(0,0,0,0.08);
}

.editor-card h1{
    text-align:center;
    color:#0b5ed7;
    margin-bottom:5px;
}

.editor-subtitle{
    text-align:center;
    color:#555;
    font-size:14px;
    margin-bottom:25px;
}

/* BUTTONS */
.btn{
    padding:10px 18px;
    border:none;
    border-radius:12px;
    background:#0b5ed7;
    color:white;
    cursor:pointer;
    font-weight:bold;
    margin-right:8px;
}

.btn-green{
    background:#16a34a;
}

.saved-list{
    margin-top:25px;
}

.saved-item{
    background:#f1f5f9;
    padding:12px 15px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.delete-btn{
    color:red;
    font-weight:bold;
    text-decoration:none;
    font-size:16px;
}
</style>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>" class="back-btn">
    ↩ Back
</a>

<div class="editor-wrapper">
    <div class="editor-card">

        <h1><?= $pageTitle ?? "Activity Editor" ?></h1>
        <div class="editor-subtitle">
            <?= $pageSubtitle ?? "" ?>
        </div>

        <?= $editorContent ?? "" ?>

    </div>
</div>

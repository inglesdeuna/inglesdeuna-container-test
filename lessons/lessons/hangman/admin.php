<?php
session_start();

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../../admin/login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>

<link rel="stylesheet" href="../../assets/css/editor.css">

<style>
.editor-wrapper{
    max-width:1100px;
    margin:auto;
    padding:30px;
}

.top-bar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}

.back-btn{
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    color:#0d6efd;
}

.editor-title{
    font-size:26px;
    font-weight:700;
    color:#1f2937;
    margin-bottom:5px;
}

.editor-subtitle{
    font-size:14px;
    color:#6b7280;
    margin-bottom:25px;
}

.save-btn{
    padding:10px 20px;
    border-radius:8px;
    border:none;
    font-weight:600;
    background:#16a34a;
    color:white;
    cursor:pointer;
}
</style>
</head>

<body>

<div class="editor-wrapper">

<div class="top-bar">
    <a href="../activities.php" class="back-btn">← Back</a>
</div>

<div class="editor-title">
    Hangman Editor
</div>

<div class="editor-subtitle">
    Manage words and audio for this activity.
</div>
<button class="save-btn">Save Changes</button>

</div>
</body>
</html>

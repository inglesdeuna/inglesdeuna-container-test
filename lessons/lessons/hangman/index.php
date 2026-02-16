<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<link rel="stylesheet" href="../../assets/css/editor.css">

<style>
.page-wrapper{
    max-width:1100px;
    margin:auto;
    padding:30px;
}

.top-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
}

.back-btn{
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    color:#0d6efd;
}

.activity-title{
    font-size:26px;
    font-weight:700;
    color:#1f2937;
    margin-bottom:5px;
}

.activity-subtitle{
    font-size:14px;
    color:#6b7280;
    margin-bottom:25px;
}

.nav-buttons{
    display:flex;
    justify-content:space-between;
    margin-top:30px;
}

.nav-btn{
    padding:10px 20px;
    border-radius:8px;
    border:none;
    font-weight:600;
    cursor:pointer;
    background:#0d6efd;
    color:white;
}
</style>
</head>

<body>

<div class="page-wrapper">

<div class="top-bar">
    <a href="../activities.php" class="back-btn">← Back</a>
</div>

<div class="activity-title">
    Hangman
</div>

<div class="activity-subtitle">
    Listen and guess the correct word.
</div>
   <div class="nav-buttons">
    <button class="nav-btn">← Previous</button>
    <button class="nav-btn">Next →</button>
</div>

</div>
</body>
</html>


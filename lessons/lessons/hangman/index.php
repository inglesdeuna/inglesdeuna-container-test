<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hangman</title>

<link rel="stylesheet" href="../../assets/css/editor.css">

<style>

/* ====== GLOBAL WRAPPER ====== */
.activity-container{
    max-width:1100px;
    margin:0 auto;
    padding:30px;
}

/* ====== TOP BAR ====== */
.activity-header{
    margin-bottom:25px;
}

.back-button{
    display:inline-block;
    margin-bottom:15px;
    font-size:14px;
    font-weight:600;
    color:#2563eb;
    text-decoration:none;
}

.back-button:hover{
    text-decoration:underline;
}

/* ====== TITLES ====== */
.activity-title{
    font-size:28px;
    font-weight:700;
    color:#1f2937;
    margin-bottom:6px;
}

.activity-subtitle{
    font-size:14px;
    color:#6b7280;
}

/* ====== GAME AREA ====== */
.game-wrapper{
    margin-top:30px;
    text-align:center;
}

/* ====== NAVIGATION ====== */
.navigation-buttons{
    display:flex;
    justify-content:space-between;
    margin-top:40px;
}

.nav-btn{
    padding:10px 22px;
    border-radius:8px;
    border:none;
    background:#2563eb;
    color:white;
    font-weight:600;
    cursor:pointer;
    transition:0.2s;
}

.nav-btn:hover{
    background:#1e40af;
}

</style>
</head>

<body>

<div class="activity-container">

    <!-- HEADER -->
    <div class="activity-header">
        <a href="../activities.php" class="back-button">← Back</a>
        <div class="activity-title">Hangman</div>
        <div class="activity-subtitle">
            Listen and guess the correct word.
        </div>
    </div>


    <!-- GAME CONTENT (NO TOCAR LOGICA) -->
    <div class="game-wrapper">

        <?php include "hangman.php"; ?>
        <!-- Si tu juego está en index original sin include,
             simplemente pega aquí el HTML original del juego -->

    </div>


    <!-- NAVIGATION -->
    <div class="navigation-buttons">
        <button class="nav-btn">← Previous</button>
        <button class="nav-btn">Next →</button>
    </div>

</div>

</body>
</html>

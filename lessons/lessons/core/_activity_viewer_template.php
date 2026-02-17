<?php
if (!isset($unit)) {
    die("Unit not specified");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $activityTitle ?? 'Activity' ?></title>
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

.activity-header{
    text-align:center;
    margin-top:80px;
    margin-bottom:30px;
}

.activity-title{
    font-size:32px;
    font-weight:700;
    color:#1d4ed8;
    margin-bottom:8px;
}

.activity-subtitle{
    font-size:15px;
    color:#475569;
}

.activity-container{
    max-width:1200px;
    margin:0 auto 60px auto;
    padding:20px;
}

.feedback{
    margin-top:15px;
    font-weight:600;
    font-size:14px;
}

.feedback.correct{ color:#16a34a; }
.feedback.wrong{ color:#dc2626; }
.feedback.completed{ color:#9333ea; }
</style>
</head>

<body>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>" class="back-btn">
    ↩ Back
</a>

<div class="activity-header">
    <div class="activity-title">
        <?= $activityTitle ?? 'Activity' ?>
    </div>

    <?php if(!empty($activitySubtitle)): ?>
        <div class="activity-subtitle">
            <?= $activitySubtitle ?>
        </div>
    <?php endif; ?>
</div>

<div class="activity-container">
    <?= $activityContent ?? '' ?>
    <div id="feedback-message" class="feedback"></div>
</div>

<script>
function showCorrect(){
    const el = document.getElementById("feedback-message");
    el.className = "feedback correct";
    el.innerHTML = "⭐ Correct!";
}

function showTryAgain(){
    const el = document.getElementById("feedback-message");
    el.className = "feedback wrong";
    el.innerHTML = "❌ Try again";
}

function showCompleted(){
    const el = document.getElementById("feedback-message");
    el.className = "feedback completed";
    el.innerHTML = "🎉 Completed!";
}
</script>

</body>
</html>

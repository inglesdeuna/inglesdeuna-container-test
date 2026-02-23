<?php

function render_activity_viewer($title, $icon, $content)
{
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        body{
            margin:0;
            font-family: Arial, sans-serif;
            background:#cfd8e6;
            padding:20px;
        }

        h1{
            text-align:center;
            color:#1d4ed8;
            margin-bottom:30px;
        }

        .activity-wrapper{
            max-width:1200px;
            margin:0 auto;
        }

        .back-btn{
            display:inline-block;
            background:#16a34a;
            color:white;
            padding:10px 18px;
            border-radius:12px;
            text-decoration:none;
            margin-bottom:20px;
        }
    </style>
</head>

<body>

<div class="activity-wrapper">

  <a href="javascript:history.back()" class="back-btn">↩ Back</a>

    <h1><?= $icon ?> <?= htmlspecialchars($title) ?></h1>

    <?= $content ?>

</div>

</body>
</html>
<?php
}

<?php

function render_activity_viewer($title, $icon, $content)
{
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
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
            font-weight:bold;
        }
    </style>
</head>

<body>

<div class="activity-wrapper">

    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">↩ Back</a>

    <h1><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>

    <?= $content ?>

</div>

</body>
</html>
<?php
}

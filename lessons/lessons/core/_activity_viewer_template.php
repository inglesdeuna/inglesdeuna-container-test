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
            padding:10px 20px 14px;
        }

        .activity-wrapper{
            max-width:1280px;
            margin:0 auto;
        }

        .top-row{
            display:flex;
            align-items:center;
            justify-content:flex-start;
            min-height:36px;
            margin-bottom:2px;
        }

        .back-btn{
            display:inline-block;
            background:#16a34a;
            color:white;
            padding:8px 14px;
            border-radius:10px;
            text-decoration:none;
            font-weight:bold;
            font-size:14px;
            line-height:1;
        }

        .viewer-header{
            text-align:center;
            margin:0 0 2px 0;
        }

        h1{
            margin:0;
            color:#1d4ed8;
            font-size:22px;
            line-height:1.12;
            font-weight:700;
        }

        .viewer-content{
            margin-top:0;
        }

        @media (max-width: 900px){
            body{
                padding:10px 12px 14px;
            }

            .top-row{
                margin-bottom:2px;
                min-height:34px;
            }

            .viewer-header{
                margin:0 0 2px 0;
            }

            h1{
                font-size:20px;
            }

            .back-btn{
                padding:8px 12px;
                font-size:13px;
            }
        }

        @media (max-width: 640px){
            h1{
                font-size:18px;
            }

            .top-row{
                min-height:32px;
            }
        }
    </style>
</head>

<body>

<div class="activity-wrapper">

    <div class="top-row">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">↩ Back</a>
    </div>

    <div class="viewer-header">
        <h1><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    </div>

    <div class="viewer-content">
        <?= $content ?>
    </div>

</div>

</body>
</html>
<?php
}

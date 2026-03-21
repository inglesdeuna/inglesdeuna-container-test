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
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        body {
            margin: 0;
            background: #eef4fb;
            font-family: Arial, sans-serif;
        }

        .editor-shell {
            min-height: 100vh;
            padding: 24px;
        }

        .editor-topbar {
            max-width: 1100px;
            margin: 0 auto 20px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #16a34a;
            color: #fff;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 600;
        }

        .back-btn:hover {
            background: #15803d;
            color: #fff;
        }

        .editor-card {
            max-width: 1100px;
            margin: 0 auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 10px 35px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .editor-header {
            padding: 24px 28px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .editor-header h1 {
            margin: 0;
            font-size: 1.7rem;
            color: #0f172a;
            font-weight: 700;
        }

        .editor-header .subtitle {
            margin-top: 6px;
            color: #64748b;
            font-size: 0.95rem;
        }

        .editor-body {
            padding: 28px;
        }
    </style>
</head>
<body>

<div class="editor-shell">
    <div class="editor-topbar">
        <a class="back-btn" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-arrow-left"></i>
            Volver
        </a>
    </div>

    <div class="editor-card">
        <div class="editor-header">
            <h1>
                <?php if ($icon): ?>
                    <i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> me-2"></i>
                <?php endif; ?>
                <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <div class="subtitle">Editor de actividad</div>
        </div>

        <div class="editor-body">
            <?= $content ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
<?php
}
?>

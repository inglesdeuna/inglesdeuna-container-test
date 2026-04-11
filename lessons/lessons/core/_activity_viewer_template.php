<?php

function render_activity_viewer($title, $icon, $content)
{
    $unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
    $assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';
    $source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
    $embedded = isset($_GET['embedded']) && (string) $_GET['embedded'] === '1';
    
    // Presentation mode: triggered by 'next' parameter (from teacher_presentation.php)
    $nextUrl = isset($_GET['next']) ? trim((string) $_GET['next']) : '';
    $isPresentationMode = $nextUrl !== '';

    // Use return_to as the back URL when it's a safe relative path
    // (prevents open-redirect: must not start with // or contain a scheme)
    $returnToParam = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
    $isSafeRelative = $returnToParam !== ''
        && !preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $returnToParam)
        && !str_starts_with($returnToParam, '//');

    if ($isSafeRelative) {
        $backUrl = $returnToParam;
    } elseif ($assignment !== '') {
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
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

        :root{
            --viewer-bg-a:#dff5ff;
            --viewer-bg-b:#fff4db;
            --viewer-bg-c:#f8d9e6;
            --viewer-paper:#fffdf9;
            --viewer-text:#1f2937;
            --viewer-muted:#475569;
            --viewer-accent:#0f766e;
            --viewer-accent-2:#14b8a6;
            --viewer-success:#16a34a;
            --viewer-shadow:0 18px 40px rgba(15, 23, 42, .12);
        }

        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            min-height:100vh;
            font-family:'Nunito', 'Segoe UI', sans-serif;
            color:var(--viewer-text);
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, .72), rgba(255, 255, 255, 0) 28%),
                radial-gradient(circle at top right, rgba(255, 255, 255, .6), rgba(255, 255, 255, 0) 24%),
                linear-gradient(135deg, var(--viewer-bg-a) 0%, var(--viewer-bg-b) 48%, var(--viewer-bg-c) 100%);
            padding:18px 22px 24px;
        }

        /* ─────────────────────────────────────────────── */
        /* PRESENTATION MODE – fullscreen, zero padding, no scroll */
        /* ─────────────────────────────────────────────── */
        body.presentation-mode {
            margin: 0;
            padding: 0;
            background: #000;
            overflow: hidden;
        }

        body.presentation-mode .activity-wrapper {
            max-width: 100%;
            height: 100vh;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        body.presentation-mode .top-row {
            display: none;
        }

        body.presentation-mode .viewer-content {
            flex: 1;
            border-radius: 0;
            padding: 0;
            margin: 0;
            box-shadow: none;
            border: none;
            background: #fff;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        body.presentation-mode .viewer-content > :is(div, section) {
            max-width: 100%;
            width: 100%;
        }

        body.presentation-mode .viewer-content :is(.mc-viewer, .dd-stage, .lo-stage, .vc-viewer, .dict-stage, .pron-stage, .match-stage, .flashcards-wrap, .qz-wrap, .flipbook-viewer, .ppt-viewer-shell, .ex-viewer, .wp-viewer-wrap) {
            max-width: 100% !important;
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
        }

        body.presentation-mode .viewer-content :is(.mc-intro, .dd-intro, .lo-intro, .vc-intro, .dict-intro, .pron-intro, .match-intro, .flashcards-intro, .flipbook-intro, .ex-intro, .ppt-intro) {
            margin: 0 !important;
            padding: 12px 16px !important;
            flex-shrink: 0 !important;
            background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%) !important;
            border-bottom: 1px solid #e5e7eb !important;
        }

        body.presentation-mode .viewer-content :is(.mc-intro h2, .dd-intro h2, .lo-intro h2, .vc-intro h2, .dict-intro h2, .pron-intro h2, .match-intro h2, .flashcards-intro h2, .flipbook-intro h2, .ex-intro h2, .ppt-intro h2) {
            font-size: clamp(28px, 2.5vw, 42px) !important;
            line-height: 1.1 !important;
            margin: 0 !important;
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif !important;
            font-weight: 700 !important;
            text-align: center !important;
            color: #5b21b6 !important;
        }

        body.presentation-mode .viewer-content :is(.ppt-slide-title) {
            font-size: clamp(28px, 2.5vw, 42px) !important;
            line-height: 1.1 !important;
            text-align: center !important;
            color: #5b21b6 !important;
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif !important;
            font-weight: 700 !important;
        }

        body.presentation-mode .viewer-content :is(.mc-card, #sentenceBox, .vc-panel, .flipbook-viewer__card) {
            padding: 0 !important;
            flex: 1 !important;
            overflow-y: auto !important;
            background: #fff !important;
        }

        body.presentation-mode .viewer-content :is(.mc-question, #promptText, .lo-prompt, .vc-question) {
            font-size: clamp(24px, 2.5vw, 40px) !important;
            line-height: 1.2 !important;
            margin: 0 !important;
            padding: 16px !important;
            text-align: center !important;
        }

        body.presentation-mode .viewer-content :is(.mc-options, .vc-options) {
            max-height: none !important;
            flex: 1 !important;
            overflow-y: auto !important;
            padding: 12px 16px !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
        }

        body.presentation-mode .viewer-content :is(.mc-option, .vc-option) {
            min-height: 60px !important;
            font-size: clamp(18px, 2vw, 28px) !important;
            padding: 14px 16px !important;
            margin-bottom: 10px !important;
        }

        body.presentation-mode .viewer-content :is(.mc-controls, .controls, .lo-controls, .vc-controls, .qz-actions, .ex-actions, .flipbook-toolbar__right) {
            gap: 12px !important;
            flex-shrink: 0 !important;
            padding: 12px 16px !important;
            background: #f8fbff !important;
            border-top: 1px solid #e5e7eb !important;
            display: flex !important;
            justify-content: center !important;
            flex-wrap: wrap !important;
        }

        body.presentation-mode .viewer-content :is(.mc-btn, .dd-btn, .lo-btn, .vc-btn, .action-btn, .qz-btn, .ppt-btn, .ex-btn, .flash-btn, .flipbook-btn, .dict-stage .btn, .pron-stage .btn) {
            padding: 14px 20px !important;
            min-width: 160px !important;
            font-size: 16px !important;
            font-weight: 800 !important;
            border-radius: 10px !important;
            flex-grow: 1 !important;
            max-width: 200px !important;
        }

        body.presentation-mode .pres-next-button {
            background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%) !important;
            color: #fff !important;
            border: none !important;
            padding: 14px 24px !important;
            font-size: 16px !important;
            font-weight: 800 !important;
            border-radius: 10px !important;
            cursor: pointer !important;
            min-width: 180px !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
        }

        body.presentation-mode .pres-next-button:hover {
            filter: brightness(1.06) !important;
            transform: translateY(-1px) !important;
        }

        /* ─────────────────────────────────────────────── */
        /* Normal mode (non-presentation) */
        /* ─────────────────────────────────────────────── */
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
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:linear-gradient(180deg, #3d73ee 0%, #2563eb 100%);
            color:#ffffff;
            padding:10px 14px;
            border-radius:10px;
            text-decoration:none;
            font-weight:700;
            font-size:13px;
            font-family:'Nunito', 'Segoe UI', sans-serif;
            line-height:1;
            box-shadow:0 10px 22px rgba(37, 99, 235, .28);
            transition:transform .18s ease, filter .18s ease;
        }

        .back-btn:hover{
            filter:brightness(1.07);
            transform:translateY(-1px);
        }

        .viewer-header{
            text-align:center;
            margin:0 0 10px 0;
        }

        h1{
            margin:0;
            font-family:'Fredoka', 'Trebuchet MS', sans-serif;
            color:#0f172a;
            font-size:clamp(24px, 2vw, 34px);
            line-height:1.12;
            font-weight:700;
            letter-spacing:.02em;
        }

        .viewer-content{
            margin-top:0;
            background:rgba(255, 253, 249, .52);
            border:1px solid rgba(255, 255, 255, .6);
            border-radius:30px;
            padding:18px;
            box-shadow:var(--viewer-shadow);
            backdrop-filter:blur(10px);
        }

        /* Global size normalization for activity viewers */
        .viewer-content > :is(div, section){
            max-width:980px;
            margin-left:auto;
            margin-right:auto;
        }

        .viewer-content :is(.mc-viewer, .dd-stage, .lo-stage, .vc-viewer, .dict-stage, .pron-stage, .match-stage, .flashcards-wrap, .qz-wrap, .flipbook-viewer, .ppt-viewer-shell, .ex-viewer){
            max-width:980px !important;
            margin-left:auto !important;
            margin-right:auto !important;
        }

        .viewer-content :is(.mc-intro, .dd-intro, .lo-intro, .vc-intro, .dict-intro, .pron-intro, .match-intro, .flashcards-intro, .flipbook-intro, .ex-intro, .ppt-intro){
            margin-bottom:12px !important;
            padding:16px 18px !important;
        }

        .viewer-content :is(.mc-intro h2, .dd-intro h2, .lo-intro h2, .vc-intro h2, .dict-intro h2, .pron-intro h2, .match-intro h2, .flashcards-intro h2, .flipbook-intro h2, .ex-intro h2, .ppt-intro h2){
            font-size:clamp(26px, 2.2vw, 30px) !important;
            line-height:1.1 !important;
            margin:0 0 8px !important;
            font-family:'Fredoka', 'Trebuchet MS', sans-serif !important;
            font-weight:700 !important;
            text-align:center !important;
            color:#5b21b6 !important;
        }

        .viewer-content :is(.ppt-slide-title){
            font-size:clamp(26px, 2.2vw, 30px) !important;
            line-height:1.1 !important;
            text-align:center !important;
            color:#5b21b6 !important;
            font-family:'Fredoka', 'Trebuchet MS', sans-serif !important;
            font-weight:700 !important;
        }

        .viewer-content :is(.mc-card, #sentenceBox, .vc-panel, .flipbook-viewer__card){
            padding:14px !important;
        }

        .viewer-content :is(.mc-question, #promptText, .lo-prompt, .vc-question){
            font-size:clamp(20px, 2.1vw, 30px) !important;
            line-height:1.12 !important;
            margin-bottom:10px !important;
        }

        .viewer-content :is(.mc-controls, .controls, .lo-controls, .vc-controls, .qz-actions, .ex-actions, .flipbook-toolbar__right){
            gap:10px !important;
        }

        .viewer-content :is(.mc-btn, .dd-btn, .lo-btn, .vc-btn, .action-btn, .qz-btn, .ppt-btn, .ex-btn, .flash-btn, .flipbook-btn, .dict-stage .btn, .pron-stage .btn){
            padding:11px 18px !important;
            min-width:142px !important;
            font-size:14px !important;
            font-weight:800 !important;
            border-radius:999px !important;
        }

        @media (max-width: 768px){
            .viewer-content :is(.mc-btn, .dd-btn, .lo-btn, .vc-btn, .action-btn, .qz-btn, .ppt-btn, .ex-btn, .flash-btn, .flipbook-btn, .dict-stage .btn, .pron-stage .btn){
                width:100% !important;
                max-width:300px !important;
                min-width:0 !important;
            }

            .viewer-content :is(.mc-controls, .controls, .lo-controls, .vc-controls, .qz-actions, .ex-actions, .flipbook-toolbar__right){
                justify-content:center !important;
                flex-wrap:wrap !important;
            }
        }

        @media (max-height: 900px) and (min-width: 769px){
            .viewer-content :is(.mc-intro, .dd-intro, .lo-intro, .vc-intro, .dict-intro, .pron-intro, .match-intro, .flashcards-intro, .flipbook-intro, .ex-intro, .ppt-intro){
                margin-bottom:10px !important;
                padding:14px 16px !important;
            }

            .viewer-content :is(.mc-intro h2, .dd-intro h2, .lo-intro h2, .vc-intro h2, .dict-intro h2, .pron-intro h2, .match-intro h2, .flashcards-intro h2, .flipbook-intro h2, .ex-intro h2, .ppt-intro h2, .ppt-slide-title){
                font-size:clamp(22px, 1.9vw, 26px) !important;
            }

            .viewer-content :is(.mc-question, #promptText, .lo-prompt, .vc-question){
                font-size:clamp(18px, 1.8vw, 26px) !important;
            }

            .viewer-content :is(.mc-btn, .dd-btn, .lo-btn, .vc-btn, .action-btn, .qz-btn, .ppt-btn, .ex-btn, .flash-btn, .flipbook-btn, .dict-stage .btn, .pron-stage .btn){
                padding:10px 16px !important;
                min-width:132px !important;
                font-size:13px !important;
            }

            .viewer-content :is(.mc-options, .vc-options){
                max-height:180px;
                overflow-y:auto;
            }
        }

        @media (max-width: 900px){
            body{
                padding:12px 12px 18px;
            }

            .top-row{
                margin-bottom:2px;
                min-height:34px;
            }

            .viewer-header{
                margin:0 0 8px 0;
            }

            h1{
                font-size:22px;
            }

            .back-btn{
                padding:9px 13px;
                font-size:13px;
            }

            .viewer-content{
                border-radius:24px;
                padding:14px;
            }
        }

        @media (max-width: 640px){
            h1{
                font-size:20px;
            }

            .top-row{
                min-height:32px;
            }

            .viewer-content{
                border-radius:20px;
                padding:12px;
            }
        }
    </style>
</head>

<body<?= $isPresentationMode ? ' class="presentation-mode"' : '' ?>>

<div class="activity-wrapper">

    <?php if (!$embedded && !$isPresentationMode) { ?>
    <div class="top-row">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">↩ Back</a>
    </div>
    <?php } ?>

    <div class="viewer-content">
        <?= $content ?>
        
        <?php if ($isPresentationMode && $nextUrl !== '') { ?>
        <div style="flex-shrink: 0; padding: 12px 16px; background: #f8fbff; border-top: 1px solid #e5e7eb; display: flex; justify-content: center; align-items: center; gap: 12px;">
            <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" class="pres-next-button">
                <span>siguiente actividad</span>
                <span style="font-size: 18px;">▶</span>
            </a>
        </div>
        <?php } ?>
    </div>

</div>

<script>
// Presentation mode configuration
window.PRESENTATION_MODE = <?= json_encode($isPresentationMode) ?>;
window.PRESENTATION_NEXT_URL = <?= json_encode($nextUrl) ?>;

// Prevent automatic scroll to top in presentation mode
if (window.PRESENTATION_MODE) {
    // Override window.scrollTo during presentation
    var originalScrollTo = window.scrollTo;
    window.scrollTo = function() {
        // Allow explicit scrollTo calls but with no animation
        if (arguments.length > 0 && typeof arguments[0] === 'object' && arguments[0].behavior === 'smooth') {
            return; // Silently ignore smooth scrolls in presentation mode
        }
        originalScrollTo.apply(window, arguments);
    };
}
</script>

</body>
</html>
<?php
}

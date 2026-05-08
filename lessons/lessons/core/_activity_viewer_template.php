<?php

/**
 * Renders the standardised activity header (title + optional instructions).
 * CSS is injected via the shared <style> block below.
 */
function render_activity_header(string $title, string $instructions = ''): string
{
    $title        = trim($title);
    $instructions = trim($instructions);
    if ($title === '') {
        return '';
    }
    $out  = '<div class="act-header">';
    $out .= '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
    if ($instructions !== '') {
        $out .= '<p>' . nl2br(htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    $out .= '</div>';
    return $out;
}

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

    <link rel="stylesheet" href="../../assets/css/presentation-mode.css">
    <link rel="stylesheet" href="../../assets/css/video-two-col.css">
    <link rel="stylesheet" href="../../assets/css/activity-design-system.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

        :root{
            --viewer-bg: #f0faf6;
            --viewer-paper: #ffffff;
            --viewer-text: #1f2937;
            --viewer-muted: #5b6577;
            --viewer-accent: #7F77DD;
            --viewer-accent-2: #2f5bb5;
            --viewer-success: #16a34a;
            --viewer-shadow: 0 8px 24px rgba(0,0,0,.08);
        }

        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            min-height:100vh;
            font-family: Arial, sans-serif;
            color:var(--viewer-text);
            background: #f0faf6;
            padding: 30px;
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
            background: #f0faf6;
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

        /* Video-layout wrappers need overflow visible so the vtc-content-col can scroll */
        body.presentation-mode .viewer-content .wpvl-wrap.vtc-layout {
            display: grid !important;
            flex-direction: unset !important;
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
                /* Removed global voice toggle UI */
                /* .tts-global-controls{
                    position:fixed;
                    top:12px;
                    right:12px;
                    z-index:9999;
                    display:inline-flex;
                    align-items:center;
                    gap:8px;
                    background:#ffffff;
                    border:1px solid #d8dbe4;
                    border-radius:999px;
                    padding:6px 8px;
                    box-shadow:0 4px 14px rgba(0,0,0,.12);
                }

                .tts-global-label{
                    color:#5b6577;
                    font-size:11px;
                    font-weight:900;
                    text-transform:uppercase;
                    letter-spacing:.04em;
                }

                .tts-global-select{
                    border:1px solid #d8dbe4;
                    border-radius:999px;
                    background:#fff;
                    color:#4338ca;
                    font-family:'Nunito','Segoe UI',sans-serif;
                    font-size:12px;
                    font-weight:800;
                    padding:7px 12px;
                } */
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
            min-height:42px;
            margin-bottom:0;
            background:#EEEDFE;
            border-bottom:1.5px solid #AFA9EC;
            padding:0 16px;
        }

        .tts-global-controls{
            position:fixed;
            top:12px;
            right:12px;
            z-index:9999;
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:#ffffff;
            border:1px solid #d8dbe4;
            border-radius:999px;
            padding:6px 8px;
            box-shadow:0 4px 14px rgba(0,0,0,.12);
        }

        .tts-global-label{
            color:#5b6577;
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.04em;
        }

        .tts-global-select{
            border:1px solid #d8dbe4;
            border-radius:999px;
            background:#fff;
            color:#4338ca;
            font-family:'Nunito','Segoe UI',sans-serif;
            font-size:12px;
            font-weight:800;
            padding:7px 12px;
        }

        .back-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:5px;
            background:#7F77DD;
            color:#ffffff;
            padding:10px 18px;
            border-radius:999px;
            text-decoration:none;
            font-weight:800;
            font-size:13px;
            font-family:'Nunito', 'Segoe UI', sans-serif;
            line-height:1;
            box-shadow:0 4px 14px rgba(127,119,221,.30);
            transition:transform .18s cubic-bezier(.34,1.4,.64,1), box-shadow .15s, filter .15s;
        }

        .back-btn:hover{
            filter:brightness(1.08);
            transform:translateY(-2px) scale(1.04);
            box-shadow:0 8px 22px rgba(127,119,221,.42);
        }

        /* ── Canonical action button ───────────────────────────
           Use .viewer-btn on Next / Previous / Show Answer buttons
           in new activities so they automatically match app style. */
        .viewer-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:11px 18px;
            border:none;
            border-radius:999px;
            color:#fff;
            font-weight:800;
            font-family:'Nunito','Segoe UI',sans-serif;
            font-size:14px;
            min-width:142px;
            cursor:pointer;
            background:#7F77DD;
            box-shadow:0 4px 14px rgba(127,119,221,.30);
            transition:transform .18s cubic-bezier(.34,1.4,.64,1), box-shadow .15s, filter .15s;
            line-height:1;
            text-decoration:none;
        }
        .viewer-btn:hover,
        .viewer-btn:focus-visible{
            filter:brightness(1.04);
            transform:translateY(-1px);
        }
        .viewer-btn:disabled{
            opacity:.55;
            cursor:default;
        }

        .viewer-header{
            text-align:center;
            margin:0 0 10px 0;
        }

        h1{
            margin:0;
            font-family: Arial, sans-serif;
            color: var(--viewer-text);
            font-size: 26px;
            line-height:1.12;
            font-weight:700;
            letter-spacing:.02em;
        }

        .viewer-content{
            margin-top:0;
            background: transparent;
            border:none;
            border-radius:0;
            padding:0;
            box-shadow: none;
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
            font-size: 22px !important;
            line-height:1.1 !important;
            margin:0 0 8px !important;
            font-family: Arial, sans-serif !important;
            font-weight:600 !important;
            text-align:center !important;
            color: var(--viewer-text) !important;
        }

        .viewer-content :is(.ppt-slide-title){
            font-size: 22px !important;
            line-height:1.1 !important;
            text-align:center !important;
            color: var(--viewer-text) !important;
            font-family: Arial, sans-serif !important;
            font-weight:600 !important;
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
            padding:8px 12px !important;
            min-width:142px !important;
            font-size:14px !important;
            font-weight:700 !important;
            border-radius:8px !important;
            background: #7F77DD !important;
            color: #fff !important;
            border: none !important;
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
                padding:20px;
            }

            .top-row{
                margin-bottom:2px;
                min-height:34px;
            }

            .viewer-header{
                margin:0 0 8px 0;
            }

            h1{
                font-size:24px;
            }

            .back-btn{
                padding:8px 16px;
                font-size:12px;
            }

            .viewer-content{
                border-radius:14px;
                padding:20px;
            }
        }

        @media (max-width: 640px){
            body{
                padding:12px;
            }

            h1{
                font-size:22px;
            }

            .top-row{
                min-height:32px;
            }

            .back-btn{
                padding:7px 14px;
                font-size:12px;
            }

            .viewer-content{
                border-radius:12px;
                padding:14px;
            }

            .viewer-content > :is(div, section){
                max-width:100%;
            }

            .viewer-content :is(.mc-intro, .dd-intro, .lo-intro, .vc-intro, .dict-intro, .pron-intro, .match-intro, .flashcards-intro, .flipbook-intro, .ex-intro, .ppt-intro){
                padding:12px 12px !important;
            }

            .viewer-content :is(.mc-intro h2, .dd-intro h2, .lo-intro h2, .vc-intro h2, .dict-intro h2, .pron-intro h2, .match-intro h2, .flashcards-intro h2, .flipbook-intro h2, .ex-intro h2, .ppt-intro h2){
                font-size:20px !important;
            }

            .viewer-content :is(.mc-question, #promptText, .lo-prompt, .vc-question){
                font-size:clamp(18px, 5.8vw, 24px) !important;
                line-height:1.25 !important;
            }

            .viewer-content :is(.mc-card, #sentenceBox, .vc-panel, .flipbook-viewer__card){
                padding:10px !important;
            }
        }

        @media (max-width: 420px){
            body{
                padding:8px;
            }

            h1{
                font-size:20px;
            }

            .viewer-content{
                padding:10px;
            }

            .top-row{
                min-height:30px;
            }
        }

        /* ── Shared activity header (.act-header) ─────────────────────────── */
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

        .act-header {
            margin: 0 auto 18px;
            padding: 22px 26px;
            border-radius: 26px;
            border: 1px solid #d9cff6;
            background: linear-gradient(135deg, #eef4ff 0%, #f8ebff 48%, #e8fff7 100%);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .08);
            max-width: 980px;
            box-sizing: border-box;
        }
        .act-header h2 {
            margin: 0 0 8px;
            font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
            font-size: clamp(26px, 4vw, 36px);
            font-weight: 700;
            line-height: 1.15;
            color: #4c1d95;
            letter-spacing: .2px;
        }
        .act-header p {
            margin: 0;
            font-size: 18px;
            color: #5b516f;
            line-height: 1.6;
        }
        @media (max-width: 760px) {
            .act-header { padding: 16px 16px; border-radius: 18px; margin-bottom: 14px; }
            .act-header h2 { font-size: 26px; }
            .act-header p  { font-size: 16px; }
        }
        body.fullscreen-embedded .act-header {
            padding: 10px 16px !important;
            margin-bottom: 8px !important;
            border-radius: 12px !important;
        }
        body.fullscreen-embedded .act-header h2 { font-size: 22px !important; }
        body.fullscreen-embedded .act-header p  { font-size: 15px !important; }

        /* ── Fullscreen-embedded: parent page has entered fullscreen, iframe fills viewport ── */
        body.fullscreen-embedded {
            height: 100vh !important;
            overflow: hidden !important;
            padding: 0 !important;
            background: #f0faf6 !important;
        }
        body.fullscreen-embedded .activity-wrapper {
            height: 100%;
            max-width: 100%;
            display: flex;
            flex-direction: column;
            padding: 6px 8px 4px;
        }
        body.fullscreen-embedded .top-row { display: none !important; }
        body.fullscreen-embedded .viewer-header { margin: 0 0 4px !important; }
        body.fullscreen-embedded h1 { font-size: 18px !important; }
        body.fullscreen-embedded .viewer-content {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            border-radius: 8px;
            padding: 10px 14px;
            margin: 0;
        }

        /* ─────────────────────────────────────────────── */
        /* EMBEDDED MODE – compact, height-constrained, no scroll */
        /* ─────────────────────────────────────────────── */
        body.embedded-mode {
            padding: 0;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        body.embedded-mode .activity-wrapper {
            max-width: 100%;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            margin: 0;
        }

        body.embedded-mode .top-row,
        body.embedded-mode .viewer-header {
            display: none !important;
        }

        body.embedded-mode .viewer-content {
            flex: 1;
            min-height: 0;
            overflow: hidden;
            display: flex !important;
            flex-direction: column !important;
            border-radius: 0;
            padding: 10px 12px;
            margin-top: 0;
            background: #fff;
            box-shadow: none;
            border: none;
            backdrop-filter: none;
        }

        body.embedded-mode .viewer-content > :is(div, section) {
            max-width: 100%;
        }
    </style>
</head>

<body<?= $isPresentationMode ? ' class="presentation-mode"' : ($embedded ? ' class="embedded-mode"' : '') ?>>

<div class="activity-wrapper">

    <?php if (!$embedded && !$isPresentationMode) { ?>
    <div class="top-row">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">Back</a>
    </div>
    <?php } ?>

    <div class="viewer-content">
        <?= $content ?>
        
        <?php if ($isPresentationMode && $nextUrl !== '') { ?>
        <div style="flex-shrink: 0; padding: 12px 16px; background: #EEEDFE; border-top: 1.5px solid #AFA9EC; display: flex; justify-content: center; align-items: center; gap: 12px;">
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
    var originalScrollTo = window.scrollTo;
    window.scrollTo = function() {
        if (arguments.length > 0 && typeof arguments[0] === 'object' && arguments[0].behavior === 'smooth') {
            return;
        }
        originalScrollTo.apply(window, arguments);
    };
}

// Fullscreen-embedded: parent page signals fullscreen state changes via postMessage
window.addEventListener('message', function (e) {
    if (!e.data || typeof e.data !== 'object') return;
    var t = e.data.type;
    if (t === 'fs-enter') {
        document.body.classList.add('fullscreen-embedded');
        document.dispatchEvent(new CustomEvent('fullscreen-embedded', { detail: { active: true } }));
    } else if (t === 'fs-exit') {
        document.body.classList.remove('fullscreen-embedded');
        document.dispatchEvent(new CustomEvent('fullscreen-embedded', { detail: { active: false } }));
    }
});

// Global browser-TTS voice selection removed for viewers.
</script>

</body>
</html>
<?php
}

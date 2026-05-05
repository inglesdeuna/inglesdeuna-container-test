<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$nextUrl = isset($_GET['next']) ? trim((string) $_GET['next']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_powerpoint_title(): string
{
    return 'PowerPoint';
}

function normalize_template(string $value): string
{
    $allowed = ['title_text', 'text_image', 'image_full'];
    return in_array($value, $allowed, true) ? $value : 'title_text';
}

function normalize_font_family(string $value): string
{
    $allowed = ['Arial', 'Georgia', 'Verdana', 'Tahoma', 'Times New Roman'];
    return in_array($value, $allowed, true) ? $value : 'Arial';
}

function normalize_color(string $value): string
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtoupper($value);
    }

    return '#FFFFFF';
}

function normalize_canva_link(string $value): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^https?:\/\//i', $value)) {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
        return '';
    }

    if (strpos($host, 'canva.com') === false) {
        return $value;
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
    $path = (string) ($parts['path'] ?? '/');

    // Canva shared links must be /view for iframe embedding.
    $path = preg_replace('#/edit(?:/)?$#i', '/view', $path);
    $path = preg_replace('#/watch(?:/)?$#i', '/view', $path);

    if (!preg_match('#/view$#i', $path)) {
        $path = rtrim($path, '/') . '/view';
    }

    return $scheme . '://' . $host . $path . '?embed';
}

function powerpoint_open_url(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';

    $parts = parse_url($value);
    if (!is_array($parts)) return $value;

    $host = strtolower((string) ($parts['host'] ?? ''));
    if (strpos($host, 'canva.com') === false) {
        return $value;
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
    $path = (string) ($parts['path'] ?? '/');

    $path = preg_replace('#/edit(?:/)?$#i', '/view', $path);
    $path = preg_replace('#/watch(?:/)?$#i', '/view', $path);

    if (!preg_match('#/view$#i', $path)) {
        $path = rtrim($path, '/') . '/view';
    }

    return $scheme . '://' . $host . $path;
}

function normalize_powerpoint_payload($rawData): array
{
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    $title = default_powerpoint_title();
    $slides = [];
    $presentationFile = '';
    $presentationName = '';
    $canvaLink = '';

    if (is_array($decoded)) {
        $rawTitle = trim((string) ($decoded['title'] ?? ''));
        if ($rawTitle !== '') {
            $title = $rawTitle;
        }

        $presentationFile = trim((string) ($decoded['presentation_file'] ?? ''));
        $presentationName = trim((string) ($decoded['presentation_name'] ?? ''));
        $canvaLink = normalize_canva_link((string) ($decoded['canva_link'] ?? ''));

        $slidesSource = isset($decoded['slides']) && is_array($decoded['slides']) ? $decoded['slides'] : [];

        foreach ($slidesSource as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $fontSize = (int) ($slide['font_size'] ?? 28);
            if ($fontSize < 14) $fontSize = 14;
            if ($fontSize > 72) $fontSize = 72;

            $titleSize = (int) ($slide['title_size'] ?? 36);
            if ($titleSize < 16) $titleSize = 16;
            if ($titleSize > 96) $titleSize = 96;

            $imageSize = (int) ($slide['image_size'] ?? 50);
            if ($imageSize < 20) $imageSize = 20;
            if ($imageSize > 100) $imageSize = 100;

            $allowedAlign = ['left','center','right'];
            $allowedImgPos = ['right','left','top','bottom'];

            $slides[] = [
                'template'       => normalize_template((string) ($slide['template'] ?? 'title_text')),
                'title'          => trim((string) ($slide['title'] ?? '')),
                'text'           => trim((string) ($slide['text'] ?? '')),
                'font_family'    => normalize_font_family((string) ($slide['font_family'] ?? 'Arial')),
                'font_size'      => $fontSize,
                'title_size'     => $titleSize,
                'bg_color'       => normalize_color((string) ($slide['bg_color'] ?? '#FFFFFF')),
                'title_color'    => normalize_color((string) ($slide['title_color'] ?? '#1E3A5F')),
                'text_color'     => normalize_color((string) ($slide['text_color'] ?? '#334155')),
                'text_align'     => in_array($slide['text_align'] ?? '', $allowedAlign, true) ? $slide['text_align'] : 'left',
                'title_align'    => in_array($slide['title_align'] ?? '', $allowedAlign, true) ? $slide['title_align'] : 'center',
                'bold'           => !empty($slide['bold']),
                'italic'         => !empty($slide['italic']),
                'image'          => trim((string) ($slide['image'] ?? '')),
                'image_size'     => $imageSize,
                'image_position' => in_array($slide['image_position'] ?? '', $allowedImgPos, true) ? $slide['image_position'] : 'right',
                'music'          => trim((string) ($slide['music'] ?? '')),
                'music_name'     => trim((string) ($slide['music_name'] ?? '')),
                'tts_text'       => trim((string) ($slide['tts_text'] ?? '')),
                'tts_lang'       => in_array($slide['tts_lang'] ?? '', ['en-US','es-MX'], true) ? $slide['tts_lang'] : 'en-US',
            ];
        }
    }

    return [
        'title' => $title,
        'slides' => $slides,
        'presentation_file' => $presentationFile,
        'presentation_name' => $presentationName,
        'canva_link' => $canvaLink,
    ];
}

function load_powerpoint_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'title' => default_powerpoint_title(),
        'slides' => [],
        'presentation_file' => '',
        'presentation_name' => '',
        'canva_link' => '',
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'powerpoint' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT data FROM activities WHERE unit_id = :unit AND type = 'powerpoint' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    return normalize_powerpoint_payload($row['data'] ?? null);
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_powerpoint_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? default_powerpoint_title());
$slides = isset($activity['slides']) && is_array($activity['slides']) ? $activity['slides'] : [];
$presentationFile = (string) ($activity['presentation_file'] ?? '');
$presentationName = (string) ($activity['presentation_name'] ?? '');
$canvaLink = (string) ($activity['canva_link'] ?? '');
$canvaOpenLink = powerpoint_open_url($canvaLink);
$showCanvaBlock = $canvaLink !== '';
$showSlidesBlock = !$showCanvaBlock && !empty($slides);
$showEmptyBlock = !$showCanvaBlock && !$showSlidesBlock && $presentationFile === '';

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --ppt-orange: #F97316;
    --ppt-orange-dark: #C2580A;
    --ppt-orange-soft: #FFF0E6;
    --ppt-purple: #7F77DD;
    --ppt-purple-dark: #534AB7;
    --ppt-purple-soft: #EEEDFE;
    --ppt-white: #FFFFFF;
    --ppt-lila-border: #EDE9FA;
    --ppt-muted: #9B94BE;
    --ppt-ink: #271B5D;
}

html,
body {
    width: 100%;
    min-height: 100%;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
}

.activity-wrapper {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
}

.top-row {
    display: none !important;
}

.viewer-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

.ppt-page {
    width: 100%;
    min-height: 100vh;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #ffffff;
    box-sizing: border-box;
}

.ppt-app {
    width: min(980px, 100%);
    margin: 0 auto;
}

.ppt-topbar {
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    position: relative;
}

.ppt-topbar-title {
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    color: #9B94BE;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.ppt-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}

.ppt-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.ppt-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1.03;
}

.ppt-hero p {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 8px 0 0;
}

.ppt-board {
    background: #ffffff;
    border: 1px solid #F0EEF8;
    border-radius: 34px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    width: min(900px, 100%);
    margin: 0 auto;
    box-sizing: border-box;
    position: relative;
}

.ppt-file {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 22px;
    padding: 14px 16px;
    margin-bottom: 14px;
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    box-shadow: 0 4px 14px rgba(127,119,221,.08);
}

.ppt-file-name {
    color: #534AB7;
    font-family: 'Nunito', sans-serif;
    font-size: 13px;
    font-weight: 900;
}

.ppt-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
}

.ppt-embedded-file {
    margin-bottom: 14px;
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    padding: 12px;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    overflow: hidden;
}

.ppt-embedded-file iframe {
    width: 100%;
    height: min(72vh, 620px);
    min-height: 420px;
    border: none;
    border-radius: 20px;
    background: #ffffff;
    display: block;
}

.ppt-note {
    font-size: 12px;
    color: #9B94BE;
    font-weight: 800;
    margin-top: 8px;
    text-align: center;
}

.ppt-stage {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
}

.ppt-slide {
    min-height: 520px;
    padding: 28px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
    box-sizing: border-box;
}

.ppt-slide.template-title_text {
    flex-direction: column;
}

.ppt-slide.template-text_image {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
}

.ppt-slide.template-image_full {
    display: flex;
    flex-direction: column;
}

.ppt-col-text {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    align-self: stretch;
}

.ppt-slide-title {
    margin: 0;
    color: #F97316;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-weight: 700;
    line-height: 1.08;
    text-align: center;
}

.ppt-slide-text {
    margin: 0;
    color: #534AB7;
    line-height: 1.7;
    white-space: pre-wrap;
}

.ppt-image-wrap {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
}

.ppt-image {
    max-width: 100%;
    max-height: 420px;
    border-radius: 18px;
    object-fit: contain;
    border: 1px solid #EDE9FA;
    background: #ffffff;
    box-shadow: 0 8px 24px rgba(127,119,221,.10);
}

.ppt-toolbar {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px;
    border-top: 1px solid #F0EEF8;
    background: #ffffff;
    align-items: center;
}

.ppt-toolbar-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    width: 100%;
}

.ppt-slide-audio-wrap {
    display: flex;
    justify-content: center;
    padding: 12px 0 4px;
    grid-column: 1 / -1;
    width: 100%;
}

.ppt-slide-audio-btn,
.ppt-btn,
.ppt-completed-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 999px;
    padding: 13px 20px;
    min-width: clamp(112px, 16vw, 154px);
    font-weight: 900;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
    text-decoration: none;
    color: #ffffff;
    transition: transform .12s, filter .12s, box-shadow .12s;
}

.ppt-slide-audio-btn:hover,
.ppt-btn:hover,
.ppt-completed-btn:hover {
    filter: brightness(1.07);
    transform: translateY(-1px);
}

.ppt-slide-audio-btn,
.ppt-btn-primary {
    background: #7F77DD;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.ppt-slide-audio-btn.playing {
    background: #F97316;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

.ppt-btn-light,
.ppt-btn-next-act {
    background: #F97316;
    box-shadow: 0 6px 18px rgba(249,115,22,.22);
}

.ppt-btn-mint {
    background: #7F77DD;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.ppt-btn-stop {
    background: #534AB7;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

.ppt-count {
    background: #7F77DD;
    color: #ffffff;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 900;
    border-radius: 999px;
    padding: 7px 11px;
    white-space: nowrap;
}

.ppt-empty {
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 22px;
    padding: 28px;
    text-align: center;
    color: #C2580A;
    font-family: 'Nunito', sans-serif;
    font-weight: 900;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
}

.ppt-completed-screen {
    display: none;
    background: #ffffff;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    min-height: clamp(300px, 42vh, 430px);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: clamp(28px, 5vw, 48px) 24px;
    gap: 12px;
    box-sizing: border-box;
}

.ppt-completed-screen.active {
    display: flex;
}

.ppt-completed-icon {
    font-size: 64px;
    line-height: 1;
    margin-bottom: 4px;
}

.ppt-completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: #F97316;
    margin: 0;
    line-height: 1.03;
}

.ppt-completed-text {
    font-family: 'Nunito', sans-serif;
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: #9B94BE;
    margin: 0;
}

.ppt-completed-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

.ppt-completed-btn {
    background: #7F77DD;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
}

@media (max-width: 900px) {
    .ppt-page {
        padding: 12px;
    }

    .ppt-board {
        border-radius: 26px;
        padding: 14px;
        width: 100%;
    }

    .ppt-hero h1 {
        font-size: clamp(26px, 8vw, 38px);
    }

    .ppt-slide {
        padding: 18px;
        min-height: 420px;
    }

    .ppt-slide.template-text_image {
        grid-template-columns: 1fr;
    }

    .ppt-embedded-file iframe {
        height: 58vh;
        min-height: 320px;
        border-radius: 16px;
    }

    .ppt-toolbar-row {
        flex-direction: column;
        align-items: stretch;
    }

    .ppt-actions {
        width: 100%;
    }

    .ppt-btn,
    .ppt-btn-next-act,
    .ppt-completed-btn {
        width: 100%;
        justify-content: center;
        text-align: center;
        display: flex;
    }
}
</style>

<div class="ppt-page">
    <div class="ppt-app">

        <div class="ppt-topbar">
            <span class="ppt-topbar-title">PowerPoint</span>
        </div>

        <div class="ppt-hero">
            <div class="ppt-kicker">Activity</div>
            <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Use the navigation buttons to go through each slide.</p>
        </div>

        <div class="ppt-board">

            <?php if ($presentationFile !== '') { ?>
                <div class="ppt-file">
                    <span class="ppt-file-name">Uploaded presentation: <?php echo htmlspecialchars($presentationName !== '' ? $presentationName : 'presentation.pptx', ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="ppt-actions">
                        <button type="button" class="ppt-btn ppt-btn-primary" id="btnOpenPresentation">Open File</button>
                    </div>
                </div>

                <?php if (stripos($presentationFile, 'data:application/pdf') === 0) { ?>
                    <div class="ppt-embedded-file">
                        <iframe src="<?php echo htmlspecialchars($presentationFile, ENT_QUOTES, 'UTF-8'); ?>" title="Uploaded presentation PDF"></iframe>
                    </div>
                <?php } ?>
            <?php } ?>

            <?php if ($canvaLink !== '') { ?>
                <div class="ppt-file">
                    <span class="ppt-file-name">Canva presentation configured</span>
                    <div class="ppt-actions">
                        <button type="button" class="ppt-btn ppt-btn-primary" id="btnOpenCanva">Open Canva</button>
                    </div>
                </div>

                <div class="ppt-embedded-file">
                    <iframe
                        src="<?php echo htmlspecialchars($canvaLink, ENT_QUOTES, 'UTF-8'); ?>"
                        title="Canva presentation"
                        loading="lazy"
                        allowfullscreen
                        allow="fullscreen; autoplay; clipboard-write; encrypted-media">
                    </iframe>
                    <div class="ppt-note">If Canva blocks the embed, use Open Canva.</div>
                </div>
            <?php } ?>

            <?php if ($showEmptyBlock) { ?>
                <div class="ppt-empty">No slides are configured for this activity.</div>
            <?php } elseif ($showSlidesBlock) { ?>
                <div class="ppt-stage">
                    <div id="pptSlide" class="ppt-slide"></div>

                    <div class="ppt-toolbar">
                        <div class="ppt-toolbar-row" id="pptNavRow">
                            <button type="button" class="ppt-btn ppt-btn-light" id="btnPrev">Previous</button>
                            <span class="ppt-count" id="pptCounter"></span>
                            <button type="button" class="ppt-btn ppt-btn-primary" id="btnNext">Next</button>
                            <?php if ($nextUrl !== '') { ?>
                                <a class="ppt-btn ppt-btn-next-act" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Next Activity</a>
                            <?php } ?>
                        </div>

                        <div class="ppt-toolbar-row" id="pptTtsRow" style="display:none">
                            <button type="button" class="ppt-btn ppt-btn-mint" id="btnTts">Read</button>
                            <button type="button" class="ppt-btn ppt-btn-stop" id="btnStopTts">Stop</button>
                        </div>
                    </div>
                </div>
            <?php } ?>

            <div id="ppt-completed-screen" class="ppt-completed-screen">
                <div class="ppt-completed-icon">✅</div>
                <h2 class="ppt-completed-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="ppt-completed-text">You've reviewed all the slides. Great job!</p>
                <div class="ppt-completed-actions">
                    <button type="button" class="ppt-completed-btn" id="ppt-restart-btn">Back</button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
const PPT_SLIDES = <?php echo json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_PRESENTATION_FILE = <?php echo json_encode($presentationFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_PRESENTATION_NAME = <?php echo json_encode($presentationName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_CANVA_LINK = <?php echo json_encode($canvaLink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_CANVA_OPEN_LINK = <?php echo json_encode($canvaOpenLink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

let slideIndex = 0;
let currentAudio = null;

function escapeHtml(rawValue) {
    return String(rawValue)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderSlide() {
    const stage = document.getElementById('pptSlide');
    const counter = document.getElementById('pptCounter');
    if (!stage || !counter || !Array.isArray(PPT_SLIDES) || !PPT_SLIDES.length) return;

    const slide = PPT_SLIDES[slideIndex] || {};
    const template = ['title_text','text_image','image_full'].includes(slide.template) ? slide.template : 'title_text';
    const bg = /^#[0-9a-fA-F]{6}$/.test(String(slide.bg_color || '')) ? slide.bg_color : '#FFFFFF';
    const fontFamily = ['Arial','Georgia','Verdana','Tahoma','Times New Roman','Courier New','Trebuchet MS','Impact'].includes(slide.font_family) ? slide.font_family : 'Arial';
    const fontSize = Math.max(12, Math.min(72, Number(slide.font_size || 28)));
    const titleSize = Math.max(16, Math.min(96, Number(slide.title_size || 36)));
    const titleColor = /^#[0-9a-fA-F]{6}$/i.test(String(slide.title_color || '')) ? slide.title_color : '#F97316';
    const textColor = /^#[0-9a-fA-F]{6}$/i.test(String(slide.text_color || '')) ? slide.text_color : '#534AB7';
    const textAlign = ['left','center','right'].includes(slide.text_align) ? slide.text_align : 'left';
    const titleAlign = ['left','center','right'].includes(slide.title_align) ? slide.title_align : 'center';
    const fw = slide.bold ? '800' : '600';
    const fi = slide.italic ? 'italic' : 'normal';
    const imgPct = Math.max(20, Math.min(100, Number(slide.image_size || 50))) + '%';
    const imgPos = ['right','left','top','bottom'].includes(slide.image_position) ? slide.image_position : 'right';

    stage.className = 'ppt-slide template-' + template;
    stage.style.background = bg;

    const hasImage = !!slide.image;
    const imgTag = hasImage ? '<img class="ppt-image" src="' + escapeHtml(slide.image) + '" alt="Slide image" style="max-width:' + imgPct + ';max-height:' + imgPct + '">' : '';
    const imgWrap = hasImage ? '<div class="ppt-image-wrap">' + imgTag + '</div>' : '';
    const titleHtml = slide.title ? '<h2 class="ppt-slide-title" style="width:100%;box-sizing:border-box;font-family:' + escapeHtml(fontFamily) + ';font-size:' + titleSize + 'px;color:' + titleColor + ';text-align:' + titleAlign + '">' + escapeHtml(slide.title) + '</h2>' : '';
    const textHtml = slide.text ? '<p class="ppt-slide-text" style="width:100%;box-sizing:border-box;font-family:' + escapeHtml(fontFamily) + ';font-size:' + fontSize + 'px;color:' + textColor + ';text-align:' + textAlign + ';font-weight:' + fw + ';font-style:' + fi + '">' + escapeHtml(slide.text) + '</p>' : '';

    if (template === 'text_image') {
        const textCol = '<div class="ppt-col-text">' + titleHtml + textHtml + '</div>';
        const imgCol = '<div class="ppt-image-wrap" style="flex-basis:' + imgPct + '">' + imgTag + '</div>';

        if (imgPos === 'left') {
            stage.innerHTML = imgCol + textCol;
        } else if (imgPos === 'top') {
            stage.style.flexDirection = 'column';
            stage.innerHTML = imgWrap + '<div class="ppt-col-text">' + titleHtml + textHtml + '</div>';
        } else if (imgPos === 'bottom') {
            stage.style.flexDirection = 'column';
            stage.innerHTML = '<div class="ppt-col-text">' + titleHtml + textHtml + '</div>' + imgWrap;
        } else {
            stage.innerHTML = textCol + imgCol;
        }
    } else {
        stage.innerHTML = '<div class="ppt-col-text">' + titleHtml + textHtml + '</div>' + imgWrap;
    }

    counter.textContent = 'Slide ' + (slideIndex + 1) + ' / ' + PPT_SLIDES.length;

    const ttsRow = document.getElementById('pptTtsRow');
    if (ttsRow) {
        ttsRow.style.display = (slide.tts_text && String(slide.tts_text).trim()) ? 'flex' : 'none';
    }

    if (currentAudio) {
        currentAudio.pause();
        currentAudio = null;
    }

    if (slide.music) {
        const audioWrap = document.createElement('div');
        audioWrap.className = 'ppt-slide-audio-wrap';

        const musicLabel = (slide.music_name && String(slide.music_name).trim())
            ? escapeHtml(String(slide.music_name).trim())
            : 'Audio';

        const audioBtn = document.createElement('button');
        audioBtn.type = 'button';
        audioBtn.className = 'ppt-slide-audio-btn';
        audioBtn.innerHTML = 'Play ' + musicLabel;

        audioBtn.addEventListener('click', function () {
            if (!currentAudio) {
                currentAudio = new Audio(slide.music);
                currentAudio.onended = function () {
                    audioBtn.classList.remove('playing');
                    audioBtn.innerHTML = 'Play ' + musicLabel;
                };
            }

            if (currentAudio.paused) {
                currentAudio.play().catch(function () {});
                audioBtn.classList.add('playing');
                audioBtn.innerHTML = 'Pause ' + musicLabel;
            } else {
                currentAudio.pause();
                audioBtn.classList.remove('playing');
                audioBtn.innerHTML = 'Play ' + musicLabel;
            }
        });

        audioWrap.appendChild(audioBtn);
        stage.appendChild(audioWrap);
    }
}

function speakSlide() {
    if (!Array.isArray(PPT_SLIDES) || !PPT_SLIDES.length) return;

    const slide = PPT_SLIDES[slideIndex] || {};
    const textToRead = String(slide.tts_text || '').trim() || String(slide.text || '').trim() || String(slide.title || '').trim();
    if (!textToRead) return;

    const ttsLang = String(slide.tts_lang || '').trim() || 'en-US';
    const utterance = new SpeechSynthesisUtterance(textToRead);
    utterance.lang = ttsLang;
    utterance.rate = 1;

    const selectedVoice = getPreferredVoice(ttsLang);
    if (selectedVoice) {
        utterance.voice = selectedVoice;
    }

    speechSynthesis.cancel();
    speechSynthesis.speak(utterance);
}

function getPreferredVoice(lang) {
    lang = lang || 'en-US';
    const voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
    if (!Array.isArray(voices) || voices.length === 0) {
        return null;
    }

    const langPrefix = lang.split('-')[0].toLowerCase();
    const matchedVoices = voices.filter((voice) => {
        const vl = String(voice.lang || '').toLowerCase();
        return vl === lang.toLowerCase() || vl.startsWith(langPrefix + '-') || vl.startsWith(langPrefix + '_');
    });

    if (!matchedVoices.length) {
        return voices[0] || null;
    }

    const femaleHints = ['female', 'woman', 'zira', 'samantha', 'karen', 'aria', 'jenny', 'emma', 'olivia', 'ava',
        'paulina', 'sabina', 'esperanza', 'mónica', 'monica', 'conchita'];

    const femaleVoice = matchedVoices.find((voice) => {
        const label = (String(voice.name || '') + ' ' + String(voice.voiceURI || '')).toLowerCase();
        return femaleHints.some((hint) => label.includes(hint));
    });

    return femaleVoice || matchedVoices[0];
}

function openPresentation() {
    if (!PPT_PRESENTATION_FILE) return;
    window.open(PPT_PRESENTATION_FILE, '_blank');
}

function openCanva() {
    const url = PPT_CANVA_OPEN_LINK || PPT_CANVA_LINK;
    if (!url) return;
    window.open(url, '_blank');
}

function stopSpeech() {
    speechSynthesis.cancel();
}

document.getElementById('btnPrev')?.addEventListener('click', function () {
    if (!PPT_SLIDES.length) return;
    slideIndex = Math.max(0, slideIndex - 1);
    renderSlide();
});

document.getElementById('btnNext')?.addEventListener('click', function () {
    if (!PPT_SLIDES.length) return;

    if (slideIndex >= PPT_SLIDES.length - 1) {
        const stageEl = document.querySelector('.ppt-stage');
        const completedEl = document.getElementById('ppt-completed-screen');

        if (stageEl) stageEl.style.display = 'none';
        if (completedEl) completedEl.classList.add('active');

        return;
    }

    slideIndex = Math.min(PPT_SLIDES.length - 1, slideIndex + 1);
    renderSlide();
});

document.getElementById('ppt-restart-btn')?.addEventListener('click', function () {
    const stageEl = document.querySelector('.ppt-stage');
    const completedEl = document.getElementById('ppt-completed-screen');

    if (completedEl) completedEl.classList.remove('active');
    if (stageEl) stageEl.style.display = '';

    slideIndex = 0;
    renderSlide();
});

document.getElementById('btnTts')?.addEventListener('click', speakSlide);
document.getElementById('btnStopTts')?.addEventListener('click', stopSpeech);
document.getElementById('btnOpenPresentation')?.addEventListener('click', openPresentation);
document.getElementById('btnOpenCanva')?.addEventListener('click', openCanva);

if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = function () {};
}

if (Array.isArray(PPT_SLIDES) && PPT_SLIDES.length) {
    renderSlide();
}
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🖥️', $content);

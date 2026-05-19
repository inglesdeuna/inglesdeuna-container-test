<?php
// Detect POST body silently dropped by PHP due to post_max_size being exceeded.
// When this happens $_POST is empty but Content-Length is large — must check
// BEFORE session_start() so no headers are sent before this guard fires.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes  = (int) ini_get('post_max_size') * 1024 * 1024;
    // ini_get returns strings like "200M" – parse properly
    $rawPostMax = trim((string) ini_get('post_max_size'));
    $unit = strtoupper(substr($rawPostMax, -1));
    $num  = (float) $rawPostMax;
    if ($unit === 'G') { $postMaxBytes = (int) ($num * 1024 * 1024 * 1024); }
    elseif ($unit === 'M') { $postMaxBytes = (int) ($num * 1024 * 1024); }
    elseif ($unit === 'K') { $postMaxBytes = (int) ($num * 1024); }
    else { $postMaxBytes = (int) $num; }

    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        http_response_code(413);
        header('Content-Type: text/html; charset=UTF-8');
        $limitMB = round($postMaxBytes / 1024 / 1024);
        $sentMB  = round($contentLength / 1024 / 1024);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>File too large</title>'
           . '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#fef2f2;}'
           . '.box{background:#fff;border:1px solid #fca5a5;border-radius:16px;padding:32px 40px;max-width:480px;text-align:center;}'
           . 'h2{color:#b91c1c;margin:0 0 12px;}p{color:#374151;margin:0 0 16px;line-height:1.6;}'
           . 'a{display:inline-block;padding:10px 24px;background:#7c3aed;color:#fff;border-radius:999px;text-decoration:none;font-weight:700;}</style></head>'
           . '<body><div class="box"><h2>&#x26A0; File too large</h2>'
           . '<p>The file you tried to upload is <strong>' . $sentMB . ' MB</strong> but the server limit is <strong>' . $limitMB . ' MB</strong>.</p>'
           . '<p>Please compress the file or split the presentation into smaller parts.</p>'
           . '<a href="javascript:history.back()">&#x2190; Go back</a></div></body></html>';
        exit;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

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

function default_slide(): array
{
    return [
        'template'       => 'title_text',
        'title'          => '',
        'text'           => '',
        'font_family'    => 'Arial',
        'font_size'      => 28,
        'title_size'     => 36,
        'bg_color'       => '#FFFFFF',
        'title_color'    => '#1E3A5F',
        'text_color'     => '#334155',
        'text_align'     => 'left',
        'title_align'    => 'center',
        'bold'           => false,
        'italic'         => false,
        'image'          => '',
        'image_size'     => 50,
        'image_position' => 'right',
        'music'          => '',
        'music_name'     => '',
        'tts_text'       => '',
        'tts_lang'       => 'en-US',
        'voice_id'       => 'nzFihrBIvB34imQBuxub',
    ];
}

function normalize_font_family(string $value): string
{
    $allowed = ['Arial', 'Georgia', 'Verdana', 'Tahoma', 'Times New Roman', 'Courier New', 'Trebuchet MS', 'Impact'];
    return in_array($value, $allowed, true) ? $value : 'Arial';
}

function normalize_template(string $value): string
{
    $allowed = ['title_text', 'text_image', 'image_full'];
    return in_array($value, $allowed, true) ? $value : 'title_text';
}

function normalize_color(string $value): string
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtoupper($value);
    }

    return '#FFFFFF';
}

function normalize_data_blob(string $value, int $maxLength): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) > $maxLength) {
        return '';
    }

    return $value;
}

  function normalize_presentation_name(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $value = preg_replace('/[^a-zA-Z0-9_\-. ]+/', '_', $value);
    return trim((string) $value);
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

    // Non-Canva URLs: return as-is
    if (strpos($host, 'canva.com') === false) {
      return $value;
    }

    // Canva URL: ensure the path ends with /view and add the bare ?embed flag.
    // Canva requires exactly "?embed" (no value) for embeddable iframes.
    $path = (string) ($parts['path'] ?? '/');
    // Convert /edit to /view
    $path = preg_replace('#/edit(/*)$#i', '/view', $path);
    // Ensure path ends with /view (some share links omit it)
    if (!preg_match('#/view$#i', $path)) {
      $path = rtrim($path, '/') . '/view';
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
    // Strip all query params; append bare ?embed required by Canva
    return $scheme . '://' . $host . $path . '?embed';
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

    $presentationFile = normalize_data_blob((string) ($decoded['presentation_file'] ?? ''), 40 * 1024 * 1024);
    $presentationName = normalize_presentation_name((string) ($decoded['presentation_name'] ?? ''));
    $canvaLink = normalize_canva_link((string) ($decoded['canva_link'] ?? ''));

        $slidesSource = isset($decoded['slides']) && is_array($decoded['slides']) ? $decoded['slides'] : [];

        foreach ($slidesSource as $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $fontSize = (int) ($slide['font_size'] ?? 28);
            if ($fontSize < 14) {
                $fontSize = 14;
            }
            if ($fontSize > 72) {
                $fontSize = 72;
            }

            $titleSize = (int) ($slide['title_size'] ?? 36);
            if ($titleSize < 16) { $titleSize = 16; }
            if ($titleSize > 96) { $titleSize = 96; }
            $imageSize = (int) ($slide['image_size'] ?? 50);
            if ($imageSize < 20) { $imageSize = 20; }
            if ($imageSize > 100) { $imageSize = 100; }
            $allowedAlign  = ['left', 'center', 'right'];
            $allowedImgPos = ['right', 'left', 'top', 'bottom'];
            $allowedVoices = ['nzFihrBIvB34imQBuxub', 'NoOVOzCQFLOvtsMoNcdT', 'Nggzl2QAXh3OijoXD116'];
            $voiceId = trim((string) ($slide['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));
            if (!in_array($voiceId, $allowedVoices, true)) {
              $voiceId = 'nzFihrBIvB34imQBuxub';
            }
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
                'text_align'     => in_array($slide['text_align']  ?? '', $allowedAlign, true) ? $slide['text_align']  : 'left',
                'title_align'    => in_array($slide['title_align'] ?? '', $allowedAlign, true) ? $slide['title_align'] : 'center',
                'bold'           => !empty($slide['bold']),
                'italic'         => !empty($slide['italic']),
                'image'          => normalize_data_blob((string) ($slide['image'] ?? ''), 12 * 1024 * 1024),
                'image_size'     => $imageSize,
                'image_position' => in_array($slide['image_position'] ?? '', $allowedImgPos, true) ? $slide['image_position'] : 'right',
                'music'          => normalize_data_blob((string) ($slide['music'] ?? ''), 18 * 1024 * 1024),
                'music_name'     => trim((string) ($slide['music_name'] ?? '')),
                'tts_text'       => trim((string) ($slide['tts_text'] ?? '')),
                'tts_lang'       => in_array($slide['tts_lang'] ?? '', ['en-US','es-MX'], true) ? $slide['tts_lang'] : 'en-US',
                'voice_id'       => $voiceId,
            ];
        }
    }

    if (empty($slides)) {
        $slides[] = default_slide();
    }

    return [
        'title' => $title,
        'slides' => $slides,
      'presentation_file' => $presentationFile,
      'presentation_name' => $presentationName,
      'canva_link' => $canvaLink,
    ];
}

  function encode_powerpoint_payload(string $title, array $slides, string $presentationFile, string $presentationName, string $canvaLink): string
{
    $safeCanvaLink = normalize_canva_link($canvaLink);

    return json_encode([
        'title' => trim($title) !== '' ? trim($title) : default_powerpoint_title(),
        'slides' => array_values($slides),
      'presentation_file' => normalize_data_blob($presentationFile, 40 * 1024 * 1024),
      'presentation_name' => normalize_presentation_name($presentationName),
      'canva_link' => $safeCanvaLink,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function load_powerpoint_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id' => '',
        'title' => default_powerpoint_title(),
        'slides' => [default_slide()],
      'presentation_file' => '',
      'presentation_name' => '',
        'canva_link' => '',
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'powerpoint' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'powerpoint' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_powerpoint_payload($row['data'] ?? null);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? default_powerpoint_title()),
        'slides' => isset($payload['slides']) && is_array($payload['slides']) ? $payload['slides'] : [default_slide()],
      'presentation_file' => (string) ($payload['presentation_file'] ?? ''),
      'presentation_name' => (string) ($payload['presentation_name'] ?? ''),
      'canva_link' => (string) ($payload['canva_link'] ?? ''),
    ];
}

  function save_powerpoint_activity(PDO $pdo, string $unit, string $activityId, string $title, array $slides, string $presentationFile, string $presentationName, string $canvaLink): string
{
    $json = encode_powerpoint_payload($title, $slides, $presentationFile, $presentationName, $canvaLink);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'powerpoint' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'powerpoint'");
        $stmt->execute([
            'data' => $json,
            'id' => $targetId,
        ]);

        return $targetId;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO activities (unit_id, type, data, position, created_at)\n        VALUES (\n            :unit_id,\n            'powerpoint',\n            :data,\n            (\n                SELECT COALESCE(MAX(position), 0) + 1\n                FROM activities\n                WHERE unit_id = :unit_id2\n            ),\n            CURRENT_TIMESTAMP\n        )\n        RETURNING id\n    ");
    $stmt->execute([
        'unit_id' => $unit,
        'unit_id2' => $unit,
        'data' => $json,
    ]);

    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
  die('Unit not specified');
}

$activity = load_powerpoint_activity($pdo, $unit, $activityId);

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string) ($_POST['activity_title'] ?? default_powerpoint_title()));
    $slidesJson = (string) ($_POST['slides_payload'] ?? '[]');
  $presentationJson = (string) ($_POST['presentation_payload'] ?? '{}');
    $canvaLink = trim((string) ($_POST['canva_link'] ?? ''));
    $decodedSlides = json_decode($slidesJson, true);
  $decodedPresentation = json_decode($presentationJson, true);

    if (!is_array($decodedSlides)) {
        $decodedSlides = [];
    }

  if (!is_array($decodedPresentation)) {
    $decodedPresentation = [];
  }

    $payload = normalize_powerpoint_payload([
        'title' => $postedTitle,
        'slides' => $decodedSlides,
    'presentation_file' => (string) ($decodedPresentation['file'] ?? ''),
    'presentation_name' => (string) ($decodedPresentation['name'] ?? ''),
        'canva_link' => $canvaLink,
    ]);

    $savedActivityId = save_powerpoint_activity(
        $pdo,
        $unit,
        $activityId,
        (string) $payload['title'],
        (array) $payload['slides'],
        (string) ($payload['presentation_file'] ?? ''),
        (string) ($payload['presentation_name'] ?? ''),
        (string) ($payload['canva_link'] ?? '')
    );

    $params = [
        'unit=' . urlencode($unit),
        'saved=1'
    ];

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
    }

    if ($assignment !== '') {
        $params[] = 'assignment=' . urlencode($assignment);
    }

    if ($source !== '') {
        $params[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $params));
    exit;
}

$activityTitle = (string) ($activity['title'] ?? default_powerpoint_title());
$slides = isset($activity['slides']) && is_array($activity['slides']) ? $activity['slides'] : [default_slide()];
$presentationFile = (string) ($activity['presentation_file'] ?? '');
$presentationName = (string) ($activity['presentation_name'] ?? '');
$canvaLink = (string) ($activity['canva_link'] ?? '');

ob_start();
?>

<style>
/* ── Shell ─────────────────────────────────────────────── */
.ppt-editor-shell{max-width:1080px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif}
.ppt-success{background:#ecfdf5;border:1px solid #86efac;color:#166534;border-radius:12px;padding:12px 16px;font-weight:800;margin-bottom:14px}

/* ── Top bar ───────────────────────────────────────────── */
.ppt-topbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px 16px;margin-bottom:14px;box-shadow:0 4px 14px rgba(15,23,42,.05)}
.ppt-topbar input{flex:1 1 220px;min-width:0}

/* ── Add bar ───────────────────────────────────────────── */
.ppt-addbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.ppt-addbar select{flex:0 0 auto}

/* ── Slide list ────────────────────────────────────────── */
.ppt-slides-list{display:flex;flex-direction:column;gap:14px}

/* ── Individual slide card ─────────────────────────────── */
.ppt-slide-card{border-radius:18px;overflow:hidden;border:1px solid #dbeafe;box-shadow:0 6px 18px rgba(15,23,42,.07);background:#fff}
.ppt-slide-card.collapsed .ppt-slide-body{display:none}
.ppt-slide-header{display:flex;align-items:center;gap:8px;padding:10px 14px;background:linear-gradient(90deg,#2563eb 0%,#1d4ed8 100%);cursor:pointer;user-select:none}
.ppt-slide-header-title{flex:1;color:#fff;font-weight:800;font-size:14px}
.ppt-slide-headerbtns{display:flex;gap:6px}
.ppt-slide-body{padding:16px;display:flex;flex-direction:column;gap:12px}

/* Layouts inside card body */
.ppt-body-cols{display:grid;grid-template-columns:1fr 340px;gap:16px}
@media(max-width:860px){.ppt-body-cols{grid-template-columns:1fr}}

/* Fields */
.ppt-label{display:block;margin-bottom:5px;font-size:12px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.04em}
.ppt-input,.ppt-select,.ppt-textarea{width:100%;border:1.5px solid #cbd5e1;border-radius:10px;padding:9px 11px;font-size:14px;font-family:inherit;background:#fff;box-sizing:border-box;transition:border-color .15s}
.ppt-input:focus,.ppt-select:focus,.ppt-textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.ppt-textarea{min-height:80px;resize:vertical}
.ppt-row{display:grid;gap:10px}
.ppt-row-2{grid-template-columns:1fr 1fr}
.ppt-row-3{grid-template-columns:1fr 1fr 1fr}
.ppt-row-4{grid-template-columns:1fr 1fr 1fr 1fr}
@media(max-width:600px){.ppt-row-2,.ppt-row-3,.ppt-row-4{grid-template-columns:1fr}}

/* Color swatch row */
.ppt-swatch-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:4px}
.ppt-swatch{width:26px;height:26px;border-radius:6px;border:2px solid transparent;cursor:pointer;transition:transform .12s,border-color .12s;flex-shrink:0}
.ppt-swatch:hover{transform:scale(1.18)}
.ppt-swatch.active{border-color:#0f172a}

/* Toggle buttons (align / bold / italic) */
.ppt-toggle-row{display:flex;gap:6px;flex-wrap:wrap}
.ppt-toggle{background:#f1f5f9;border:1.5px solid #cbd5e1;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s,border-color .15s,color .15s;font-family:inherit}
.ppt-toggle.active{background:#2563eb;border-color:#2563eb;color:#fff}

/* Image size slider */
.ppt-slider-row{display:flex;align-items:center;gap:8px}
.ppt-slider-row input[type=range]{flex:1}
.ppt-slider-val{font-size:13px;font-weight:700;color:#2563eb;min-width:36px;text-align:right}

/* Live preview */
.ppt-preview-wrap{position:sticky;top:12px}
.ppt-preview-label{font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.ppt-preview-stage{width:100%;aspect-ratio:16/9;border-radius:14px;border:1px solid #dbeafe;overflow:hidden;display:flex;align-items:stretch;box-shadow:0 8px 24px rgba(15,23,42,.1);background:#fff}
/* preview sub-layouts */
.pprev-col-fill{width:100%;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:10px;box-sizing:border-box;gap:4px}
.pprev-col-text{flex:1;display:flex;flex-direction:column;justify-content:center;padding:8px 10px;gap:4px;min-width:0}
.pprev-col-image{flex:0 0 44%;display:flex;align-items:center;justify-content:center;padding:6px;overflow:hidden}
.pprev-img{max-width:100%;max-height:100%;object-fit:contain;border-radius:8px}
.pprev-title{font-weight:800;line-height:1.2;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
.pprev-text{line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:6;-webkit-box-orient:vertical;white-space:pre-wrap}

/* Image & audio upload areas */
.ppt-upload-area{background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:8px}
.ppt-preview-image{max-width:100%;max-height:120px;border-radius:8px;border:1px solid #cbd5e1;object-fit:cover}

/* Buttons */
.ppt-btn{border:none;border-radius:10px;padding:9px 14px;font-weight:800;cursor:pointer;font-family:inherit;font-size:13px;transition:filter .14s,transform .14s;display:inline-flex;align-items:center;gap:5px}
.ppt-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.ppt-btn-primary{background:linear-gradient(180deg,#3b82f6,#2563eb);color:#fff}
.ppt-btn-green{background:linear-gradient(180deg,#22c55e,#16a34a);color:#fff}
.ppt-btn-orange{background:linear-gradient(180deg,#f97316,#ea580c);color:#fff}
.ppt-btn-danger{background:linear-gradient(180deg,#f87171,#dc2626);color:#fff}
.ppt-btn-light{background:#f1f5f9;color:#1e293b;border:1px solid #cbd5e1}
.ppt-btn-sm{padding:6px 10px;font-size:12px;border-radius:8px}

/* Bottom actions */
.ppt-bottom-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}

/* Extra cards */
.ppt-extra-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px 16px;margin-bottom:12px;box-shadow:0 4px 12px rgba(15,23,42,.04)}
.ppt-section-title{font-size:13px;font-weight:800;color:#334155;margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em}
</style>

<div class="ppt-editor-shell">
<?php if (isset($_GET['saved']) && $_GET['saved'] === '1') { ?>
    <div class="ppt-success">✅ Actividad guardada correctamente.</div>
<?php } ?>

<form id="powerpointForm" method="post">
    <input type="hidden" name="slides_payload" id="slides_payload" value="[]">
    <input type="hidden" name="presentation_payload" id="presentation_payload" value="{}">

    <!-- Title bar -->
    <div class="ppt-topbar">
        <label class="ppt-label" for="activity_title" style="white-space:nowrap;margin:0">📋 Título</label>
        <input class="ppt-input" id="activity_title" name="activity_title" style="flex:1 1 260px"
               value="<?php echo htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8'); ?>" required placeholder="Título de la actividad">
        <button type="submit" class="ppt-btn ppt-btn-primary">💾 Guardar</button>
    </div>

    <!-- Add slide bar -->
    <div class="ppt-addbar">
        <select id="newSlideTemplate" class="ppt-select" style="width:auto;flex:0 0 auto">
            <option value="title_text">🖼️ Título + Texto</option>
            <option value="text_image">📄 Texto + Imagen</option>
            <option value="image_full">🖼️ Imagen completa</option>
        </select>
        <button type="button" class="ppt-btn ppt-btn-green" id="btnAddSlide">＋ Agregar slide</button>
        <button type="button" class="ppt-btn ppt-btn-light" id="btnCollapseAll">▲ Colapsar todos</button>
        <button type="button" class="ppt-btn ppt-btn-light" id="btnExpandAll">▼ Expandir todos</button>
    </div>

    <!-- Slide list -->
    <div class="ppt-slides-list" id="slidesContainer"></div>

    <!-- Upload presentation -->
    <div class="ppt-extra-card" style="margin-top:14px">
        <p class="ppt-section-title">📁 Subir presentación completa (PowerPoint / PDF / Canva export)</p>
        <input class="ppt-input" id="pptFileInput" type="file"
               accept=".ppt,.pptx,.pdf,.canva,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/pdf">
        <div style="margin-top:8px" id="pptFileStatus"></div>
    </div>

    <!-- Canva link -->
    <div class="ppt-extra-card">
        <p class="ppt-section-title">🔗 Link de Canva (opcional)</p>
        <input class="ppt-input" id="canva_link" name="canva_link" placeholder="https://www.canva.com/..."
               value="<?php echo htmlspecialchars($canvaLink, ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="ppt-bottom-actions">
        <button type="submit" class="ppt-btn ppt-btn-primary" style="padding:11px 26px;font-size:15px">💾 Guardar PowerPoint</button>
    </div>
</form>
</div>

<script>
const INITIAL_SLIDES = <?php echo json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const INITIAL_PRESENTATION = {
  file: <?php echo json_encode($presentationFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
  name: <?php echo json_encode($presentationName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};

const FONT_OPTIONS  = ['Arial','Georgia','Verdana','Tahoma','Times New Roman','Courier New','Trebuchet MS','Impact'];
const ALIGN_OPTIONS = ['left','center','right'];
const BG_PRESETS    = [
  '#FFFFFF','#F0F6FF','#FFF7ED','#F0FDF4','#FDF4FF','#FEF9C3',
  '#1e293b','#1e3a5f','#3b0764','#0f172a','#065f46','#7c2d12'
];
const TEXT_PRESETS  = [
  '#0f172a','#1e3a5f','#5b21b6','#065f46','#7c2d12','#FFFFFF','#f0f6ff','#fef9c3'
];

function escapeHtml(v) {
  return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function createSlideModel(tpl) {
  return {
    template: tpl || 'title_text',
    title: '',
    text: '',
    font_family: 'Arial',
    font_size: 28,
    title_size: 36,
    bg_color: '#FFFFFF',
    title_color: '#1e3a5f',
    text_color: '#334155',
    text_align: 'left',
    title_align: 'center',
    bold: false,
    italic: false,
    image: '',
    image_size: 50,
    image_position: 'right',
    music: '',
    music_name: '',
    tts_text: '',
    tts_lang: 'en-US',
    voice_id: 'nzFihrBIvB34imQBuxub'
  };
}

function normalizeSlideState(s) {
  const fs = Number(s.font_size||28), ts = Number(s.title_size||36), is = Number(s.image_size||50);
  const allowedVoices = ['nzFihrBIvB34imQBuxub','NoOVOzCQFLOvtsMoNcdT','Nggzl2QAXh3OijoXD116'];
  const voiceId = String(s.voice_id || 'nzFihrBIvB34imQBuxub');
  return {
    template:       ['title_text','text_image','image_full'].includes(s.template) ? s.template : 'title_text',
    title:          String(s.title||''),
    text:           String(s.text||''),
    font_family:    FONT_OPTIONS.includes(s.font_family) ? s.font_family : 'Arial',
    font_size:      Math.max(12, Math.min(72, Number.isFinite(fs) ? fs : 28)),
    title_size:     Math.max(16, Math.min(96, Number.isFinite(ts) ? ts : 36)),
    bg_color:       /^#[0-9a-fA-F]{6}$/.test(String(s.bg_color||'')) ? String(s.bg_color).toUpperCase() : '#FFFFFF',
    title_color:    /^#[0-9a-fA-F]{6}$/.test(String(s.title_color||'')) ? String(s.title_color).toUpperCase() : '#1E3A5F',
    text_color:     /^#[0-9a-fA-F]{6}$/.test(String(s.text_color||'')) ? String(s.text_color).toUpperCase() : '#334155',
    text_align:     ALIGN_OPTIONS.includes(s.text_align) ? s.text_align : 'left',
    title_align:    ALIGN_OPTIONS.includes(s.title_align) ? s.title_align : 'center',
    bold:           !!s.bold,
    italic:         !!s.italic,
    image:          String(s.image||''),
    image_size:     Math.max(20, Math.min(100, Number.isFinite(is) ? is : 50)),
    image_position: ['right','left','top','bottom'].includes(s.image_position) ? s.image_position : 'right',
    music:          String(s.music||''),
    music_name:     String(s.music_name||''),
    tts_text:       String(s.tts_text||''),
    tts_lang:       ['en-US','es-MX'].includes(s.tts_lang) ? s.tts_lang : 'en-US',
    voice_id:       allowedVoices.includes(voiceId) ? voiceId : 'nzFihrBIvB34imQBuxub'
  };
}

let slidesState = Array.isArray(INITIAL_SLIDES) && INITIAL_SLIDES.length
  ? INITIAL_SLIDES.map(s => Object.assign(createSlideModel(s.template), s))
  : [createSlideModel('title_text')];

let presentationState = { file: String(INITIAL_PRESENTATION.file||''), name: String(INITIAL_PRESENTATION.name||'') };

/* ─── file → data URL ─── */
function fileToDataUrl(f) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.onload  = () => res(String(r.result||''));
    r.onerror = () => rej(new Error('read error'));
    r.readAsDataURL(f);
  });
}

/* ─── image file → compressed data URL (max 1400px, JPEG 88%) ─── */
function compressImageFile(f) {
  return new Promise((res, rej) => {
    const url = URL.createObjectURL(f);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      const MAX = 1400;
      let w = img.naturalWidth, h = img.naturalHeight;
      if (w > MAX || h > MAX) {
        if (w >= h) { h = Math.round(h * MAX / w); w = MAX; }
        else        { w = Math.round(w * MAX / h); h = MAX; }
      }
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      res(canvas.toDataURL('image/jpeg', 0.88));
    };
    img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('load error')); };
    img.src = url;
  });
}

/* ─── Swatch HTML ─── */
function swatchesHtml(presets, fieldKey, currentVal) {
  return presets.map(c =>
    '<span class="ppt-swatch' + (c.toUpperCase()===currentVal.toUpperCase()?' active':'') + '" '
    + 'style="background:' + c + ';border-color:' + (c.toUpperCase()===currentVal.toUpperCase()?'#0f172a':'transparent') + '"'
    + ' data-swatch="' + fieldKey + '" data-color="' + c + '" title="' + c + '"></span>'
  ).join('');
}

/* ─── Toggle row HTML ─── */
function togglesHtml(fieldKey, options, labels, currentVal) {
  return options.map((v,i) =>
    '<button type="button" class="ppt-toggle' + (currentVal===v?' active':'') + '" data-toggle="' + fieldKey + '" data-val="' + v + '">' + labels[i] + '</button>'
  ).join('');
}

/* ─── Live preview render ─── */
function buildPreview(slide) {
  const bg    = slide.bg_color || '#FFFFFF';
  const tCol  = slide.title_color || '#1e3a5f';
  const txCol = slide.text_color  || '#334155';
  const tSize = Math.round(slide.title_size * 0.38);  // scale to preview
  const txSize= Math.round(slide.font_size  * 0.36);
  const tAlign= slide.title_align || 'center';
  const txAlign=slide.text_align  || 'left';
  const fw    = slide.bold   ? '800' : '600';
  const fs    = slide.italic ? 'italic' : 'normal';
  const hasImg= !!slide.image;
  const imgPct= (slide.image_size||50) + '%';
  const tpl   = slide.template;
  const imgTag= hasImg ? '<img class="pprev-img" src="'+slide.image+'" alt="">' : '<span style="font-size:11px;color:#94a3b8">No image</span>';
  const titleEl = slide.title ? '<div class="pprev-title" style="width:100%;box-sizing:border-box;font-family:'+escapeHtml(slide.font_family)+';font-size:'+tSize+'px;color:'+tCol+';text-align:'+tAlign+';font-weight:800">'+escapeHtml(slide.title)+'</div>' : '';
  const textEl  = slide.text  ? '<div class="pprev-text" style="width:100%;box-sizing:border-box;font-family:'+escapeHtml(slide.font_family)+';font-size:'+txSize+'px;color:'+txCol+';text-align:'+txAlign+';font-weight:'+fw+';font-style:'+fs+'">'+escapeHtml(slide.text)+'</div>' : '';

  let inner = '';
  if (tpl === 'image_full' || tpl === 'title_text') {
    inner = '<div class="pprev-col-fill" style="text-align:center">'+titleEl+textEl+(hasImg?'<div style="margin-top:6px;max-width:'+imgPct+';max-height:55%">'+imgTag+'</div>':'')+'</div>';
  } else {
    // text_image – image position
    const pos = slide.image_position || 'right';
    const textCol = '<div class="pprev-col-text">'+titleEl+textEl+'</div>';
    const imgCol  = '<div class="pprev-col-image" style="flex-basis:'+imgPct+'">'+imgTag+'</div>';
    if (pos === 'left') {
      inner = imgCol + textCol;
    } else if (pos === 'top') {
      inner = '<div style="display:flex;flex-direction:column;width:100%"><div style="flex:0 0 '+imgPct+';display:flex;align-items:center;justify-content:center;padding:4px">'+imgTag+'</div>'+textCol+'</div>';
    } else if (pos === 'bottom') {
      inner = '<div style="display:flex;flex-direction:column;width:100%">'+textCol+'<div style="flex:0 0 '+imgPct+';display:flex;align-items:center;justify-content:center;padding:4px">'+imgTag+'</div></div>';
    } else {
      inner = textCol + imgCol;
    }
  }
  return '<div class="ppt-preview-stage" style="background:'+bg+'">'+inner+'</div>';
}

/* ─── Render all slides ─── */
function renderSlides() {
  const container = document.getElementById('slidesContainer');
  const wasCollapsed = {};
  container.querySelectorAll('.ppt-slide-card').forEach((el,i) => {
    wasCollapsed[i] = el.classList.contains('collapsed');
  });
  container.innerHTML = '';

  slidesState.forEach((slideData, idx) => {
    const slide = normalizeSlideState(slideData);
    slidesState[idx] = slide;

    const card = document.createElement('div');
    card.className = 'ppt-slide-card' + (wasCollapsed[idx] ? ' collapsed' : '');

    /* ── header ── */
    const hdr = document.createElement('div');
    hdr.className = 'ppt-slide-header';
    hdr.innerHTML =
      '<span class="ppt-slide-header-title">▶ Slide '+(idx+1)+(slide.title?' — '+escapeHtml(slide.title.substring(0,40)):'')+' <small style="opacity:.7;font-weight:400">('+slide.template.replace('_',' ')+')</small></span>'+
      '<div class="ppt-slide-headerbtns">'+
        (idx>0                        ? '<button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="up">↑</button>' : '')+
        (idx<slidesState.length-1     ? '<button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="down">↓</button>' : '')+
        '<button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="duplicate">⧉ Duplicar</button>'+
        '<button type="button" class="ppt-btn ppt-btn-danger ppt-btn-sm" data-action="remove">✕</button>'+
      '</div>';
    hdr.addEventListener('click', e => {
      if (e.target.closest('[data-action]')) return;
      card.classList.toggle('collapsed');
    });
    card.appendChild(hdr);

    /* ── body ── */
    const body = document.createElement('div');
    body.className = 'ppt-slide-body';

    /* font options */
    const fontOpts = FONT_OPTIONS.map(f =>
      '<option value="'+f+'"'+(slide.font_family===f?' selected':'')+' style="font-family:'+f+'">'+f+'</option>'
    ).join('');
    /* image position options */
    const imgPosOpts = ['right','left','top','bottom'].map(p =>
      '<option value="'+p+'"'+(slide.image_position===p?' selected':'')+'>'+p.charAt(0).toUpperCase()+p.slice(1)+'</option>'
    ).join('');

    body.innerHTML =
      '<div class="ppt-body-cols">'+

      /* ── LEFT: editor fields ── */
      '<div style="display:flex;flex-direction:column;gap:12px">'+

        /* Template */
        '<div class="ppt-row ppt-row-2">'+
          '<div><label class="ppt-label">📐 Layout</label>'+
            '<select class="ppt-select" data-field="template">'+
              '<option value="title_text"'+(slide.template==='title_text'?' selected':'')+'>Título + Texto</option>'+
              '<option value="text_image"'+(slide.template==='text_image'?' selected':'')+'>Texto + Imagen</option>'+
              '<option value="image_full"'+(slide.template==='image_full'?' selected':'')+'>Imagen completa</option>'+
            '</select>'+
          '</div>'+
          '<div><label class="ppt-label">🔤 Fuente</label>'+
            '<select class="ppt-select" data-field="font_family">'+fontOpts+'</select>'+
          '</div>'+
        '</div>'+

        /* Title + title size + title align */
        '<div><label class="ppt-label">✏️ Título del slide</label>'+
          '<input class="ppt-input" data-field="title" value="'+escapeHtml(slide.title)+'" placeholder="Escribe el título...">'+
        '</div>'+
        '<div class="ppt-row ppt-row-3">'+
          '<div><label class="ppt-label">📏 Tamaño título</label>'+
            '<input class="ppt-input" type="number" min="16" max="96" data-field="title_size" value="'+slide.title_size+'">'+
          '</div>'+
          '<div><label class="ppt-label">🎨 Color título</label>'+
            '<input class="ppt-input" type="color" data-field="title_color" value="'+slide.title_color+'">'+
            '<div class="ppt-swatch-row" data-swatchgroup="title_color">'+swatchesHtml(TEXT_PRESETS,'title_color',slide.title_color)+'</div>'+
          '</div>'+
          '<div><label class="ppt-label">⇔ Alineación título</label>'+
            '<div class="ppt-toggle-row" data-togglegroup="title_align">'+togglesHtml('title_align',['left','center','right'],['Izq','Ctro','Der'],slide.title_align)+'</div>'+
          '</div>'+
        '</div>'+

        /* Text body */
        '<div><label class="ppt-label">📝 Texto del slide</label>'+
          '<textarea class="ppt-textarea" data-field="text">'+escapeHtml(slide.text)+'</textarea>'+
        '</div>'+
        '<div class="ppt-row ppt-row-4">'+
          '<div><label class="ppt-label">📏 Tamaño texto</label>'+
            '<input class="ppt-input" type="number" min="12" max="72" data-field="font_size" value="'+slide.font_size+'">'+
          '</div>'+
          '<div><label class="ppt-label">🎨 Color texto</label>'+
            '<input class="ppt-input" type="color" data-field="text_color" value="'+slide.text_color+'">'+
            '<div class="ppt-swatch-row" data-swatchgroup="text_color">'+swatchesHtml(TEXT_PRESETS,'text_color',slide.text_color)+'</div>'+
          '</div>'+
          '<div><label class="ppt-label">⇔ Alineación texto</label>'+
            '<div class="ppt-toggle-row" data-togglegroup="text_align">'+togglesHtml('text_align',['left','center','right'],['Izq','Ctro','Der'],slide.text_align)+'</div>'+
          '</div>'+
          '<div><label class="ppt-label">Aa Estilo</label>'+
            '<div class="ppt-toggle-row">'+
              '<button type="button" class="ppt-toggle'+(slide.bold?' active':'')+'" data-toggle="bold" data-val="bold"><b>B</b></button>'+
              '<button type="button" class="ppt-toggle'+(slide.italic?' active':'')+'" data-toggle="italic" data-val="italic"><i>I</i></button>'+
            '</div>'+
          '</div>'+
        '</div>'+

        /* Background */
        '<div class="ppt-row ppt-row-2">'+
          '<div><label class="ppt-label">🖌 Fondo del slide</label>'+
            '<input class="ppt-input" type="color" data-field="bg_color" value="'+slide.bg_color+'">'+
            '<div class="ppt-swatch-row" data-swatchgroup="bg_color">'+swatchesHtml(BG_PRESETS,'bg_color',slide.bg_color)+'</div>'+
          '</div>'+
          /* Image position (only relevant for text_image) */
          '<div data-section="img-extras">'+
            '<label class="ppt-label">🖼 Pos. imagen</label>'+
            '<select class="ppt-select" data-field="image_position">'+imgPosOpts+'</select>'+
            '<div style="margin-top:6px"><label class="ppt-label">↔ Tamaño imagen</label>'+
              '<div class="ppt-slider-row"><input type="range" min="20" max="100" step="5" data-field="image_size" value="'+slide.image_size+'"><span class="ppt-slider-val" data-sizeval>'+slide.image_size+'%</span></div>'+
            '</div>'+
          '</div>'+
        '</div>'+

        /* Image upload */
        '<div class="ppt-upload-area">'+
          '<label class="ppt-label">🖼 Imagen</label>'+
          '<input class="ppt-input" type="file" accept="image/*" data-upload="image">'+
          '<div data-imgpreview style="margin-top:6px;display:flex;gap:8px;align-items:center">'+
            (slide.image ? '<img class="ppt-preview-image" src="'+slide.image+'" alt="imagen">' : '<span style="color:#94a3b8;font-size:13px">Sin imagen</span>')+
            '<button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="clear-image">✕ Quitar</button>'+
          '</div>'+
        '</div>'+

        /* Audio upload */
        '<div class="ppt-upload-area">'+
          '<label class="ppt-label">🎵 Audio MP3</label>'+
          '<input class="ppt-input" type="file" accept="audio/mpeg,audio/mp3,audio/*" data-upload="music">'+
          '<div data-audiopreview style="margin-top:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">'+
            (slide.music
              ? '<span style="color:#5b21b6;font-weight:700;font-size:13px">🎵 '+(slide.music_name ? escapeHtml(slide.music_name) : 'audio.mp3')+'</span>'
              : '<span style="color:#94a3b8;font-size:13px">Sin audio</span>')+
            '<button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="clear-music">✕ Quitar</button>'+
          '</div>'+
        '</div>'+

        /* TTS */
        '<div class="ppt-row ppt-row-2">'+
          '<div><label class="ppt-label">🔊 Texto TTS <span style="font-weight:400;font-size:11px">(opcional &mdash; se lee en voz alta)</span></label>'+
            '<textarea class="ppt-textarea" data-field="tts_text" style="min-height:60px" placeholder="Deja vacío para usar el texto del slide...">'+escapeHtml(slide.tts_text)+'</textarea>'+
          '</div>'+
          '<div style="display:flex;flex-direction:column;gap:10px">'+
            '<div><label class="ppt-label">🌐 Idioma del TTS</label>'+
              '<select class="ppt-select" data-field="tts_lang">'+
                '<option value="en-US"'+(slide.tts_lang==='en-US'?' selected':'')+'>🇺🇸 English</option>'+
                '<option value="es-MX"'+(slide.tts_lang==='es-MX'?' selected':'')+'>🌎 Español (neutro)</option>'+
              '</select>'+
            '</div>'+
            '<div><label class="ppt-label">🗣️ Voz ElevenLabs</label>'+
              '<select class="ppt-select" data-field="voice_id">'+
                '<option value="nzFihrBIvB34imQBuxub"'+(slide.voice_id==='nzFihrBIvB34imQBuxub'?' selected':'')+'>Adult Male (Josh)</option>'+
                '<option value="NoOVOzCQFLOvtsMoNcdT"'+(slide.voice_id==='NoOVOzCQFLOvtsMoNcdT'?' selected':'')+'>Adult Female (Lily)</option>'+
                '<option value="Nggzl2QAXh3OijoXD116"'+(slide.voice_id==='Nggzl2QAXh3OijoXD116'?' selected':'')+'>Child (Candy)</option>'+
              '</select>'+
            '</div>'+
          '</div>'+
        '</div>'+

      '</div>'+/* end LEFT */

      /* ── RIGHT: live preview ── */
      '<div class="ppt-preview-wrap">'+
        '<div class="ppt-preview-label">👁 Vista previa</div>'+
        '<div data-preview>'+buildPreview(slide)+'</div>'+
      '</div>'+

      '</div>';/* end ppt-body-cols */

    card.appendChild(body);
    bindSlideCardEvents(body, idx);
    container.appendChild(card);
  });
}

/* ─── Live preview refresh (no full re-render) ─── */
function refreshPreview(cardBody, idx) {
  const slide  = normalizeSlideState(slidesState[idx]);
  slidesState[idx] = slide;
  const prev = cardBody.querySelector('[data-preview]');
  if (prev) prev.innerHTML = buildPreview(slide);
  /* refresh header title */
  const hdr = cardBody.closest('.ppt-slide-card').querySelector('.ppt-slide-header-title');
  if (hdr) hdr.textContent = '▶ Slide '+(idx+1)+(slide.title?' — '+slide.title.substring(0,40):'')+' ('+slide.template.replace('_',' ')+')';
}

/* ─── Bind events for a single slide card ─── */
function bindSlideCardEvents(cardBody, sidx) {
  /* scalar fields */
  cardBody.querySelectorAll('[data-field]').forEach(el => {
    const key = el.getAttribute('data-field');
    const isCheckbox = el.type === 'checkbox';
    const ev = (el.tagName === 'TEXTAREA' || el.type === 'text' || el.type === 'number') ? 'input' : 'change';
    el.addEventListener(ev, () => {
      slidesState[sidx][key] = isCheckbox ? el.checked : el.value;
      /* update swatch active state */
      const sg = cardBody.querySelector('[data-swatchgroup="'+key+'"]');
      if (sg) sg.querySelectorAll('.ppt-swatch').forEach(sw => {
        const match = sw.dataset.color.toUpperCase() === String(el.value).toUpperCase();
        sw.classList.toggle('active', match);
        sw.style.borderColor = match ? '#0f172a' : 'transparent';
      });
      /* update slider label */
      if (key === 'image_size') { const sv = cardBody.querySelector('[data-sizeval]'); if(sv) sv.textContent = el.value+'%'; }
      refreshPreview(cardBody, sidx);
    });
  });

  /* swatch clicks */
  cardBody.querySelectorAll('.ppt-swatch').forEach(sw => {
    sw.addEventListener('click', () => {
      const key = sw.getAttribute('data-swatch');
      const col = sw.getAttribute('data-color');
      slidesState[sidx][key] = col;
      /* sync color input */
      const inp = cardBody.querySelector('[data-field="'+key+'"]');
      if (inp) inp.value = col;
      /* update active swatches in this group */
      const sg = cardBody.querySelector('[data-swatchgroup="'+key+'"]');
      if (sg) sg.querySelectorAll('.ppt-swatch').forEach(s2 => {
        const m = s2.dataset.color.toUpperCase() === col.toUpperCase();
        s2.classList.toggle('active', m);
        s2.style.borderColor = m ? '#0f172a' : 'transparent';
      });
      refreshPreview(cardBody, sidx);
    });
  });

  /* toggle buttons */
  cardBody.querySelectorAll('[data-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-toggle');
      const val = btn.getAttribute('data-val');
      if (key === 'bold' || key === 'italic') {
        slidesState[sidx][key] = !slidesState[sidx][key];
        btn.classList.toggle('active', slidesState[sidx][key]);
      } else {
        /* text_align / title_align */
        slidesState[sidx][key] = val;
        const grp = cardBody.querySelector('[data-togglegroup="'+key+'"]');
        if (grp) grp.querySelectorAll('[data-toggle="'+key+'"]').forEach(b2 => b2.classList.toggle('active', b2.dataset.val===val));
      }
      refreshPreview(cardBody, sidx);
    });
  });

  /* file uploads */
  const imageInput = cardBody.querySelector('[data-upload="image"]');
  const musicInput = cardBody.querySelector('[data-upload="music"]');

  imageInput.addEventListener('change', async () => {
    const f = imageInput.files && imageInput.files[0];
    if (!f) return;
    if (!f.type.startsWith('image/')) { alert('El archivo no es una imagen válida.'); imageInput.value=''; return; }
    try {
      slidesState[sidx].image = await compressImageFile(f);
      const prevDiv = cardBody.querySelector('[data-imgpreview]');
      if (prevDiv) prevDiv.innerHTML = '<img class="ppt-preview-image" src="'+slidesState[sidx].image+'" alt="imagen"><button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="clear-image">✕ Quitar</button>';
      refreshPreview(cardBody, sidx);
      /* re-bind clear button */
      const clrBtn = cardBody.querySelector('[data-imgpreview] [data-action="clear-image"]');
      if (clrBtn) clrBtn.addEventListener('click', () => { slidesState[sidx].image=''; const pd=cardBody.querySelector('[data-imgpreview]'); if(pd) pd.innerHTML='<span style="color:#94a3b8;font-size:13px">Sin imagen</span><button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="clear-image">✕ Quitar</button>'; refreshPreview(cardBody,sidx); });
    } catch(e) { alert('No se pudo procesar la imagen.'); }
  });

  musicInput.addEventListener('change', async () => {
    const f = musicInput.files && musicInput.files[0];
    if (!f) return;
    try {
      slidesState[sidx].music = await fileToDataUrl(f);
      slidesState[sidx].music_name = f.name;
      const fnLabel = escapeHtml(f.name);
      const prevDiv = cardBody.querySelector('[data-audiopreview]');
      if (prevDiv) prevDiv.innerHTML = '<span style="color:#5b21b6;font-weight:700;font-size:13px">🎵 '+fnLabel+'</span><button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="clear-music">✕ Quitar</button>';
      /* re-bind clear button */
      const clrBtn = cardBody.querySelector('[data-audiopreview] [data-action="clear-music"]');
      if (clrBtn) clrBtn.addEventListener('click', () => { slidesState[sidx].music=''; slidesState[sidx].music_name=''; const pd=cardBody.querySelector('[data-audiopreview]'); if(pd) pd.innerHTML='<span style="color:#94a3b8;font-size:13px">Sin audio</span><button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" data-action="clear-music">✕ Quitar</button>'; });
    } catch(e) { alert('No se pudo procesar el audio.'); }
  });

  /* action buttons in header */
  cardBody.closest('.ppt-slide-card').querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', () => {
      const a = btn.getAttribute('data-action');
      if (a === 'remove') {
        if (slidesState.length === 1) { alert('Debe haber al menos un slide.'); return; }
        slidesState.splice(sidx, 1); renderSlides(); return;
      }
      if (a === 'up' && sidx > 0) {
        [slidesState[sidx-1],slidesState[sidx]] = [slidesState[sidx],slidesState[sidx-1]]; renderSlides(); return;
      }
      if (a === 'down' && sidx < slidesState.length-1) {
        [slidesState[sidx+1],slidesState[sidx]] = [slidesState[sidx],slidesState[sidx+1]]; renderSlides(); return;
      }
      if (a === 'duplicate') {
        slidesState.splice(sidx+1, 0, JSON.parse(JSON.stringify(slidesState[sidx]))); renderSlides(); return;
      }
      /* clear actions handled inline via re-bind above */
    });
  });
}

/* ─── Presentation file upload ─── */
function renderPresentationStatus() {
  const status = document.getElementById('pptFileStatus');
  if (!status) return;
  if (!presentationState.file) {
    status.innerHTML = '<span style="color:#64748b;font-size:13px">Sin archivo subido aún.</span>';
    return;
  }
  const fn = escapeHtml(presentationState.name||'presentacion.pptx');
  status.innerHTML = '<span style="color:#0f172a;font-weight:700">Archivo subido: '+fn+'</span> '
    + '<button type="button" class="ppt-btn ppt-btn-light ppt-btn-sm" id="btnClearPres">✕ Quitar</button>';
  document.getElementById('btnClearPres').addEventListener('click', () => {
    presentationState = {file:'',name:''}; renderPresentationStatus();
  });
}

/* ─── Top-level event bindings ─── */
document.getElementById('btnAddSlide').addEventListener('click', () => {
  slidesState.push(createSlideModel(document.getElementById('newSlideTemplate').value));
  renderSlides();
  /* scroll to new slide */
  const cards = document.querySelectorAll('.ppt-slide-card');
  if (cards.length) cards[cards.length-1].scrollIntoView({behavior:'smooth',block:'start'});
});

document.getElementById('btnCollapseAll').addEventListener('click', () => {
  document.querySelectorAll('.ppt-slide-card').forEach(c => c.classList.add('collapsed'));
});
document.getElementById('btnExpandAll').addEventListener('click', () => {
  document.querySelectorAll('.ppt-slide-card').forEach(c => c.classList.remove('collapsed'));
});

document.getElementById('pptFileInput').addEventListener('change', async ev => {
  const f = ev.target.files && ev.target.files[0];
  if (!f) return;
  const n = f.name.toLowerCase();
  if (!n.endsWith('.ppt')&&!n.endsWith('.pptx')&&!n.endsWith('.pdf')&&!n.endsWith('.canva')) {
    alert('Archivos permitidos: .ppt, .pptx, .pdf, .canva'); ev.target.value=''; return;
  }
  try {
    presentationState = { file: await fileToDataUrl(f), name: f.name };
    renderPresentationStatus();
  } catch(e) { alert('No se pudo procesar el archivo.'); }
});

document.getElementById('powerpointForm').addEventListener('submit', ev => {
  const ns = slidesState.map(normalizeSlideState);
  document.getElementById('slides_payload').value = JSON.stringify(ns);
  document.getElementById('presentation_payload').value = JSON.stringify(presentationState);
  if (!ns.length) { ev.preventDefault(); alert('Debe haber al menos un slide.'); }
});

renderSlides();
renderPresentationStatus();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('PowerPoint Editor', 'fas fa-display', $content);

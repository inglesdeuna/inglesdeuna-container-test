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

    $presentationFile = trim((string) ($decoded['presentation_file'] ?? ''));
    $presentationName = trim((string) ($decoded['presentation_name'] ?? ''));
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
            $allowedAlign    = ['left','center','right'];
            $allowedImgPos   = ['right','left','top','bottom'];
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
$showCanvaBlock = $canvaLink !== '';
$showSlidesBlock = !$showCanvaBlock && !empty($slides);
$showEmptyBlock = !$showCanvaBlock && !$showSlidesBlock && $presentationFile === '';

ob_start();
?>

<style>
.ppt-viewer-shell{max-width:1100px;margin:0 auto}
.ppt-intro{margin-bottom:18px;padding:24px 26px;border-radius:26px;border:1px solid #d7c7ff;background:linear-gradient(135deg,#eef4ff 0%,#f7edff 48%,#fff6d9 100%);box-shadow:0 16px 34px rgba(15,23,42,.09)}
.ppt-intro h2{margin:0 0 8px;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:30px;line-height:1.1;color:#5b21b6}
.ppt-intro p{margin:0;color:#62556f;font-size:16px;line-height:1.6}
.ppt-completed-screen{display:none;text-align:center;max-width:600px;margin:40px auto;padding:40px 20px}
.ppt-completed-screen.active{display:block}
.ppt-completed-icon{font-size:80px;margin-bottom:20px}
.ppt-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:36px;font-weight:700;color:#be185d;margin:0 0 14px;line-height:1.2}
.ppt-completed-text{font-size:16px;color:#6b4b5f;line-height:1.6;margin:0 0 28px}
.ppt-completed-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.ppt-completed-btn{display:inline-block;padding:12px 24px;border:none;border-radius:999px;background:linear-gradient(180deg,#db2777 0%,#be185d 100%);color:#fff;font-weight:700;font-size:16px;cursor:pointer;box-shadow:0 10px 24px rgba(0,0,0,.14);transition:transform .18s ease,filter .18s ease}
.ppt-completed-btn:hover{transform:scale(1.05);filter:brightness(1.07)}
.ppt-stage{background:linear-gradient(180deg,#fffdfc 0%,#f8fbff 100%);border:1px solid #ddd6fe;border-radius:24px;overflow:hidden;box-shadow:0 14px 28px rgba(15,23,42,.1)}
.ppt-slide{min-height:520px;padding:28px;display:flex;gap:20px;align-items:flex-start}
.ppt-slide.template-title_text{flex-direction:column}
.ppt-slide.template-text_image{display:grid;grid-template-columns:1.1fr .9fr}
.ppt-slide.template-image_full{display:flex;flex-direction:column}
.ppt-col-text{display:flex;flex-direction:column;gap:10px;width:100%;align-self:stretch}
.ppt-slide-title{margin:0;color:#5b21b6;font-family:'Fredoka','Trebuchet MS',sans-serif;font-weight:800;line-height:1.08;text-align:center}
.ppt-slide-text{margin:0;color:#334155;line-height:1.7;white-space:pre-wrap}
.ppt-image-wrap{width:100%;display:flex;justify-content:center;align-items:center}
.ppt-image{max-width:100%;max-height:420px;border-radius:18px;object-fit:contain;border:1px solid rgba(15,23,42,.08);background:#fff;box-shadow:0 10px 22px rgba(15,23,42,.08)}
.ppt-toolbar{display:flex;flex-direction:column;gap:10px;padding:14px 16px;border-top:1px solid #ddd6fe;background:linear-gradient(180deg,#faf5ff 0%,#f0fdfa 100%);align-items:center}
.ppt-toolbar-row{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;align-items:center;width:100%}
.ppt-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;align-items:center}
.ppt-slide-audio-wrap{display:flex;justify-content:center;padding:12px 0 4px;grid-column:1/-1;width:100%}
.ppt-slide-audio-btn{border:none;border-radius:999px;padding:10px 22px;background:linear-gradient(180deg,#8b5cf6 0%,#7c3aed 100%);color:#fff;font-weight:800;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;box-shadow:0 6px 18px rgba(124,58,237,.25);transition:filter .15s,transform .15s}
.ppt-slide-audio-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.ppt-slide-audio-btn.playing{background:linear-gradient(180deg,#10b981 0%,#059669 100%)}
.ppt-tts-lang{display:none}
.ppt-btn{border:none;border-radius:999px;padding:11px 16px;font-weight:800;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;cursor:pointer;box-shadow:0 10px 22px rgba(15,23,42,.12);transition:transform .15s ease,filter .15s ease;text-decoration:none}
.ppt-btn:hover{filter:brightness(1.04);transform:translateY(-1px)}
.ppt-btn-primary{background:linear-gradient(180deg,#8b5cf6 0%,#7c3aed 100%);color:#fff}
.ppt-btn-light{background:linear-gradient(180deg,#f59eb2 0%,#ec4899 100%);color:#fff}
.ppt-btn-mint{background:linear-gradient(180deg,#5eead4 0%,#14b8a6 100%);color:#083344}
.ppt-btn-stop{background:linear-gradient(180deg,#94a3b8 0%,#64748b 100%);color:#fff}
.ppt-btn-next-act{background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);color:#fff}
.ppt-count{font-weight:800;color:#5b21b6}
.ppt-empty{background:#fff;border:1px solid #ddd6fe;border-radius:18px;padding:28px;text-align:center;color:#7c2d12;font-weight:700}
.ppt-file{background:linear-gradient(180deg,#fffdfc 0%,#faf5ff 100%);border:1px solid #ddd6fe;border-radius:18px;padding:16px 18px;margin-bottom:14px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;box-shadow:0 10px 22px rgba(15,23,42,.07)}
.ppt-file-name{color:#5b21b6;font-weight:800}
.ppt-embedded-file{margin-bottom:14px;background:#fff;border:1px solid #ddd6fe;border-radius:18px;padding:12px;box-shadow:0 10px 22px rgba(15,23,42,.07)}
.ppt-embedded-file iframe{width:100%;height:420px;border:none;border-radius:12px}
.ppt-note{font-size:12px;color:#64748b;margin-top:6px}
@media (max-width: 900px){
  .ppt-intro{padding:20px 18px}
  .ppt-intro h2{font-size:26px}
  .ppt-slide{padding:18px;min-height:420px}
  .ppt-slide.template-text_image{grid-template-columns:1fr}
  .ppt-toolbar-row{flex-direction:column;align-items:stretch}
  .ppt-actions{width:100%}
  .ppt-btn,.ppt-btn-next-act{width:100%;justify-content:center;text-align:center;display:flex}
}
</style>

<div class="ppt-viewer-shell">
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
      <span class="ppt-file-name">Canva link configured</span>
      <div class="ppt-actions">
        <button type="button" class="ppt-btn ppt-btn-primary" id="btnOpenCanva">Open Canva</button>
      </div>
    </div>

    <div class="ppt-embedded-file">
      <iframe src="<?php echo htmlspecialchars($canvaLink, ENT_QUOTES, 'UTF-8'); ?>" title="Canva presentation"></iframe>
      <div class="ppt-note">If Canva blocks embedding in this browser, use the Open Canva button.</div>
    </div>
  <?php } ?>

    <?php if ($showEmptyBlock) { ?>
        <div class="ppt-empty">No slides are configured for this activity.</div>
    <?php } elseif ($showSlidesBlock) { ?>
        <div class="ppt-stage">
            <div id="pptSlide" class="ppt-slide"></div>

            <div class="ppt-toolbar">
                <div class="ppt-toolbar-row" id="pptNavRow">
                    <button type="button" class="ppt-btn ppt-btn-light" id="btnPrev">&#9664; Anterior</button>
                    <span class="ppt-count" id="pptCounter"></span>
                    <button type="button" class="ppt-btn ppt-btn-primary" id="btnNext">Siguiente &#9654;</button>
                    <?php if ($nextUrl !== '') { ?>
                        <a class="ppt-btn ppt-btn-next-act" style="text-decoration:none;display:inline-flex;align-items:center;" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Siguiente actividad &#9654;</a>
                    <?php } ?>
                </div>
                <div class="ppt-toolbar-row" id="pptTtsRow" style="display:none">
                    <button type="button" class="ppt-btn ppt-btn-mint" id="btnTts">&#128266; Leer</button>
                    <button type="button" class="ppt-btn ppt-btn-stop" id="btnStopTts">&#9209; Detener</button>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<div id="ppt-completed-screen" class="ppt-completed-screen">
    <div class="ppt-completed-icon">✅</div>
    <h2 class="ppt-completed-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
    <p class="ppt-completed-text">You've reviewed all the slides. Great job!</p>
    <div class="ppt-completed-actions">
        <button type="button" class="ppt-completed-btn" id="ppt-restart-btn">Back to Slides</button>
    </div>
</div>

<script>
const PPT_SLIDES = <?php echo json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_PRESENTATION_FILE = <?php echo json_encode($presentationFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_PRESENTATION_NAME = <?php echo json_encode($presentationName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const PPT_CANVA_LINK = <?php echo json_encode($canvaLink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
  const template    = ['title_text','text_image','image_full'].includes(slide.template) ? slide.template : 'title_text';
  const bg          = /^#[0-9a-fA-F]{6}$/.test(String(slide.bg_color||'')) ? slide.bg_color : '#FFFFFF';
  const fontFamily  = ['Arial','Georgia','Verdana','Tahoma','Times New Roman','Courier New','Trebuchet MS','Impact'].includes(slide.font_family) ? slide.font_family : 'Arial';
  const fontSize    = Math.max(12, Math.min(72, Number(slide.font_size  || 28)));
  const titleSize   = Math.max(16, Math.min(96, Number(slide.title_size || 36)));
  const titleColor  = /^#[0-9a-fA-F]{6}$/i.test(String(slide.title_color||'')) ? slide.title_color : '#1e3a5f';
  const textColor   = /^#[0-9a-fA-F]{6}$/i.test(String(slide.text_color||''))  ? slide.text_color  : '#334155';
  const textAlign   = ['left','center','right'].includes(slide.text_align)  ? slide.text_align  : 'left';
  const titleAlign  = ['left','center','right'].includes(slide.title_align) ? slide.title_align : 'center';
  const fw          = slide.bold   ? '800' : '600';
  const fi          = slide.italic ? 'italic' : 'normal';
  const imgPct      = Math.max(20, Math.min(100, Number(slide.image_size||50))) + '%';
  const imgPos      = ['right','left','top','bottom'].includes(slide.image_position) ? slide.image_position : 'right';

  stage.className = 'ppt-slide template-' + template;
  stage.style.background = bg;

  const hasImage  = !!slide.image;
  const imgTag    = hasImage ? '<img class="ppt-image" src="' + slide.image + '" alt="Slide image" style="max-width:'+imgPct+';max-height:'+imgPct+'">' : '';
  const imgWrap   = hasImage ? '<div class="ppt-image-wrap">'+imgTag+'</div>' : '';
  const titleHtml = slide.title ? '<h2 class="ppt-slide-title" style="width:100%;box-sizing:border-box;font-family:'+escapeHtml(fontFamily)+';font-size:'+titleSize+'px;color:'+titleColor+';text-align:'+titleAlign+'">'+escapeHtml(slide.title)+'</h2>' : '';
  const textHtml  = slide.text  ? '<p  class="ppt-slide-text"  style="width:100%;box-sizing:border-box;font-family:'+escapeHtml(fontFamily)+';font-size:'+fontSize+'px;color:'+textColor+';text-align:'+textAlign+';font-weight:'+fw+';font-style:'+fi+'">'+escapeHtml(slide.text)+'</p>' : '';

  if (template === 'text_image') {
    const textCol = '<div class="ppt-col-text">'+titleHtml+textHtml+'</div>';
    const imgCol  = '<div class="ppt-image-wrap" style="flex-basis:'+imgPct+'">'+imgTag+'</div>';
    if (imgPos === 'left') {
      stage.innerHTML = imgCol + textCol;
    } else if (imgPos === 'top') {
      stage.style.flexDirection = 'column';
      stage.innerHTML = imgWrap + '<div class="ppt-col-text">'+titleHtml+textHtml+'</div>';
    } else if (imgPos === 'bottom') {
      stage.style.flexDirection = 'column';
      stage.innerHTML = '<div class="ppt-col-text">'+titleHtml+textHtml+'</div>' + imgWrap;
    } else {
      stage.innerHTML = textCol + imgCol;
    }
  } else {
    /* title_text and image_full */
    stage.innerHTML = '<div class="ppt-col-text">'+titleHtml+textHtml+'</div>' + imgWrap;
  }

  counter.textContent = 'Slide ' + (slideIndex + 1) + ' / ' + PPT_SLIDES.length;

  // Show TTS buttons only when the slide has tts_text configured
  const ttsRow = document.getElementById('pptTtsRow');
  if (ttsRow) {
    ttsRow.style.display = (slide.tts_text && String(slide.tts_text).trim()) ? 'flex' : 'none';
  }

  // Stop any currently playing audio when navigating
  if (currentAudio) {
    currentAudio.pause();
    currentAudio = null;
  }

  // Add a play button inside the slide if music is attached (no auto-play)
  if (slide.music) {
    const audioWrap = document.createElement('div');
    audioWrap.className = 'ppt-slide-audio-wrap';
    const musicLabel = (slide.music_name && String(slide.music_name).trim())
      ? escapeHtml(String(slide.music_name).trim())
      : '🎵 Audio';
    const audioBtn = document.createElement('button');
    audioBtn.type = 'button';
    audioBtn.className = 'ppt-slide-audio-btn';
    audioBtn.innerHTML = '▶ ' + musicLabel;
    audioBtn.addEventListener('click', function () {
      if (!currentAudio) {
        currentAudio = new Audio(slide.music);
        currentAudio.onended = () => {
          audioBtn.classList.remove('playing');
          audioBtn.innerHTML = '▶ ' + musicLabel;
        };
      }
      if (currentAudio.paused) {
        currentAudio.play().catch(() => {});
        audioBtn.classList.add('playing');
        audioBtn.innerHTML = '⏸ ' + musicLabel;
      } else {
        currentAudio.pause();
        audioBtn.classList.remove('playing');
        audioBtn.innerHTML = '▶ ' + musicLabel;
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
  if (!textToRead) {
    return;
  }

  const ttsLang = (String(slide.tts_lang || '').trim() || 'en-US');
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
  if (!PPT_CANVA_LINK) return;
  let openUrl = PPT_CANVA_LINK;
  try {
    const parsedUrl = new URL(PPT_CANVA_LINK);
    parsedUrl.searchParams.delete('embed');
    openUrl = parsedUrl.toString();
  } catch (error) {
  }
  window.open(openUrl, '_blank');
}

function stopSpeech() {
  speechSynthesis.cancel();
}

document.getElementById('btnPrev')?.addEventListener('click', () => {
  if (!PPT_SLIDES.length) return;
  slideIndex = Math.max(0, slideIndex - 1);
  renderSlide();
});

document.getElementById('btnNext')?.addEventListener('click', () => {
  if (!PPT_SLIDES.length) return;
  if (slideIndex >= PPT_SLIDES.length - 1) {
    // Last slide — show completed screen
    const stageEl = document.querySelector('.ppt-stage');
    const completedEl = document.getElementById('ppt-completed-screen');
    if (stageEl) stageEl.style.display = 'none';
    if (completedEl) completedEl.classList.add('active');
    return;
  }
  slideIndex = Math.min(PPT_SLIDES.length - 1, slideIndex + 1);
  renderSlide();
});

document.getElementById('ppt-restart-btn')?.addEventListener('click', () => {
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

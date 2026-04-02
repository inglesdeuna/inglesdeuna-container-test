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
    if ($host === '' || strpos($host, 'canva.com') === false) {
      return $value;
    }

    $path = (string) ($parts['path'] ?? '');
    if ($path !== '' && preg_match('#/edit/?$#i', $path)) {
      $path = preg_replace('#/edit/?$#i', '/view', $path);
    }

    $query = [];
    if (!empty($parts['query'])) {
      parse_str((string) $parts['query'], $query);
    }
    $query['embed'] = '1';

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
    $normalized = $scheme . '://' . $host . $path;
    if (!empty($query)) {
      $normalized .= '?' . http_build_query($query);
    }

    return $normalized;
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

            $slides[] = [
                'template' => normalize_template((string) ($slide['template'] ?? 'title_text')),
                'title' => trim((string) ($slide['title'] ?? '')),
                'text' => trim((string) ($slide['text'] ?? '')),
                'font_family' => normalize_font_family((string) ($slide['font_family'] ?? 'Arial')),
                'font_size' => $fontSize,
                'bg_color' => normalize_color((string) ($slide['bg_color'] ?? '#FFFFFF')),
                'image' => trim((string) ($slide['image'] ?? '')),
                'music' => trim((string) ($slide['music'] ?? '')),
                'tts_text' => trim((string) ($slide['tts_text'] ?? '')),
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
.ppt-col-text{display:flex;flex-direction:column;gap:10px}
.ppt-slide-title{margin:0;color:#5b21b6;font-family:'Fredoka','Trebuchet MS',sans-serif;font-weight:800;line-height:1.08;text-align:center}
.ppt-slide-text{margin:0;color:#334155;line-height:1.7;white-space:pre-wrap}
.ppt-image-wrap{width:100%;display:flex;justify-content:center;align-items:center}
.ppt-image{max-width:100%;max-height:420px;border-radius:18px;object-fit:contain;border:1px solid rgba(15,23,42,.08);background:#fff;box-shadow:0 10px 22px rgba(15,23,42,.08)}
.ppt-toolbar{display:flex;gap:10px;flex-wrap:wrap;padding:14px 16px;border-top:1px solid #ddd6fe;background:linear-gradient(180deg,#faf5ff 0%,#f0fdfa 100%);align-items:center;justify-content:space-between}
.ppt-actions{display:flex;gap:8px;flex-wrap:wrap}
.ppt-btn{border:none;border-radius:999px;padding:11px 16px;font-weight:800;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;cursor:pointer;box-shadow:0 10px 22px rgba(15,23,42,.12);transition:transform .15s ease,filter .15s ease;text-decoration:none}
.ppt-btn:hover{filter:brightness(1.04);transform:translateY(-1px)}
.ppt-btn-primary{background:linear-gradient(180deg,#8b5cf6 0%,#7c3aed 100%);color:#fff}
.ppt-btn-light{background:linear-gradient(180deg,#f59eb2 0%,#ec4899 100%);color:#fff}
.ppt-btn-mint{background:linear-gradient(180deg,#5eead4 0%,#14b8a6 100%);color:#083344}
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
  .ppt-toolbar{flex-direction:column;align-items:stretch}
  .ppt-actions{width:100%}
  .ppt-btn{width:100%;justify-content:center;text-align:center}
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
                <div class="ppt-actions">
                    <button type="button" class="ppt-btn ppt-btn-light" id="btnPrev">Previous</button>
                    <button type="button" class="ppt-btn ppt-btn-primary" id="btnNext">Next</button>
                    <button type="button" class="ppt-btn ppt-btn-mint" id="btnTts">Read Aloud</button>
                    <button type="button" class="ppt-btn ppt-btn-light" id="btnStopTts">Stop Reading</button>
                </div>

                <div class="ppt-actions">
                    <span class="ppt-count" id="pptCounter"></span>
                    <?php if ($nextUrl !== '') { ?>
                        <a class="ppt-btn ppt-btn-primary" style="text-decoration:none;display:inline-flex;align-items:center;" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Next Activity</a>
                    <?php } ?>
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
  const template = ['title_text','text_image','image_full'].includes(slide.template) ? slide.template : 'title_text';
  const backgroundColor = /^#[0-9a-fA-F]{6}$/.test(String(slide.bg_color || '')) ? slide.bg_color : '#FFFFFF';
  const fontFamily = ['Arial','Georgia','Verdana','Tahoma','Times New Roman'].includes(slide.font_family) ? slide.font_family : 'Arial';
  const fontSize = Math.max(14, Math.min(72, Number(slide.font_size || 28)));

  stage.className = 'ppt-slide template-' + template;
  stage.style.background = backgroundColor;

  const hasImage = !!slide.image;
  const imageHtml = hasImage ? '<div class="ppt-image-wrap"><img class="ppt-image" src="' + slide.image + '" alt="Slide image"></div>' : '';

  if (template === 'image_full') {
    stage.innerHTML = '' +
      '<div class="ppt-col-text">' +
        '<h2 class="ppt-slide-title" style="font-family:' + escapeHtml(fontFamily) + ';font-size:' + fontSize + 'px;">' + escapeHtml(slide.title || '') + '</h2>' +
        '<p class="ppt-slide-text" style="font-family:' + escapeHtml(fontFamily) + ';font-size:' + Math.max(14, fontSize - 8) + 'px;">' + escapeHtml(slide.text || '') + '</p>' +
      '</div>' +
      imageHtml;
  } else if (template === 'text_image') {
    stage.innerHTML = '' +
      '<div class="ppt-col-text">' +
        '<h2 class="ppt-slide-title" style="font-family:' + escapeHtml(fontFamily) + ';font-size:' + fontSize + 'px;">' + escapeHtml(slide.title || '') + '</h2>' +
        '<p class="ppt-slide-text" style="font-family:' + escapeHtml(fontFamily) + ';font-size:' + Math.max(14, fontSize - 8) + 'px;">' + escapeHtml(slide.text || '') + '</p>' +
      '</div>' +
      '<div>' + imageHtml + '</div>';
  } else {
    stage.innerHTML = '' +
      '<div class="ppt-col-text">' +
        '<h2 class="ppt-slide-title" style="font-family:' + escapeHtml(fontFamily) + ';font-size:' + fontSize + 'px;">' + escapeHtml(slide.title || '') + '</h2>' +
        '<p class="ppt-slide-text" style="font-family:' + escapeHtml(fontFamily) + ';font-size:' + Math.max(14, fontSize - 8) + 'px;">' + escapeHtml(slide.text || '') + '</p>' +
      '</div>' +
      imageHtml;
  }

  counter.textContent = 'Slide ' + (slideIndex + 1) + ' / ' + PPT_SLIDES.length;

  if (currentAudio) {
    currentAudio.pause();
    currentAudio = null;
  }

  if (slide.music) {
    currentAudio = new Audio(slide.music);
    currentAudio.play().catch(() => {});
  }
}

function speakSlide() {
  if (!Array.isArray(PPT_SLIDES) || !PPT_SLIDES.length) return;

  const slide = PPT_SLIDES[slideIndex] || {};
  const textToRead = String(slide.tts_text || '').trim() || String(slide.text || '').trim() || String(slide.title || '').trim();
  if (!textToRead) {
    return;
  }

  const utterance = new SpeechSynthesisUtterance(textToRead);
  utterance.lang = 'en-US';
  utterance.rate = 1;
  const selectedVoice = getPreferredEnglishFemaleVoice();
  if (selectedVoice) {
    utterance.voice = selectedVoice;
  }
  speechSynthesis.cancel();
  speechSynthesis.speak(utterance);
}

function getPreferredEnglishFemaleVoice() {
  const voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
  if (!Array.isArray(voices) || voices.length === 0) {
    return null;
  }

  const englishVoices = voices.filter((voice) => /^en(-|_)/i.test(String(voice.lang || '')) || /english/i.test(String(voice.name || '')));
  if (!englishVoices.length) {
    return voices[0] || null;
  }

  const femaleHints = ['female', 'woman', 'zira', 'samantha', 'karen', 'aria', 'jenny', 'emma', 'olivia', 'ava'];
  const femaleVoice = englishVoices.find((voice) => {
    const label = (String(voice.name || '') + ' ' + String(voice.voiceURI || '')).toLowerCase();
    return femaleHints.some((hint) => label.includes(hint));
  });

  return femaleVoice || englishVoices[0];
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

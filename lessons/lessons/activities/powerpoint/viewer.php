<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$nextUrl = isset($_GET['next']) ? trim((string) $_GET['next']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
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

function normalize_powerpoint_payload($rawData): array
{
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    $title = default_powerpoint_title();
    $slides = [];

    if (is_array($decoded)) {
        $rawTitle = trim((string) ($decoded['title'] ?? ''));
        if ($rawTitle !== '') {
            $title = $rawTitle;
        }

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
    ];
}

function load_powerpoint_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'title' => default_powerpoint_title(),
        'slides' => [],
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

ob_start();
?>

<style>
.ppt-viewer-shell{max-width:1100px;margin:0 auto}
.ppt-stage{background:#fff;border:1px solid #dbe4f0;border-radius:18px;overflow:hidden;box-shadow:0 10px 24px rgba(15,23,42,.1)}
.ppt-slide{min-height:520px;padding:26px;display:flex;gap:18px;align-items:flex-start}
.ppt-slide.template-title_text{flex-direction:column}
.ppt-slide.template-text_image{display:grid;grid-template-columns:1.1fr .9fr}
.ppt-slide.template-image_full{display:flex;flex-direction:column}
.ppt-col-text{display:flex;flex-direction:column;gap:10px}
.ppt-slide-title{margin:0;color:#0f172a;font-weight:800;line-height:1.15}
.ppt-slide-text{margin:0;color:#1e293b;line-height:1.6;white-space:pre-wrap}
.ppt-image-wrap{width:100%;display:flex;justify-content:center;align-items:center}
.ppt-image{max-width:100%;max-height:420px;border-radius:12px;object-fit:contain;border:1px solid rgba(15,23,42,.12);background:#fff}
.ppt-toolbar{display:flex;gap:10px;flex-wrap:wrap;padding:12px 14px;border-top:1px solid #dbe4f0;background:#f8fbff;align-items:center;justify-content:space-between}
.ppt-actions{display:flex;gap:8px;flex-wrap:wrap}
.ppt-btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.ppt-btn-primary{background:#2563eb;color:#fff}
.ppt-btn-light{background:#e2e8f0;color:#0f172a}
.ppt-count{font-weight:700;color:#334155}
.ppt-empty{background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:28px;text-align:center;color:#b91c1c;font-weight:700}
@media (max-width: 900px){
  .ppt-slide{padding:16px;min-height:420px}
  .ppt-slide.template-text_image{grid-template-columns:1fr}
}
</style>

<div class="ppt-viewer-shell">
    <?php if (empty($slides)) { ?>
        <div class="ppt-empty">No hay diapositivas configuradas en esta actividad.</div>
    <?php } else { ?>
        <div class="ppt-stage">
            <div id="pptSlide" class="ppt-slide"></div>

            <div class="ppt-toolbar">
                <div class="ppt-actions">
                    <button type="button" class="ppt-btn ppt-btn-light" id="btnPrev">Anterior</button>
                    <button type="button" class="ppt-btn ppt-btn-primary" id="btnNext">Siguiente</button>
                    <button type="button" class="ppt-btn ppt-btn-light" id="btnTts">Leer voz (TTS)</button>
                    <button type="button" class="ppt-btn ppt-btn-light" id="btnStopTts">Detener voz</button>
                </div>

                <div class="ppt-actions">
                    <span class="ppt-count" id="pptCounter"></span>
                    <?php if ($nextUrl !== '') { ?>
                        <a class="ppt-btn ppt-btn-primary" style="text-decoration:none;display:inline-flex;align-items:center;" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Siguiente actividad</a>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<script>
const PPT_SLIDES = <?php echo json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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

  counter.textContent = 'Diapositiva ' + (slideIndex + 1) + ' / ' + PPT_SLIDES.length;

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
  utterance.lang = 'es-CO';
  utterance.rate = 1;
  speechSynthesis.cancel();
  speechSynthesis.speak(utterance);
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
  slideIndex = Math.min(PPT_SLIDES.length - 1, slideIndex + 1);
  renderSlide();
});

document.getElementById('btnTts')?.addEventListener('click', speakSlide);
document.getElementById('btnStopTts')?.addEventListener('click', stopSpeech);

if (Array.isArray(PPT_SLIDES) && PPT_SLIDES.length) {
  renderSlide();
}
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🖥️', $content);

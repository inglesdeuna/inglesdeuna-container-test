<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

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
        'template' => 'title_text',
        'title' => '',
        'text' => '',
        'font_family' => 'Arial',
        'font_size' => 28,
        'bg_color' => '#ffffff',
        'image' => '',
        'music' => '',
        'tts_text' => '',
    ];
}

function normalize_font_family(string $value): string
{
    $allowed = ['Arial', 'Georgia', 'Verdana', 'Tahoma', 'Times New Roman'];
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

            $slides[] = [
                'template' => normalize_template((string) ($slide['template'] ?? 'title_text')),
                'title' => trim((string) ($slide['title'] ?? '')),
                'text' => trim((string) ($slide['text'] ?? '')),
                'font_family' => normalize_font_family((string) ($slide['font_family'] ?? 'Arial')),
                'font_size' => $fontSize,
                'bg_color' => normalize_color((string) ($slide['bg_color'] ?? '#FFFFFF')),
                'image' => normalize_data_blob((string) ($slide['image'] ?? ''), 8 * 1024 * 1024),
                'music' => normalize_data_blob((string) ($slide['music'] ?? ''), 18 * 1024 * 1024),
                'tts_text' => trim((string) ($slide['tts_text'] ?? '')),
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
    die('Unidad no especificada');
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
.ppt-editor-shell{max-width:980px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.ppt-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;box-shadow:0 8px 24px rgba(15,23,42,.06)}
.ppt-row{display:flex;gap:10px;flex-wrap:wrap}
.ppt-row > *{flex:1 1 180px}
.ppt-label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#334155}
.ppt-input,.ppt-select,.ppt-textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff}
.ppt-textarea{min-height:96px;resize:vertical}
.ppt-actions{display:flex;gap:10px;flex-wrap:wrap}
.ppt-btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.ppt-btn-primary{background:#2563eb;color:#fff}
.ppt-btn-secondary{background:#0f766e;color:#fff}
.ppt-btn-danger{background:#dc2626;color:#fff}
.ppt-btn-light{background:#e2e8f0;color:#1e293b}
.ppt-slide{border:1px solid #dbe4f0;border-radius:14px;padding:14px;background:#f8fbff;display:flex;flex-direction:column;gap:10px}
.ppt-slide-head{display:flex;justify-content:space-between;align-items:center;gap:10px}
.ppt-preview-media{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ppt-preview-image{max-width:180px;max-height:110px;border-radius:8px;border:1px solid #cbd5e1;object-fit:cover}
.ppt-success{background:#ecfdf5;border:1px solid #86efac;color:#166534;border-radius:10px;padding:10px 12px;font-weight:700}
</style>

<div class="ppt-editor-shell">
    <?php if (isset($_GET['saved']) && $_GET['saved'] === '1') { ?>
        <div class="ppt-success">Actividad guardada correctamente.</div>
    <?php } ?>

    <form id="powerpointForm" method="post">
        <input type="hidden" name="slides_payload" id="slides_payload" value="[]">
      <input type="hidden" name="presentation_payload" id="presentation_payload" value="{}">

        <div class="ppt-card">
            <label class="ppt-label" for="activity_title">Titulo de la actividad</label>
            <input class="ppt-input" id="activity_title" name="activity_title" value="<?php echo htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="ppt-card">
            <div class="ppt-actions" style="margin-bottom:12px;">
                <select id="newSlideTemplate" class="ppt-select" style="max-width:240px;">
                    <option value="title_text">Template: Titulo + Texto</option>
                    <option value="text_image">Template: Texto + Imagen</option>
                    <option value="image_full">Template: Imagen completa</option>
                </select>
                <button type="button" class="ppt-btn ppt-btn-secondary" id="btnAddSlide">+ Agregar diapositiva</button>
            </div>

            <div id="slidesContainer"></div>
        </div>

          <div class="ppt-card">
            <label class="ppt-label">Upload full presentation (PowerPoint or Canva export)</label>
            <input class="ppt-input" id="pptFileInput" type="file" accept=".ppt,.pptx,.pdf,.canva,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/pdf">
            <div class="ppt-preview-media" style="margin-top:10px;" id="pptFileStatus"></div>
          </div>

          <div class="ppt-card">
            <label class="ppt-label" for="canva_link">Canva share link (optional)</label>
            <input class="ppt-input" id="canva_link" name="canva_link" placeholder="https://www.canva.com/..." value="<?php echo htmlspecialchars($canvaLink, ENT_QUOTES, 'UTF-8'); ?>">
          </div>

        <div class="ppt-card">
            <div class="ppt-actions">
                <button type="submit" class="ppt-btn ppt-btn-primary">Guardar PowerPoint</button>
            </div>
        </div>
    </form>
</div>

<script>
const INITIAL_SLIDES = <?php echo json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const INITIAL_PRESENTATION = {
  file: <?php echo json_encode($presentationFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
  name: <?php echo json_encode($presentationName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};
const FONT_OPTIONS = ['Arial','Georgia','Verdana','Tahoma','Times New Roman'];

function createSlideModel(templateName) {
  return {
    template: templateName || 'title_text',
    title: '',
    text: '',
    font_family: 'Arial',
    font_size: 28,
    bg_color: '#FFFFFF',
    image: '',
    music: '',
    tts_text: ''
  };
}

let slidesState = Array.isArray(INITIAL_SLIDES) && INITIAL_SLIDES.length ? INITIAL_SLIDES : [createSlideModel('title_text')];
let presentationState = {
  file: String(INITIAL_PRESENTATION.file || ''),
  name: String(INITIAL_PRESENTATION.name || '')
};

function fileToDataUrl(fileObject) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('No fue posible leer el archivo.'));
    reader.readAsDataURL(fileObject);
  });
}

function normalizeSlideState(slideData) {
  const fontSize = Number(slideData.font_size || 28);
  return {
    template: ['title_text','text_image','image_full'].includes(slideData.template) ? slideData.template : 'title_text',
    title: String(slideData.title || ''),
    text: String(slideData.text || ''),
    font_family: FONT_OPTIONS.includes(slideData.font_family) ? slideData.font_family : 'Arial',
    font_size: Math.max(14, Math.min(72, Number.isFinite(fontSize) ? fontSize : 28)),
    bg_color: /^#[0-9a-fA-F]{6}$/.test(String(slideData.bg_color || '')) ? String(slideData.bg_color).toUpperCase() : '#FFFFFF',
    image: String(slideData.image || ''),
    music: String(slideData.music || ''),
    tts_text: String(slideData.tts_text || '')
  };
}

function renderSlides() {
  const container = document.getElementById('slidesContainer');
  container.innerHTML = '';

  slidesState.forEach((slideData, index) => {
    const slide = normalizeSlideState(slideData);
    slidesState[index] = slide;

    const card = document.createElement('div');
    card.className = 'ppt-slide';

    const fontOptionsHtml = FONT_OPTIONS.map((fontValue) => {
      const selected = slide.font_family === fontValue ? 'selected' : '';
      return '<option value="' + fontValue + '" ' + selected + '>' + fontValue + '</option>';
    }).join('');

    const imagePreviewHtml = slide.image ? '<img class="ppt-preview-image" src="' + slide.image + '" alt="Imagen slide">' : '<span style="color:#64748b;font-size:13px;">Sin imagen</span>';
    const audioPreviewHtml = slide.music ? '<audio controls preload="none" src="' + slide.music + '" style="max-width:220px;"></audio>' : '<span style="color:#64748b;font-size:13px;">Sin audio</span>';

    card.innerHTML = '' +
      '<div class="ppt-slide-head">' +
        '<strong>Diapositiva ' + (index + 1) + '</strong>' +
        '<div class="ppt-actions">' +
          '<button type="button" class="ppt-btn ppt-btn-light" data-action="up">Subir</button>' +
          '<button type="button" class="ppt-btn ppt-btn-light" data-action="down">Bajar</button>' +
          '<button type="button" class="ppt-btn ppt-btn-danger" data-action="remove">Eliminar</button>' +
        '</div>' +
      '</div>' +
      '<div class="ppt-row">' +
        '<div><label class="ppt-label">Template</label><select class="ppt-select" data-field="template"><option value="title_text" ' + (slide.template === 'title_text' ? 'selected' : '') + '>Titulo + Texto</option><option value="text_image" ' + (slide.template === 'text_image' ? 'selected' : '') + '>Texto + Imagen</option><option value="image_full" ' + (slide.template === 'image_full' ? 'selected' : '') + '>Imagen completa</option></select></div>' +
        '<div><label class="ppt-label">Titulo de diapositiva</label><input class="ppt-input" data-field="title" value="' + escapeHtml(slide.title) + '"></div>' +
      '</div>' +
      '<div class="ppt-row">' +
        '<div><label class="ppt-label">Fuente</label><select class="ppt-select" data-field="font_family">' + fontOptionsHtml + '</select></div>' +
        '<div><label class="ppt-label">Tamaño fuente</label><input class="ppt-input" type="number" min="14" max="72" data-field="font_size" value="' + slide.font_size + '"></div>' +
        '<div><label class="ppt-label">Color fondo</label><input class="ppt-input" type="color" data-field="bg_color" value="' + slide.bg_color + '"></div>' +
      '</div>' +
      '<div><label class="ppt-label">Texto explicativo</label><textarea class="ppt-textarea" data-field="text">' + escapeHtml(slide.text) + '</textarea></div>' +
      '<div><label class="ppt-label">Text for English TTS (optional)</label><textarea class="ppt-textarea" data-field="tts_text">' + escapeHtml(slide.tts_text) + '</textarea></div>' +
      '<div class="ppt-row">' +
        '<div>' +
          '<label class="ppt-label">Imagen (subir foto)</label>' +
          '<input class="ppt-input" type="file" accept="image/*" data-upload="image">' +
          '<div class="ppt-preview-media" style="margin-top:8px;">' + imagePreviewHtml + '<button type="button" class="ppt-btn ppt-btn-light" data-action="clear-image">Quitar imagen</button></div>' +
        '</div>' +
        '<div>' +
          '<label class="ppt-label">Musica MP3 (subir audio)</label>' +
          '<input class="ppt-input" type="file" accept="audio/mpeg,audio/mp3,audio/*" data-upload="music">' +
          '<div class="ppt-preview-media" style="margin-top:8px;">' + audioPreviewHtml + '<button type="button" class="ppt-btn ppt-btn-light" data-action="clear-music">Quitar audio</button></div>' +
        '</div>' +
      '</div>';

    bindSlideCardEvents(card, index);
    container.appendChild(card);
  });
}

function bindSlideCardEvents(cardElement, slideIndex) {
  cardElement.querySelectorAll('[data-field]').forEach((fieldElement) => {
    fieldElement.addEventListener('input', () => {
      const key = fieldElement.getAttribute('data-field');
      slidesState[slideIndex][key] = fieldElement.value;
    });
    fieldElement.addEventListener('change', () => {
      const key = fieldElement.getAttribute('data-field');
      slidesState[slideIndex][key] = fieldElement.value;
    });
  });

  const imageInput = cardElement.querySelector('[data-upload="image"]');
  const musicInput = cardElement.querySelector('[data-upload="music"]');

  imageInput.addEventListener('change', async () => {
    const selectedFile = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;
    if (!selectedFile) return;
    if (!selectedFile.type.startsWith('image/')) {
      alert('El archivo de imagen no es valido.');
      imageInput.value = '';
      return;
    }

    try {
      const dataUrl = await fileToDataUrl(selectedFile);
      slidesState[slideIndex].image = dataUrl;
      renderSlides();
    } catch (error) {
      alert('No fue posible procesar la imagen.');
    }
  });

  musicInput.addEventListener('change', async () => {
    const selectedFile = musicInput.files && musicInput.files[0] ? musicInput.files[0] : null;
    if (!selectedFile) return;

    try {
      const dataUrl = await fileToDataUrl(selectedFile);
      slidesState[slideIndex].music = dataUrl;
      renderSlides();
    } catch (error) {
      alert('No fue posible procesar el audio.');
    }
  });

  cardElement.querySelectorAll('[data-action]').forEach((buttonElement) => {
    buttonElement.addEventListener('click', () => {
      const action = buttonElement.getAttribute('data-action');

      if (action === 'remove') {
        if (slidesState.length === 1) {
          alert('Debe existir al menos una diapositiva.');
          return;
        }
        slidesState.splice(slideIndex, 1);
        renderSlides();
        return;
      }

      if (action === 'up' && slideIndex > 0) {
        const temp = slidesState[slideIndex - 1];
        slidesState[slideIndex - 1] = slidesState[slideIndex];
        slidesState[slideIndex] = temp;
        renderSlides();
        return;
      }

      if (action === 'down' && slideIndex < slidesState.length - 1) {
        const temp = slidesState[slideIndex + 1];
        slidesState[slideIndex + 1] = slidesState[slideIndex];
        slidesState[slideIndex] = temp;
        renderSlides();
        return;
      }

      if (action === 'clear-image') {
        slidesState[slideIndex].image = '';
        renderSlides();
        return;
      }

      if (action === 'clear-music') {
        slidesState[slideIndex].music = '';
        renderSlides();
      }
    });
  });
}

function escapeHtml(rawValue) {
  return String(rawValue)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function renderPresentationStatus() {
  const status = document.getElementById('pptFileStatus');
  if (!status) return;

  if (!presentationState.file) {
    status.innerHTML = '<span style="color:#64748b;font-size:13px;">No uploaded PowerPoint/Canva file yet.</span>';
    return;
  }

  const fileName = escapeHtml(presentationState.name || 'presentacion.pptx');
  status.innerHTML = '' +
    '<span style="color:#0f172a;font-weight:700;">Uploaded file: ' + fileName + '</span>' +
    '<button type="button" class="ppt-btn ppt-btn-light" id="btnClearPresentation">Remove file</button>';

  const clearButton = document.getElementById('btnClearPresentation');
  if (clearButton) {
    clearButton.addEventListener('click', () => {
      presentationState = { file: '', name: '' };
      renderPresentationStatus();
    });
  }
}

document.getElementById('btnAddSlide').addEventListener('click', () => {
  const templateSelect = document.getElementById('newSlideTemplate');
  slidesState.push(createSlideModel(templateSelect.value));
  renderSlides();
});

document.getElementById('pptFileInput').addEventListener('change', async (event) => {
  const selectedFile = event.target.files && event.target.files[0] ? event.target.files[0] : null;
  if (!selectedFile) {
    return;
  }

  const lowerName = selectedFile.name.toLowerCase();
  const isAllowed =
    lowerName.endsWith('.ppt') ||
    lowerName.endsWith('.pptx') ||
    lowerName.endsWith('.pdf') ||
    lowerName.endsWith('.canva');

  if (!isAllowed) {
    alert('Allowed files: .ppt, .pptx, .pdf, .canva');
    event.target.value = '';
    return;
  }

  try {
    const dataUrl = await fileToDataUrl(selectedFile);
    presentationState = {
      file: dataUrl,
      name: selectedFile.name
    };
    renderPresentationStatus();
  } catch (error) {
    alert('Could not process the uploaded presentation file.');
  }
});

document.getElementById('powerpointForm').addEventListener('submit', (event) => {
  const normalizedSlides = slidesState.map(normalizeSlideState);
  document.getElementById('slides_payload').value = JSON.stringify(normalizedSlides);
  document.getElementById('presentation_payload').value = JSON.stringify(presentationState);

  if (!normalizedSlides.length) {
    event.preventDefault();
    alert('Debes crear al menos una diapositiva.');
  }
});

renderSlides();
renderPresentationStatus();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('PowerPoint Editor', 'fas fa-display', $content);

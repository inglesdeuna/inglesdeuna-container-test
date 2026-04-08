<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

/* 
   DATA LAYER
 */

function wp_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') { return ''; }
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && isset($row['unit_id'])) ? (string) $row['unit_id'] : '';
}

function wp_normalize_payload($rawData): array
{
    $default = ['title' => 'Writing Practice', 'description' => '', 'questions' => []];
    if ($rawData === null || $rawData === '') { return $default; }
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) { return $default; }

    $allowed   = ['writing', 'listen_write', 'fill_sentence', 'fill_paragraph', 'video_writing'];
    $questions = [];
    foreach ((array) ($decoded['questions'] ?? []) as $item) {
        if (!is_array($item)) { continue; }
        $type   = in_array($item['type'] ?? '', $allowed, true) ? (string) $item['type'] : 'writing';
        $rawAns = $item['correct_answers'] ?? [];
        $ans    = [];
        if (is_array($rawAns)) {
            foreach ($rawAns as $a) { $a = trim((string) $a); if ($a !== '') $ans[] = $a; }
        } elseif (is_string($rawAns) && $rawAns !== '') {
            $ans = array_values(array_filter(array_map('trim', explode("\n", $rawAns))));
        }
        $questions[] = [
            'id'              => trim((string) ($item['id']          ?? uniqid('wp_'))),
            'type'            => $type,
            'question'        => trim((string) ($item['question']    ?? '')),
            'instruction'     => trim((string) ($item['instruction'] ?? '')),
            'media'           => trim((string) ($item['media']       ?? '')),
            'correct_answers' => $ans,
            'points'          => 1,
        ];
    }
    return [
        'title'       => trim((string) ($decoded['title']       ?? '')) ?: $default['title'],
        'description' => trim((string) ($decoded['description'] ?? '')),
        'questions'   => $questions,
    ];
}

function wp_load_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = ['id' => '', 'payload' => wp_normalize_payload(null)];
    $row      = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'writing_practice' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) { return $fallback; }
    return ['id' => (string) ($row['id'] ?? ''), 'payload' => wp_normalize_payload($row['data'] ?? null)];
}

function wp_save_activity(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json     = json_encode(wp_normalize_payload($payload), JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;
    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }
    if ($targetId !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'writing_practice'");
        $stmt->execute(['data' => $json, 'id' => $targetId]);
        return $targetId;
    }
    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:unit_id, 'writing_practice', :data,
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id = :unit_id2),
            CURRENT_TIMESTAMP)
        RETURNING id
    ");
    $stmt->execute(['unit_id' => $unit, 'unit_id2' => $unit, 'data' => $json]);
    return (string) $stmt->fetchColumn();
}

/* 
   BOOTSTRAP
 */

if ($unit === '' && $activityId !== '') {
    $unit = wp_resolve_unit($pdo, $activityId);
}
if ($unit === '') { die('Unit not specified'); }

$loaded     = wp_load_activity($pdo, $unit, $activityId);
$payload    = $loaded['payload'];
if ($activityId === '' && !empty($loaded['id'])) { $activityId = (string) $loaded['id']; }

/* 
   HANDLE POST
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim((string) ($_POST['activity_title'] ?? ''));
    $description = trim((string) ($_POST['description']    ?? ''));
    $types       = isset($_POST['wp_type'])     && is_array($_POST['wp_type'])     ? $_POST['wp_type']     : [];
    $questions   = isset($_POST['wp_question']) && is_array($_POST['wp_question']) ? $_POST['wp_question'] : [];
    $instructions= isset($_POST['wp_instr'])    && is_array($_POST['wp_instr'])    ? $_POST['wp_instr']    : [];
    $mediasPost  = isset($_POST['wp_media'])    && is_array($_POST['wp_media'])    ? $_POST['wp_media']    : [];
    $answersList = isset($_POST['wp_answers'])  && is_array($_POST['wp_answers'])  ? $_POST['wp_answers']  : [];
    $videoFiles  = isset($_FILES['wp_video_file']) ? $_FILES['wp_video_file'] : null;
    $audioFiles  = isset($_FILES['wp_audio_file'])  ? $_FILES['wp_audio_file']  : null;

    $allowed = ['writing', 'listen_write', 'fill_sentence', 'fill_paragraph', 'video_writing'];
    $sanitized = [];
    foreach ($questions as $i => $qRaw) {
        $type   = in_array($types[$i] ?? '', $allowed, true) ? $types[$i] : 'writing';
        $q      = trim((string) $qRaw);
        $instr  = trim((string) ($instructions[$i] ?? ''));
        $media  = trim((string) ($mediasPost[$i]   ?? ''));
        $rawAns = trim((string) ($answersList[$i]  ?? ''));

        if ($type === 'video_writing' && $videoFiles
            && !empty($videoFiles['name'][$i])
            && !empty($videoFiles['tmp_name'][$i])) {
            $uploaded = upload_video_to_cloudinary($videoFiles['tmp_name'][$i]);
            if ($uploaded) { $media = $uploaded; }
        }
        if ($type === 'listen_write' && $audioFiles
            && !empty($audioFiles['name'][$i])
            && !empty($audioFiles['tmp_name'][$i])
            && ($audioFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = upload_audio_to_cloudinary($audioFiles['tmp_name'][$i]);
            if ($uploaded) { $media = $uploaded; }
        }

        if ($q === '' && $instr === '') { continue; }
        $ans = array_values(array_filter(array_map('trim', explode("\n", $rawAns))));
        $sanitized[] = [
            'id'              => 'wp_' . uniqid(),
            'type'            => $type,
            'question'        => $q,
            'instruction'     => $instr,
            'media'           => $media,
            'correct_answers' => $ans,
            'points'          => 1,
        ];
    }

    $savedId = wp_save_activity($pdo, $unit, $activityId, [
        'title'       => $title,
        'description' => $description,
        'questions'   => $sanitized,
    ]);

    $params = ['unit=' . urlencode($unit), 'saved=1'];
    if ($savedId    !== '') $params[] = 'id='         . urlencode($savedId);
    if ($assignment !== '') $params[] = 'assignment=' . urlencode($assignment);
    if ($source     !== '') $params[] = 'source='     . urlencode($source);
    header('Location: editor.php?' . implode('&', $params));
    exit;
}

$questions     = $payload['questions']   ?? [];
$activityTitle = $payload['title']       ?? 'Writing Practice';
$description   = $payload['description'] ?? '';

ob_start();
?>
<style>
.wp-form {
    max-width: 900px;
    margin: 0 auto;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
}
.wp-intro {
    background: linear-gradient(135deg, #fdf4ff 0%, #f0e8ff 52%, #fdf4ff 100%);
    border: 1px solid #e9d5ff;
    border-radius: 20px;
    padding: 18px 20px;
    margin: 0 0 14px;
    box-shadow: 0 12px 26px rgba(15,23,42,.08);
}
.wp-intro h3 {
    margin: 0 0 6px;
    font-size: 22px;
    font-weight: 700;
    color: #0f172a;
}
.wp-intro p { margin: 0; color: #475569; font-size: 14px; line-height: 1.5; }
.wp-title-box {
    background: #fff;
    padding: 14px;
    margin-bottom: 14px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 18px rgba(15,23,42,.04);
}
.wp-title-box label { display: block; font-weight: 800; margin-bottom: 6px; color: #1e293b; }
.wp-title-box input,
.wp-title-box textarea {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
    margin-bottom: 10px;
}
.wp-title-box textarea { min-height: 70px; resize: vertical; margin-bottom: 0; }
.wp-block {
    position: relative;
    overflow: hidden;
    background: linear-gradient(180deg, #fdf4ff 0%, #fff 100%);
    padding: 14px;
    margin-bottom: 12px;
    border-radius: 16px;
    border: 1px solid #e9d5ff;
    box-shadow: 0 10px 22px rgba(15,23,42,.06);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 10px;
}
.wp-block::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 7px;
    background: linear-gradient(90deg, #a855f7 0%, #7c3aed 100%);
}
.wp-block label { font-weight: 800; color: #0f172a; display: block; margin-bottom: 4px; font-size: 13px; }
.wp-block input,
.wp-block select,
.wp-block textarea {
    width: 100%;
    padding: 9px 11px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
}
.wp-block textarea { min-height: 80px; resize: vertical; }
.wp-col-full  { grid-column: span 2; }
.wp-col-half  { grid-column: span 1; }
.wp-audio-row { grid-column: span 2; display: none; }
.wp-video-row { grid-column: span 2; display: none; }
.wp-audio-row.visible,
.wp-video-row.visible { display: block; }
.wp-video-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.wp-block-num {
    font-size: 11px; font-weight: 800; color: #7c3aed;
    text-transform: uppercase; letter-spacing: .06em; grid-column: span 2; margin-top: 4px;
}
.btn-remove-wp {
    background: #ef4444; color: #fff; border: none;
    padding: 8px 12px; border-radius: 10px; cursor: pointer;
    font-weight: 800; font-family: inherit; grid-column: span 2; justify-self: end;
}
.actions-row {
    display: flex; gap: 10px; justify-content: center; margin-top: 10px; flex-wrap: wrap;
}
.btn-add-wp, .btn-save-wp {
    border: none; border-radius: 10px; cursor: pointer;
    font-weight: 800; font-family: inherit; padding: 10px 14px;
    transition: transform .15s, filter .15s;
}
.btn-add-wp:hover, .btn-save-wp:hover { filter: brightness(1.06); transform: translateY(-1px); }
.btn-add-wp  { background: #a855f7; color: #fff; }
.btn-save-wp { background: #7c3aed; color: #fff; }
.saved-notice {
    max-width: 900px; margin: 0 auto 14px; padding: 10px 12px;
    border-radius: 10px; border: 1px solid #c4b5fd;
    background: #f5f3ff; color: #5b21b6; font-weight: 800;
}
@media (max-width: 680px) { .wp-block { display: flex; flex-direction: column; } .wp-video-inner { grid-template-columns: 1fr; } }
</style>

<?php if (isset($_GET['saved'])): ?>
    <p class="saved-notice"> Guardado correctamente</p>
<?php endif; ?>

<form class="wp-form" id="wpForm" method="post" enctype="multipart/form-data">
    <section class="wp-intro">
        <h3>Writing Practice &mdash; Editor</h3>
        <p>Agrega una pregunta por bloque. Elige el tipo, escribe el enunciado y agrega las respuestas correctas (una por línea). Para escritura libre, deja las respuestas en blanco.</p>
    </section>

    <div class="wp-title-box">
        <label for="activity_title">Título de la actividad</label>
        <input id="activity_title" type="text" name="activity_title"
               value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Writing Practice" required>
        <label for="description">Instrucción general <span style="font-weight:400;">(opcional)</span></label>
        <textarea id="description" name="description"
                  placeholder="Ej: Lee cada pregunta y escribe tu respuesta."><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <div id="wpItems">
    <?php foreach ($questions as $i => $q): ?>
        <?php
        $type    = $q['type']        ?? 'writing';
        $qText   = $q['question']    ?? '';
        $instr   = $q['instruction'] ?? '';
        $media   = $q['media']       ?? '';
        $answers = implode("\n", $q['correct_answers'] ?? []);
        ?>
        <div class="wp-block">
            <span class="wp-block-num">Pregunta <?= $i + 1 ?></span>

            <div class="wp-col-full">
                <label>Tipo</label>
                <select name="wp_type[]" class="wp-type-select" onchange="wpToggleMedia(this)">
                    <option value="writing"        <?= $type==='writing'        ?'selected':'' ?>> Escritura libre</option>
                    <option value="listen_write"   <?= $type==='listen_write'   ?'selected':'' ?>> Escuchar y escribir</option>
                    <option value="fill_sentence"  <?= $type==='fill_sentence'  ?'selected':'' ?>> Completar oración</option>
                    <option value="fill_paragraph" <?= $type==='fill_paragraph' ?'selected':'' ?>> Completar párrafo</option>
                    <option value="video_writing"  <?= $type==='video_writing'  ?'selected':'' ?>> Video + escritura</option>
                </select>
            </div>

            <div class="wp-col-full">
                <label>Pregunta / enunciado</label>
                <textarea name="wp_question[]" rows="2"
                          placeholder="Escribe la pregunta o el enunciado aquí..."><?= htmlspecialchars($qText, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="wp-col-full">
                <label>Instrucción adicional <span style="font-weight:400;font-size:12px;">(opcional)</span></label>
                <input type="text" name="wp_instr[]"
                       value="<?= htmlspecialchars($instr, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Ej: Escribe al menos 3 oraciones completas.">
            </div>

            <!-- Audio row – shown for listen_write -->
            <div class="wp-audio-row<?= $type==='listen_write' ? ' visible' : '' ?>">
                <div class="wp-video-inner">
                    <div>
                        <label>🎧 URL del audio (MP3/OGG)</label>
                        <input type="url" name="wp_media[]"
                               value="<?= $type==='listen_write' ? htmlspecialchars($media, ENT_QUOTES, 'UTF-8') : '' ?>"
                               <?= $type!=='listen_write' ? 'disabled' : '' ?>
                               placeholder="https://example.com/audio.mp3">
                    </div>
                    <div>
                        <label>— o sube MP3/OGG</label>
                        <input type="file" name="wp_audio_file[]" accept="audio/*"
                               <?= $type!=='listen_write' ? 'disabled' : '' ?>>
                    </div>
                </div>
            </div>

            <!-- Video  only video_writing -->
            <div class="wp-video-row<?= $type==='video_writing' ? ' visible' : '' ?>">
                <div class="wp-video-inner">
                    <div>
                        <label> URL del video (YouTube / MP4)</label>
                        <input type="url" name="wp_media[]"
                               value="<?= $type==='video_writing' ? htmlspecialchars($media, ENT_QUOTES, 'UTF-8') : '' ?>"
                               <?= $type!=='video_writing' ? 'disabled' : '' ?>
                               placeholder="https://youtube.com/watch?v=...">
                    </div>
                    <div>
                        <label> o sube un video</label>
                        <input type="file" name="wp_video_file[]" accept="video/*"
                               <?= $type!=='video_writing' ? 'disabled' : '' ?>>
                    </div>
                </div>
            </div>

            <?php if ($type!=='video_writing' && $type!=='listen_write'): ?>
                <input type="hidden" name="wp_media[]" value="">
            <?php endif; ?>

            <div class="wp-col-full">
                <label>Respuestas correctas <span style="font-weight:400;font-size:12px;">(una por línea  deja vacío para escritura libre)</span></label>
                <textarea name="wp_answers[]" rows="3"
                          placeholder="Respuesta 1&#10;Variante aceptada&#10;Otra forma válida"><?= htmlspecialchars($answers, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <button type="button" class="btn-remove-wp" onclick="wpRemove(this)"> Eliminar</button>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="actions-row">
        <button type="button" class="btn-add-wp" onclick="wpAdd()">+ Agregar pregunta</button>
        <button type="submit" class="btn-save-wp"> Guardar</button>
    </div>
</form>

<script>
var wpCount = <?= count($questions) ?>;

function wpToggleMedia(select) {
    var block    = select.closest('.wp-block');
    var type     = select.value;
    var audioRow = block.querySelector('.wp-audio-row');
    var videoRow = block.querySelector('.wp-video-row');
    var hidden   = block.querySelector('input[type="hidden"][name="wp_media[]"]');

    if (audioRow) {
        audioRow.classList.remove('visible');
        audioRow.querySelectorAll('input').forEach(function(inp){ inp.disabled = true; });
    }
    if (videoRow) {
        videoRow.classList.remove('visible');
        videoRow.querySelectorAll('input').forEach(function(inp){ inp.disabled = true; });
    }
    if (hidden) { hidden.disabled = false; }

    if (type === 'listen_write' && audioRow) {
        audioRow.classList.add('visible');
        audioRow.querySelectorAll('input').forEach(function(inp){ inp.disabled = false; });
        if (hidden) { hidden.disabled = true; }
    }
    if (type === 'video_writing' && videoRow) {
        videoRow.classList.add('visible');
        videoRow.querySelectorAll('input').forEach(function(inp){ inp.disabled = false; });
        if (hidden) { hidden.disabled = true; }
    }
}

function wpAdd() {
    wpCount++;
    var container = document.getElementById('wpItems');
    var div = document.createElement('div');
    div.className = 'wp-block';
    div.innerHTML =
        '<span class="wp-block-num">Pregunta ' + wpCount + '</span>' +
        '<div class="wp-col-full"><label>Tipo</label>' +
        '<select name="wp_type[]" class="wp-type-select" onchange="wpToggleMedia(this)">' +
        '<option value="writing">\u270D\uFE0F Escritura libre</option>' +
        '<option value="listen_write">\uD83C\uDFA7 Escuchar y escribir</option>' +
        '<option value="fill_sentence">\uD83D\uDCDD Completar oraci\u00F3n</option>' +
        '<option value="fill_paragraph">\uD83D\uDCC4 Completar p\u00E1rrafo</option>' +
        '<option value="video_writing">\uD83C\uDFAC Video + escritura</option>' +
        '</select></div>' +
        '<div class="wp-col-full"><label>Pregunta / enunciado</label>' +
        '<textarea name="wp_question[]" rows="2" placeholder="Escribe la pregunta o el enunciado aqu\u00ED..."></textarea></div>' +
        '<div class="wp-col-full"><label>Instrucci\u00F3n adicional <span style="font-weight:400;font-size:12px;">(opcional)</span></label>' +
        '<input type="text" name="wp_instr[]" placeholder="Ej: Escribe al menos 3 oraciones completas."></div>' +
        '<div class="wp-audio-row"><div class="wp-video-inner">' +
        '<div><label>\uD83C\uDFA7 URL del audio (MP3/OGG)</label>' +
        '<input type="url" name="wp_media[]" disabled placeholder="https://example.com/audio.mp3"></div>' +
        '<div><label>\u2014 o sube MP3/OGG</label>' +
        '<input type="file" name="wp_audio_file[]" accept="audio/*" disabled></div>' +
        '</div></div>' +
        '<div class="wp-video-row"><div class="wp-video-inner">' +
        '<div><label>\uD83C\uDFAC URL del video (YouTube / MP4)</label>' +
        '<input type="url" name="wp_media[]" disabled placeholder="https://youtube.com/watch?v=..."></div>' +
        '<div><label>\u2014 o sube un video</label>' +
        '<input type="file" name="wp_video_file[]" accept="video/*" disabled></div>' +
        '</div></div>' +
        '<input type="hidden" name="wp_media[]" value="">' +
        '<div class="wp-col-full"><label>Respuestas correctas <span style="font-weight:400;font-size:12px;">(una por l\u00EDnea \u2014 deja vac\u00EDo para escritura libre)</span></label>' +
        '<textarea name="wp_answers[]" rows="3" placeholder="Respuesta 1&#10;Variante aceptada&#10;Otra forma v\u00E1lida"></textarea></div>' +
        '<button type="button" class="btn-remove-wp" onclick="wpRemove(this)">\u2716 Eliminar</button>';
    container.appendChild(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function wpRemove(btn) {
    var block = btn.closest('.wp-block');
    if (block) {
        block.remove();
        document.querySelectorAll('.wp-block .wp-block-num').forEach(function(span, idx) {
            span.textContent = 'Pregunta ' + (idx + 1);
        });
    }
}

document.querySelectorAll('.wp-type-select').forEach(function(sel) {
    wpToggleMedia(sel);
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Writing Practice &mdash; Editor', 'fas fa-pen-nib', $content);
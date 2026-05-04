<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

/* ── DATA LAYER ──────────────────────────────────────────── */

function wpe_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $s = $pdo->prepare("SELECT unit_id FROM activities WHERE id=:id LIMIT 1");
    $s->execute(['id' => $id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return ($r && isset($r['unit_id'])) ? (string)$r['unit_id'] : '';
}

function wpe_normalize(mixed $payload): array {
    $allowed = ['writing', 'video_writing'];
    $qs = [];
    foreach ((array)($payload['questions'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $type = in_array($item['type'] ?? '', $allowed, true) ? (string)$item['type'] : 'writing';
        $qs[] = [
            'id'             => trim((string)($item['id'] ?? uniqid('wp_'))),
            'type'           => $type,
            'question'       => trim((string)($item['question']    ?? '')),
            'instruction'    => trim((string)($item['instruction'] ?? '')),
            'media'          => trim((string)($item['media']       ?? '')),
            'writing_rows'   => max(2, min(14, (int)($item['writing_rows']   ?? 6))),
            'response_count' => max(1, min(20, (int)($item['response_count'] ?? 1))),
        ];
    }
    return [
        'title'       => trim((string)($payload['title']       ?? '')) ?: 'Writing Practice',
        'description' => trim((string)($payload['description'] ?? '')),
        'questions'   => $qs,
    ];
}

function wpe_load(PDO $pdo, string $unit, string $id): array {
    $fallback = ['id' => '', 'payload' => wpe_normalize([])];
    $row = null;
    if ($id !== '') {
        $s = $pdo->prepare("SELECT id, data FROM activities WHERE id=:id AND type='writing_practice' LIMIT 1");
        $s->execute(['id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $s = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id=:u AND type='writing_practice' ORDER BY id ASC LIMIT 1");
        $s->execute(['u' => $unit]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $raw = is_string($row['data'] ?? null) ? json_decode((string)$row['data'], true) : [];
    return ['id' => (string)$row['id'], 'payload' => wpe_normalize(is_array($raw) ? $raw : [])];
}

function wpe_save(PDO $pdo, string $unit, string $id, array $payload): string {
    $json = json_encode(wpe_normalize($payload), JSON_UNESCAPED_UNICODE);
    $target = $id;
    if ($target === '') {
        $s = $pdo->prepare("SELECT id FROM activities WHERE unit_id=:u AND type='writing_practice' ORDER BY id ASC LIMIT 1");
        $s->execute(['u' => $unit]);
        $target = trim((string)$s->fetchColumn());
    }
    if ($target !== '') {
        $s = $pdo->prepare("UPDATE activities SET data=:d WHERE id=:id AND type='writing_practice'");
        $s->execute(['d' => $json, 'id' => $target]);
        return $target;
    }
    $s = $pdo->prepare("INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (:u,'writing_practice',:d,(SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:u2),CURRENT_TIMESTAMP)
        RETURNING id");
    $s->execute(['u' => $unit, 'u2' => $unit, 'd' => $json]);
    return (string)$s->fetchColumn();
}

/* ── BOOTSTRAP ───────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') $unit = wpe_resolve_unit($pdo, $activityId);
if ($unit === '') die('Unit not specified');

$loaded     = wpe_load($pdo, $unit, $activityId);
$payload    = $loaded['payload'];
if ($activityId === '' && !empty($loaded['id'])) $activityId = $loaded['id'];

/* ── HANDLE POST ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowed    = ['writing', 'video_writing'];
    $types      = (array)($_POST['wp_type']      ?? []);
    $questions  = (array)($_POST['wp_question']  ?? []);
    $instrs     = (array)($_POST['wp_instr']      ?? []);
    $mediaUrls  = (array)($_POST['wp_media']      ?? []);
    $mediaOld   = (array)($_POST['wp_media_old']  ?? []);
    $rowsList   = (array)($_POST['wp_rows']        ?? []);
    $countList  = (array)($_POST['wp_count']       ?? []);
    $videoFiles = $_FILES['wp_video_file'] ?? null;

    $sanitized = [];
    foreach ($questions as $i => $qRaw) {
        $type  = in_array($types[$i] ?? '', $allowed, true) ? $types[$i] : 'writing';
        $q     = trim((string)$qRaw);
        $instr = trim((string)($instrs[$i] ?? ''));
        $media = '';

        if ($type === 'video_writing') {
            $media = trim((string)($mediaUrls[$i] ?? ''));
            if ($media === '') $media = trim((string)($mediaOld[$i] ?? ''));
            if ($videoFiles && !empty($videoFiles['name'][$i]) && !empty($videoFiles['tmp_name'][$i])) {
                $up = upload_video_to_cloudinary($videoFiles['tmp_name'][$i]);
                if ($up) $media = $up;
            }
        }

        if ($q === '' && $instr === '') continue;
        $sanitized[] = [
            'id'             => 'wp_' . uniqid(),
            'type'           => $type,
            'question'       => $q,
            'instruction'    => $instr,
            'media'          => $media,
            'writing_rows'   => max(2, min(14, (int)($rowsList[$i]  ?? 6))),
            'response_count' => max(1, min(20, (int)($countList[$i] ?? 1))),
        ];
    }

    $savedId = wpe_save($pdo, $unit, $activityId, [
        'title'       => trim((string)($_POST['activity_title'] ?? '')),
        'description' => trim((string)($_POST['description']    ?? '')),
        'questions'   => $sanitized,
    ]);

    $p = ['unit=' . urlencode($unit), 'saved=1'];
    if ($savedId    !== '') $p[] = 'id='         . urlencode($savedId);
    if ($assignment !== '') $p[] = 'assignment=' . urlencode($assignment);
    if ($source     !== '') $p[] = 'source='     . urlencode($source);
    header('Location: editor.php?' . implode('&', $p));
    exit;
}

$questions     = $payload['questions']   ?? [];
$activityTitle = $payload['title']       ?? 'Writing Practice';
$description   = $payload['description'] ?? '';

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --wp-purple:     #7F77DD;
    --wp-purple-dk:  #534AB7;
    --wp-purple-soft:#EEEDFE;
    --wp-orange:     #F97316;
    --wp-orange-dk:  #C2580A;
    --wp-ink:        #271B5D;
    --wp-muted:      #7C739B;
    --wp-shadow:     0 8px 28px rgba(83,74,183,.14);
}
*{box-sizing:border-box}
.wpe-shell{max-width:860px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif}
.wpe-saved{background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:10px 14px;
    color:#5b21b6;font-weight:800;margin-bottom:14px;text-align:center}
.wpe-intro{background:linear-gradient(135deg,#eeedfe 0%,#f3f0ff 100%);
    border:1px solid #c4b5fd;border-radius:20px;padding:18px 20px;margin-bottom:16px;
    box-shadow:var(--wp-shadow)}
.wpe-intro h3{margin:0 0 4px;font-family:'Fredoka',sans-serif;font-size:22px;color:var(--wp-purple-dk)}
.wpe-intro p{margin:0;color:var(--wp-muted);font-size:13px}
.wpe-section{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px;
    margin-bottom:14px;box-shadow:0 4px 14px rgba(15,23,42,.05)}
.wpe-label{display:block;font-weight:800;font-size:13px;color:var(--wp-ink);margin-bottom:5px}
.wpe-input,.wpe-textarea,.wpe-select{
    width:100%;padding:9px 12px;border-radius:10px;border:1px solid #d1d5db;
    font-size:14px;font-family:inherit;box-sizing:border-box}
.wpe-textarea{min-height:72px;resize:vertical}
.wpe-block{position:relative;background:linear-gradient(180deg,#fdf4ff,#fff);
    border:1px solid #e9d5ff;border-radius:16px;padding:16px;margin-bottom:12px;
    box-shadow:0 6px 18px rgba(15,23,42,.06);display:grid;grid-template-columns:1fr 1fr;gap:8px 12px}
.wpe-block::before{content:'';position:absolute;top:0;left:0;right:0;height:5px;
    background:linear-gradient(90deg,var(--wp-purple),var(--wp-purple-dk));border-radius:16px 16px 0 0}
.wpe-block-num{grid-column:span 2;font-size:11px;font-weight:900;color:var(--wp-purple);
    text-transform:uppercase;letter-spacing:.07em;margin-top:4px}
.wpe-full{grid-column:span 2}
.wpe-video-row{grid-column:span 2;display:none}
.wpe-video-row.show{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.wpe-rows-row{grid-column:span 2;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.wpe-hint{font-size:11px;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;
    border-radius:8px;padding:7px 10px;margin-top:4px;grid-column:span 2}
.wpe-btn-remove{background:#ef4444;color:#fff;border:none;padding:8px 12px;border-radius:10px;
    cursor:pointer;font-weight:800;font-family:inherit;grid-column:span 2;justify-self:end;font-size:13px}
.wpe-actions{display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap}
.wpe-btn{border:none;border-radius:10px;cursor:pointer;font-weight:800;font-family:inherit;
    padding:11px 18px;font-size:14px;transition:filter .15s,transform .15s}
.wpe-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.wpe-btn-add{background:var(--wp-purple);color:#fff}
.wpe-btn-save{background:var(--wp-purple-dk);color:#fff}
@media(max-width:640px){.wpe-block{display:flex;flex-direction:column}.wpe-video-row.show{grid-template-columns:1fr}}
</style>

<?php if (isset($_GET['saved'])): ?>
<p class="wpe-saved">✓ Guardado correctamente</p>
<?php endif; ?>

<form class="wpe-shell" id="wpeForm" method="post" enctype="multipart/form-data">

    <div class="wpe-intro">
        <h3>✍️ Writing Practice — Editor</h3>
        <p>Crea preguntas de <strong>Escritura libre</strong> (el estudiante escribe libremente) o <strong>Video + escritura</strong> (el estudiante ve un video y escribe).</p>
    </div>

    <div class="wpe-section">
        <label class="wpe-label" for="activity_title">Título de la actividad</label>
        <input id="activity_title" class="wpe-input" type="text" name="activity_title"
               value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Writing Practice" required>
        <label class="wpe-label" style="margin-top:12px" for="description">Instrucción general <span style="font-weight:400">(opcional)</span></label>
        <textarea id="description" class="wpe-textarea" name="description"
                  placeholder="Ej: Lee cada pregunta y escribe tu respuesta."><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <div id="wpeItems">
    <?php foreach ($questions as $i => $q):
        $type  = in_array((string)($q['type'] ?? 'writing'), ['writing','video_writing'], true) ? $q['type'] : 'writing';
        $qText = $q['question']    ?? '';
        $instr = $q['instruction'] ?? '';
        $media = $q['media']       ?? '';
        $wRows = max(2, min(14, (int)($q['writing_rows']   ?? 6)));
        $rCount= max(1, min(20, (int)($q['response_count'] ?? 1)));
    ?>
        <div class="wpe-block">
            <span class="wpe-block-num">Pregunta <?= $i + 1 ?></span>

            <div class="wpe-full">
                <label class="wpe-label">Tipo</label>
                <select class="wpe-select wpe-type-sel" name="wp_type[]" onchange="wpeToggle(this)">
                    <option value="writing"       <?= $type==='writing'       ?'selected':'' ?>>✍️ Escritura libre</option>
                    <option value="video_writing" <?= $type==='video_writing' ?'selected':'' ?>>🎬 Video + escritura</option>
                </select>
            </div>

            <div class="wpe-full">
                <label class="wpe-label">Pregunta / enunciado</label>
                <textarea class="wpe-textarea" name="wp_question[]" rows="2"
                          placeholder="Escribe la pregunta o el enunciado aquí..."><?= htmlspecialchars($qText, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="wpe-full">
                <label class="wpe-label">Instrucción adicional <span style="font-weight:400;font-size:12px">(opcional)</span></label>
                <input class="wpe-input" type="text" name="wp_instr[]"
                       value="<?= htmlspecialchars($instr, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Ej: Escribe al menos 3 oraciones completas.">
            </div>

            <div class="wpe-video-row <?= $type==='video_writing'?'show':'' ?>">
                <input type="hidden" name="wp_media_old[]" value="<?= htmlspecialchars($media, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label class="wpe-label">URL del video (YouTube / MP4)</label>
                    <input class="wpe-input" type="url" name="wp_media[]"
                           value="<?= $type==='video_writing' ? htmlspecialchars($media, ENT_QUOTES, 'UTF-8') : '' ?>"
                           <?= $type!=='video_writing'?'disabled':'' ?>
                           placeholder="https://youtube.com/watch?v=...">
                </div>
                <div>
                    <label class="wpe-label">O sube un video</label>
                    <input class="wpe-input" type="file" name="wp_video_file[]" accept="video/*"
                           <?= $type!=='video_writing'?'disabled':'' ?>>
                </div>
            </div>

            <div class="wpe-rows-row">
                <div>
                    <label class="wpe-label">Respuestas (cantidad)</label>
                    <input class="wpe-input" type="number" name="wp_count[]" min="1" max="20"
                           value="<?= htmlspecialchars((string)$rCount, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label class="wpe-label">Filas por respuesta</label>
                    <input class="wpe-input" type="number" name="wp_rows[]" min="2" max="14"
                           value="<?= htmlspecialchars((string)$wRows, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <button type="button" class="wpe-btn-remove" onclick="wpeRemove(this)">✕ Eliminar</button>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="wpe-actions">
        <button type="button" class="wpe-btn wpe-btn-add" onclick="wpeAdd()">+ Agregar pregunta</button>
        <button type="submit" class="wpe-btn wpe-btn-save">💾 Guardar</button>
    </div>
</form>

<script>
var wpeCount = <?= count($questions) ?>;

function wpeToggle(sel) {
    var block = sel.closest('.wpe-block');
    var type  = sel.value;
    var vRow  = block.querySelector('.wpe-video-row');
    var urlIn = block.querySelector('input[name="wp_media[]"]');
    var fileIn= block.querySelector('input[name="wp_video_file[]"]');

    if (type === 'video_writing') {
        vRow.classList.add('show');
        if (urlIn)  { urlIn.disabled  = false; }
        if (fileIn) { fileIn.disabled = false; }
    } else {
        vRow.classList.remove('show');
        if (urlIn)  { urlIn.disabled  = true; urlIn.value = ''; }
        if (fileIn) { fileIn.disabled = true; }
    }
}

function wpeBuildBlock(num) {
    return '<span class="wpe-block-num">Pregunta ' + num + '</span>'
        + '<div class="wpe-full"><label class="wpe-label">Tipo</label>'
        + '<select class="wpe-select wpe-type-sel" name="wp_type[]" onchange="wpeToggle(this)">'
        + '<option value="writing" selected>✍️ Escritura libre</option>'
        + '<option value="video_writing">🎬 Video + escritura</option>'
        + '</select></div>'
        + '<div class="wpe-full"><label class="wpe-label">Pregunta / enunciado</label>'
        + '<textarea class="wpe-textarea" name="wp_question[]" rows="2" placeholder="Escribe la pregunta o el enunciado aquí..."></textarea></div>'
        + '<div class="wpe-full"><label class="wpe-label">Instrucción adicional <span style="font-weight:400;font-size:12px">(opcional)</span></label>'
        + '<input class="wpe-input" type="text" name="wp_instr[]" placeholder="Ej: Escribe al menos 3 oraciones completas."></div>'
        + '<div class="wpe-video-row">'
        + '<input type="hidden" name="wp_media_old[]" value="">'
        + '<div><label class="wpe-label">URL del video (YouTube / MP4)</label>'
        + '<input class="wpe-input" type="url" name="wp_media[]" disabled placeholder="https://youtube.com/watch?v=..."></div>'
        + '<div><label class="wpe-label">O sube un video</label>'
        + '<input class="wpe-input" type="file" name="wp_video_file[]" accept="video/*" disabled></div>'
        + '</div>'
        + '<div class="wpe-rows-row">'
        + '<div><label class="wpe-label">Respuestas (cantidad)</label>'
        + '<input class="wpe-input" type="number" name="wp_count[]" min="1" max="20" value="1"></div>'
        + '<div><label class="wpe-label">Filas por respuesta</label>'
        + '<input class="wpe-input" type="number" name="wp_rows[]" min="2" max="14" value="6"></div>'
        + '</div>'
        + '<button type="button" class="wpe-btn-remove" onclick="wpeRemove(this)">✕ Eliminar</button>';
}

function wpeAdd() {
    wpeCount++;
    var div = document.createElement('div');
    div.className = 'wpe-block';
    div.innerHTML = wpeBuildBlock(wpeCount);
    document.getElementById('wpeItems').appendChild(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function wpeRemove(btn) {
    btn.closest('.wpe-block').remove();
    document.querySelectorAll('.wpe-block .wpe-block-num').forEach(function(s, i) {
        s.textContent = 'Pregunta ' + (i + 1);
    });
}

document.querySelectorAll('.wpe-type-sel').forEach(wpeToggle);
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Writing Practice — Editor', 'fas fa-pen-nib', $content);

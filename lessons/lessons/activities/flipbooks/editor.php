<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

if ($unit === '') {
    die('Unidad no especificada');
}

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_flipbook_title(): string
{
    return 'Flipbook';
}

function normalize_flipbook_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_flipbook_title();
}

function normalize_flipbook_payload($rawData): array
{
    $default = array(
        'title' => default_flipbook_title(),
        'language' => 'en-US',
        'listen_enabled' => true,
        'page_texts' => array(),
        'pdf_url' => '',
        'pdf_public_id' => '',
        'pdf_bytes' => 0,
        'pdf' => '',
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    return array(
        'title' => normalize_flipbook_title(isset($decoded['title']) ? (string) $decoded['title'] : ''),
        'language' => isset($decoded['language']) && trim((string) $decoded['language']) !== '' ? trim((string) $decoded['language']) : 'en-US',
        'listen_enabled' => !isset($decoded['listen_enabled']) || (bool) $decoded['listen_enabled'],
        'page_texts' => isset($decoded['page_texts']) && is_array($decoded['page_texts']) ? array_values($decoded['page_texts']) : array(),
        'pdf_url' => isset($decoded['pdf_url']) ? trim((string) $decoded['pdf_url']) : '',
        'pdf_public_id' => isset($decoded['pdf_public_id']) ? trim((string) $decoded['pdf_public_id']) : '',
        'pdf_bytes' => isset($decoded['pdf_bytes']) ? (int) $decoded['pdf_bytes'] : 0,
        'pdf' => isset($decoded['pdf']) ? trim((string) $decoded['pdf']) : '',
    );
}

function encode_flipbook_payload(array $payload): string
{
    return json_encode(array(
        'title' => normalize_flipbook_title(isset($payload['title']) ? (string) $payload['title'] : ''),
        'language' => isset($payload['language']) && trim((string) $payload['language']) !== '' ? trim((string) $payload['language']) : 'en-US',
        'listen_enabled' => !empty($payload['listen_enabled']),
        'page_texts' => isset($payload['page_texts']) && is_array($payload['page_texts']) ? array_values($payload['page_texts']) : array(),
        'pdf_url' => isset($payload['pdf_url']) ? trim((string) $payload['pdf_url']) : '',
        'pdf_public_id' => isset($payload['pdf_public_id']) ? trim((string) $payload['pdf_public_id']) : '',
        'pdf_bytes' => isset($payload['pdf_bytes']) ? (int) $payload['pdf_bytes'] : 0,
        'pdf' => isset($payload['pdf']) ? trim((string) $payload['pdf']) : '',
    ), JSON_UNESCAPED_UNICODE);
}

function parse_page_texts($raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $texts = array();

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed !== '') {
            $texts[] = $trimmed;
        }
    }

    return $texts;
}

function get_upload_error_message($code): string
{
    $messages = array(
        UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize en el servidor.',
        UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño permitido por el formulario.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente.',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún PDF.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor.',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco.',
        UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida.',
    );

    return isset($messages[$code]) ? $messages[$code] : 'Error desconocido de subida (' . (int) $code . ').';
}

function save_pdf_locally(string $tmpPath, string $originalName, string $unit): array
{
    $uploadDir = __DIR__ . '/uploads';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return array('error' => 'No se pudo crear la carpeta de uploads del flipbook.');
        }
    }

    $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', $unit);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);

    $filename = 'unit_' . $safeUnit . '_' . time() . '_' . substr(md5((string) mt_rand()), 0, 8) . '_' . $safeName;
    if (!preg_match('/\.pdf$/i', $filename)) {
        $filename .= '.pdf';
    }

    $targetPath = $uploadDir . '/' . $filename;

    $moved = is_uploaded_file($tmpPath)
        ? move_uploaded_file($tmpPath, $targetPath)
        : rename($tmpPath, $targetPath);

    if (!$moved) {
        return array('error' => 'No se pudo guardar el PDF en el servidor.');
    }

    return array(
        'secure_url' => '/lessons/lessons/activities/flipbooks/uploads/' . $filename,
        'public_id' => 'local:' . $filename,
        'bytes' => file_exists($targetPath) ? (int) filesize($targetPath) : 0,
    );
}

function load_flipbook_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_flipbook_title(),
        'payload' => normalize_flipbook_payload(null),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'flipbooks'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'flipbooks'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'flipbooks'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_flipbook_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_flipbook_title((string) $payload['title']),
        'payload' => $payload,
    );
}

function save_flipbook_activity(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $columns = activities_columns($pdo);
    $payload['title'] = normalize_flipbook_title(isset($payload['title']) ? (string) $payload['title'] : '');
    $json = encode_flipbook_payload($payload);

    $hasUnitId = in_array('unit_id', $columns, true);
    $hasUnit = in_array('unit', $columns, true);
    $hasData = in_array('data', $columns, true);
    $hasContentJson = in_array('content_json', $columns, true);
    $hasId = in_array('id', $columns, true);
    $hasTitle = in_array('title', $columns, true);
    $hasName = in_array('name', $columns, true);

    $targetId = $activityId;

    if ($targetId === '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit_id = :unit
                   AND type = 'flipbooks'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }

        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit = :unit
                   AND type = 'flipbooks'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = array();
        $params = array('id' => $targetId);

        if ($hasData) {
            $setParts[] = 'data = :data';
            $params['data'] = $json;
        }
        if ($hasContentJson) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $json;
        }
        if ($hasTitle) {
            $setParts[] = 'title = :title';
            $params['title'] = $payload['title'];
        }
        if ($hasName) {
            $setParts[] = 'name = :name';
            $params['name'] = $payload['title'];
        }

        if (!empty($setParts)) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET " . implode(', ', $setParts) . "
                 WHERE id = :id
                   AND type = 'flipbooks'"
            );
            $stmt->execute($params);
        }

        return $targetId;
    }

    $insertColumns = array();
    $insertValues = array();
    $params = array();

    $newId = '';
    if ($hasId) {
        $newId = md5(random_bytes(16));
        $insertColumns[] = 'id';
        $insertValues[] = ':id';
        $params['id'] = $newId;
    }

    if ($hasUnitId) {
        $insertColumns[] = 'unit_id';
        $insertValues[] = ':unit_id';
        $params['unit_id'] = $unit;
    } elseif ($hasUnit) {
        $insertColumns[] = 'unit';
        $insertValues[] = ':unit';
        $params['unit'] = $unit;
    }

    $insertColumns[] = 'type';
    $insertValues[] = "'flipbooks'";

    if ($hasData) {
        $insertColumns[] = 'data';
        $insertValues[] = ':data';
        $params['data'] = $json;
    }
    if ($hasContentJson) {
        $insertColumns[] = 'content_json';
        $insertValues[] = ':content_json';
        $params['content_json'] = $json;
    }
    if ($hasTitle) {
        $insertColumns[] = 'title';
        $insertValues[] = ':title';
        $params['title'] = $payload['title'];
    }
    if ($hasName) {
        $insertColumns[] = 'name';
        $insertValues[] = ':name';
        $params['name'] = $payload['title'];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO activities (" . implode(', ', $insertColumns) . ")
         VALUES (" . implode(', ', $insertValues) . ")"
    );
    $stmt->execute($params);

    return $newId;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unidad no especificada');
}

$activity = load_flipbook_activity($pdo, $unit, $activityId);
$flipbook = isset($activity['payload']) && is_array($activity['payload']) ? $activity['payload'] : normalize_flipbook_payload(null);

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_pdf']) && $_POST['delete_pdf'] === '1') {
        $flipbook['pdf_url'] = '';
        $flipbook['pdf_public_id'] = '';
        $flipbook['pdf_bytes'] = 0;
        $flipbook['pdf'] = '';

        $savedActivityId = save_flipbook_activity($pdo, $unit, $activityId, $flipbook);

        $params = array('unit=' . urlencode($unit), 'saved=1');
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

    $flipbook['title'] = normalize_flipbook_title(isset($_POST['title']) ? (string) $_POST['title'] : '');
    $flipbook['language'] = isset($_POST['language']) && trim((string) $_POST['language']) !== '' ? trim((string) $_POST['language']) : 'en-US';
    $flipbook['listen_enabled'] = isset($_POST['listen_enabled']) && (string) $_POST['listen_enabled'] === '1';
    $flipbook['page_texts'] = parse_page_texts(isset($_POST['page_texts']) ? $_POST['page_texts'] : '');

    if (isset($_FILES['pdf']) && isset($_FILES['pdf']['error'])) {
        $pdfError = (int) $_FILES['pdf']['error'];

        if ($pdfError === UPLOAD_ERR_OK) {
            $extension = strtolower(pathinfo((string) $_FILES['pdf']['name'], PATHINFO_EXTENSION));

            if ($extension !== 'pdf') {
                $errorMsg = 'Solo se permite subir archivos PDF.';
            } else {
                $upload = save_pdf_locally((string) $_FILES['pdf']['tmp_name'], (string) $_FILES['pdf']['name'], $unit);

                if (isset($upload['error'])) {
                    $errorMsg = $upload['error'];
                } else {
                    $flipbook['pdf_url'] = $upload['secure_url'];
                    $flipbook['pdf_public_id'] = $upload['public_id'];
                    $flipbook['pdf_bytes'] = $upload['bytes'];
                    $flipbook['pdf'] = $upload['secure_url'];
                }
            }
        } elseif ($pdfError !== UPLOAD_ERR_NO_FILE) {
            $errorMsg = get_upload_error_message($pdfError);
        }
    }

    if ($errorMsg === '') {
        $savedActivityId = save_flipbook_activity($pdo, $unit, $activityId, $flipbook);

        $params = array('unit=' . urlencode($unit), 'saved=1');
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
}

$currentPdf = isset($flipbook['pdf_url']) && trim((string) $flipbook['pdf_url']) !== ''
    ? trim((string) $flipbook['pdf_url'])
    : (isset($flipbook['pdf']) ? trim((string) $flipbook['pdf']) : '');
$currentTitle = normalize_flipbook_title(isset($flipbook['title']) ? (string) $flipbook['title'] : '');
$currentLanguage = isset($flipbook['language']) ? (string) $flipbook['language'] : 'en-US';
$currentListen = !isset($flipbook['listen_enabled']) || (bool) $flipbook['listen_enabled'];
$currentPageTexts = isset($flipbook['page_texts']) && is_array($flipbook['page_texts']) ? $flipbook['page_texts'] : array();

$draftKey = 'flipbook_draft_' . md5($unit . '|' . $activityId . '|' . $assignment . '|' . $source);

ob_start();
?>
<style>
.flipbook-form{max-width:860px;margin:0 auto;text-align:left}
.flipbook-form input[type="text"],
.flipbook-form input[type="file"],
.flipbook-form select,
.flipbook-form textarea{
    width:100%;
    padding:10px;
    border:1px solid #d1d5db;
    border-radius:8px;
    box-sizing:border-box;
    margin-top:6px;
}
.flipbook-form .row{margin-bottom:14px}
.file-box{
    margin-top:18px;
    background:#f3f4f6;
    border:1px solid #e5e7eb;
    padding:12px;
    border-radius:10px;
}
.editor-note{
    background:#eef6ff;
    border:1px solid #bfdbfe;
    color:#1e3a8a;
    padding:10px 12px;
    border-radius:10px;
    margin-bottom:14px;
    font-size:14px;
}
.preview-box{
    margin-top:18px;
    border:1px solid #e5e7eb;
    background:#fff;
    border-radius:14px;
    padding:12px;
}
.preview-frame{
    width:100%;
    height:480px;
    border:none;
    border-radius:10px;
    background:#f8fafc;
}
.small-muted{
    color:#6b7280;
    font-size:13px;
}
.toolbar-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
}
.secondary-btn{
    background:#64748b;
    color:#fff;
    border:none;
    border-radius:8px;
    padding:10px 14px;
    cursor:pointer;
    font-weight:700;
}
.delete-btn{
    background:#dc2626;
    color:#fff;
    border:none;
    border-radius:8px;
    padding:10px 14px;
    cursor:pointer;
    font-weight:700;
}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>
<?php } ?>

<?php if ($errorMsg !== '') { ?>
    <p style="color:#dc2626;font-weight:bold;margin-bottom:15px;">❌ <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
<?php } ?>

<div class="editor-note">
    Este editor guarda el título, el PDF y el texto por página para el botón Listen. Si cierras accidentalmente, el navegador conserva un borrador local hasta que guardes.
</div>

<form class="flipbook-form" id="flipbookForm" method="post" enctype="multipart/form-data">
    <div class="row">
        <label style="font-weight:bold;">Título del flipbook</label>
        <input type="text" name="title" value="<?= htmlspecialchars($currentTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej: My Story Book" required>
    </div>

    <div class="row">
        <label style="font-weight:bold;">Idioma de lectura (Listen)</label>
        <select name="language">
            <option value="en-US" <?= $currentLanguage === 'en-US' ? 'selected' : '' ?>>English (en-US)</option>
            <option value="es-ES" <?= $currentLanguage === 'es-ES' ? 'selected' : '' ?>>Español (es-ES)</option>
        </select>
    </div>

    <div class="row">
        <label style="display:flex;align-items:center;gap:8px;font-weight:bold;">
            <input type="hidden" name="listen_enabled" value="0">
            <input type="checkbox" name="listen_enabled" value="1" <?= $currentListen ? 'checked' : '' ?>>
            Activar botón Listen en el viewer
        </label>
    </div>

    <div class="row">
        <label style="font-weight:bold;">Subir PDF (reemplaza el actual)</label>
        <input type="file" name="pdf" accept="application/pdf">
        <div class="small-muted">Sube un PDF visual, colorido, con imágenes. El flipbook lo mostrará dentro del contenedor de presentación.</div>
    </div>

    <div class="row">
        <label style="font-weight:bold;">Texto por página para Listen (1 línea = 1 página)</label>
        <textarea name="page_texts" rows="8" placeholder="Page 1 text...&#10;Page 2 text..."><?= htmlspecialchars(implode("\n", $currentPageTexts), ENT_QUOTES, 'UTF-8') ?></textarea>
        <div class="small-muted">Si el PDF tiene 10 páginas, puedes poner 10 líneas. Cada línea se leerá en la página correspondiente.</div>
    </div>

    <div class="toolbar-row">
        <button type="submit" class="save-btn">💾 Guardar flipbook</button>
        <button type="button" class="secondary-btn" id="clearDraftBtn">Borrar borrador local</button>
    </div>
</form>

<?php if ($currentPdf !== '') { ?>
    <div class="file-box">
        <div><strong>PDF actual:</strong> <a href="<?= htmlspecialchars($currentPdf, ENT_QUOTES, 'UTF-8') ?>" target="_blank">Abrir PDF</a></div>

        <?php if (!empty($flipbook['pdf_bytes'])) { ?>
            <div style="margin-top:6px;color:#6b7280;">Tamaño: <?= number_format(((int) $flipbook['pdf_bytes']) / 1024 / 1024, 2) ?> MB</div>
        <?php } ?>

        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="delete_pdf" value="1">
            <button type="submit" class="delete-btn">✖ Quitar PDF</button>
        </form>
    </div>

    <div class="preview-box">
        <div style="font-weight:bold;margin-bottom:10px;">Vista previa rápida</div>
        <iframe
            class="preview-frame"
            src="viewer.php?unit=<?= urlencode($unit) ?><?php if ($activityId !== '') { ?>&id=<?= urlencode($activityId) ?><?php } ?><?php if ($assignment !== '') { ?>&assignment=<?= urlencode($assignment) ?><?php } ?><?php if ($source !== '') { ?>&source=<?= urlencode($source) ?><?php } ?>"
            title="Preview Flipbook"
        ></iframe>
    </div>
<?php } ?>

<script>
(function () {
    const form = document.getElementById('flipbookForm');
    const clearDraftBtn = document.getElementById('clearDraftBtn');
    const draftKey = <?= json_encode($draftKey, JSON_UNESCAPED_UNICODE) ?>;
    let formChanged = false;
    let formSubmitted = false;

    function markChanged() {
        formChanged = true;
        saveDraft();
    }

    function serializeDraft() {
        const titleEl = form.querySelector('[name="title"]');
        const languageEl = form.querySelector('[name="language"]');
        const listenEl = form.querySelector('[name="listen_enabled"][type="checkbox"]');
        const pageTextsEl = form.querySelector('[name="page_texts"]');

        return {
            title: titleEl ? titleEl.value : '',
            language: languageEl ? languageEl.value : 'en-US',
            listen_enabled: !!(listenEl && listenEl.checked),
            page_texts: pageTextsEl ? pageTextsEl.value : ''
        };
    }

    function saveDraft() {
        if (!form) return;
        try {
            localStorage.setItem(draftKey, JSON.stringify(serializeDraft()));
        } catch (e) {}
    }

    function loadDraft() {
        try {
            const raw = localStorage.getItem(draftKey);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function applyDraft(draft) {
        if (!draft || !form) return;

        const titleEl = form.querySelector('[name="title"]');
        const languageEl = form.querySelector('[name="language"]');
        const listenEl = form.querySelector('[name="listen_enabled"][type="checkbox"]');
        const pageTextsEl = form.querySelector('[name="page_texts"]');

        if (titleEl && draft.title !== undefined) titleEl.value = draft.title;
        if (languageEl && draft.language !== undefined) languageEl.value = draft.language;
        if (listenEl && draft.listen_enabled !== undefined) listenEl.checked = !!draft.listen_enabled;
        if (pageTextsEl && draft.page_texts !== undefined) pageTextsEl.value = draft.page_texts;
    }

    const savedFlag = new URLSearchParams(window.location.search).get('saved');
    if (savedFlag === '1') {
        try { localStorage.removeItem(draftKey); } catch (e) {}
    } else {
        const draft = loadDraft();
        if (draft) {
            const wantsRestore = window.confirm('Se encontró un borrador local del flipbook. ¿Quieres restaurarlo?');
            if (wantsRestore) {
                applyDraft(draft);
            }
        }
    }

    form.querySelectorAll('input, textarea, select').forEach(function (el) {
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });

    form.addEventListener('submit', function () {
        formSubmitted = true;
        formChanged = false;
        try { localStorage.removeItem(draftKey); } catch (e) {}
    });

    window.addEventListener('beforeunload', function (e) {
        if (formChanged && !formSubmitted) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    if (clearDraftBtn) {
        clearDraftBtn.addEventListener('click', function () {
            try { localStorage.removeItem(draftKey); } catch (e) {}
            alert('Borrador local eliminado.');
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('📖 Flipbook Editor', '📖', $content);

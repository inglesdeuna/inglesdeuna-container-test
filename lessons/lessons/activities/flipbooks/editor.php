<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unidad no especificada');
}

function load_flipbook_data($pdo, $unit)
{
    $stmt = $pdo->prepare(
        "SELECT data
         FROM activities
         WHERE unit_id = :unit
           AND type = 'flipbooks'
         LIMIT 1"
    );
    $stmt->execute(array('unit' => $unit));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $raw = isset($row['data']) ? $row['data'] : '{}';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : array();
}

function save_flipbook_data($pdo, $unit, $payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $check = $pdo->prepare(
        "SELECT id
         FROM activities
         WHERE unit_id = :unit
           AND type = 'flipbooks'
         LIMIT 1"
    );
    $check->execute(array('unit' => $unit));

    if ($check->fetch()) {
        $stmt = $pdo->prepare(
            "UPDATE activities
             SET data = :data
             WHERE unit_id = :unit
               AND type = 'flipbooks'"
        );
        $stmt->execute(array(
            'data' => $json,
            'unit' => $unit,
        ));
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO activities (id, unit_id, type, data)
             VALUES (:id, :unit, 'flipbooks', :data)"
        );
        $stmt->execute(array(
            'id' => md5(random_bytes(16)),
            'unit' => $unit,
            'data' => $json,
        ));
    }
}

function save_pdf_locally($tmpPath, $originalName, $unit)
{
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return array('error' => 'No se pudo crear la carpeta de uploads del flipbook.');
        }
    }

    $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $unit);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $originalName);
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

function upload_pdf_to_cloudinary($tmpPath, $originalName)
{
    $cloud = getenv('CLOUDINARY_CLOUD_NAME');
    $key = getenv('CLOUDINARY_API_KEY');
    $secret = getenv('CLOUDINARY_API_SECRET');

    $cloud = $cloud !== false ? trim((string) $cloud) : '';
    $key = $key !== false ? trim((string) $key) : '';
    $secret = $secret !== false ? trim((string) $secret) : '';

    if ($cloud === '' || $key === '' || $secret === '') {
        return array('error' => 'Cloudinary no está configurado en el entorno.');
    }

    $timestamp = time();
    $publicId = 'unit_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($_GET['unit'] ?? '')) . '_' . $timestamp;

    $signatureParams = array(
        'folder' => 'flipbooks',
        'public_id' => $publicId,
        'timestamp' => $timestamp,
        'unique_filename' => 'true',
        'use_filename' => 'true',
    );

    ksort($signatureParams);

    $signatureStringParts = array();
    foreach ($signatureParams as $keyParam => $valueParam) {
        $signatureStringParts[] = $keyParam . '=' . $valueParam;
    }

    $signatureBase = implode('&', $signatureStringParts) . $secret;
    $signature = sha1($signatureBase);

    $post = array(
        'file' => new CURLFile($tmpPath, 'application/pdf', $originalName),
        'api_key' => $key,
        'timestamp' => $timestamp,
        'signature' => $signature,
        'public_id' => $publicId,
        'folder' => 'flipbooks',
        'resource_type' => 'raw',
        'use_filename' => 'true',
        'unique_filename' => 'true',
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.cloudinary.com/v1_1/' . $cloud . '/raw/upload');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);

    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return array('error' => 'Error al subir PDF: ' . $curlError);
    }

    $response = json_decode($result, true);

    if ($httpCode >= 400) {
        $message = is_array($response) && isset($response['error']['message'])
            ? $response['error']['message']
            : 'Error HTTP ' . $httpCode . ' en Cloudinary';
        return array('error' => $message);
    }

    if (!is_array($response) || isset($response['error'])) {
        $message = is_array($response) && isset($response['error']['message'])
            ? $response['error']['message']
            : 'Error desconocido subiendo PDF';
        return array('error' => $message);
    }

    $secureUrl = isset($response['secure_url']) ? (string) $response['secure_url'] : '';
    $format = isset($response['format']) ? (string) $response['format'] : '';
    if ($secureUrl !== '' && $format === 'pdf' && !preg_match('/\.pdf(\?|$)/i', $secureUrl)) {
        $secureUrl .= '.pdf';
    }

    return array(
        'secure_url' => $secureUrl,
        'public_id' => isset($response['public_id']) ? $response['public_id'] : '',
        'bytes' => isset($response['bytes']) ? (int) $response['bytes'] : 0,
    );
}

function get_upload_error_message($code)
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

function parse_page_texts($raw)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $texts = array();

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $texts[] = $trimmed;
        }
    }

    return $texts;
}

$flipbook = load_flipbook_data($pdo, $unit);
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_pdf'])) {
        $flipbook['pdf_url'] = '';
        $flipbook['pdf_public_id'] = '';
        $flipbook['pdf_bytes'] = 0;
        save_flipbook_data($pdo, $unit, $flipbook);

        header('Location: editor.php?unit=' . urlencode((string) $unit) . '&saved=1');
        exit;
    }

    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $language = isset($_POST['language']) ? trim($_POST['language']) : 'en-US';
    $listenEnabled = isset($_POST['listen_enabled']) && $_POST['listen_enabled'] === '1';
    $pageTexts = parse_page_texts(isset($_POST['page_texts']) ? $_POST['page_texts'] : '');

    $flipbook['title'] = $title !== '' ? $title : 'My Flipbook';
    $flipbook['language'] = $language !== '' ? $language : 'en-US';
    $flipbook['listen_enabled'] = $listenEnabled;
    $flipbook['page_texts'] = $pageTexts;

    if (isset($_FILES['pdf']) && isset($_FILES['pdf']['error'])) {
        $pdfError = (int) $_FILES['pdf']['error'];

        if ($pdfError === UPLOAD_ERR_OK) {
            $extension = strtolower(pathinfo((string) $_FILES['pdf']['name'], PATHINFO_EXTENSION));
            if ($extension !== 'pdf') {
                $errorMsg = 'Solo se permite subir archivos PDF.';
            } else {
                $upload = save_pdf_locally($_FILES['pdf']['tmp_name'], $_FILES['pdf']['name'], $unit);

                if (isset($upload['error'])) {
                    $cloudUpload = upload_pdf_to_cloudinary($_FILES['pdf']['tmp_name'], $_FILES['pdf']['name']);
                    if (isset($cloudUpload['error'])) {
                        $errorMsg = $upload['error'] . ' / Cloudinary: ' . $cloudUpload['error'];
                    } else {
                        $flipbook['pdf_url'] = $cloudUpload['secure_url'];
                        $flipbook['pdf_public_id'] = $cloudUpload['public_id'];
                        $flipbook['pdf_bytes'] = $cloudUpload['bytes'];
                        $flipbook['pdf'] = $cloudUpload['secure_url'];
                    }
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
        save_flipbook_data($pdo, $unit, $flipbook);
        header('Location: editor.php?unit=' . urlencode((string) $unit) . '&saved=1');
        exit;
    }
}

$currentPdf = isset($flipbook['pdf_url']) ? $flipbook['pdf_url'] : '';
$currentTitle = isset($flipbook['title']) ? $flipbook['title'] : 'My Flipbook';
$currentLanguage = isset($flipbook['language']) ? $flipbook['language'] : 'en-US';
$currentListen = isset($flipbook['listen_enabled']) ? (bool) $flipbook['listen_enabled'] : true;
$currentPageTexts = isset($flipbook['page_texts']) && is_array($flipbook['page_texts']) ? $flipbook['page_texts'] : array();

ob_start();
?>
<style>
.flipbook-form{max-width:800px;margin:0 auto;text-align:left;}
.flipbook-form input[type="text"],
.flipbook-form input[type="file"],
.flipbook-form select,
.flipbook-form textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box;margin-top:6px;}
.flipbook-form .row{margin-bottom:14px;}
.file-box{margin-top:18px;background:#f3f4f6;border:1px solid #e5e7eb;padding:12px;border-radius:10px;}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>
<?php } ?>

<?php if ($errorMsg !== '') { ?>
    <p style="color:#dc2626;font-weight:bold;margin-bottom:15px;">❌ <?= htmlspecialchars($errorMsg) ?></p>
<?php } ?>

<form class="flipbook-form" method="post" enctype="multipart/form-data">
    <div class="row">
        <label style="font-weight:bold;">Título del flipbook</label>
        <input type="text" name="title" value="<?= htmlspecialchars($currentTitle) ?>" placeholder="Ej: My Story Book">
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
        <label style="font-weight:bold;">Subir PDF (puede reemplazar el actual)</label>
        <input type="file" name="pdf" accept="application/pdf">
        <small style="color:#6b7280;">Se guarda localmente en el servidor (y usa Cloudinary solo como fallback si falla el guardado local).</small>
    </div>

    <div class="row">
        <label style="font-weight:bold;">Texto por página para Listen (1 línea = 1 página)</label>
        <textarea name="page_texts" rows="8" placeholder="Page 1 text...&#10;Page 2 text..."><?= htmlspecialchars(implode("\n", $currentPageTexts)) ?></textarea>
    </div>

    <button type="submit" class="save-btn">💾 Guardar flipbook</button>
</form>

<?php if ($currentPdf !== '') { ?>
    <div class="file-box">
        <div><strong>PDF actual:</strong> <a href="<?= htmlspecialchars($currentPdf) ?>" target="_blank">Abrir PDF</a></div>
        <?php if (!empty($flipbook['pdf_bytes'])) { ?>
            <div style="margin-top:6px;color:#6b7280;">Tamaño: <?= number_format(((int) $flipbook['pdf_bytes']) / 1024 / 1024, 2) ?> MB</div>
        <?php } ?>

        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="delete_pdf" value="1">
            <button type="submit" class="delete-btn">✖ Quitar PDF</button>
        </form>
    </div>
<?php } ?>

<?php
$content = ob_get_clean();
render_activity_editor('📖 Flipbook Editor', '📖', $content);

<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Método no permitido.'
    ]);
    exit;
}

try {
    $activityId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    $unit       = isset($_POST['unit']) ? trim((string) $_POST['unit']) : 'general';
    $source     = isset($_POST['source']) ? trim((string) $_POST['source']) : '';
    $assignment = isset($_POST['assignment']) ? trim((string) $_POST['assignment']) : '';
    $title      = isset($_POST['title']) ? trim((string) $_POST['title']) : 'Flipbook';
    $language   = isset($_POST['language']) ? trim((string) $_POST['language']) : 'en-US';

    $listenEnabledRaw = $_POST['listen_enabled'] ?? '1';
    $listenEnabled = ($listenEnabledRaw === '1' || $listenEnabledRaw === 1 || $listenEnabledRaw === true || $listenEnabledRaw === 'true');

    if ($activityId === '') {
        throw new Exception('ID de actividad faltante.');
    }

    if ($title === '') {
        throw new Exception('El título no puede estar vacío.');
    }

    $allowedLanguages = ['en-US', 'en-GB', 'es-ES'];
    if (!in_array($language, $allowedLanguages, true)) {
        $language = 'en-US';
    }

    $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        throw new Exception('La actividad no existe.');
    }

    $currentData = json_decode($activity['data'] ?? '', true);
    if (!is_array($currentData)) {
        $currentData = [];
    }

    $pdfUrl = isset($currentData['pdf_url']) ? (string) $currentData['pdf_url'] : '';

    $pageTextsRaw = $_POST['page_texts'] ?? '[]';
    $pageTextsDecoded = json_decode($pageTextsRaw, true);

    if (!is_array($pageTextsDecoded)) {
        $pageTextsDecoded = [];
    }

    $pageTexts = [];
    foreach ($pageTextsDecoded as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $pageTexts[] = $line;
        }
    }

    if (isset($_FILES['pdf']) && is_array($_FILES['pdf']) && ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Ocurrió un error al subir el archivo PDF.');
        }

        $tmpPath = $_FILES['pdf']['tmp_name'] ?? '';
        $originalName = $_FILES['pdf']['name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            throw new Exception('El archivo debe estar en formato PDF.');
        }

        if (!is_uploaded_file($tmpPath)) {
            throw new Exception('El archivo subido no es válido.');
        }

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            throw new Exception('No se pudo crear la carpeta de uploads.');
        }

        $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', $unit);
        if ($safeUnit === '') {
            $safeUnit = 'general';
        }

        $newFileName = 'flipbook_' . $activityId . '_' . $safeUnit . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $destinationPath = $uploadDir . $newFileName;

        if (!move_uploaded_file($tmpPath, $destinationPath)) {
            throw new Exception('No se pudo guardar el archivo PDF en el servidor.');
        }

        $pdfUrl = '/lessons/lessons/activities/flipbooks/uploads/' . $newFileName;
    }

    if ($pdfUrl === '') {
        throw new Exception('Debes cargar un archivo PDF para el flipbook.');
    }

    $payload = [
        'type'           => 'flipbook',
        'title'          => $title,
        'pdf_url'        => $pdfUrl,
        'listen_enabled' => $listenEnabled,
        'language'       => $language,
        'page_texts'     => array_values($pageTexts),
        'updated_at'     => date('Y-m-d H:i:s')
    ];

    $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonData === false) {
        throw new Exception('No se pudo generar el JSON de la actividad.');
    }

    $updateStmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
    $updated = $updateStmt->execute([
        'data' => $jsonData,
        'id'   => $activityId
    ]);

    if (!$updated) {
        throw new Exception('No se pudo actualizar la actividad en la base de datos.');
    }

    echo json_encode([
        'status'     => 'success',
        'message'    => 'Actividad guardada correctamente.',
        'data'       => $payload,
        'context'    => [
            'id'         => $activityId,
            'unit'       => $unit,
            'source'     => $source,
            'assignment' => $assignment
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

try {
    $activityId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    $unit       = isset($_POST['unit']) ? trim((string) $_POST['unit']) : 'general';
    $title      = isset($_POST['title']) ? trim((string) $_POST['title']) : 'Flipbook';
    $language   = isset($_POST['language']) ? trim((string) $_POST['language']) : 'en-US';
    $listenEnabled = isset($_POST['listen_enabled']) && $_POST['listen_enabled'] === '1';

    if ($activityId === '') {
        throw new Exception('ID de actividad faltante.');
    }

    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $currentActivity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentActivity) {
        throw new Exception('Actividad no encontrada.');
    }

    $currentData = json_decode($currentActivity['data'] ?? '', true);
    if (!is_array($currentData)) {
        $currentData = [];
    }

    $pdfUrl = isset($currentData['pdf_url']) ? (string) $currentData['pdf_url'] : '';

    $pageTextsRaw = $_POST['page_texts'] ?? '[]';
    $pageTexts = json_decode($pageTextsRaw, true);
    if (!is_array($pageTexts)) {
        $pageTexts = [];
    }

    $pageTexts = array_values(array_filter(array_map(function ($line) {
        return trim((string) $line);
    }, $pageTexts), function ($line) {
        return $line !== '';
    }));

    if (isset($_FILES['pdf']) && ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/pdfs/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            throw new Exception('No se pudo crear la carpeta de uploads.');
        }

        $tmpPath = $_FILES['pdf']['tmp_name'];
        $originalName = $_FILES['pdf']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            throw new Exception('El archivo debe ser un PDF válido.');
        }

        $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', $unit);
        if ($safeUnit === '') {
            $safeUnit = 'general';
        }

        $newFileName = 'unit_' . $safeUnit . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $destination = $uploadDir . $newFileName;

        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new Exception('No se pudo guardar el archivo PDF.');
        }

        $pdfUrl = '/lessons/lessons/activities/flipbooks/uploads/pdfs/' . $newFileName;
    }

    if ($pdfUrl === '') {
        throw new Exception('Debes cargar un archivo PDF.');
    }

    $payload = [
        'type'           => 'flipbook',
        'title'          => $title,
        'pdf_url'        => $pdfUrl,
        'listen_enabled' => $listenEnabled,
        'language'       => $language,
        'page_texts'     => $pageTexts,
        'updated_at'     => date('Y-m-d H:i:s')
    ];

    $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $updateStmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
    $ok = $updateStmt->execute([
        'data' => $jsonData,
        'id'   => $activityId
    ]);

    if (!$ok) {
        throw new Exception('No se pudo actualizar la actividad.');
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Actividad guardada correctamente.',
        'data' => $payload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

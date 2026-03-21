<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

try {
    $activityId = $_POST['id'] ?? '';
    $unit = $_POST['unit'] ?? 'unknown';
    $title = trim($_POST['title'] ?? 'Flipbook');
    $listenEnabled = (bool)($_POST['listen_enabled'] ?? true);
    $language = $_POST['language'] ?? 'en-US';
    
    // Procesar los textos de página (uno por línea)
    $rawTexts = $_POST['page_texts'] ?? '';
    $pageTexts = array_filter(array_map('trim', explode("\n", $rawTexts)));

    if ($activityId === '') {
        throw new Exception('ID de actividad faltante');
    }

    // 1. Obtener datos actuales para mantener el PDF previo si no se sube uno nuevo
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $currentActivity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $currentData = json_decode($currentActivity['data'] ?? '{}', true) ?: [];
    $pdfUrl = $currentData['pdf_url'] ?? '';

    // 2. Manejo de subida de nuevo PDF
    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        
        // Crear carpeta si no existe
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $fileTmpPath = $_FILES['pdf']['tmp_name'];
        $fileName = $_FILES['pdf']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExtension !== 'pdf') {
            throw new Exception('El archivo debe ser un PDF válido.');
        }

        // Generar nombre único estandarizado
        $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', $unit);
        $newFileName = 'unit_' . $safeUnit . '_' . time() . '_' . uniqid() . '.pdf';
        $destPath = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            // Guardamos la ruta relativa para el viewer
            $pdfUrl = '/lessons/lessons/activities/flipbooks/uploads/' . $newFileName;
        } else {
            throw new Exception('No se pudo mover el archivo al directorio de destino.');
        }
    }

    // 3. Construir el nuevo JSON para la columna 'data'
    // Mantenemos la estructura de 'page_texts' para la función 'Listen' del viewer
    $payload = [
        'title' => $title,
        'pdf_url' => $pdfUrl,
        'listen_enabled' => $listenEnabled,
        'language' => $language,
        'page_texts' => array_values($pageTexts), // Resetear índices del array
        'type' => 'flipbook',
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // 4. Actualizar la base de datos
    $updateStmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
    $success = $updateStmt->execute([
        'data' => $jsonData,
        'id' => $activityId
    ]);

    if ($success) {
        echo json_encode(['status' => 'success', 'message' => 'Actividad guardada correctamente']);
    } else {
        throw new Exception('Error al actualizar la base de datos.');
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

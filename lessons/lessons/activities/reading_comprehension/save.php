<?php
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$isAuth = !empty($_SESSION['admin_logged'])
    || !empty($_SESSION['academic_logged'])
    || !empty($_SESSION['teacher_logged']);

if (!$isAuth) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';

$body = (string) file_get_contents('php://input');
$payload = json_decode($body, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$activityId = isset($payload['id']) ? trim((string) $payload['id']) : '';
$title      = isset($payload['title']) ? trim((string) $payload['title']) : '';
$texts      = $payload['texts'] ?? null;

if ($activityId === '' || !is_array($texts)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$dataJson = json_encode(['title' => $title, 'texts' => $texts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function rc_save_has_column(PDO $pdo, string $col): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='activities' AND column_name=:c LIMIT 1");
        $st->execute(['c' => $col]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$titleCol = null;
if (rc_save_has_column($pdo, 'title')) {
    $titleCol = 'title';
} elseif (rc_save_has_column($pdo, 'name')) {
    $titleCol = 'name';
}

try {
    if ($titleCol !== null && $title !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data, {$titleCol} = :title WHERE id = :id AND type = 'reading_comprehension'");
        $stmt->execute(['data' => $dataJson, 'title' => $title, 'id' => $activityId]);
    } else {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'reading_comprehension'");
        $stmt->execute(['data' => $dataJson, 'id' => $activityId]);
    }

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM activities WHERE id = :id AND type = 'reading_comprehension' LIMIT 1");
        $check->execute(['id' => $activityId]);
        if (!$check->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Activity not found']);
            exit;
        }
    }
} catch (Throwable $e) {
    error_log('[reading_comprehension/save] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

echo json_encode(['ok' => true]);

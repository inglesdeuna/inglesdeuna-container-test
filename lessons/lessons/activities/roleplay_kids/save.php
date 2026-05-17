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
$scene      = $payload['scene'] ?? null;
$turns      = $payload['turns'] ?? null;

if ($activityId === '' || !is_array($scene) || !is_array($turns)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$dataJson = json_encode(['scene' => $scene, 'turns' => $turns], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
$stmt->execute(['data' => $dataJson, 'id' => $activityId]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Activity not found']);
    exit;
}

echo json_encode(['ok' => true]);

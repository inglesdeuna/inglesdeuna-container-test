<?php
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$isAuth = !empty($_SESSION['admin_logged'])
    || !empty($_SESSION['academic_logged'])
    || !empty($_SESSION['teacher_logged'])
    || !empty($_SESSION['teacher_id'])
    || !empty($_SESSION['teacher_username']);

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

function rk_log_save(string $message, array $context = []): void
{
    $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    error_log('[roleplay_kids/save] ' . $message . $ctx);
}

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

rk_log_save('Incoming save request', [
    'activity_id' => $activityId,
    'save_destination' => 'activities.data',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'scene_keys' => is_array($scene) ? array_values(array_keys($scene)) : [],
    'turns_count' => is_array($turns) ? count($turns) : null,
]);

if ($activityId === '' || !is_array($scene) || !is_array($turns)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$dataJson = json_encode(['scene' => $scene, 'turns' => $turns], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

rk_log_save('Save payload prepared', [
    'activity_id' => $activityId,
    'save_destination' => 'activities.data',
    'payload_bytes' => strlen((string) $dataJson),
]);

$stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
$stmt->execute(['data' => $dataJson, 'id' => $activityId]);

if ($stmt->rowCount() === 0) {
    $existsStmt = $pdo->prepare("SELECT id FROM activities WHERE id = :id LIMIT 1");
    $existsStmt->execute(['id' => $activityId]);
    $exists = $existsStmt->fetchColumn();
    if (!$exists) {
        rk_log_save('Save failed: activity not found', ['activity_id' => $activityId]);
        http_response_code(404);
        echo json_encode(['error' => 'Activity not found']);
        exit;
    }

    rk_log_save('No data change detected (rowCount=0 but activity exists)', ['activity_id' => $activityId]);
}

rk_log_save('Save completed', [
    'activity_id' => $activityId,
    'affected_rows' => $stmt->rowCount(),
    'save_destination' => 'activities.data',
]);

echo json_encode([
    'ok' => true,
    'activity_id' => $activityId,
    'save_destination' => 'activities.data',
    'turns_count' => is_array($turns) ? count($turns) : 0,
]);

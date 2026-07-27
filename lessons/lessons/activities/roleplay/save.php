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

$pdo->exec("
    CREATE TABLE IF NOT EXISTS roleplay_revisions (
        id BIGSERIAL PRIMARY KEY,
        activity_id INT NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
        data JSONB NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("
    CREATE INDEX IF NOT EXISTS roleplay_revisions_activity_idx
    ON roleplay_revisions (activity_id, created_at DESC)
");

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
$allowTurnReduction = !empty($payload['allow_turn_reduction']);

if ($activityId === '' || !is_array($scene) || !is_array($turns)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$dataJson = json_encode(['scene' => $scene, 'turns' => $turns], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($dataJson === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not encode Roleplay data']);
    exit;
}

try {
    $pdo->beginTransaction();
    $currentStmt = $pdo->prepare('SELECT type, data FROM activities WHERE id = :id FOR UPDATE');
    $currentStmt->execute(['id' => $activityId]);
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Activity not found']);
        exit;
    }
    if (strtolower((string)($current['type'] ?? '')) !== 'roleplay') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Activity is not a Roleplay']);
        exit;
    }

    $previous = json_decode((string)($current['data'] ?? ''), true);
    $previousTurns = [];
    if (is_array($previous)) {
        foreach (['turns', 'dialogue', 'dialogs', 'lines', 'items'] as $key) {
            if (isset($previous[$key]) && is_array($previous[$key])) {
                $previousTurns = $previous[$key];
                break;
            }
        }
    }
    if (count($turns) < count($previousTurns) && !$allowTurnReduction) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Refusing to overwrite Roleplay with fewer dialogue turns']);
        exit;
    }

    $revisionStmt = $pdo->prepare(
        'INSERT INTO roleplay_revisions (activity_id, data) VALUES (:id, CAST(:data AS jsonb))'
    );
    $revisionStmt->execute(['id' => $activityId, 'data' => (string)($current['data'] ?? '{}')]);

    $storedData = is_array($previous) ? $previous : [];
    $storedData['scene'] = $scene;
    $storedData['turns'] = $turns;
    $dataJson = json_encode($storedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($dataJson === false) {
        throw new RuntimeException('Could not encode Roleplay data');
    }
    $stmt = $pdo->prepare("UPDATE activities SET data = CAST(:data AS jsonb) WHERE id = :id");
    $stmt->execute(['data' => $dataJson, 'id' => $activityId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[roleplay/save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not save Roleplay']);
    exit;
}

if (!isset($stmt) || $stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Activity not found']);
    exit;
}

echo json_encode(['ok' => true]);

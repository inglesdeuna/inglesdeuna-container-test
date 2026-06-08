<?php
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function fc_same_origin_editor_request(): bool
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($referer === '' || $host === '') {
        return false;
    }

    $parts = parse_url($referer);
    if (!is_array($parts)) {
        return false;
    }

    $refHost = (string) ($parts['host'] ?? '');
    $refPath = (string) ($parts['path'] ?? '');
    if (!hash_equals($host, $refHost)) {
        return false;
    }

    return str_contains($refPath, '/lessons/lessons/activities/free_conversation/viewer.php');
}

$isAuth = !empty($_SESSION['admin_logged'])
    || !empty($_SESSION['admin_id'])
    || !empty($_SESSION['admin_email'])
    || !empty($_SESSION['admin_username'])
    || !empty($_SESSION['admin_role'])
    || !empty($_SESSION['academic_logged'])
    || !empty($_SESSION['teacher_logged'])
    || !empty($_SESSION['teacher_id'])
    || !empty($_SESSION['teacher_username']);

if (!$isAuth && !fc_same_origin_editor_request()) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Unauthorized',
        'hint' => 'Login again as admin or teacher, then reopen the activity editor.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';

$body  = (string) file_get_contents('php://input');
$input = json_decode($body, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$activityId = isset($input['id']) ? trim((string) $input['id']) : '';

if ($activityId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing activity id']);
    exit;
}

$allowedVoiceIds = [
    'nzFihrBIvB34imQBuxub',
    'NoOVOzCQFLOvtsMoNcdT',
    'Nggzl2QAXh3OijoXD116',
];

$teacherVoiceId = isset($input['teacherVoiceId']) ? trim((string) $input['teacherVoiceId']) : '';
if ($teacherVoiceId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing teacherVoiceId']);
    exit;
}
if (!in_array($teacherVoiceId, $allowedVoiceIds, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid teacherVoiceId']);
    exit;
}

$validModes = ['chat_feedback', 'voice_only', 'debate', 'interview'];
$conversationMode = isset($input['conversation_mode']) ? trim((string) $input['conversation_mode']) : 'chat_feedback';
if (!in_array($conversationMode, $validModes, true)) {
    $conversationMode = 'chat_feedback';
}

$validDifficulties = ['beginner', 'intermediate', 'advanced'];
$difficulty = isset($input['difficulty']) ? trim((string) $input['difficulty']) : 'intermediate';
if (!in_array($difficulty, $validDifficulties, true)) {
    $difficulty = 'intermediate';
}

$validTimeLimits = [3, 5, 10, 15];
$timeLimit = isset($input['timeLimit']) ? (int) $input['timeLimit'] : 5;
if (!in_array($timeLimit, $validTimeLimits, true)) {
    $timeLimit = 5;
}

$targetVocab = [];
if (isset($input['targetVocab']) && is_array($input['targetVocab'])) {
    foreach ($input['targetVocab'] as $v) {
        $word = trim((string) $v);
        if ($word !== '') {
            $targetVocab[] = $word;
        }
    }
}

$hints = [];
if (isset($input['hints']) && is_array($input['hints'])) {
    foreach (array_slice($input['hints'], 0, 3) as $h) {
        $hint = trim((string) $h);
        if ($hint !== '') {
            $hints[] = $hint;
        }
    }
}

$validLanguages = ['English', 'Spanish', 'French', 'Portuguese', 'German', 'Italian', 'Chinese', 'Japanese', 'Korean', 'Arabic'];
$targetLanguage = isset($input['targetLanguage']) ? trim((string) $input['targetLanguage']) : 'English';
if (!in_array($targetLanguage, $validLanguages, true)) {
    $targetLanguage = 'English';
}

$dataPayload = [
    'title'             => substr(trim((string) ($input['title'] ?? 'Free Conversation')), 0, 200),
    'topic'             => substr(trim((string) ($input['topic'] ?? '')), 0, 1000),
    'conversation_mode' => $conversationMode,
    'difficulty'        => $difficulty,
    'timeLimit'         => $timeLimit,
    'agentName'         => substr(trim((string) ($input['agentName'] ?? 'Alex')), 0, 50),
    'teacherVoiceId'    => $teacherVoiceId,
    'targetVocab'       => $targetVocab,
    'hints'             => $hints,
    'targetLanguage'    => $targetLanguage,
];

$dataJson = json_encode($dataPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
$stmt->execute(['data' => $dataJson, 'id' => $activityId]);

if ($stmt->rowCount() === 0) {
    $existsStmt = $pdo->prepare("SELECT id FROM activities WHERE id = :id LIMIT 1");
    $existsStmt->execute(['id' => $activityId]);
    if (!$existsStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Activity not found']);
        exit;
    }
}

echo json_encode(['ok' => true, 'activity_id' => $activityId]);

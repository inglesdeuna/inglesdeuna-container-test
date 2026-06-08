<?php
/**
 * OpenAI proxy for Free Conversation.
 * Keeps the API key server-side and returns an Anthropic-like response shape
 * so the existing React viewer can keep reading data.content[0].text.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$isAuth = !empty($_SESSION['admin_logged'])
    || !empty($_SESSION['academic_logged'])
    || !empty($_SESSION['teacher_logged'])
    || !empty($_SESSION['teacher_id'])
    || !empty($_SESSION['teacher_username'])
    || !empty($_SESSION['student_logged']);

if (!$isAuth) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function fc_env(string $key): string
{
    $value = $_ENV[$key] ?? getenv($key) ?? ($_SERVER[$key] ?? '');
    if ((!is_string($value) || trim($value) === '') && function_exists('apache_getenv')) {
        $apacheValue = apache_getenv($key, true);
        if (is_string($apacheValue) && trim($apacheValue) !== '') {
            $value = $apacheValue;
        }
    }

    return is_string($value) ? trim($value) : '';
}

$apiKey = fc_env('OPENAI_API_KEY');
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'OPENAI_API_KEY is not configured on the server.']);
    exit;
}

$body = (string) file_get_contents('php://input');
if ($body === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$incoming = json_decode($body, true);
if (!is_array($incoming)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$system = trim((string) ($incoming['system'] ?? ''));
$messages = $incoming['messages'] ?? [];
if (!is_array($messages)) {
    $messages = [];
}

$openAiMessages = [];
if ($system !== '') {
    $openAiMessages[] = ['role' => 'system', 'content' => $system];
}

foreach ($messages as $message) {
    if (!is_array($message)) continue;
    $role = (string) ($message['role'] ?? 'user');
    $content = (string) ($message['content'] ?? '');
    if ($content === '') continue;
    if (!in_array($role, ['user', 'assistant', 'system'], true)) {
        $role = 'user';
    }
    $openAiMessages[] = ['role' => $role, 'content' => $content];
}

if (empty($openAiMessages)) {
    http_response_code(400);
    echo json_encode(['error' => 'No messages provided']);
    exit;
}

$request = [
    'model' => 'gpt-4o-mini',
    'messages' => $openAiMessages,
    'temperature' => 0.7,
    'max_tokens' => max(200, min(1200, (int) ($incoming['max_tokens'] ?? 800))),
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($request),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);

$result = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI request error: ' . $curlError]);
    exit;
}

$decoded = json_decode((string) $result, true);
if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode(['error' => $decoded['error']['message'] ?? 'OpenAI API error']);
    exit;
}

$text = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
if ($text === '') {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI returned an empty response.']);
    exit;
}

http_response_code(200);
echo json_encode([
    'content' => [
        ['type' => 'text', 'text' => $text],
    ],
]);

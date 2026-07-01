<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$word = isset($_POST['word']) ? trim((string) $_POST['word']) : '';
$context = isset($_POST['context']) ? trim((string) $_POST['context']) : '';

if ($word === '') {
    http_response_code(400);
    echo json_encode(['error' => 'word is required']);
    exit;
}

$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY is not configured']);
    exit;
}

$prompt = 'Give the IPA pronunciation and a short, simple English definition (max 12 words) '
    . 'for the English word or phrase: "' . $word . '".'
    . ($context !== '' ? ' It is used in this sentence: "' . $context . '".' : '')
    . ' Respond ONLY with valid JSON, no markdown, no preamble: '
    . '{"ipa":"/.../","meaning":"..."}';

$payload = json_encode([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 200,
    'messages' => [
        ['role' => 'user', 'content' => $prompt],
    ],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'autofill request failed', 'detail' => $curlErr ?: ('HTTP ' . $httpCode)]);
    exit;
}

$data = json_decode($response, true);
$text = '';
foreach (($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') {
        $text .= (string) ($block['text'] ?? '');
    }
}

$text = trim(preg_replace('/```json|```/', '', $text));
$parsed = json_decode($text, true);

if (!is_array($parsed) || !isset($parsed['ipa'], $parsed['meaning'])) {
    http_response_code(502);
    echo json_encode(['error' => 'could not parse autofill response']);
    exit;
}

$ipa = trim((string) $parsed['ipa']);
$meaning = trim((string) $parsed['meaning']);

if ($ipa !== '' && $ipa[0] !== '/') {
    $ipa = '/' . trim($ipa, '/') . '/';
}

echo json_encode([
    'ipa' => $ipa,
    'meaning' => $meaning,
], JSON_UNESCAPED_UNICODE);

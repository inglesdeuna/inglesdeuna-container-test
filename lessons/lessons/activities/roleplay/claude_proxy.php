<?php
/**
 * Proxy for Anthropic Claude API.
 * The React component calls this file instead of the Anthropic API directly,
 * so the API key stays server-side (never exposed to the browser).
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function roleplay_env(string $key): string
{
    $v = $_ENV[$key] ?? getenv($key) ?? ($_SERVER[$key] ?? '');
    if ((!is_string($v) || trim($v) === '') && function_exists('apache_getenv')) {
        $ap = apache_getenv($key, true);
        if (is_string($ap) && trim($ap) !== '') $v = $ap;
    }
    if (!is_string($v) || trim($v) === '') {
        static $dotEnv = null;
        if ($dotEnv === null) {
            $dotEnv = [];
            $candidates = [
                __DIR__ . '/../../../../../.env',
                __DIR__ . '/../../../../.env',
                __DIR__ . '/../../../.env',
            ];
            foreach ($candidates as $path) {
                if (!is_file($path) || !is_readable($path)) continue;
                foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    [$k, $val] = array_map('trim', explode('=', $line, 2));
                    if ($k !== '' && !isset($dotEnv[$k])) {
                        $dotEnv[$k] = trim($val, " \t\n\r\0\x0B\"'");
                    }
                }
                break;
            }
        }
        if (isset($dotEnv[$key])) $v = $dotEnv[$key];
    }
    if (!is_string($v) || trim($v) === '') {
        static $fileSecrets = null;
        if ($fileSecrets === null) {
            $fileSecrets = [];
            $f = __DIR__ . '/../../config/tts_secrets.php';
            if (is_file($f) && is_readable($f)) {
                $loaded = require $f;
                if (is_array($loaded)) $fileSecrets = $loaded;
            }
        }
        if (isset($fileSecrets[$key])) $v = $fileSecrets[$key];
    }
    return is_string($v) ? trim($v) : '';
}

$apiKey = roleplay_env('ANTHROPIC_API_KEY');
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY is not configured on the server.']);
    exit;
}

$body = (string) file_get_contents('php://input');
if ($body === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json',
    ],
]);

$result   = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . $curlErr]);
    exit;
}

http_response_code($httpCode);
echo $result;

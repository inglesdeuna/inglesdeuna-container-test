<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function tts_env(string $key): string
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
            $envCandidates = [
                __DIR__ . '/../../../../../.env',
                __DIR__ . '/../../../../.env',
                __DIR__ . '/../../../.env',
            ];
            foreach ($envCandidates as $envPath) {
                if (!is_file($envPath) || !is_readable($envPath)) continue;
                $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    [$k, $val] = array_map('trim', explode('=', $line, 2));
                    if ($k !== '' && !isset($dotEnv[$k])) {
                        $dotEnv[$k] = trim($val, " \t\n\r\0\x0B\"'");
                    }
                }
            }
        }
        if (isset($dotEnv[$key]) && is_string($dotEnv[$key])) $v = $dotEnv[$key];
    }
    if (!is_string($v) || trim($v) === '') {
        static $fileSecrets = null;
        if ($fileSecrets === null) {
            $fileSecrets = [];
            $secretFile = __DIR__ . '/../../config/tts_secrets.php';
            if (is_file($secretFile) && is_readable($secretFile)) {
                $loaded = require $secretFile;
                if (is_array($loaded)) $fileSecrets = $loaded;
            }
        }
        if (isset($fileSecrets[$key]) && is_string($fileSecrets[$key])) $v = $fileSecrets[$key];
    }
    return is_string($v) ? trim($v) : '';
}

$allowed =
    (!empty($_SESSION['academic_logged']) && $_SESSION['academic_logged']) ||
    (!empty($_SESSION['admin_logged'])    && $_SESSION['admin_logged'])    ||
    (!empty($_SESSION['student_logged'])  && $_SESSION['student_logged']);

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$text = trim((string) ($_POST['text'] ?? ''));
$voiceId = trim((string) ($_POST['voice_id'] ?? 'nzFihrBIvB34imQBuxub'));

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Text is required']);
    exit;
}
if (mb_strlen($text) > 2500) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Text too long (max 2500 characters)']);
    exit;
}
if (!preg_match('/^[A-Za-z0-9]+$/', $voiceId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid voice ID']);
    exit;
}

$apiKey = tts_env('ELEVENLABS_API_KEY');
if ($apiKey === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ElevenLabs API key not configured']);
    exit;
}

$payload = json_encode([
    'text' => $text,
    'model_id' => 'eleven_multilingual_v2',
    'output_format' => 'mp3_44100_128',
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId));
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'xi-api-key: ' . $apiKey,
    'Content-Type: application/json',
    'Accept: audio/mpeg',
]);

$audio = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr !== '' || $httpCode !== 200 || !is_string($audio) || strlen($audio) < 100) {
    $msg = $curlErr !== '' ? $curlErr : "ElevenLabs API returned HTTP {$httpCode}";
    if (is_string($audio) && $audio !== '') {
        $j = json_decode($audio, true);
        if (isset($j['detail']['message'])) $msg = (string) $j['detail']['message'];
        elseif (isset($j['detail']) && is_string($j['detail'])) $msg = (string) $j['detail'];
    }
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audio));
header('Cache-Control: no-store');
echo $audio;

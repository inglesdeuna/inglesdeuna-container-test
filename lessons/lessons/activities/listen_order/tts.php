<?php
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Only teachers / admins may generate TTS
if (
    (empty($_SESSION['academic_logged']) || !$_SESSION['academic_logged']) &&
    (empty($_SESSION['admin_logged'])    || !$_SESSION['admin_logged'])
) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$text    = trim((string)($_POST['text']     ?? ''));
$voiceId = trim((string)($_POST['voice_id'] ?? 'JBFqnCBsd6RMkjVDRZzb'));

if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Text is required']);
    exit;
}

if (mb_strlen($text) > 2500) {
    http_response_code(400);
    echo json_encode(['error' => 'Text too long (max 2500 characters)']);
    exit;
}

// Allow only safe characters in voice_id (alphanumeric)
if (!preg_match('/^[A-Za-z0-9]+$/', $voiceId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid voice ID']);
    exit;
}

$apiKey = trim((string)(getenv('ELEVENLABS_API_KEY') ?: ($_ENV['ELEVENLABS_API_KEY'] ?? '')));
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'ElevenLabs API key not configured. Set the ELEVENLABS_API_KEY environment variable.']);
    exit;
}

// ── Call ElevenLabs TTS ──────────────────────────────────────────────────────
$payload = json_encode([
    'text'          => $text,
    'model_id'      => 'eleven_multilingual_v2',
    'output_format' => 'mp3_44100_128',
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voiceId));
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        60);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'xi-api-key: '  . $apiKey,
    'Content-Type: application/json',
    'Accept: audio/mpeg',
]);

$audio    = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '' || $httpCode !== 200 || !is_string($audio) || strlen($audio) < 100) {
    $msg = $curlErr !== '' ? $curlErr : "ElevenLabs API returned HTTP {$httpCode}";
    if (is_string($audio) && $audio !== '') {
        $j = json_decode($audio, true);
        if (isset($j['detail']['message'])) {
            $msg = (string)$j['detail']['message'];
        } elseif (isset($j['detail']) && is_string($j['detail'])) {
            $msg = $j['detail'];
        }
    }
    http_response_code(502);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── Upload MP3 to Cloudinary (raw resource type) ─────────────────────────────
$cloudName = trim((string)(getenv('CLOUDINARY_CLOUD_NAME') ?: ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? '')));
$cloudKey  = trim((string)(getenv('CLOUDINARY_API_KEY')    ?: ($_ENV['CLOUDINARY_API_KEY']    ?? '')));
$cloudSec  = trim((string)(getenv('CLOUDINARY_API_SECRET') ?: ($_ENV['CLOUDINARY_API_SECRET'] ?? '')));

if ($cloudName === '' || $cloudKey === '' || $cloudSec === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Cloudinary is not configured']);
    exit;
}

$tmpFile = tempnam(sys_get_temp_dir(), 'lo_tts_') . '.mp3';
if (file_put_contents($tmpFile, $audio) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write temporary file']);
    exit;
}

$timestamp = time();
$signature = sha1("timestamp={$timestamp}{$cloudSec}");

$ch2 = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload");
curl_setopt($ch2, CURLOPT_POST,           true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, [
    'file'      => new CURLFile($tmpFile, 'audio/mpeg', 'tts.mp3'),
    'api_key'   => $cloudKey,
    'timestamp' => (string)$timestamp,
    'signature' => $signature,
]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT,        120);

$uploadResult = curl_exec($ch2);
curl_close($ch2);
@unlink($tmpFile);

$uploadResponse = json_decode((string)$uploadResult, true);
$url = isset($uploadResponse['secure_url']) ? (string)$uploadResponse['secure_url'] : '';

if ($url === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload audio to storage']);
    exit;
}

echo json_encode(['url' => $url]);

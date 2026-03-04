<?php

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($url === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing url';
    exit;
}

$parts = parse_url($url);
if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid url';
    exit;
}

$scheme = strtolower((string) $parts['scheme']);
$host = strtolower((string) $parts['host']);

if ($scheme !== 'http' && $scheme !== 'https') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid scheme';
    exit;
}

$allowedHosts = array(
    'res.cloudinary.com',
);

$allowed = in_array($host, $allowedHosts, true) || substr($host, -strlen('.cloudinary.com')) === '.cloudinary.com';
if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Host not allowed';
    exit;
}

$clientRange = isset($_SERVER['HTTP_RANGE']) ? trim((string) $_SERVER['HTTP_RANGE']) : '';

$responseHeaders = array();
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Accept: application/pdf,*/*;q=0.8',
    'User-Agent: FlipbookProxy/1.0',
));
if ($clientRange !== '') {
    curl_setopt($ch, CURLOPT_RANGE, preg_replace('/^bytes=/', '', $clientRange));
}

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders) {
    $len = strlen($headerLine);
    $line = trim($headerLine);

    if ($line !== '' && strpos($line, ':') !== false) {
        list($name, $value) = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        $responseHeaders[$name] = $value;
    }

    return $len;
});

$body = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (($body === false || $httpCode >= 400) && $httpCode === 401 && strpos($url, '/image/upload/') !== false) {
    $retryUrl = str_replace('/image/upload/', '/raw/upload/', $url);
    $responseHeaders = array();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $retryUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/pdf,*/*;q=0.8',
        'User-Agent: FlipbookProxy/1.0',
    ));
    if ($clientRange !== '') {
        curl_setopt($ch, CURLOPT_RANGE, preg_replace('/^bytes=/', '', $clientRange));
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders) {
        $len = strlen($headerLine);
        $line = trim($headerLine);

        if ($line !== '' && strpos($line, ':') !== false) {
            list($name, $value) = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            $responseHeaders[$name] = $value;
        }

        return $len;
    });

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
}

if ($body === false || $httpCode >= 400) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error fetching PDF: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode);
    exit;
}

if ($contentType === '') {
    $contentType = isset($responseHeaders['content-type']) ? (string) $responseHeaders['content-type'] : '';
}
if ($contentType === '') {
    $contentType = 'application/pdf';
}

if ($httpCode === 206) {
    http_response_code(206);
} else {
    http_response_code(200);
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=600');
header('X-Content-Type-Options: nosniff');

if (isset($responseHeaders['accept-ranges'])) {
    header('Accept-Ranges: ' . $responseHeaders['accept-ranges']);
} else {
    header('Accept-Ranges: bytes');
}

if (isset($responseHeaders['content-range'])) {
    header('Content-Range: ' . $responseHeaders['content-range']);
}

if (isset($responseHeaders['content-length'])) {
    header('Content-Length: ' . $responseHeaders['content-length']);
}

echo $body;

<?php
declare(strict_types=1);

/**
 * Fetches a Cloudinary PDF into memory while preserving only the headers from
 * the final response. cURL invokes the header callback once per redirect; the
 * previous proxy mixed those header sets and could send a stale Content-Length
 * with the final PDF body.
 *
 * @return array{body:string|false,http_code:int,curl_error:string,headers:array<string,string>}
 */
function fetch_cloudinary_pdf(string $url, string $range): array
{
    $responseHeaders = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 240);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/pdf,application/octet-stream;q=0.9,*/*;q=0.5',
        'User-Agent: FlipbookProxy/2.0',
        'Accept-Encoding: identity',
    ]);

    if ($range !== '') {
        curl_setopt($ch, CURLOPT_RANGE, $range);
    }

    curl_setopt(
        $ch,
        CURLOPT_HEADERFUNCTION,
        static function ($ch, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $line = trim($headerLine);

            // A new HTTP status line starts a new header block. Discard headers
            // from redirects so values such as Content-Length cannot leak into
            // the final response.
            if (preg_match('/^HTTP\\/\\S+\\s+\\d{3}/i', $line) === 1) {
                $responseHeaders = [];
                return $length;
            }

            if ($line !== '' && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return $length;
        }
    );

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'body' => $body,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'headers' => $responseHeaders,
    ];
}

$url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($url === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Missing url');
}

$parts = parse_url($url);
if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid url');
}

$scheme = strtolower((string) $parts['scheme']);
$host = strtolower((string) $parts['host']);
$forceDownload = isset($_GET['dl']) && $_GET['dl'] === '1';

if ($scheme !== 'https' && $scheme !== 'http') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid scheme');
}

$allowed = $host === 'res.cloudinary.com'
    || str_ends_with($host, '.cloudinary.com');

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Host not allowed');
}

// Forward only a single syntactically valid byte range. Multiple ranges require
// multipart/byteranges and are intentionally ignored so a complete 200 response
// is returned instead of a malformed partial PDF.
$range = '';
$clientRange = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
if (
    $clientRange !== ''
    && preg_match('/^bytes=(\\d*)-(\\d*)$/', $clientRange, $matches) === 1
    && ($matches[1] !== '' || $matches[2] !== '')
) {
    $range = $matches[1] . '-' . $matches[2];
}

$response = fetch_cloudinary_pdf($url, $range);

// Recover old rows that stored an image/upload PDF URL. New uploads use raw.
if (
    ($response['body'] === false || $response['http_code'] === 401)
    && str_contains($url, '/image/upload/')
) {
    $url = str_replace('/image/upload/', '/raw/upload/', $url);
    $response = fetch_cloudinary_pdf($url, $range);
}

$body = $response['body'];
$httpCode = $response['http_code'];
$curlError = $response['curl_error'];
$responseHeaders = $response['headers'];

if ($body === false || $httpCode < 200 || $httpCode >= 300) {
    error_log(sprintf(
        'flipbook: Cloudinary PDF delivery failed (http=%d, curl_error=%s, url=%s)',
        $httpCode,
        $curlError !== '' ? $curlError : 'none',
        $url
    ));
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Error fetching PDF: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode));
}

$body = (string) $body;
if ($body === '') {
    error_log('flipbook: Cloudinary returned an empty PDF body for ' . $url);
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Cloudinary returned an empty PDF.');
}

// A complete response, or a range beginning at byte zero, must contain the PDF
// signature near the beginning. This prevents Cloudinary error HTML with a 2xx
// status from being sent to Chrome as application/pdf.
$startsAtZero = $range === '' || str_starts_with($range, '0-');
if ($startsAtZero && strpos(substr($body, 0, 1024), '%PDF-') === false) {
    error_log(sprintf(
        'flipbook: Cloudinary response is not a PDF (http=%d, bytes=%d, url=%s)',
        $httpCode,
        strlen($body),
        $url
    ));
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Cloudinary did not return a valid PDF.');
}

if ($httpCode === 206) {
    $contentRange = trim((string) ($responseHeaders['content-range'] ?? ''));
    if ($contentRange === '' || preg_match('/^bytes \\d+-\\d+\\/(?:\\d+|\\*)$/i', $contentRange) !== 1) {
        error_log('flipbook: invalid partial PDF response from Cloudinary: ' . $contentRange);
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Cloudinary returned an invalid partial PDF response.');
    }

    http_response_code(206);
    header('Content-Range: ' . $contentRange);
} else {
    http_response_code(200);
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="document.pdf"');
header('Content-Length: ' . strlen($body));
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=600');
header('X-Content-Type-Options: nosniff');

echo $body;

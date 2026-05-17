<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$allowed = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']) || !empty($_SESSION['student_logged']);
if (!$allowed) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized', 'score' => 0]);
    exit;
}

header('Content-Type: application/json');

$transcript = strtolower(trim((string) ($_POST['transcript'] ?? '')));
$expected   = strtolower(trim((string) ($_POST['expected']   ?? '')));

if ($transcript === '' || $expected === '') {
    echo json_encode(['score' => 0]);
    exit;
}

// Normalize: strip punctuation
function normalize(string $s): array {
    $s = preg_replace("/[^a-z0-9\s']/", '', $s);
    $words = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
    return array_map('trim', $words);
}

$tw = normalize($transcript);
$ew = normalize($expected);

if (empty($ew)) { echo json_encode(['score' => 0]); exit; }

// Count matching words (order-aware partial match)
$matched = 0;
$ewCount = count($ew);
$twCount = count($tw);

// Build frequency maps
$ewFreq = array_count_values($ew);
$twFreq = array_count_values($tw);

foreach ($ewFreq as $word => $count) {
    $matched += min($count, $twFreq[$word] ?? 0);
}

// Score = matched / expected_words * 100, penalise length mismatch
$precision = $twCount > 0 ? $matched / $twCount : 0;
$recall    = $matched / $ewCount;
$f1        = ($precision + $recall) > 0
    ? (2 * $precision * $recall / ($precision + $recall))
    : 0;

$score = (int) round($f1 * 100);

echo json_encode(['score' => $score, 'matched' => $matched, 'total' => $ewCount]);

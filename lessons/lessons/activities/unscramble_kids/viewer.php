<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

/* ── helpers ─────────────────────────────────────────────────────────── */
function usk_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function usk_default_title(): string { return 'Spell the Word'; }

function usk_normalize_payload($raw): array
{
    $default = ['title' => usk_default_title(), 'voice_id' => 'Nggzl2QAXh3OijoXD116', 'words' => []];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $words = [];
    foreach (($d['words'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $word = strtoupper(trim((string) ($item['word'] ?? '')));
        if ($word === '') continue;
        $words[] = [
            'word'     => $word,
            'emoji'    => trim((string) ($item['emoji']    ?? '')),
            'hint'     => trim((string) ($item['hint']     ?? '')),
            'image'    => trim((string) ($item['image']    ?? '')),
            'audio'    => trim((string) ($item['audio']    ?? '')),
            'voice_id' => trim((string) ($item['voice_id'] ?? '')),
        ];
    }

    $title   = trim((string) ($d['title']    ?? ''));
    $voiceId = trim((string) ($d['voice_id'] ?? 'Nggzl2QAXh3OijoXD116'));
    if ($voiceId === '') $voiceId = 'Nggzl2QAXh3OijoXD116';

    return [
        'title'    => $title !== '' ? $title : usk_default_title(),
        'voice_id' => $voiceId,
        'words'    => $words,
    ];
}

function usk_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['id' => '', 'title' => usk_default_title(), 'voice_id' => 'Nggzl2QAXh3OijoXD116', 'words' => []];
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble_kids' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'unscramble_kids' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $p = usk_normalize_payload($row['data'] ?? null);
    return [
        'id'       => (string) ($row['id'] ?? ''),
        'title'    => $p['title'],
        'voice_id' => $p['voice_id'],
        'words'    => $p['words'],
    ];
}

/* ── load data ───────────────────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') {
    $unit = usk_resolve_unit($pdo, $activityId);
}

$activity        = usk_load($pdo, $activityId, $unit);
$viewerTitle     = $activity['title'];
$activityVoiceId = $activity['voice_id'];
$words           = $activity['words'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

if (count($words) === 0) {
    die('No words found for this activity');
}

/* ── render ──────────────────────────────────────────────────────────── */
ob_start();
require __DIR__ . '/_unscramble_kids_view.php';
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🔤', $content);

